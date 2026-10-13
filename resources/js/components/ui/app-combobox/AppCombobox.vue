<script setup lang="ts">
import { Check, ChevronDown, Search, X } from '@lucide/vue';
import {
    ListboxContent,
    ListboxFilter,
    ListboxItem,
    ListboxRoot,
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ScrollArea } from '@/components/ui/scroll-area';
import { matchesSearch } from './searchNormalize';

/**
 * A searchable select for long lists (colleges, members, wilayas, …), on
 * the reka-ui Popover + Listbox: the menu is portalled (never clipped by a
 * dialog or a card), stays inside the viewport, matches the trigger width
 * and is fully keyboard driven.
 *
 * The search query belongs to ONE opening of the menu. It is cleared every
 * time the menu opens or closes and is never seeded from the selected
 * label — the old bug where reopening a select listed only the option
 * already chosen cannot come back (see AppCombobox.test.ts).
 */
export interface ComboboxOption {
    value: string | number;
    label: string;
    labelAr?: string;
    labelFr?: string;
    subtitle?: string;
    badge?: string;
    flagUrl?: string;
    searchTerms?: string;
    disabled?: boolean;
    icon?: Component;
}

const props = withDefaults(
    defineProps<{
        modelValue?: string | number | null;
        options: ComboboxOption[];
        placeholder?: string;
        searchPlaceholder?: string;
        emptyText?: string;
        name?: string;
        id?: string;
        disabled?: boolean;
        required?: boolean;
        tabindex?: number;
        variant?: 'blue' | 'teal';
        /** `sm` matches the 36px inputs of toolbars and admin forms. */
        size?: 'default' | 'sm';
        clearable?: boolean;
        /** Show the search field only from this many options up. */
        searchThreshold?: number;
    }>(),
    {
        modelValue: null,
        placeholder: undefined,
        searchPlaceholder: undefined,
        emptyText: undefined,
        name: '',
        id: '',
        disabled: false,
        required: false,
        tabindex: 0,
        variant: 'blue',
        size: 'default',
        clearable: true,
        searchThreshold: 0,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string | number | null];
    change: [value: string | number | null];
    clear: [];
}>();

const { t } = useI18n();

const open = ref(false);
const query = ref('');

// One opening, one query: never carried over, never the selected label.
watch(open, () => {
    query.value = '';
});

const isSelected = (option: ComboboxOption): boolean =>
    props.modelValue !== null &&
    props.modelValue !== undefined &&
    props.modelValue !== '' &&
    String(option.value) === String(props.modelValue);

const selected = computed(() => props.options.find(isSelected) ?? null);

const searchable = computed(
    () => props.options.length >= props.searchThreshold,
);

const filtered = computed(() =>
    props.options.filter((option) =>
        matchesSearch(
            [
                option.label,
                option.labelAr,
                option.labelFr,
                option.subtitle,
                option.badge,
                option.searchTerms,
                String(option.value),
            ]
                .filter(Boolean)
                .join(' '),
            query.value,
        ),
    ),
);

function choose(option: ComboboxOption): void {
    if (option.disabled) {
        return;
    }

    emit('update:modelValue', option.value);
    emit('change', option.value);
    open.value = false;
}

function clear(): void {
    if (props.disabled) {
        return;
    }

    emit('update:modelValue', null);
    emit('change', null);
    emit('clear');
}
</script>

<template>
    <div class="relative w-full text-start" data-slot="app-combobox">
        <input
            v-if="name"
            type="hidden"
            :name="name"
            :value="modelValue ?? ''"
            :required="required"
        />

        <PopoverRoot v-model:open="open">
            <div class="relative">
                <PopoverTrigger as-child :disabled="disabled">
                    <button
                        :id="id || undefined"
                        type="button"
                        role="combobox"
                        aria-haspopup="listbox"
                        :aria-expanded="open"
                        :tabindex="tabindex"
                        :disabled="disabled"
                        data-slot="app-combobox-trigger"
                        class="group relative flex w-full cursor-pointer items-center justify-between gap-2 border border-input bg-card/70 text-sm text-foreground shadow-xs transition-colors duration-200 select-none focus:outline-none"
                        :class="[
                            size === 'sm'
                                ? 'h-9 !rounded-md px-3'
                                : 'h-11 rounded-xl px-3.5',
                            open
                                ? 'border-onda-blue-600 shadow-md ring-2 ring-onda-blue-600/30 dark:border-onda-blue-500'
                                : '',
                            disabled
                                ? 'cursor-not-allowed bg-muted/40 opacity-60 hover:border-input'
                                : 'hover:border-onda-blue-500/50 hover:bg-card',
                            variant === 'teal'
                                ? 'input-premium-teal'
                                : 'input-premium',
                            clearable && selected && !disabled ? 'pe-16' : '',
                        ]"
                    >
                        <span class="flex min-w-0 items-center gap-2.5">
                            <component
                                :is="selected.icon"
                                v-if="selected?.icon"
                                class="size-4 shrink-0 text-onda-blue-600 dark:text-onda-blue-400"
                            />
                            <img
                                v-else-if="selected?.flagUrl"
                                :src="selected.flagUrl"
                                alt=""
                                class="size-4 shrink-0 rounded-xs border border-border/40 object-cover shadow-xs"
                                loading="lazy"
                            />
                            <span
                                v-if="selected"
                                class="truncate font-medium text-foreground"
                            >
                                <bdi dir="auto">{{ selected.label }}</bdi>
                            </span>
                            <span v-else class="truncate text-muted-foreground">
                                {{ placeholder ?? t('ui.combobox.placeholder') }}
                            </span>
                        </span>

                        <span
                            class="flex shrink-0 items-center gap-1.5 text-muted-foreground"
                        >
                            <span
                                v-if="selected?.badge"
                                class="rounded-md border border-onda-blue-500/20 bg-onda-blue-500/10 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-onda-blue-700 dark:text-onda-blue-300"
                            >
                                <bdi dir="ltr">{{ selected.badge }}</bdi>
                            </span>
                            <ChevronDown
                                class="size-4 text-muted-foreground transition-transform duration-200"
                                :class="open ? 'rotate-180' : ''"
                            />
                        </span>
                    </button>
                </PopoverTrigger>

                <!-- A sibling of the trigger, not a child: a button inside a
                     button is invalid and unreachable by keyboard. -->
                <button
                    v-if="clearable && selected && !disabled"
                    type="button"
                    data-slot="app-combobox-clear"
                    :aria-label="t('ui.combobox.clear')"
                    :title="t('ui.combobox.clear')"
                    class="absolute end-9 top-1/2 flex size-6 -translate-y-1/2 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/40"
                    @click="clear"
                >
                    <X class="size-3" />
                </button>
            </div>

            <PopoverPortal>
                <PopoverContent
                    align="start"
                    :side-offset="6"
                    :collision-padding="8"
                    data-slot="app-combobox-content"
                    class="motion-pop z-50 w-(--reka-popover-trigger-width) min-w-56 rounded-xl border border-border bg-popover p-1.5 text-popover-foreground shadow-lg outline-none"
                >
                    <ListboxRoot
                        :model-value="selected?.value"
                        highlight-on-hover
                        class="flex flex-col"
                    >
                        <div v-if="searchable" class="relative mb-1.5">
                            <Search
                                class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <ListboxFilter
                                v-model="query"
                                auto-focus
                                data-slot="app-combobox-search"
                                :placeholder="
                                    searchPlaceholder ?? t('common.search')
                                "
                                :aria-label="
                                    searchPlaceholder ?? t('common.search')
                                "
                                class="h-9 w-full rounded-lg border border-border bg-background ps-9 pe-12 text-xs text-foreground outline-none placeholder:text-muted-foreground focus:border-ring focus:ring-2 focus:ring-ring/30"
                            />
                            <span
                                class="absolute end-3 top-1/2 -translate-y-1/2 rounded bg-muted px-1 py-0.5 text-[10px] text-muted-foreground tabular-nums"
                                aria-hidden="true"
                            >
                                {{ filtered.length }}
                            </span>
                        </div>

                        <ScrollArea
                            class="max-h-[min(20rem,calc(var(--reka-popover-content-available-height)-4rem))]"
                        >
                            <ListboxContent
                                class="space-y-0.5 p-0.5 outline-none"
                            >
                                <ListboxItem
                                    v-for="option in filtered"
                                    :key="option.value"
                                    :value="option.value"
                                    :disabled="option.disabled"
                                    data-slot="app-combobox-option"
                                    class="flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg px-3 py-2 text-start text-xs outline-none select-none data-[disabled]:cursor-not-allowed data-[disabled]:opacity-50 data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground"
                                    :class="
                                        isSelected(option)
                                            ? 'font-semibold text-primary'
                                            : 'text-foreground'
                                    "
                                    @select.prevent="choose(option)"
                                >
                                    <span
                                        class="flex min-w-0 items-center gap-2.5"
                                    >
                                        <component
                                            :is="option.icon"
                                            v-if="option.icon"
                                            class="size-4 shrink-0 text-muted-foreground"
                                        />
                                        <img
                                            v-else-if="option.flagUrl"
                                            :src="option.flagUrl"
                                            alt=""
                                            class="size-4 shrink-0 rounded-xs border border-border/40 object-cover shadow-xs"
                                            loading="lazy"
                                        />
                                        <span class="flex min-w-0 flex-col">
                                            <span class="truncate">
                                                <bdi dir="auto">{{
                                                    option.label
                                                }}</bdi>
                                            </span>
                                            <span
                                                v-if="option.subtitle"
                                                class="truncate text-[10px] font-normal text-muted-foreground"
                                            >
                                                {{ option.subtitle }}
                                            </span>
                                        </span>
                                    </span>

                                    <span
                                        class="flex shrink-0 items-center gap-1.5"
                                    >
                                        <span
                                            v-if="option.disabled"
                                            class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
                                        >
                                            {{ t('ui.combobox.unavailable') }}
                                        </span>
                                        <span
                                            v-else-if="option.badge"
                                            class="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] font-medium text-muted-foreground"
                                        >
                                            <bdi dir="ltr">{{
                                                option.badge
                                            }}</bdi>
                                        </span>
                                        <Check
                                            v-if="isSelected(option)"
                                            class="size-4 text-primary"
                                            data-slot="app-combobox-check"
                                        />
                                    </span>
                                </ListboxItem>
                            </ListboxContent>

                            <div
                                v-if="filtered.length === 0"
                                data-slot="app-combobox-empty"
                                role="status"
                                class="flex flex-col items-center justify-center gap-2 py-7 text-center text-xs text-muted-foreground"
                            >
                                <Search
                                    class="size-5 stroke-1 text-muted-foreground"
                                />
                                <span>{{
                                    emptyText ?? t('common.noResults')
                                }}</span>
                                <button
                                    v-if="query !== ''"
                                    type="button"
                                    class="cursor-pointer text-[11px] text-primary hover:underline"
                                    @click="query = ''"
                                >
                                    {{ t('ui.combobox.reset') }}
                                </button>
                            </div>
                        </ScrollArea>
                    </ListboxRoot>
                </PopoverContent>
            </PopoverPortal>
        </PopoverRoot>
    </div>
</template>
