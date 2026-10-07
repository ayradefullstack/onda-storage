<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * A server validation message. The referentiel requests answer with an
 * `admin.referentiel.errors.*` i18n key for every rule that is ours (name
 * taken, code shape, system row…), so it renders in the admin's language;
 * anything else is Laravel's own text and is shown as it came.
 */
const props = defineProps<{ error?: string }>();

const { t, te } = useI18n();

const text = computed(() =>
    props.error && te(props.error) ? t(props.error) : props.error,
);
</script>

<template>
    <p v-if="error" class="text-xs text-destructive" role="alert">
        {{ text }}
    </p>
</template>
