<script setup lang="ts">
import { CircleX, RefreshCcw, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import FileRemovalDialog from './FileRemovalDialog.vue';

/**
 * The reason a file was rejected, directly under that file (one callout
 * per file, never a shared list): the heading "Motif du rejet", the ONDA's
 * reason word for word (a long one folds to about four lines behind "Voir
 * plus"), the decision date, the signature "ONDA" — never an officer's
 * name — the instruction, and ONE action.
 *
 * The action follows the server's flags: shown only when `canRemove`;
 * "Supprimer et remplacer" when the file sits in a slot that will take its
 * replacement (`canReplace`), plain "Supprimer" otherwise (an unclassified
 * deposit has no slot). The parent runs the request; while it does
 * (`busy`) the dialog cannot be dismissed, and it closes once it settles.
 */
const props = defineProps<{
    fileName: string;
    reason: string | null;
    reviewedAt: string | null;
    canRemove: boolean;
    canReplace: boolean;
    busy?: boolean;
}>();

const emit = defineEmits<{ replace: []; remove: [] }>();

const { t, locale } = useI18n();

/** Long enough to need folding at about four lines of the callout. */
const LONG_REASON = 240;

const expanded = ref(false);
const long = computed(() => (props.reason?.length ?? 0) > LONG_REASON);

const mode = computed(() => (props.canReplace ? 'replace' : 'remove'));
const confirming = ref(false);

watch(
    () => props.busy,
    (busy, wasBusy) => {
        if (wasBusy && !busy) {
            confirming.value = false;
        }
    },
);

function confirm(): void {
    if (props.busy) {
        return;
    }

    if (mode.value === 'replace') {
        emit('replace');
    } else {
        emit('remove');
    }
}
</script>

<template>
    <section
        class="mt-3 space-y-2.5 rounded-xl border border-destructive/30 bg-destructive/5 p-3 text-xs"
        :aria-label="t('oeuvres.replacement.reasonHeading')"
        data-testid="rejected-callout"
    >
        <div class="flex items-start gap-2.5">
            <CircleX
                class="mt-0.5 size-4 shrink-0 text-destructive"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1 space-y-1.5">
                <p
                    class="font-semibold text-foreground"
                    data-testid="rejected-heading"
                >
                    {{ t('oeuvres.replacement.reasonHeading') }}
                </p>
                <div
                    class="rounded border-s-2 border-destructive/40 bg-background/70 px-2 py-1.5 text-foreground"
                >
                    <p
                        v-if="reason"
                        class="leading-relaxed whitespace-pre-line"
                        :class="long && !expanded ? 'line-clamp-4' : ''"
                        data-testid="rejected-reason"
                    >
                        <bdi dir="auto">{{ reason }}</bdi>
                    </p>
                    <button
                        v-if="long"
                        type="button"
                        class="mt-1 cursor-pointer rounded text-[11px] font-medium text-primary outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring/40"
                        :aria-expanded="expanded"
                        data-testid="rejected-reason-toggle"
                        @click="expanded = !expanded"
                    >
                        {{
                            expanded
                                ? t('oeuvres.replacement.showLess')
                                : t('oeuvres.replacement.showMore')
                        }}
                    </button>
                    <p
                        class="mt-1 text-[11px] text-muted-foreground"
                        data-testid="rejected-signature"
                    >
                        —
                        {{
                            reviewedAt
                                ? t('oeuvres.replacement.rejectedOn', {
                                      date: formatDate(reviewedAt, locale),
                                  })
                                : t('oeuvres.replacement.rejected')
                        }}
                    </p>
                </div>
                <p
                    class="text-muted-foreground"
                    data-testid="rejected-instruction"
                >
                    {{ t('oeuvres.replacement.instruction') }}
                </p>
            </div>
        </div>

        <div v-if="canRemove" class="flex justify-end">
            <Button
                size="sm"
                :variant="mode === 'replace' ? 'destructive' : 'outline'"
                class="h-8 cursor-pointer gap-1.5 text-xs font-semibold"
                :class="
                    mode === 'replace'
                        ? ''
                        : 'text-destructive hover:bg-destructive/10 hover:text-destructive'
                "
                :data-testid="
                    mode === 'replace' ? 'rejected-replace' : 'rejected-remove'
                "
                @click="confirming = true"
            >
                <RefreshCcw
                    v-if="mode === 'replace'"
                    class="size-3.5"
                    aria-hidden="true"
                />
                <Trash2 v-else class="size-3.5" aria-hidden="true" />
                {{
                    mode === 'replace'
                        ? t('oeuvres.replacement.removeAndReplace')
                        : t('oeuvres.replacement.remove')
                }}
            </Button>
        </div>

        <FileRemovalDialog
            :open="confirming"
            :mode="mode"
            :file-name="fileName"
            :reason="reason"
            :busy="busy"
            @update:open="(open: boolean) => (confirming = open)"
            @confirm="confirm"
        />
    </section>
</template>
