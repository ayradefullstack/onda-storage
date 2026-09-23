<script setup lang="ts">
import { LockIcon } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

/**
 * A field an admin can see but never change, with the reason attached.
 *
 * Rendered rather than hidden on purpose: `code_college` and
 * `document_key` are the join keys the seeders resolve against and the
 * values frozen into `oeuvres.code_college_snapshot` and
 * `media_files.document_key_snapshot`. An admin needs to read them — and
 * needs to understand why the input is not there, rather than assume the
 * form is broken.
 */
defineProps<{
    label: string;
    value: string | number | null;
    /** i18n key under `admin.referentiel.locked.*` explaining the freeze. */
    reason: string;
}>();

const { t } = useI18n();
</script>

<template>
    <div class="space-y-1">
        <p
            class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"
        >
            <LockIcon class="size-3" />
            {{ label }}
        </p>
        <p
            class="rounded-md border border-border/80 bg-muted/50 px-2.5 py-1.5 font-mono text-xs text-foreground"
        >
            <bdi>{{ value ?? '—' }}</bdi>
        </p>
        <p class="text-[11px] leading-relaxed text-muted-foreground">
            {{ t(`admin.referentiel.locked.${reason}`) }}
        </p>
    </div>
</template>
