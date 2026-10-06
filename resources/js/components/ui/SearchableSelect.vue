<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { ChevronDown, Search, Check, X } from '@lucide/vue';

export interface SelectOption {
    value: string | number;
    label: string;
    labelAr?: string;
    labelFr?: string;
    subtitle?: string;
    badge?: string;
    flagUrl?: string;
    searchTerms?: string;
    disabled?: boolean;
    icon?: any;
}

const props = withDefaults(
    defineProps<{
        modelValue?: string | number | null;
        options: SelectOption[];
        placeholder?: string;
        searchPlaceholder?: string;
        emptyText?: string;
        name?: string;
        id?: string;
        disabled?: boolean;
        required?: boolean;
        tabindex?: number;
        variant?: 'blue' | 'teal';
        clearable?: boolean;
        showSearch?: boolean;
        searchThreshold?: number;
    }>(),
    {
        modelValue: '',
        placeholder: 'Sélectionner...',
        searchPlaceholder: 'Rechercher...',
        emptyText: 'Aucun résultat trouvé',
        name: '',
        id: '',
        disabled: false,
        required: false,
        tabindex: 0,
        variant: 'blue',
        clearable: true,
        showSearch: true,
        searchThreshold: 0,
    }
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: string | number | null): void;
    (e: 'change', value: string | number | null): void;
    (e: 'clear'): void;
}>();

const isOpen = ref(false);
const searchQuery = ref('');
const searchInputRef = ref<HTMLInputElement | null>(null);
const containerRef = ref<HTMLDivElement | null>(null);
const highlightedIndex = ref(-1);
const optionRefs = ref<Record<number, HTMLElement | null>>({});

const setOptionRef = (el: any, index: number) => {
    optionRefs.value[index] = el as HTMLElement | null;
};

// Find selected option
const selectedOption = computed(() => {
    if (props.modelValue === null || props.modelValue === undefined || props.modelValue === '') {
        return null;
    }
    return props.options.find((opt) => String(opt.value) === String(props.modelValue)) || null;
});

// Normalized search matching (case-insensitive, accent-tolerant, Arabic-tolerant)
const normalizeString = (str: string): string => {
    return str
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[أإآٱ]/g, 'ا')
        .replace(/ة/g, 'ه')
        .replace(/ى/g, 'ي')
        .trim();
};

const filteredOptions = computed(() => {
    const q = normalizeString(searchQuery.value);
    if (!q) {
        return props.options;
    }

    return props.options.filter((opt) => {
        const rawTexts = [
            opt.label,
            opt.labelAr || '',
            opt.labelFr || '',
            opt.subtitle || '',
            opt.badge || '',
            opt.searchTerms || '',
            String(opt.value || ''),
        ].join(' ');

        return normalizeString(rawTexts).includes(q) || rawTexts.toLowerCase().includes(searchQuery.value.toLowerCase());
    });
});

const shouldDisplaySearch = computed(() => {
    if (!props.showSearch) return false;
    return props.options.length >= props.searchThreshold;
});

const getNextAvailableIndex = (current: number, direction: 1 | -1): number => {
    const opts = filteredOptions.value;
    if (!opts.length) return -1;
    let next = current;
    for (let i = 0; i < opts.length; i++) {
        next = (next + direction + opts.length) % opts.length;
        if (!opts[next].disabled) {
            return next;
        }
    }
    return current;
};

const toggleDropdown = () => {
    if (props.disabled) return;
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        searchQuery.value = '';
        const selectedIdx = filteredOptions.value.findIndex(
            (opt) => String(opt.value) === String(props.modelValue)
        );
        highlightedIndex.value = selectedIdx >= 0 ? selectedIdx : getNextAvailableIndex(-1, 1);
        nextTick(() => {
            if (shouldDisplaySearch.value) {
                searchInputRef.value?.focus();
            }
            if (highlightedIndex.value >= 0 && optionRefs.value[highlightedIndex.value]) {
                optionRefs.value[highlightedIndex.value]?.scrollIntoView({ block: 'nearest' });
            }
        });
    }
};

const closeDropdown = () => {
    isOpen.value = false;
    searchQuery.value = '';
    highlightedIndex.value = -1;
};

const selectOption = (opt: SelectOption) => {
    if (opt.disabled) return;
    emit('update:modelValue', opt.value);
    emit('change', opt.value);
    closeDropdown();
};

const handleClear = (e?: Event) => {
    e?.stopPropagation();
    if (props.disabled) return;
    emit('update:modelValue', null);
    emit('change', null);
    emit('clear');
};

const clearSearch = () => {
    searchQuery.value = '';
    searchInputRef.value?.focus();
};

watch(highlightedIndex, (newIdx) => {
    if (newIdx >= 0 && optionRefs.value[newIdx]) {
        optionRefs.value[newIdx]?.scrollIntoView({ block: 'nearest' });
    }
});

// Keyboard navigation
const onKeyDown = (e: KeyboardEvent) => {
    if (props.disabled) return;

    if (!isOpen.value) {
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
            e.preventDefault();
            toggleDropdown();
        }
        return;
    }

    switch (e.key) {
        case 'ArrowDown':
            e.preventDefault();
            highlightedIndex.value = getNextAvailableIndex(highlightedIndex.value, 1);
            break;
        case 'ArrowUp':
            e.preventDefault();
            highlightedIndex.value = getNextAvailableIndex(highlightedIndex.value, -1);
            break;
        case 'Enter':
            e.preventDefault();
            if (highlightedIndex.value >= 0 && filteredOptions.value[highlightedIndex.value]) {
                const opt = filteredOptions.value[highlightedIndex.value];
                if (!opt.disabled) {
                    selectOption(opt);
                }
            }
            break;
        case 'Escape':
            e.preventDefault();
            closeDropdown();
            break;
        case 'Tab':
            closeDropdown();
            break;
    }
};

// Click outside detection
const handleClickOutside = (e: MouseEvent) => {
    if (containerRef.value && !containerRef.value.contains(e.target as Node)) {
        closeDropdown();
    }
};

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
    <div ref="containerRef" class="relative w-full text-start" @keydown="onKeyDown">
        <!-- Hidden Native Input for Native/Inertia Form Submissions -->
        <input
            v-if="name"
            type="hidden"
            :name="name"
            :id="id"
            :value="modelValue ?? ''"
            :required="required"
        />

        <!-- Trigger Button -->
        <button
            type="button"
            :tabindex="tabindex"
            :disabled="disabled"
            @click="toggleDropdown"
            class="input-premium group relative flex h-11 w-full items-center justify-between gap-2 rounded-xl border border-input bg-card/70 px-3.5 text-sm text-foreground shadow-xs transition-all duration-200 select-none cursor-pointer focus:outline-none"
            :class="[
                isOpen ? 'ring-2 ring-onda-blue-600/30 border-onda-blue-600 dark:border-onda-blue-500 shadow-md' : '',
                disabled ? 'opacity-60 cursor-not-allowed bg-muted/40 hover:border-input' : 'hover:border-onda-blue-500/50 hover:bg-card',
                variant === 'teal' ? 'input-premium-teal' : 'input-premium'
            ]"
            :aria-expanded="isOpen"
            aria-haspopup="listbox"
        >
            <div class="flex items-center gap-2.5 truncate min-w-0">
                <component
                    v-if="selectedOption?.icon"
                    :is="selectedOption.icon"
                    class="size-4 shrink-0 text-onda-blue-600 dark:text-onda-blue-400"
                />
                <img
                    v-else-if="selectedOption?.flagUrl"
                    :src="selectedOption.flagUrl"
                    :alt="selectedOption.label"
                    class="size-4 shrink-0 rounded-xs object-cover border border-border/40 shadow-xs"
                    loading="lazy"
                />

                <span v-if="selectedOption" class="truncate font-medium text-foreground">
                    <bdi dir="auto">{{ selectedOption.label }}</bdi>
                </span>
                <span v-else class="truncate text-muted-foreground/80">
                    {{ placeholder }}
                </span>
            </div>

            <div class="flex items-center gap-1.5 shrink-0 text-muted-foreground">
                <span
                    v-if="selectedOption?.badge"
                    class="rounded-md border border-onda-blue-500/20 bg-onda-blue-500/10 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-onda-blue-700 dark:text-onda-blue-300"
                >
                    <bdi dir="ltr">{{ selectedOption.badge }}</bdi>
                </span>

                <!-- Clear Selection Button -->
                <button
                    v-if="clearable && selectedOption && !disabled"
                    type="button"
                    title="Effacer la sélection"
                    class="flex size-5 items-center justify-center rounded-full text-muted-foreground/70 transition-colors hover:bg-muted hover:text-foreground"
                    @click.stop="handleClear"
                >
                    <X class="size-3" />
                </button>

                <ChevronDown
                    class="size-4 text-muted-foreground/70 transition-transform duration-200"
                    :class="{ 'rotate-180 text-onda-blue-600 dark:text-onda-blue-400': isOpen }"
                />
            </div>
        </button>

        <!-- Dropdown Menu / Combobox Popover -->
        <transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="transform scale-98 opacity-0 -translate-y-1"
            enter-to-class="transform scale-100 opacity-100 translate-y-0"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="transform scale-100 opacity-100 translate-y-0"
            leave-to-class="transform scale-98 opacity-0 -translate-y-1"
        >
            <div
                v-if="isOpen"
                class="absolute left-0 right-0 z-50 mt-1.5 max-h-84 w-full overflow-hidden rounded-2xl border border-border/80 bg-card/95 p-1.5 shadow-2xl backdrop-blur-xl ring-1 ring-black/5 dark:border-white/10 dark:bg-card/95"
            >
                <!-- Embedded Search Bar -->
                <div v-if="shouldDisplaySearch" class="relative mb-1.5 px-1 pt-1">
                    <Search class="absolute top-1/2 -translate-y-1/2 start-3.5 size-4 text-muted-foreground/70" />
                    <input
                        ref="searchInputRef"
                        v-model="searchQuery"
                        type="text"
                        :placeholder="searchPlaceholder"
                        class="h-9 w-full rounded-xl border border-border/70 bg-background/90 ps-9 pe-16 text-xs text-foreground placeholder:text-muted-foreground/70 focus:border-onda-blue-600 focus:outline-none focus:ring-2 focus:ring-onda-blue-600/20 dark:border-white/10"
                        @click.stop
                    />
                    <div class="absolute top-1/2 -translate-y-1/2 end-3 flex items-center gap-1">
                        <button
                            v-if="searchQuery"
                            type="button"
                            @click.stop="clearSearch"
                            class="flex size-5 items-center justify-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground"
                        >
                            <X class="size-3" />
                        </button>
                        <span class="rounded bg-muted/60 px-1 py-0.5 text-[10px] text-muted-foreground">
                            {{ filteredOptions.length }}
                        </span>
                    </div>
                </div>

                <!-- Scrollable Options List -->
                <div class="max-h-60 overflow-y-auto space-y-0.5 p-0.5 custom-scrollbar" role="listbox">
                    <button
                        v-for="(opt, index) in filteredOptions"
                        :key="opt.value"
                        :ref="(el) => setOptionRef(el, index)"
                        type="button"
                        role="option"
                        :disabled="opt.disabled"
                        :aria-selected="String(opt.value) === String(modelValue)"
                        @click.stop="selectOption(opt)"
                        @mouseenter="!opt.disabled ? (highlightedIndex = index) : null"
                        class="group/opt flex w-full items-center justify-between rounded-xl px-3 py-2 text-xs transition-colors duration-150 select-none text-start"
                        :class="[
                            opt.disabled
                                ? 'opacity-45 cursor-not-allowed bg-muted/10 text-muted-foreground'
                                : 'cursor-pointer',
                            String(opt.value) === String(modelValue)
                                ? 'bg-onda-blue-500/10 font-semibold text-onda-blue-700 dark:bg-onda-blue-500/20 dark:text-onda-blue-300'
                                : highlightedIndex === index && !opt.disabled
                                  ? 'bg-muted/70 text-foreground'
                                  : !opt.disabled
                                    ? 'text-foreground hover:bg-muted/50'
                                    : ''
                        ]"
                    >
                        <div class="flex items-center gap-2.5 truncate pe-2 min-w-0">
                            <component
                                v-if="opt.icon"
                                :is="opt.icon"
                                class="size-4 shrink-0 transition-transform group-hover/opt:scale-105"
                                :class="String(opt.value) === String(modelValue) ? 'text-onda-blue-600 dark:text-onda-blue-400' : 'text-muted-foreground'"
                            />
                            <img
                                v-else-if="opt.flagUrl"
                                :src="opt.flagUrl"
                                :alt="opt.label"
                                class="size-4 shrink-0 rounded-xs object-cover border border-border/40 shadow-xs"
                                loading="lazy"
                            />
                            <div class="flex flex-col items-start gap-0.5 truncate">
                                <span class="truncate">
                                    <bdi dir="auto">{{ opt.label }}</bdi>
                                </span>
                                <span v-if="opt.subtitle" class="text-[10px] text-muted-foreground truncate">
                                    {{ opt.subtitle }}
                                </span>
                            </div>
                        </div>

                        <div class="flex items-center gap-1.5 shrink-0">
                            <span
                                v-if="opt.disabled"
                                class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
                            >
                                Indisponible
                            </span>
                            <span
                                v-else-if="opt.badge"
                                class="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] font-medium text-muted-foreground group-hover/opt:bg-background"
                            >
                                <bdi dir="ltr">{{ opt.badge }}</bdi>
                            </span>
                            <Check
                                v-if="String(opt.value) === String(modelValue)"
                                class="size-4 text-onda-blue-600 dark:text-onda-blue-400"
                            />
                        </div>
                    </button>

                    <!-- Empty Results State -->
                    <div
                        v-if="filteredOptions.length === 0"
                        class="py-7 text-center text-xs text-muted-foreground flex flex-col items-center justify-center gap-2"
                    >
                        <Search class="size-5 text-muted-foreground/40 stroke-1" />
                        <span>{{ emptyText }}</span>
                        <button
                            v-if="searchQuery"
                            type="button"
                            @click.stop="clearSearch"
                            class="text-[11px] text-onda-blue-600 hover:underline dark:text-onda-blue-400"
                        >
                            Réinitialiser la recherche
                        </button>
                    </div>
                </div>
            </div>
        </transition>
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 5px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: rgba(150, 150, 150, 0.25);
    border-radius: 9999px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: rgba(150, 150, 150, 0.4);
}
</style>
