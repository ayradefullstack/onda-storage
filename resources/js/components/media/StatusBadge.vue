<script setup lang="ts">
import {
    CheckCircle2Icon,
    ClockIcon,
    Loader2Icon,
    ScanEyeIcon,
    ShieldAlertIcon,
    XCircleIcon,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
import type { MediaFileStatus } from '@/types/upload';

const props = defineProps<{
    status: MediaFileStatus;
}>();

const { t } = useI18n();

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

const presentation: Record<
    MediaFileStatus,
    { variant: BadgeVariant; icon: typeof CheckCircle2Icon; spin: boolean }
> = {
    uploading: { variant: 'outline', icon: ClockIcon, spin: false },
    assembling: { variant: 'outline', icon: Loader2Icon, spin: true },
    scanning: { variant: 'secondary', icon: ScanEyeIcon, spin: false },
    processing: { variant: 'secondary', icon: Loader2Icon, spin: true },
    ready: { variant: 'default', icon: CheckCircle2Icon, spin: false },
    failed: { variant: 'destructive', icon: XCircleIcon, spin: false },
    quarantined: { variant: 'destructive', icon: ShieldAlertIcon, spin: false },
};

const current = computed(() => presentation[props.status]);
</script>

<template>
    <Badge :variant="current.variant" class="gap-1.5">
        <component :is="current.icon" :class="current.spin && 'animate-spin'" />
        {{ t(`media.status.${status}`) }}
    </Badge>
</template>
