<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { splitBidi } from '@/lib/authInput';

/**
 * The page-level error region. The wrapper is ALWAYS in the DOM with
 * role="alert", so a screen reader announces its content the moment it
 * fills (a role="alert" element inserted already-populated is often missed).
 *
 * - `message`: one blocking message (Login: Fortify's `auth.failed`).
 * - `items`: field errors (Register) as links that focus the field; the first
 *   `max` are listed, the rest collapse into "and N more".
 */
const props = withDefaults(
    defineProps<{
        message?: string;
        items?: { id: string; label: string; message: string }[];
        max?: number;
    }>(),
    { message: '', items: () => [], max: 4 },
);

const { t } = useI18n();

const shown = computed(() => props.items.slice(0, props.max));
const hidden = computed(() => props.items.length - shown.value.length);

function focusField(event: MouseEvent, id: string): void {
    event.preventDefault();

    const el = document.getElementById(id);

    el?.scrollIntoView({ block: 'center' });
    el?.focus({ preventScroll: true });
}
</script>

<template>
    <div role="alert" class="empty:hidden">
        <div v-if="items.length" class="auth-alert">
            <CircleAlert aria-hidden="true" />
            <div>
                <p class="font-semibold">
                    {{ t('authUi.summary', { n: items.length }) }}
                </p>
                <ul>
                    <li v-for="item in shown" :key="item.id">
                        <a
                            :href="`#${item.id}`"
                            @click="focusField($event, item.id)"
                        >
                            {{ item.label }}
                        </a>
                        —
                        <template
                            v-for="(part, i) in splitBidi(item.message)"
                            :key="i"
                        >
                            <bdi v-if="part.latin" dir="ltr">{{
                                part.text
                            }}</bdi>
                            <template v-else>{{ part.text }}</template>
                        </template>
                    </li>
                    <li v-if="hidden > 0">
                        {{ t('authUi.more', { n: hidden }) }}
                    </li>
                </ul>
            </div>
        </div>
        <div v-else-if="message" class="auth-alert">
            <CircleAlert aria-hidden="true" />
            <p>{{ message }}</p>
        </div>
    </div>
</template>
