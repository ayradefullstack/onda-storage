<script setup lang="ts">
import { Check, Copy, Play } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    formatBytes,
    formatDate,
    formatDuration,
    truncateFilenameMiddle,
} from '@/lib/format';
import { variant as mediaVariant } from '@/routes/admin/media';
import StatusBadge from './StatusBadge.vue';

interface MediaFileDetail {
    uuid: string;
    original_name: string;
    extension: string;
    mime: string;
    size_bytes: number;
    status: string;
    duration_sec: number | null;
    width: number | null;
    height: number | null;
    sha256_plain: string | null;
    scan_result: 'clean' | 'infected' | 'pending' | 'unknown';
    scanned_at: string | null;
    verified_at: string | null;
    created_at: string;
    variants: string[];
}

const props = defineProps<{
    file: MediaFileDetail;
}>();

const emit = defineEmits<{
    'stream-original': [];
}>();

const { t, locale } = useI18n();

const category = computed<'video' | 'audio' | 'pdf' | 'other'>(() => {
    if (props.file.mime.startsWith('video/')) {
        return 'video';
    }

    if (props.file.mime.startsWith('audio/')) {
        return 'audio';
    }

    if (props.file.mime === 'application/pdf') {
        return 'pdf';
    }

    return 'other';
});

const hasPoster = computed(() => props.file.variants.includes('poster'));
const hasPreviewClip = computed(() => props.file.variants.includes('preview'));
const hasWaveform = computed(() => props.file.variants.includes('waveform'));

function variantUrl(kind: string): string {
    return mediaVariant({ mediaFile: props.file.uuid, kind }).url;
}

const showPreviewClip = ref(false);

const SCAN_VARIANTS: Record<string, BadgeVariants['variant']> = {
    clean: 'default',
    infected: 'destructive',
    pending: 'outline',
    unknown: 'secondary',
};

const copied = ref(false);

async function copyHash(): Promise<void> {
    if (!props.file.sha256_plain) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.file.sha256_plain);
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        // Clipboard API unavailable — the hash is still fully visible above.
    }
}
</script>

<template>
    <div class="rounded-lg border border-border p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="font-medium" :title="file.original_name">
                    <bdi>{{
                        truncateFilenameMiddle(file.original_name, 60)
                    }}</bdi>
                </p>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    <bdi dir="ltr"
                        >{{ file.mime }} ·
                        {{ formatBytes(file.size_bytes, locale) }}</bdi
                    >
                    <template v-if="file.duration_sec !== null">
                        ·
                        <bdi dir="ltr">{{
                            formatDuration(file.duration_sec)
                        }}</bdi>
                    </template>
                    <template v-if="file.width && file.height">
                        ·
                        <bdi dir="ltr">{{ file.width }}×{{ file.height }}</bdi>
                    </template>
                </p>
            </div>
            <StatusBadge kind="media" :status="file.status" />
        </div>

        <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="space-y-2">
                <div v-if="category === 'video' && hasPoster">
                    <img
                        v-if="!showPreviewClip"
                        :src="variantUrl('poster')"
                        :alt="file.original_name"
                        class="aspect-video w-full cursor-pointer rounded-md border border-border object-cover"
                        @click="hasPreviewClip && (showPreviewClip = true)"
                    />
                    <video
                        v-else
                        :src="variantUrl('preview')"
                        controls
                        autoplay
                        class="aspect-video w-full rounded-md border border-border"
                    />
                    <Button
                        v-if="hasPreviewClip && !showPreviewClip"
                        variant="outline"
                        size="sm"
                        class="mt-2"
                        @click="showPreviewClip = true"
                    >
                        <Play class="size-3.5" />
                        {{ t('admin.works.playPreview') }}
                    </Button>
                    <p class="text-xs text-muted-foreground">
                        {{ t('admin.works.watermarkNotice') }}
                    </p>
                </div>

                <div
                    v-else-if="category === 'audio' && hasWaveform"
                    class="space-y-2"
                >
                    <img
                        :src="variantUrl('waveform')"
                        :alt="file.original_name"
                        class="w-full rounded-md border border-border"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ t('admin.works.watermarkNotice') }}
                    </p>
                </div>

                <p
                    v-else
                    class="rounded-md border border-dashed border-border p-4 text-xs text-muted-foreground"
                >
                    {{ t('admin.works.noPreviewAvailable') }}
                </p>

                <Button
                    variant="outline"
                    size="sm"
                    @click="emit('stream-original')"
                >
                    {{ t('admin.stream.trigger') }}
                </Button>
            </div>

            <div class="space-y-3 text-sm">
                <div>
                    <p
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        {{ t('admin.works.fingerprint') }}
                    </p>
                    <div class="mt-1 flex items-start gap-2">
                        <code
                            class="grow font-mono text-xs break-all"
                            dir="ltr"
                        >
                            {{ file.sha256_plain ?? '—' }}
                        </code>
                        <Button
                            v-if="file.sha256_plain"
                            variant="ghost"
                            size="icon"
                            class="size-6 shrink-0"
                            :title="t('admin.works.copyFingerprint')"
                            @click="copyHash"
                        >
                            <Check v-if="copied" class="size-3.5" />
                            <Copy v-else class="size-3.5" />
                        </Button>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <p
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            {{ t('admin.works.scanResult') }}
                        </p>
                        <Badge
                            :variant="SCAN_VARIANTS[file.scan_result]"
                            class="mt-1"
                        >
                            {{ t(`admin.scanResult.${file.scan_result}`) }}
                        </Badge>
                    </div>
                    <div>
                        <p
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            {{ t('admin.works.macVerified') }}
                        </p>
                        <p class="mt-1 text-xs">
                            <bdi dir="ltr">{{
                                file.verified_at
                                    ? formatDate(file.verified_at, locale)
                                    : t('admin.works.macCheckedOnRead')
                            }}</bdi>
                        </p>
                    </div>
                </div>

                <div>
                    <p
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        {{ t('admin.works.depositedOn') }}
                    </p>
                    <p class="mt-1 text-xs">
                        <bdi dir="ltr">{{
                            formatDate(file.created_at, locale)
                        }}</bdi>
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
