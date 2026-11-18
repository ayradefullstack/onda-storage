<script setup lang="ts">
import { CircleAlert, CircleCheck, Info, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { splitBidi } from '@/lib/authInput';

export type MessageKind = 'error' | 'warn' | 'ok' | 'info';

const props = defineProps<{
    message: string;
    kind: MessageKind;
}>();

const icon = computed(
    () =>
        ({
            error: CircleAlert,
            warn: TriangleAlert,
            ok: CircleCheck,
            info: Info,
        })[props.kind],
);

// `[[Caps Lock]]` → <bdi>; never v-html, so a server message can't inject markup.
const parts = computed(() => splitBidi(props.message));
</script>

<template>
    <div class="auth-msg-body">
        <component :is="icon" aria-hidden="true" />
        <span>
            <template v-for="(part, i) in parts" :key="i">
                <bdi v-if="part.latin" dir="ltr">{{ part.text }}</bdi>
                <template v-else>{{ part.text }}</template>
            </template>
        </span>
    </div>
</template>
