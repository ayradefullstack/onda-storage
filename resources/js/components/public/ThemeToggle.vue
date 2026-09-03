<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/composables/useAppearance';
import type { Appearance } from '@/composables/useAppearance';

const { t } = useI18n();
const { appearance, updateAppearance } = useAppearance();

const options: { value: Appearance; key: string; Icon: typeof Sun }[] = [
    { value: 'light', key: 'theme.light', Icon: Sun },
    { value: 'dark', key: 'theme.dark', Icon: Moon },
    { value: 'system', key: 'theme.system', Icon: Monitor },
];

const currentIcon = computed(
    () => options.find((o) => o.value === appearance.value)?.Icon ?? Monitor,
);
const currentLabel = computed(() =>
    t('theme.current', {
        theme: t(
            options.find((o) => o.value === appearance.value)?.key ??
                'theme.system',
        ),
    }),
);
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger
            class="inline-flex h-9 w-9 items-center justify-center rounded-md text-foreground hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :aria-label="currentLabel"
        >
            <component :is="currentIcon" class="size-4" />
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuItem
                v-for="option in options"
                :key="option.value"
                :aria-current="appearance === option.value ? 'true' : undefined"
                class="aria-[current=true]:font-semibold aria-[current=true]:text-seal"
                @click="updateAppearance(option.value)"
            >
                <component :is="option.Icon" class="size-4" />
                {{ t(option.key) }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
