<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Lock, Send } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { AppDialog } from '@/components/ui/app-dialog';
import { Button } from '@/components/ui/button';
import { submit } from '@/routes/author/oeuvres';

/**
 * "Confirm all files are uploaded."
 *
 * Submission is the point of no return for the author: the deposit leaves
 * their hands and the server stops accepting files, removals and
 * deletions until an officer decides. So the dialog lists what is given
 * up, item by item, rather than asking a vague "are you sure?".
 *
 * It does not re-run the gate. The button that opens it is only offered
 * when the page's advisory check passes, and SubmissionGate re-decides on
 * POST — a refusal comes back as validation errors naming every blocker.
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

    router.post(
        submit(props.oeuvre.uuid).url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
                emit('close');
            },
        },
    );
};

const onOpenChange = (open: boolean) => {
    if (!open) {
        emit('close');
    }
};
</script>

<template>
    <AppDialog
        :open="oeuvre !== null"
        @update:open="onOpenChange"
        size="md"
        :busy="busy"
    >
        <template #icon
            ><div
                class="mb-1 flex size-11 items-center justify-center rounded-xl border border-onda-blue-500/20 bg-onda-blue-500/10 text-onda-blue-600 dark:text-onda-blue-400"
            >
                <Send class="size-5" /></div
        ></template>
        <template #title>{{ t('oeuvres.submit.title') }}</template>
        <template #description
            ><i18n-t keypath="oeuvres.submit.body" tag="span">
                <template #label>
                    <bdi class="font-semibold text-foreground">{{
                        oeuvre?.label
                    }}</bdi>
                </template>
            </i18n-t></template
        >
        <div class="grid gap-4">
            <!-- Exactly what the author gives up, enumerated. -->
            <div
                class="space-y-2.5 rounded-xl border border-border/80 bg-muted/40 p-4 text-xs leading-relaxed"
            >
                <p
                    class="flex items-center gap-2 font-semibold text-foreground"
                >
                    <Lock class="size-3.5" />
                    {{ t('oeuvres.submit.freezeTitle') }}
                </p>
                <ul
                    class="ms-1 space-y-1.5 border-s-2 border-border/70 ps-4 text-muted-foreground"
                >
                    <li>{{ t('oeuvres.submit.freezeNoFiles') }}</li>
                    <li>{{ t('oeuvres.submit.freezeNoRemove') }}</li>
                    <li>{{ t('oeuvres.submit.freezeNoDelete') }}</li>
                </ul>
                <p class="pt-1 text-muted-foreground">
                    {{ t('oeuvres.submit.freezeUntil') }}
                </p>
            </div>
        </div>
        <template #footer
            ><Button
                variant="outline"
                class="cursor-pointer rounded-xl"
                :disabled="busy"
                @click="emit('close')"
            >
                {{ t('oeuvres.submit.cancel') }}
            </Button>
            <Button
                class="cursor-pointer gap-2 rounded-xl bg-onda-blue-600 text-white hover:bg-onda-blue-700"
                :disabled="busy"
                @click="confirm"
            >
                <Send class="size-4" />
                {{ t('oeuvres.submit.confirm') }}
            </Button></template
        >
    </AppDialog>
</template>
