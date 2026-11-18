<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    insertAtCursor,
    REASON_MAX,
    REASON_MIN,
    reasonLength,
    reasonState,
} from './decisions';
import type { ReasonChip } from './decisions';

/**
 * The rejection reason. It reaches the author word for word and is the
 * only thing telling them what to fix, so the field shows the count as it
 * is typed and says, inline, why the server would refuse it (20 to 2000
 * characters, trimmed — counted the way the server counts).
 *
 * The quick reasons are starting points, inserted at the cursor: an
 * officer picks one and then says which document, which file.
 *
 * The text is the parent's (`v-model`), so leaving this screen and coming
 * back — "Retour" — loses nothing.
 */
const reason = defineModel<string>({ required: true });

const props = defineProps<{
    disabled?: boolean;
    /** A refusal of this field by the server, shown in place of the help. */
    serverError?: string | null;
    /** Replaces the deposit-level quick reasons (the file reject dialog). */
    chips?: ReasonChip[];
    /** Added after them ("Inclure les fichiers rejetés"). */
    extraChips?: ReasonChip[];
}>();

const CHIPS = [
    'missingDocument',
    'unreadableFile',
    'wrongInformation',
    'wrongFormat',
] as const;

/** The field grows with its text up to here, then scrolls. */
const MAX_HEIGHT = 240;

const { t } = useI18n();
const touched = ref(false);
const field = ref<HTMLTextAreaElement | null>(null);

const length = computed(() => reasonLength(reason.value));
const state = computed(() => reasonState(reason.value));

const error = computed<string | null>(() => {
    if (state.value === 'long') {
        const over = length.value - REASON_MAX;

        return t('review.reject.tooLong', { count: over }, over);
    }

    if (state.value === 'short' && touched.value) {
        const missing = REASON_MIN - length.value;

        return t('review.reject.tooShort', { count: missing }, missing);
    }

    return props.serverError ?? null;
});

function grow(): void {
    const element = field.value;

    if (element === null) {
        return;
    }

    element.style.height = 'auto';
    element.style.height = `${Math.min(element.scrollHeight, MAX_HEIGHT)}px`;
    element.style.overflowY =
        element.scrollHeight > MAX_HEIGHT ? 'auto' : 'hidden';
}

watch(reason, () => void nextTick(grow));

onMounted(() => {
    grow();
    field.value?.focus();
});

const chipList = computed<ReasonChip[]>(() => [
    ...(props.chips ??
        CHIPS.map((id) => {
            const label = t(`review.reject.chips.${id}`);

            return { id, label, text: label };
        })),
    ...(props.extraChips ?? []),
]);

function insert(chip: ReasonChip): void {
    const element = field.value;
    const start = element?.selectionStart ?? reason.value.length;
    const end = element?.selectionEnd ?? reason.value.length;
    const result = insertAtCursor(reason.value, chip.text, start, end);

    reason.value = result.text;

    void nextTick(() => {
        element?.focus();
        element?.setSelectionRange(result.cursor, result.cursor);
    });
}

/** The parent's submit: show the minimum error even if never blurred. */
function touch(): void {
    touched.value = true;
}

defineExpose({ touch });
</script>

<template>
    <div class="space-y-3" data-testid="reject-screen">
        <div class="space-y-1.5">
            <label
                for="reject-reason"
                class="text-sm font-medium text-foreground"
            >
                {{ t('review.reject.reasonLabel') }}
            </label>
            <textarea
                id="reject-reason"
                ref="field"
                v-model="reason"
                rows="4"
                dir="auto"
                data-testid="reject-reason"
                class="block w-full resize-none rounded-lg border bg-background px-3 py-2 text-sm leading-relaxed outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/40 aria-invalid:border-destructive aria-invalid:ring-destructive/20"
                :style="{ maxHeight: `${MAX_HEIGHT}px` }"
                :placeholder="t('review.reject.placeholder')"
                :aria-invalid="error !== null"
                aria-describedby="reject-reason-help reject-reason-error"
                :disabled="disabled"
                @blur="touched = true"
            />
            <div
                class="flex items-start justify-between gap-3 text-xs text-muted-foreground"
            >
                <div class="min-w-0">
                    <p
                        v-if="error"
                        id="reject-reason-error"
                        role="alert"
                        class="text-destructive"
                        data-testid="reject-error"
                    >
                        {{ error }}
                    </p>
                    <p v-else id="reject-reason-help">
                        {{
                            t('review.reject.bounds', {
                                min: REASON_MIN,
                                max: REASON_MAX,
                            })
                        }}
                    </p>
                </div>
                <p
                    class="shrink-0 tabular-nums"
                    :class="
                        state === 'long' ? 'font-medium text-destructive' : ''
                    "
                    aria-live="polite"
                    data-testid="reject-counter"
                >
                    <bdi dir="ltr">{{ length }} / {{ REASON_MAX }}</bdi>
                </p>
            </div>
        </div>

        <div class="space-y-1.5">
            <p class="text-xs font-medium text-muted-foreground">
                {{ t('review.reject.chipsLabel') }}
            </p>
            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="chip in chipList"
                    :key="chip.id"
                    type="button"
                    :data-chip="chip.id"
                    :disabled="disabled"
                    class="cursor-pointer rounded-full border bg-background px-2.5 py-1 text-xs transition-colors outline-none hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring/40 disabled:pointer-events-none disabled:opacity-50"
                    @click="insert(chip)"
                >
                    {{ chip.label }}
                </button>
            </div>
        </div>
    </div>
</template>
