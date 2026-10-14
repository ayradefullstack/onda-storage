<script setup lang="ts">
import {
    Ban,
    Eye,
    MoreHorizontal,
    PlayCircle,
    ShieldCheck,
    UserCheck,
    UserRoundCog,
} from '@lucide/vue';
import { PopoverAnchor } from 'reka-ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { AppDialog } from '@/components/ui/app-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Popover, PopoverContent } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatDate } from '@/lib/format';
import { review } from '@/routes/admin/oeuvres';
import AcceptDialog from './AcceptDialog.vue';
import { acceptBlockers, availableActions, postDecision } from './decisions';
import type { Checks, Holder, Outcome } from './decisions';
import RejectDialog from './RejectDialog.vue';

/**
 * Everything an officer can do to one deposit, and only what its status
 * allows right now (see `availableActions`):
 *
 *   submitted                 Commencer l'examen (a confirm popover)
 *   under_review, yours       Accepter · Rejeter (dialogs)
 *   under_review, another's   "En cours d'examen par X" · Reprendre
 *   anything else             nothing
 *
 * `bar` is the oeuvre page's header; `menu` is the compact form for a
 * table row. Both open the same dialogs and announce the same outcomes.
 *
 * It never changes the page's data itself: it emits `changed` and the page
 * reloads its props, so the status shown is always the server's. That is
 * also what a conflict does — "Déjà traité par X le …", then a reload.
 */
const props = withDefaults(
    defineProps<{
        oeuvre: {
            uuid: string;
            label: string;
            author: string | null;
            status: string;
        };
        holder: Holder | null;
        checks: Checks;
        canDecide: boolean;
        advisories?: number;
        variant?: 'bar' | 'menu';
    }>(),
    { advisories: 0, variant: 'bar' },
);

const emit = defineEmits<{ changed: [] }>();

const { t, locale } = useI18n();

const actions = computed(() =>
    availableActions(props.oeuvre.status, props.holder, props.canDecide),
);
const hasActions = computed(() => Object.values(actions.value).some(Boolean));
const blockers = computed(() => acceptBlockers(props.checks));
const blockedReason = computed(() =>
    blockers.value
        .map(({ key, count }) => t(`review.blockers.${key}`, { count }, count))
        .join(' · '),
);

const startOpen = ref(false);
const acceptOpen = ref(false);
const rejectOpen = ref(false);
const takeOverOpen = ref(false);
const busy = ref(false);

const dialogOeuvre = computed(() => ({
    uuid: props.oeuvre.uuid,
    label: props.oeuvre.label,
    author: props.oeuvre.author,
    reference: props.oeuvre.uuid,
}));

type Kind = 'started' | 'accepted' | 'rejected' | 'takenOver';

function announce(kind: Kind, outcome: Outcome): void {
    switch (outcome.kind) {
        case 'done':
            if (outcome.replayed) {
                toast.info(t('review.toast.replayed'));
            } else {
                toast.success(t(`review.toast.${kind}`));
            }

            break;
        case 'conflict': {
            const { conflict } = outcome;

            if (conflict.kind === 'decided') {
                toast.error(
                    conflict.by === null
                        ? t('review.toast.decidedUnknown')
                        : t('review.toast.decided', {
                              name: conflict.by,
                              date:
                                  conflict.at === null
                                      ? '—'
                                      : formatDate(conflict.at, locale.value),
                          }),
                );
            } else if (conflict.kind === 'held' && conflict.by !== null) {
                toast.warning(t('review.toast.held', { name: conflict.by }));
            } else {
                toast.warning(t('review.toast.moved'));
            }

            break;
        }
        case 'failed':
            toast.error(t('review.toast.failed'));

            // Nothing reached the server, or nothing came back: the page's
            // data is not known to be stale, so it is left alone.
            return;
        default:
            toast.error(t('review.toast.invalid'));
    }

    emit('changed');
}

async function open(takeOver: boolean): Promise<void> {
    if (busy.value) {
        return;
    }

    busy.value = true;
    const outcome = await postDecision(
        review(props.oeuvre.uuid).url,
        takeOver ? { take_over: true } : {},
    );
    busy.value = false;
    startOpen.value = false;
    takeOverOpen.value = false;

    announce(takeOver ? 'takenOver' : 'started', outcome);
}

/** From the row menu: let it close before the next layer takes focus. */
function later(action: () => void): void {
    setTimeout(action, 0);
}
</script>

<template>
    <div
        v-if="hasActions || variant === 'bar'"
        :class="
            variant === 'bar'
                ? 'flex flex-col items-start gap-1.5 sm:items-end'
                : 'inline-flex'
        "
        data-testid="oeuvre-decisions"
        :data-status="oeuvre.status"
    >
        <!-- Who has it, when that is worth saying. -->
        <p
            v-if="
                variant === 'bar' && oeuvre.status === 'under_review' && holder
            "
            class="flex items-center gap-1.5 text-xs text-muted-foreground"
            data-testid="holder-notice"
        >
            <UserCheck class="size-3.5 shrink-0" />
            <span v-if="holder.is_you">{{ t('review.held.you') }}</span>
            <i18n-t v-else keypath="review.held.other" tag="span">
                <template #name>
                    <bdi class="font-medium text-foreground">{{
                        holder.name
                    }}</bdi>
                </template>
            </i18n-t>
        </p>

        <Popover v-if="hasActions" v-model:open="startOpen">
            <PopoverAnchor as-child>
                <div class="flex flex-wrap items-center gap-2">
                    <!-- Header form -->
                    <template v-if="variant === 'bar'">
                        <Button
                            v-if="actions.start"
                            data-testid="action-start"
                            :aria-expanded="startOpen"
                            @click="startOpen = true"
                        >
                            <PlayCircle class="size-4" />
                            {{ t('review.actions.start') }}
                        </Button>

                        <template v-if="actions.accept">
                            <TooltipProvider
                                v-if="blockers.length > 0"
                                :delay-duration="150"
                            >
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <!-- A disabled button fires no
                                             pointer events: the wrapper
                                             carries the tooltip and focus. -->
                                        <span
                                            tabindex="0"
                                            class="inline-flex rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
                                            :aria-label="`${t('review.actions.accept')} — ${blockedReason}`"
                                        >
                                            <Button
                                                variant="success"
                                                disabled
                                                data-testid="action-accept"
                                            >
                                                <ShieldCheck class="size-4" />
                                                {{ t('review.actions.accept') }}
                                            </Button>
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent>{{
                                        blockedReason
                                    }}</TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                            <Button
                                v-else
                                variant="success"
                                data-testid="action-accept"
                                @click="acceptOpen = true"
                            >
                                <ShieldCheck class="size-4" />
                                {{ t('review.actions.accept') }}
                            </Button>
                        </template>

                        <Button
                            v-if="actions.reject"
                            variant="outline"
                            class="text-destructive hover:text-destructive"
                            data-testid="action-reject"
                            @click="rejectOpen = true"
                        >
                            <Ban class="size-4" />
                            {{ t('review.actions.reject') }}
                        </Button>

                        <Button
                            v-if="actions.takeOver"
                            variant="outline"
                            data-testid="action-take-over"
                            @click="takeOverOpen = true"
                        >
                            <UserRoundCog class="size-4" />
                            {{ t('review.actions.takeOver') }}
                        </Button>
                    </template>

                    <!-- Table-row form -->
                    <DropdownMenu v-else>
                        <TooltipProvider :delay-duration="150">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon-sm"
                                            data-testid="decision-menu"
                                            class="size-8 rounded-lg border border-border/60 bg-background/60 text-muted-foreground shadow-2xs transition-all duration-150 hover:border-border hover:bg-muted hover:text-foreground active:scale-95"
                                            :aria-label="
                                                t('review.actions.menu')
                                            "
                                            :title="t('review.actions.menu')"
                                        >
                                            <MoreHorizontal class="size-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                </TooltipTrigger>
                                <TooltipContent side="top">
                                    <p>{{ t('review.actions.menu') }}</p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                        <DropdownMenuContent align="end" class="min-w-52">
                            <DropdownMenuItem
                                v-if="actions.start"
                                data-testid="menu-start"
                                @select="later(() => (startOpen = true))"
                            >
                                <PlayCircle class="size-4" />
                                {{ t('review.actions.start') }}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="actions.accept"
                                data-testid="menu-accept"
                                :disabled="blockers.length > 0"
                                class="items-start"
                                @select="later(() => (acceptOpen = true))"
                            >
                                <ShieldCheck class="mt-0.5 size-4" />
                                <span class="flex flex-col">
                                    <span>{{
                                        t('review.actions.accept')
                                    }}</span>
                                    <span
                                        v-if="blockers.length > 0"
                                        class="text-[11px] whitespace-normal text-muted-foreground"
                                        >{{ blockedReason }}</span
                                    >
                                </span>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="actions.reject"
                                data-testid="menu-reject"
                                class="text-destructive focus:text-destructive"
                                @select="later(() => (rejectOpen = true))"
                            >
                                <Ban class="size-4" />
                                {{ t('review.actions.reject') }}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="actions.takeOver"
                                data-testid="menu-take-over"
                                class="items-start"
                                @select="later(() => (takeOverOpen = true))"
                            >
                                <UserRoundCog class="mt-0.5 size-4" />
                                <span class="flex flex-col">
                                    <span>{{
                                        t('review.actions.takeOver')
                                    }}</span>
                                    <span
                                        v-if="holder"
                                        class="text-[11px] whitespace-normal text-muted-foreground"
                                        >{{
                                            t('review.held.other', {
                                                name: holder.name,
                                            })
                                        }}</span
                                    >
                                </span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </PopoverAnchor>

            <!-- "Commencer l'examen": one click and a small confirm. -->
            <PopoverContent
                align="end"
                class="w-72 space-y-3 p-4"
                data-testid="start-confirm"
            >
                <div class="space-y-1">
                    <p class="text-sm font-semibold">
                        {{ t('review.start.confirmTitle') }}
                    </p>
                    <p class="text-xs leading-relaxed text-muted-foreground">
                        {{ t('review.start.confirmBody') }}
                    </p>
                </div>
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="busy"
                        @click="startOpen = false"
                    >
                        {{ t('review.cancel') }}
                    </Button>
                    <Button
                        size="sm"
                        data-testid="start-confirm-button"
                        :disabled="busy"
                        @click="open(false)"
                    >
                        <Spinner v-if="busy" class="size-3.5" />
                        <Eye v-else class="size-3.5" />
                        {{ t('review.start.confirm') }}
                    </Button>
                </div>
            </PopoverContent>
        </Popover>

        <!-- Why "Accepter" is greyed: said in the page, not only on hover. -->
        <p
            v-if="variant === 'bar' && actions.accept && blockers.length > 0"
            class="max-w-xs text-xs text-muted-foreground sm:text-end"
            data-testid="accept-hint"
        >
            {{ blockedReason }}
        </p>

        <AcceptDialog
            v-model:open="acceptOpen"
            :oeuvre="dialogOeuvre"
            :checks="checks"
            :advisories="advisories"
            @outcome="(outcome: Outcome) => announce('accepted', outcome)"
        />

        <RejectDialog
            v-model:open="rejectOpen"
            :oeuvre="dialogOeuvre"
            @outcome="(outcome: Outcome) => announce('rejected', outcome)"
        />

        <AppDialog
            :open="takeOverOpen"
            size="sm"
            :busy="busy"
            :title="t('review.takeOver.title')"
            @update:open="(value: boolean) => (takeOverOpen = value)"
        >
            <template #icon>
                <div
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl border bg-muted text-muted-foreground"
                >
                    <UserRoundCog class="size-5" />
                </div>
            </template>
            <p
                class="text-sm leading-relaxed text-muted-foreground"
                data-testid="take-over-dialog"
            >
                {{ t('review.takeOver.body', { name: holder?.name ?? '' }) }}
            </p>
            <template #footer>
                <Button
                    variant="outline"
                    :disabled="busy"
                    @click="takeOverOpen = false"
                >
                    {{ t('review.cancel') }}
                </Button>
                <Button
                    data-testid="take-over-confirm"
                    :disabled="busy"
                    @click="open(true)"
                >
                    <Spinner v-if="busy" class="size-4" />
                    {{ t('review.takeOver.confirm') }}
                </Button>
            </template>
        </AppDialog>
    </div>
</template>
