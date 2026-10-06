<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

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
    <Dialog :open="open" @update:open="(o) => emit('update:open', o)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription v-if="description">{{
                    description
                }}</DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="emit('submit')">
                <slot />

                <DialogFooter class="gap-2 sm:gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        class="cursor-pointer"
                        :disabled="saving"
                        @click="emit('update:open', false)"
                        >{{ t('admin.referentiel.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        class="cursor-pointer"
                        :disabled="saving"
                        >{{ t('admin.referentiel.createSubmit') }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
