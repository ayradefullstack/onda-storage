<script setup lang="ts">
import { Ban } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { AppDialog } from '@/components/ui/app-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { reject } from '@/routes/admin/oeuvres';
import {
    insertAtCursor,
    postDecision,
    REASON_MAX,
    REASON_MIN,
    reasonLength,
    reasonState,
} from './decisions';
import type { Outcome } from './decisions';
import { useKeyboardInset } from './useKeyboardInset';

/**
 * "Rejeter le dépôt": the reason is the whole point. It reaches the author
 * word for word and is the only thing telling them what to fix, so the
 * dialog will not send one the server would refuse (20 to 2000 characters,
 * trimmed) and shows the count as it is typed.
 *
 * The quick reasons are starting points, inserted at the cursor: an
 * officer picks one and then says which document, which file.
 */
const props = defineProps<{
    open: boolean;
    oeuvre: { uuid: string; label: string };
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
    outcome: [outcome: Outcome];
}>();

const CHIPS = [
    'missingDocument',
    'unreadableFile',
    'wrongInformation',
    'wrongFormat',
] as const;

/** The field grows with its text up to here, then scrolls. */
const MAX_HEIGHT = 240;

const { t } = useI18n();
const reason = ref('');
const busy = ref(false);
const touched = ref(false);
const serverErrors = ref<string[]>([]);
const field = ref<HTMLTextAreaElement | null>(null);

useKeyboardInset();

const length = computed(() => reasonLength(reason.value));
const state = computed(() => reasonState(reason.value));

const error = computed<string | null>(() => {
    if (state.value === 'long') {
        const over = length.value - REASON_MAX;

        return t('review.reject.tooLong', { count: over }, over);
    }

    if (state.value === 'short' && touched.value) {
        const missing = REASON_MIN - length.value;

        return t('review.reject.tooShort', { count: missing }, missing);
    }

    return serverErrors.value[0] ?? null;
});

function grow(): void {
    const element = field.value;

    if (element === null) {
        return;
    }

    element.style.height = 'auto';
    element.style.height = `${Math.min(element.scrollHeight, MAX_HEIGHT)}px`;
    element.style.overflowY =
        element.scrollHeight > MAX_HEIGHT ? 'auto' : 'hidden';
}

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        reason.value = '';
        touched.value = false;
        serverErrors.value = [];
        void nextTick(grow);
    },
);

watch(reason, () => {
    serverErrors.value = [];
    void nextTick(grow);
});

function insert(chip: (typeof CHIPS)[number]): void {
    const element = field.value;
    const start = element?.selectionStart ?? reason.value.length;
    const end = element?.selectionEnd ?? reason.value.length;
    const result = insertAtCursor(
        reason.value,
        t(`review.reject.chips.${chip}`),
        start,
        end,
    );

    reason.value = result.text;

    void nextTick(() => {
        element?.focus();
        element?.setSelectionRange(result.cursor, result.cursor);
    });
}

async function confirm(): Promise<void> {
    touched.value = true;

    if (busy.value || state.value !== 'ok') {
        return;
    }

    busy.value = true;
    const outcome = await postDecision(reject(props.oeuvre.uuid).url, {
        reason: reason.value.trim(),
    });
    busy.value = false;

    // A reason the server still refuses is this field's problem, shown
    // here; anything else (a conflict, a moved status) is the page's.
    if (outcome.kind === 'invalid' && outcome.errors.reason !== undefined) {
        serverErrors.value = outcome.errors.reason;

        return;
    }

    emit('update:open', false);
    emit('outcome', outcome);
}
</script>

<template>
    <AppDialog
        :open="open"
        size="lg"
        :busy="busy"
        form
        class="max-sm:bottom-[var(--keyboard-inset,0px)]max-sm:max-h-[calc(100dvh-var(--keyboard-inset,0px)-1rem)]"
        @update:open="(value: boolean) => emit('update:open', value)"
        @submit="confirm"
    >
        <template #icon>
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-xl border border-destructive/20 bg-destructive/10 text-destructive"
            >
                <Ban class="size-5" />
            </div>
        </template>
        <template #title>{{ t('review.reject.title') }}</template>
        <template #description>
            <bdi class="font-medium text-foreground">{{ oeuvre.label }}</bdi>
            — {{ t('review.reject.description') }}
        </template>

        <div class="space-y-3" data-testid="reject-dialog">
            <div class="space-y-1.5">
                <label
                    for="reject-reason"
                    class="text-sm font-medium text-foreground"
                >
                    {{ t('review.reject.reasonLabel') }}
                </label>
                <textarea
                    id="reject-reason"
                    ref="field"
                    v-model="reason"
                    rows="4"
                    dir="auto"
                    data-testid="reject-reason"
                    class="block w-full resize-none rounded-lg border bg-background px-3 py-2 text-sm leading-relaxed outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/40 aria-invalid:border-destructive aria-invalid:ring-destructive/20"
                    :style="{ maxHeight: `${MAX_HEIGHT}px` }"
                    :placeholder="t('review.reject.placeholder')"
                    :aria-invalid="error !== null"
                    aria-describedby="reject-reason-help reject-reason-error"
                    :disabled="busy"
                    @blur="touched = true"
                />
                <div
                    class="flex items-start justify-between gap-3 text-xs text-muted-foreground"
                >
                    <div class="min-w-0">
                        <p
                            v-if="error"
                            id="reject-reason-error"
                            role="alert"
                            class="text-destructive"
                            data-testid="reject-error"
                        >
                            {{ error }}
                        </p>
                        <p v-else id="reject-reason-help">
                            {{
                                t('review.reject.bounds', {
                                    min: REASON_MIN,
                                    max: REASON_MAX,
                                })
                            }}
                        </p>
                    </div>
                    <p
                        class="shrink-0 tabular-nums"
                        :class="
                            state === 'long'
                                ? 'font-medium text-destructive'
                                : ''
                        "
                        aria-live="polite"
                        data-testid="reject-counter"
                    >
                        <bdi dir="ltr">{{ length }} / {{ REASON_MAX }}</bdi>
                    </p>
                </div>
            </div>

            <div class="space-y-1.5">
                <p class="text-xs font-medium text-muted-foreground">
                    {{ t('review.reject.chipsLabel') }}
                </p>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="chip in CHIPS"
                        :key="chip"
                        type="button"
                        :data-chip="chip"
                        :disabled="busy"
                        class="cursor-pointer rounded-full border bg-background px-2.5 py-1 text-xs transition-colors outline-none hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring/40 disabled:pointer-events-none disabled:opacity-50"
                        @click="insert(chip)"
                    >
                        {{ t(`review.reject.chips.${chip}`) }}
                    </button>
                </div>
            </div>
        </div>

        <template #footer>
            <Button
                type="button"
                variant="outline"
                :disabled="busy"
                @click="emit('update:open', false)"
            >
                {{ t('review.cancel') }}
            </Button>
            <Button
                type="submit"
                variant="destructive"
                data-testid="reject-confirm"
                :disabled="busy || state !== 'ok'"
            >
                <Spinner v-if="busy" class="size-4" />
                <Ban v-else class="size-4" />
                {{ t('review.reject.confirm') }}
            </Button>
        </template>
    </AppDialog>
</template>
