<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowRight, CircleCheck, FileQuestion } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FileReviewLayout from '@/components/consult/FileReviewLayout.vue';
import FileDecisionActions from '@/components/review/FileDecisionActions.vue';
import { nextToReview, REVIEW_STATUS } from '@/components/review/fileReview';
import type { FileAllowed } from '@/components/review/fileReview';
import ReviewStatusBadge from '@/components/review/ReviewStatusBadge.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { silent } from '@/composables/useGlobalLoader';
import { formatBytes } from '@/lib/format';
import { dashboard } from '@/routes/admin';
import {
    index as oeuvresIndex,
    show as oeuvreShow,
} from '@/routes/admin/oeuvres';
import { review } from '@/routes/admin/oeuvres/files';
import type { ConsultationDescriptor } from '@/types/consultation';

const props = defineProps<{
    oeuvre: {
        uuid: string;
        title: string | null;
        created_at: string;
        status: string;
    };
    file: {
        uuid: string;
        original_name: string;
        extension: string;
        status: string;
        size_bytes: number;
        mime: string;
        sha256_plain: string | null;
        review_status: string;
        review_reason: string | null;
        allowed: FileAllowed;
    };
    siblings: {
        uuid: string;
        original_name: string;
        slot: string | null;
        review_status: string;
    }[];
    consultation: ConsultationDescriptor;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard() },
            { title: 'Works', href: oeuvresIndex() },
        ],
    },
});

const { t, locale } = useI18n();

// A thin wrapper: the page body is shared with the author's own preview.
const fileHref = (uuid: string): string =>
    review({ oeuvre: props.oeuvre.uuid, mediaFile: uuid }).url;

/** The next sibling still `pending`, skipping decided files. */
const nextPending = computed(() =>
    nextToReview(props.siblings, props.file.uuid),
);

function goNext(): void {
    if (nextPending.value !== null) {
        router.visit(fileHref(nextPending.value.uuid));
    }
}

// After a decision: offer the next file to review, inline.
const justDecided = ref(false);

watch(
    () => props.file.uuid,
    () => (justDecided.value = false),
);

function onDecided(): void {
    justDecided.value = true;
    router.reload(silent({ only: ['file', 'siblings'] }));
}

function onStale(): void {
    router.reload(silent({ only: ['file', 'siblings', 'oeuvre'] }));
}

// No preview for this format: the decision must not depend on one.
const unsupported = computed(
    () =>
        props.consultation.status === 'unsupported' ||
        props.consultation.mode === 'unsupported',
);
</script>

<template>
    <Head :title="file.original_name" />

    <FileReviewLayout
        :oeuvre="oeuvre"
        :file="file"
        :siblings="siblings"
        :consultation="consultation"
        :back-href="oeuvreShow(oeuvre.uuid).url"
        :file-href="fileHref"
    >
        <template #header-actions>
            <ReviewStatusBadge :status="file.review_status" />
            <FileDecisionActions
                :key="file.uuid"
                :oeuvre="oeuvre.uuid"
                :file="file"
                :allowed="file.allowed"
                size="sm"
                @decided="onDecided"
                @stale="onStale"
            />
            <Button
                type="button"
                size="sm"
                variant="ghost"
                :disabled="nextPending === null"
                data-testid="next-to-review"
                @click="goNext"
            >
                {{ t('review.file.nextToReview') }}
                <ArrowRight class="size-4 rtl:rotate-180" />
            </Button>
        </template>

        <template #before-viewer>
            <p
                v-if="
                    file.review_status === REVIEW_STATUS.REJECTED &&
                    file.review_reason
                "
                class="rounded border-s-2 border-destructive/40 bg-muted/60 px-2 py-1 text-xs whitespace-pre-line"
                data-testid="file-reject-reason"
            >
                <span class="font-medium"
                    >{{ t('review.file.rejectedReason') }} :
                </span>
                <bdi dir="auto">{{ file.review_reason }}</bdi>
            </p>

            <div
                v-if="justDecided"
                role="status"
                class="motion-fade-up flex flex-wrap items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2 text-sm"
                data-testid="next-prompt"
            >
                <CircleCheck
                    class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                />
                <template v-if="nextPending">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        data-testid="next-prompt-go"
                        @click="goNext"
                    >
                        {{ t('review.file.nextPrompt') }}
                        <ArrowRight class="size-4 rtl:rotate-180" />
                    </Button>
                </template>
                <span v-else>{{ t('review.file.allDecided') }}</span>
            </div>

            <Alert v-if="unsupported" data-testid="unsupported-alert">
                <FileQuestion />
                <AlertTitle>{{
                    t('review.file.unsupported.title')
                }}</AlertTitle>
                <AlertDescription>
                    <p>{{ t('review.file.unsupported.body') }}</p>
                    <dl
                        class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs"
                    >
                        <dt class="text-muted-foreground">
                            {{ t('consult.meta.name') }}
                        </dt>
                        <dd class="min-w-0 break-all">
                            <bdi dir="ltr">{{ file.original_name }}</bdi>
                        </dd>
                        <dt class="text-muted-foreground">
                            {{ t('consult.meta.size') }}
                        </dt>
                        <dd>
                            <bdi dir="ltr">{{
                                formatBytes(file.size_bytes, locale)
                            }}</bdi>
                        </dd>
                        <dt class="text-muted-foreground">
                            {{ t('review.file.meta.type') }}
                        </dt>
                        <dd>
                            <bdi dir="ltr"
                                >{{ file.extension.toUpperCase() }} ·
                                {{ file.mime }}</bdi
                            >
                        </dd>
                        <template v-if="file.sha256_plain">
                            <dt class="text-muted-foreground">SHA-256</dt>
                            <dd class="min-w-0">
                                <code
                                    dir="ltr"
                                    class="font-mono text-[11px] break-all"
                                    >{{ file.sha256_plain }}</code
                                >
                            </dd>
                        </template>
                    </dl>
                </AlertDescription>
            </Alert>
        </template>
    </FileReviewLayout>
</template>
