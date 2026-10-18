<script setup lang="ts">
import {
    ChevronDown,
    CircleCheck,
    CircleX,
    RefreshCcw,
    RotateCcw,
    Trash2,
    Upload,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { formatDate } from '@/lib/format';

/**
 * "Historique de ce document", collapsed: what happened to one slot, oldest
 * first — uploads, the ONDA's decisions (with the reason of a rejection),
 * the author's removals, replacements. Read-only; built on the server
 * (OeuvreController::slotHistory). Every decision is signed ONDA: no
 * reviewer name exists in this data.
 */
export interface HistoryEvent {
    at: string | null;
    kind:
        | 'uploaded'
        | 'replacement'
        | 'accepted'
        | 'rejected'
        | 'reset'
        | 'removed';
    file: string;
    reason: string | null;
}

defineProps<{ events: HistoryEvent[] }>();

const { t, locale } = useI18n();

const ICONS = {
    uploaded: Upload,
    replacement: RefreshCcw,
    accepted: CircleCheck,
    rejected: CircleX,
    reset: RotateCcw,
    removed: Trash2,
} as const;

const TONES: Record<HistoryEvent['kind'], string> = {
    uploaded: 'text-muted-foreground',
    replacement: 'text-muted-foreground',
    accepted: 'text-emerald-600 dark:text-emerald-400',
    rejected: 'text-destructive',
    reset: 'text-muted-foreground',
    removed: 'text-muted-foreground',
};
</script>

<template>
    <Collapsible
        v-if="events.length > 0"
        class="rounded-lg border border-border/70"
        data-testid="slot-history"
    >
        <CollapsibleTrigger
            class="group flex w-full cursor-pointer items-center justify-between gap-2 px-3 py-2 text-xs font-medium text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/40"
        >
            {{ t('oeuvres.history.title') }}
            <ChevronDown
                class="size-3.5 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none"
                aria-hidden="true"
            />
        </CollapsibleTrigger>
        <CollapsibleContent>
            <ol class="space-y-2 border-t border-border/70 px-3 py-2 text-xs">
                <li
                    v-for="(event, i) in events"
                    :key="i"
                    class="flex items-start gap-2"
                    data-testid="history-event"
                    :data-kind="event.kind"
                >
                    <component
                        :is="ICONS[event.kind]"
                        class="mt-0.5 size-3.5 shrink-0"
                        :class="TONES[event.kind]"
                        aria-hidden="true"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="text-foreground">
                            {{
                                t(`oeuvres.history.${event.kind}`, {
                                    file: event.file,
                                })
                            }}
                        </p>
                        <p
                            v-if="event.reason"
                            class="mt-0.5 rounded border-s-2 border-destructive/40 bg-muted/60 px-2 py-1 whitespace-pre-line text-foreground"
                        >
                            <bdi dir="auto">{{ event.reason }}</bdi>
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            <bdi dir="ltr">{{
                                event.at ? formatDate(event.at, locale) : ''
                            }}</bdi>
                            <template
                                v-if="
                                    ['accepted', 'rejected', 'reset'].includes(
                                        event.kind,
                                    )
                                "
                            >
                                · ONDA</template
                            >
                        </p>
                    </div>
                </li>
            </ol>
        </CollapsibleContent>
    </Collapsible>
</template>
