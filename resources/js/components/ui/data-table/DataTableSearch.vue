<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Loader2, Search, X } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { cn } from '@/lib/utils';

interface Props {
    modelValue: string;
    placeholder?: string;
    loading?: boolean;
    debounce?: number;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    placeholder: 'Rechercher...',
    loading: false,
    debounce: 300,
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'clear'): void;
    (e: 'submit'): void;
}>();

const { t } = useI18n();

const internalValue = ref(props.modelValue ?? '');

watch(
    () => props.modelValue,
    (val) => {
        if (val !== internalValue.value) {
            internalValue.value = val ?? '';
        }
    },
);

let timer: ReturnType<typeof setTimeout> | undefined;

const handleInput = (e: Event) => {
    const val = (e.target as HTMLInputElement).value;
    internalValue.value = val;

    clearTimeout(timer);
    if (props.debounce > 0) {
        timer = setTimeout(() => {
            emit('update:modelValue', val);
        }, props.debounce);
    } else {
        emit('update:modelValue', val);
    }
};

const handleClear = () => {
    internalValue.value = '';
    clearTimeout(timer);
    emit('update:modelValue', '');
    emit('clear');
};

const handleKeyDown = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        handleClear();
    } else if (e.key === 'Enter') {
        clearTimeout(timer);
        emit('update:modelValue', internalValue.value);
        emit('submit');
    }
};

const hasValue = computed(() => internalValue.value.length > 0);
</script>

<template>
    <div :class="cn('relative w-full max-w-xs sm:max-w-sm', $props.class)">
        <!-- Leading Search Icon / Loading Spinner -->
        <span
            class="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-muted-foreground/70"
            aria-hidden="true"
        >
            <Loader2
                v-if="loading"
                :stroke-width="1.75"
                class="size-4 animate-spin text-primary"
            />
            <Search
                v-else
                :stroke-width="1.75"
                class="size-4"
            />
        </span>

        <!-- Input Field -->
        <input
            :value="internalValue"
            type="text"
            :placeholder="placeholder"
            :class="
                cn(
                    'h-9 w-full rounded-lg border border-input bg-background/50 px-3 ps-9 text-xs transition-colors duration-150',
                    hasValue ? 'pe-8' : '',
                    'placeholder:text-muted-foreground/60 text-foreground',
                    'focus:bg-background focus:border-ring focus:outline-none focus:ring-2 focus:ring-ring/40',
                )
            "
            @input="handleInput"
            @keydown="handleKeyDown"
        />

        <!-- Trailing Clear Button (X) -->
        <button
            v-if="hasValue"
            type="button"
            class="absolute end-2 top-1/2 flex size-5 -translate-y-1/2 cursor-pointer items-center justify-center rounded-md text-muted-foreground/60 transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            :title="t('common.clear', 'Effacer')"
            :aria-label="t('common.clear', 'Effacer')"
            @click="handleClear"
        >
            <X :stroke-width="2" class="size-3.5" />
        </button>
    </div>
</template>
