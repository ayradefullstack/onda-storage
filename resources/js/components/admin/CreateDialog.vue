<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { AppDialog } from '@/components/ui/app-dialog';
import { Button } from '@/components/ui/button';

/**
 * The frame of every referentiel "create" form: title, the fields (slot) and
 * Create / Cancel. Each tab owns its own fields and its own request — this
 * only keeps the dialog behaviour identical across the five.
 */
defineProps<{
    open: boolean;
    title: string;
    description?: string;
    saving: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'submit'): void;
}>();

const { t } = useI18n();
</script>

<template>
    <AppDialog
        :open="open"
        :title="title"
        :description="description"
        :busy="saving"
        size="lg"
        form
        @update:open="(o) => emit('update:open', o)"
        @submit="emit('submit')"
    >
        <div class="space-y-4">
            <slot />
        </div>

        <template #footer>
            <Button
                type="button"
                variant="outline"
                class="cursor-pointer"
                :disabled="saving"
                @click="emit('update:open', false)"
                >{{ t('admin.referentiel.cancel') }}</Button
            >
            <Button type="submit" class="cursor-pointer" :disabled="saving">{{
                t('admin.referentiel.createSubmit')
            }}</Button>
        </template>
    </AppDialog>
</template>
