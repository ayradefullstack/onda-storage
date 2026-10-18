<script setup lang="ts">
import { ArrowDown, CircleCheck, CircleX, RefreshCcw } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';

/**
 * "À corriger": what the author must fix on a deposit under review — every
 * rejected file still on it, and every slot whose rejected file was removed
 * and still waits for its replacement — each with "Aller au fichier", plus
 * "Fichier suivant à corriger" that cycles through them.
 *
 * Announced once (`role="alert"` on first render only): a silent partial
 * reload must not read it out again.
 *
 * With nothing to fix, it turns into a calm confirmation — only when
 * `allSent` (nothing rejected, nothing still uploading, at least one
 * replacement waiting for the ONDA): never a fake "done" mid-upload.
 */
export interface ToFixItem {
    /** The DOM id to jump to (a file row or a slot's dropzone). */
    target: string;
    name: string;
    slot: string | null;
    /** `file`: a rejected file; `slot`: a replacement still expected. */
    kind: 'file' | 'slot';
}

const props = defineProps<{
    items: ToFixItem[];
    allSent: boolean;
    lastUploadAt: string | null;
}>();

const emit = defineEmits<{ go: [target: string] }>();

const { t, locale } = useI18n();

const announce = ref(true);
const cursor = ref(0);

onMounted(() => {
    setTimeout(() => (announce.value = false), 1500);
});

function next(): void {
    if (props.items.length === 0) {
        return;
    }

    const item = props.items[cursor.value % props.items.length]!;
    cursor.value = (cursor.value + 1) % props.items.length;
    emit('go', item.target);
}
</script>

<template>
    <section
        v-if="items.length > 0"
        class="space-y-3 rounded-xl border border-destructive/30 bg-destructive/5 p-4"
        :role="announce ? 'alert' : undefined"
        data-testid="to-fix-panel"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2
                class="flex items-center gap-2 text-sm font-semibold text-foreground"
            >
                <CircleX class="size-4 text-destructive" aria-hidden="true" />
                {{
                    t(
                        'oeuvres.review.toFixTitle',
                        { count: items.length },
                        items.length,
                    )
                }}
            </h2>
            <Button
                v-if="items.length > 1"
                size="sm"
                variant="outline"
                class="h-8 cursor-pointer gap-1.5 text-xs"
                data-testid="to-fix-next"
                @click="next"
            >
                <ArrowDown class="size-3.5" />
                {{ t('oeuvres.review.nextToFix') }}
            </Button>
        </div>
        <ul class="divide-y divide-destructive/15 text-sm">
            <li
                v-for="item in items"
                :key="item.target"
                class="flex flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0"
                data-testid="to-fix-item"
            >
                <div class="min-w-0">
                    <p class="truncate font-medium text-foreground">
                        <component
                            :is="item.kind === 'file' ? CircleX : RefreshCcw"
                            class="me-1 inline size-3.5 text-destructive"
                            aria-hidden="true"
                        />
                        <bdi>{{
                            item.kind === 'file'
                                ? item.name
                                : t('oeuvres.replacement.expected')
                        }}</bdi>
                    </p>
                    <p
                        v-if="item.slot"
                        class="truncate text-xs text-muted-foreground"
                    >
                        <bdi>{{ item.slot }}</bdi>
                    </p>
                </div>
                <button
                    type="button"
                    class="shrink-0 cursor-pointer rounded text-xs font-medium text-primary outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring/40"
                    data-testid="to-fix-go"
                    @click="emit('go', item.target)"
                >
                    {{ t('oeuvres.review.goToFile') }}
                </button>
            </li>
        </ul>
    </section>

    <section
        v-else-if="allSent"
        class="flex items-start gap-3 rounded-xl border border-border/80 bg-muted/30 p-4 text-sm"
        role="status"
        data-testid="all-sent"
    >
        <CircleCheck
            class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
            aria-hidden="true"
        />
        <div class="min-w-0 space-y-0.5">
            <p class="font-medium text-foreground">
                {{ t('oeuvres.review.allSent') }}
            </p>
            <p v-if="lastUploadAt" class="text-xs text-muted-foreground">
                {{
                    t('oeuvres.review.lastUpload', {
                        date: formatDate(lastUploadAt, locale),
                    })
                }}
            </p>
        </div>
    </section>
</template>
