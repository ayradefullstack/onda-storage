<script setup lang="ts">
import { Ban, Check, ChevronDown, PencilLine, RotateCcw } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/format';
import { decide } from '@/routes/admin/oeuvres/files';
import type { Outcome } from './decisions';
import FileRejectDialog from './FileRejectDialog.vue';
import {
    ACTION_DECISION,
    invalidMessage,
    postFileDecision,
    REVIEW_STATUS,
} from './fileReview';
import type { FileAction, FileAllowed, ReviewStatus } from './fileReview';

/**
 * The decision buttons of ONE file. It offers exactly the server's
 * `allowed.actions` and nothing it worked out itself; when that list is
 * empty it says why (`allowed.reason`).
 *
 *   pending   "Accepter" (behind a small confirm) and "Rejeter".
 *   decided   "Modifier la décision": the opposite decision, a new reason
 *             for a rejected file, or back to pending.
 *
 * Every answer is told apart: success → toast and `decided` (the page
 * reloads only its file props, silently); 409 → who holds or who decided,
 * then `stale` so the page refetches; 422 → the server's own message.
 */
const props = withDefaults(
    defineProps<{
        oeuvre: string;
        file: {
            uuid: string;
            original_name: string;
            review_status: string;
            review_reason?: string | null;
        };
        allowed: FileAllowed;
        /** The review page's header uses the larger buttons. */
        size?: 'sm' | 'default';
    }>(),
    { size: 'sm' },
);

const emit = defineEmits<{
    decided: [status: ReviewStatus];
    stale: [];
}>();

const { t, locale } = useI18n();

const busy = ref<FileAction | null>(null);
const confirmOpen = ref(false);
const reviseOpen = ref(false);
const rejectOpen = ref(false);
const reasonError = ref<string | null>(null);
const notice = ref<string | null>(null);

const can = (action: FileAction): boolean =>
    props.allowed.actions.includes(action);
const undecided = computed(
    () => props.file.review_status === REVIEW_STATUS.PENDING,
);
const empty = computed(() => props.allowed.actions.length === 0);
/** Read-only once the deposit is decided: no greyed buttons, just why. */
const showDisabled = computed(
    () => empty.value && props.allowed.reason !== 'oeuvre_decided',
);
const reasonId = computed(() => `file-actions-reason-${props.file.uuid}`);

function conflictText(outcome: Extract<Outcome, { kind: 'conflict' }>): string {
    const { conflict } = outcome;

    if (conflict.kind === 'decided') {
        return conflict.by === null
            ? t('review.toast.decidedUnknown')
            : t('review.toast.decided', {
                  name: conflict.by,
                  date:
                      conflict.at === null
                          ? '—'
                          : formatDate(conflict.at, locale.value),
              });
    }

    if (conflict.kind === 'held' && conflict.by !== null) {
        return t('review.toast.held', { name: conflict.by });
    }

    return t('review.dialog.moved');
}

async function act(action: FileAction, reason?: string): Promise<void> {
    if (busy.value !== null) {
        return;
    }

    busy.value = action;
    notice.value = null;
    reasonError.value = null;

    const decision = ACTION_DECISION[action];
    const outcome = await postFileDecision(
        decide({ oeuvre: props.oeuvre, mediaFile: props.file.uuid }).url,
        decision,
        reason,
    );

    busy.value = null;

    switch (outcome.kind) {
        case 'done':
            confirmOpen.value = false;
            reviseOpen.value = false;
            rejectOpen.value = false;

            if (outcome.replayed) {
                toast.info(t('review.toast.replayed'));
            } else {
                toast.success(t(`review.file.toast.${decision}`));
            }

            emit('decided', decision);

            return;
        case 'conflict':
            notice.value = conflictText(outcome);
            rejectOpen.value = false;
            toast.error(notice.value);
            emit('stale');

            return;
        case 'invalid': {
            const message = invalidMessage(outcome.errors);

            if (action === 'reject' && outcome.errors.reason !== undefined) {
                reasonError.value = message;

                return;
            }

            notice.value = message ?? t('review.toast.invalid');
            rejectOpen.value = false;
            toast.error(notice.value);
            emit('stale');

            return;
        }
        default:
            notice.value = t('review.toast.failed');
            toast.error(notice.value);
    }
}

function openReject(): void {
    reviseOpen.value = false;
    reasonError.value = null;
    rejectOpen.value = true;
}
</script>

<template>
    <div class="flex flex-col items-start gap-1" data-testid="file-actions">
        <div class="flex flex-wrap items-center gap-1.5">
            <template v-if="showDisabled && undecided">
                <Button
                    type="button"
                    :size="size"
                    variant="outline"
                    disabled
                    :aria-describedby="reasonId"
                    data-testid="file-accept"
                >
                    <Check class="size-3.5" />
                    {{ t('review.file.accept') }}
                </Button>
                <Button
                    type="button"
                    :size="size"
                    variant="outline"
                    disabled
                    :aria-describedby="reasonId"
                    data-testid="file-reject"
                >
                    <Ban class="size-3.5" />
                    {{ t('review.file.reject') }}
                </Button>
            </template>

            <template v-else-if="!empty && undecided">
                <Popover v-if="can('accept')" v-model:open="confirmOpen">
                    <PopoverTrigger as-child>
                        <Button
                            type="button"
                            :size="size"
                            variant="success"
                            :disabled="busy !== null"
                            data-testid="file-accept"
                        >
                            <Check class="size-3.5" />
                            {{ t('review.file.accept') }}
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent
                        class="w-60 space-y-3 p-3"
                        align="start"
                        data-testid="file-accept-popover"
                    >
                        <p class="text-sm font-medium">
                            {{ t('review.file.acceptQuestion') }}
                        </p>
                        <div class="flex justify-end gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                :disabled="busy !== null"
                                @click="confirmOpen = false"
                            >
                                {{ t('review.cancel') }}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="success"
                                :disabled="busy !== null"
                                data-testid="file-accept-confirm"
                                @click="act('accept')"
                            >
                                <Spinner
                                    v-if="busy === 'accept'"
                                    class="size-3.5"
                                />
                                <Check v-else class="size-3.5" />
                                {{ t('review.file.accept') }}
                            </Button>
                        </div>
                    </PopoverContent>
                </Popover>

                <Button
                    v-if="can('reject')"
                    type="button"
                    :size="size"
                    variant="outline"
                    class="text-destructive hover:text-destructive"
                    :disabled="busy !== null"
                    data-testid="file-reject"
                    @click="openReject"
                >
                    <Ban class="size-3.5" />
                    {{ t('review.file.reject') }}
                </Button>
            </template>

            <Popover v-else-if="!empty" v-model:open="reviseOpen">
                <PopoverTrigger as-child>
                    <Button
                        type="button"
                        :size="size"
                        variant="outline"
                        :disabled="busy !== null"
                        data-testid="file-revise"
                    >
                        <Spinner v-if="busy !== null" class="size-3.5" />
                        <PencilLine v-else class="size-3.5" />
                        {{ t('review.file.revise') }}
                        <ChevronDown class="size-3.5 opacity-60" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    class="w-56 p-1"
                    align="start"
                    data-testid="file-revise-menu"
                >
                    <button
                        v-if="can('accept')"
                        type="button"
                        class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm outline-none hover:bg-accent focus-visible:bg-accent disabled:opacity-50"
                        :disabled="busy !== null"
                        data-testid="file-revise-accept"
                        @click="act('accept')"
                    >
                        <Check
                            class="size-4 text-emerald-600 dark:text-emerald-400"
                        />
                        {{ t('review.file.accept') }}
                    </button>
                    <button
                        v-if="can('reject')"
                        type="button"
                        class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm text-destructive outline-none hover:bg-accent focus-visible:bg-accent disabled:opacity-50"
                        :disabled="busy !== null"
                        data-testid="file-revise-reject"
                        @click="openReject"
                    >
                        <Ban class="size-4" />
                        {{
                            file.review_status === REVIEW_STATUS.REJECTED
                                ? t('review.file.changeReason')
                                : t('review.file.reject')
                        }}
                    </button>
                    <button
                        v-if="can('reset')"
                        type="button"
                        class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm outline-none hover:bg-accent focus-visible:bg-accent disabled:opacity-50"
                        :disabled="busy !== null"
                        data-testid="file-revise-reset"
                        @click="act('reset')"
                    >
                        <RotateCcw class="size-4 text-muted-foreground" />
                        {{ t('review.file.reset') }}
                    </button>
                </PopoverContent>
            </Popover>
        </div>

        <p
            v-if="empty && allowed.reason"
            :id="reasonId"
            class="text-xs text-muted-foreground"
            data-testid="file-actions-reason"
            :data-reason="allowed.reason"
        >
            {{ t(`review.file.disabled.${allowed.reason}`) }}
        </p>

        <p
            v-if="notice"
            role="alert"
            class="text-xs text-destructive"
            data-testid="file-decision-notice"
        >
            {{ notice }}
        </p>

        <FileRejectDialog
            v-model:open="rejectOpen"
            :file-name="file.original_name"
            :busy="busy === 'reject'"
            :server-error="reasonError"
            :initial="
                file.review_status === REVIEW_STATUS.REJECTED
                    ? file.review_reason
                    : null
            "
            @confirm="(reason: string) => act('reject', reason)"
        />
    </div>
</template>
