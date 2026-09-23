<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AlertTriangle, HardDrive, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
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
import { destroy } from '@/routes/oeuvres';

/**
 * Deleting a whole deposit.
 *
 * The dialog exists to say two things that genuinely surprise people, and
 * it says them before the button, not after:
 *
 *  1. The encrypted bytes stay on disk. Delete sets `deleted_at`; the
 *     purge job removes the ciphertext after the retention window.
 *  2. Storage quota does not recover today. Quota counts every file not
 *     yet purged, so deleting a 5 GB draft to make room frees nothing
 *     right now.
 *
 * An author who deletes a large draft, sees the quota unchanged and was
 * not warned will call support. That is the whole reason this copy is
 * three lines instead of "Are you sure?".
 */
const props = defineProps<{
    oeuvre: { uuid: string; label: string } | null;
}>();

const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();
const busy = ref(false);

const confirm = () => {
    if (props.oeuvre === null) {
        return;
    }

    busy.value = true;

    router.delete(destroy(props.oeuvre.uuid).url, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            emit('close');
        },
    });
};

const onOpenChange = (open: boolean) => {
    if (!open) {
        emit('close');
    }
};
</script>

<template>
    <Dialog :open="oeuvre !== null" @update:open="onOpenChange">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <div
                    class="mb-1 flex size-11 items-center justify-center rounded-xl border border-rose-500/20 bg-rose-500/10 text-rose-600 dark:text-rose-400"
                >
                    <Trash2 class="size-5" />
                </div>
                <DialogTitle>{{ t('oeuvres.delete.title') }}</DialogTitle>
                <DialogDescription>
                    <i18n-t keypath="oeuvres.delete.body" tag="span">
                        <template #label>
                            <bdi class="font-semibold text-foreground">{{
                                oeuvre?.label
                            }}</bdi>
                        </template>
                    </i18n-t>
                </DialogDescription>
            </DialogHeader>

            <!-- The two surprises, stated plainly. -->
            <div
                class="space-y-3 rounded-xl border border-amber-500/25 bg-amber-500/5 p-4 text-xs leading-relaxed"
            >
                <div class="flex gap-2.5">
                    <HardDrive
                        class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400"
                    />
                    <p class="text-muted-foreground">
                        <span class="font-semibold text-foreground">{{
                            t('oeuvres.delete.bytesTitle')
                        }}</span>
                        {{ ' ' }}{{ t('oeuvres.delete.bytesBody') }}
                    </p>
                </div>
                <div class="flex gap-2.5">
                    <AlertTriangle
                        class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400"
                    />
                    <p class="text-muted-foreground">
                        <span class="font-semibold text-foreground">{{
                            t('oeuvres.delete.quotaTitle')
                        }}</span>
                        {{ ' ' }}{{ t('oeuvres.delete.quotaBody') }}
                    </p>
                </div>
            </div>

            <DialogFooter class="gap-2 sm:gap-2">
                <Button
                    variant="outline"
                    class="cursor-pointer rounded-xl"
                    :disabled="busy"
                    @click="emit('close')"
                >
                    {{ t('oeuvres.delete.cancel') }}
                </Button>
                <Button
                    variant="destructive"
                    class="cursor-pointer gap-2 rounded-xl"
                    :disabled="busy"
                    @click="confirm"
                >
                    <Trash2 class="size-4" />
                    {{ t('oeuvres.delete.confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
