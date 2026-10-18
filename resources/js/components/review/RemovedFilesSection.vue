<script setup lang="ts">
import { ChevronDown, FileX2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { formatDate } from '@/lib/format';

export interface RemovedFile {
    uuid: string;
    original_name: string;
    review_reason: string | null;
    removed_at: string | null;
    /** A replacement the author withdrew before it was decided. */
    withdrawn_replacement?: boolean;
    replaced_by: {
        uuid: string;
        original_name: string;
        removed: boolean;
    } | null;
}

/**
 * Evidence, read-only: the rejected files the author removed during the
 * review, why each was rejected, when it was removed, and what replaced it.
 * Collapsed by default; the files themselves are no longer consultable
 * (their bytes are released), only their record is.
 */
defineProps<{
    files: RemovedFile[];
    /** Link to a replacement's row on the page. */
    hrefFor: (uuid: string) => string;
}>();

const { t, locale } = useI18n();
</script>

<template>
    <Collapsible
        v-if="files.length > 0"
        class="rounded-md border border-border"
        data-testid="removed-files"
    >
        <CollapsibleTrigger
            class="group flex w-full cursor-pointer items-center justify-between gap-2 px-3 py-2 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
        >
            <span class="flex items-center gap-2">
                <FileX2
                    class="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                {{
                    t('review.replacement.removedTitle', {
                        count: files.length,
                    })
                }}
            </span>
            <ChevronDown
                class="size-4 text-muted-foreground transition-transform group-data-[state=open]:rotate-180"
                aria-hidden="true"
            />
        </CollapsibleTrigger>
        <CollapsibleContent>
            <ul class="divide-y divide-border/60 border-t border-border">
                <li
                    v-for="file in files"
                    :key="file.uuid"
                    class="space-y-1 px-3 py-2 text-xs"
                    data-testid="removed-file"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <bdi
                            dir="ltr"
                            class="font-medium text-foreground line-through decoration-muted-foreground/60"
                            >{{ file.original_name }}</bdi
                        >
                        <span
                            v-if="file.withdrawn_replacement"
                            class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
                            data-testid="withdrawn-replacement"
                            >{{ t('review.replacement.withdrawn') }}</span
                        >
                        <span
                            v-if="file.removed_at"
                            class="text-muted-foreground"
                        >
                            {{
                                t('review.replacement.removedOn', {
                                    date: formatDate(file.removed_at, locale),
                                })
                            }}
                        </span>
                    </div>
                    <p
                        v-if="file.review_reason"
                        class="rounded border-s-2 border-destructive/40 bg-muted/60 px-2 py-1 whitespace-pre-line text-foreground"
                    >
                        <bdi dir="auto">{{ file.review_reason }}</bdi>
                    </p>
                    <p class="text-muted-foreground">
                        <template v-if="file.replaced_by">
                            {{ t('review.replacement.replacedBy') }}
                            <a
                                v-if="!file.replaced_by.removed"
                                :href="hrefFor(file.replaced_by.uuid)"
                                class="font-medium text-primary hover:underline"
                                data-testid="replaced-by-link"
                                ><bdi dir="ltr">{{
                                    file.replaced_by.original_name
                                }}</bdi></a
                            >
                            <template v-else>
                                <bdi dir="ltr" class="font-medium">{{
                                    file.replaced_by.original_name
                                }}</bdi>
                                {{ t('review.replacement.replacementRemoved') }}
                            </template>
                        </template>
                        <template v-else>{{
                            t('review.replacement.notReplaced')
                        }}</template>
                    </p>
                </li>
            </ul>
        </CollapsibleContent>
    </Collapsible>
</template>
