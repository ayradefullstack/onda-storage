<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { useAppearance } from '@/composables/useAppearance';

const { t } = useI18n();

const { appearance, updateAppearance } = useAppearance();

const tabs = [
    { value: 'light', Icon: Sun, label: 'theme.light' },
    { value: 'dark', Icon: Moon, label: 'theme.dark' },
    { value: 'system', Icon: Monitor, label: 'theme.system' },
] as const;
</script>

<template>
    <div
        class="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800"
    >
        <button
            v-for="{ value, Icon, label } in tabs"
            :key="value"
            @click="updateAppearance(value)"
            :class="[
                'flex items-center rounded-md px-3.5 py-1.5 transition-colors',
                appearance === value
                    ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                    : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
            ]"
        >
            <component :is="Icon" class="-ms-1 h-4 w-4" />
            <span class="ms-1.5 text-sm">{{ t(label) }}</span>
        </button>
    </div>
</template>
