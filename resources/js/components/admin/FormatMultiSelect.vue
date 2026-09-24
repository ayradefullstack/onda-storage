<script setup lang="ts">
import { Check, ChevronDown, Search, ShieldAlert, X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

/**
 * Grouped, searchable multi-select over the file-format registry.
 *
 * The options come from the SERVER (`FileFormats::grouped()`), never from a
 * list in TypeScript — a second copy would drift the first time a format is
 * added, and drift here means the admin can select something the pipeline
 * will then reject.
 *
 * Fifty-odd formats across ten categories, so scrolling is not a selection
 * method: there is a search box, and each category has a select-all, because
 * an admin configuring an audio slot wants every audio format in one action
 * rather than eight clicks.
 */
export interface Format {
    extension: string;
    label: string;
    mimes: string[];
    inline_safe: boolean;
}

export interface FormatGroup {
    category: string;
    formats: Format[];
}

const props = defineProps<{
    groups: FormatGroup[];
    modelValue: string[];
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>();

const { t } = useI18n();

const open = ref(false);
const query = ref('');
const searchInput = ref<InstanceType<typeof Input> | null>(null);

watch(open, async (isOpen) => {
    if (!isOpen) {
        query.value = '';

        return;
    }

    // Focus the search on open: with this many options, typing is the
    // primary way in.
    await nextTick();
    (
        searchInput.value?.$el as HTMLInputElement | undefined
    )?.focus?.();
});

const selected = computed(() => new Set(props.modelValue));

/**
 * Matches on the extension, the label and the category name, so "sheet"
 * finds the spreadsheet group and "jpg" finds JPG directly.
 */
const filteredGroups = computed<FormatGroup[]>(() => {
    const q = query.value.trim().toLowerCase();

    if (q === '') {
        return props.groups;
    }

    return props.groups
        .map((group) => {
            const categoryMatches = t(
                `admin.referentiel.formats.categories.${group.category}`,
            )
                .toLowerCase()
                .includes(q);

            return {
                category: group.category,
                formats: categoryMatches
                    ? group.formats
                    : group.formats.filter(
                          (f) =>
                              f.extension.includes(q) ||
                              f.label.toLowerCase().includes(q),
                      ),
            };
        })
        .filter((group) => group.formats.length > 0);
});

/** Selected formats, in registry order rather than click order. */
const selectedFormats = computed<Format[]>(() =>
    props.groups
        .flatMap((g) => g.formats)
        .filter((f) => selected.value.has(f.extension)),
);

/**
 * The union of every MIME across the selection — the same derivation the
 * server runs on save, shown live so the admin sees what the pipeline will
 * accept before committing to it.
 */
const derivedMimes = computed<string[]>(() =>
    [...new Set(selectedFormats.value.flatMap((f) => f.mimes))].sort(),
);

const notInlineSafe = computed(() =>
    selectedFormats.value.filter((f) => !f.inline_safe),
);

const toggle = (extension: string) => {
    const next = new Set(props.modelValue);

    if (next.has(extension)) {
        next.delete(extension);
    } else {
        next.add(extension);
    }

    emit('update:modelValue', orderedByRegistry(next));
};

const groupState = (group: FormatGroup) => {
    const chosen = group.formats.filter((f) =>
        selected.value.has(f.extension),
    ).length;

    return {
        all: chosen === group.formats.length && chosen > 0,
        some: chosen > 0 && chosen < group.formats.length,
        count: chosen,
    };
};

const toggleGroup = (group: FormatGroup) => {
    const next = new Set(props.modelValue);
    const { all } = groupState(group);

    for (const format of group.formats) {
        if (all) {
            next.delete(format.extension);
        } else {
            next.add(format.extension);
        }
    }

    emit('update:modelValue', orderedByRegistry(next));
};

const remove = (extension: string) => {
    emit(
        'update:modelValue',
        props.modelValue.filter((e) => e !== extension),
    );
};

/** Keeps the stored list stable regardless of the order things were clicked. */
const orderedByRegistry = (chosen: Set<string>) =>
    props.groups
        .flatMap((g) => g.formats)
        .map((f) => f.extension)
        .filter((e) => chosen.has(e));
</script>

<template>
    <div class="space-y-2.5">
        <!-- Selected formats as removable chips. -->
        <div v-if="selectedFormats.length > 0" class="flex flex-wrap gap-1.5">
            <span
                v-for="format in selectedFormats"
                :key="format.extension"
                class="inline-flex items-center gap-1 rounded-md border border-border/80 bg-muted/60 py-0.5 ps-2 pe-1 text-[11px]"
            >
                <bdi class="font-mono font-medium">{{ format.label }}</bdi>
                <ShieldAlert
                    v-if="!format.inline_safe"
                    class="size-3 text-amber-600 dark:text-amber-400"
                    :aria-label="t('admin.referentiel.formats.notInlineSafe')"
                />
                <button
                    type="button"
                    class="cursor-pointer rounded p-0.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                    :aria-label="
                        t('admin.referentiel.formats.remove', {
                            format: format.label,
                        })
                    "
                    @click="remove(format.extension)"
                >
                    <X class="size-3" />
                </button>
            </span>
        </div>
        <p v-else class="text-[11px] text-muted-foreground">
            {{ t('admin.referentiel.formats.noneSelected') }}
        </p>

        <Popover v-model:open="open">
            <PopoverTrigger as-child>
                <Button
                    type="button"
                    variant="outline"
                    class="h-9 w-full cursor-pointer justify-between text-sm font-normal"
                >
                    <span>{{
                        t('admin.referentiel.formats.choose', {
                            count: selectedFormats.length,
                        })
                    }}</span>
                    <ChevronDown class="size-4 opacity-60" />
                </Button>
            </PopoverTrigger>

            <PopoverContent
                class="w-[min(30rem,calc(100vw-2rem))] p-0"
                align="start"
            >
                <div class="border-b border-border p-2">
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            ref="searchInput"
                            v-model="query"
                            type="search"
                            class="h-8 ps-8 text-sm"
                            :placeholder="
                                t('admin.referentiel.formats.searchPlaceholder')
                            "
                        />
                    </div>
                </div>

                <div class="max-h-80 overflow-y-auto p-1">
                    <p
                        v-if="filteredGroups.length === 0"
                        class="px-2 py-6 text-center text-xs text-muted-foreground"
                    >
                        {{ t('admin.referentiel.formats.noMatch') }}
                    </p>

                    <div
                        v-for="group in filteredGroups"
                        :key="group.category"
                        class="mb-1"
                    >
                        <div
                            class="flex items-center justify-between gap-2 px-2 py-1"
                        >
                            <p
                                class="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                {{
                                    t(
                                        `admin.referentiel.formats.categories.${group.category}`,
                                    )
                                }}
                            </p>
                            <!-- One action for "every audio format". -->
                            <button
                                type="button"
                                class="cursor-pointer rounded px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                @click="toggleGroup(group)"
                            >
                                {{
                                    groupState(group).all
                                        ? t('admin.referentiel.formats.clearAll')
                                        : t('admin.referentiel.formats.selectAll')
                                }}
                            </button>
                        </div>

                        <button
                            v-for="format in group.formats"
                            :key="format.extension"
                            type="button"
                            role="option"
                            :aria-selected="selected.has(format.extension)"
                            class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-xs transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                            @click="toggle(format.extension)"
                        >
                            <span
                                :class="[
                                    'flex size-4 shrink-0 items-center justify-center rounded border',
                                    selected.has(format.extension)
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-border',
                                ]"
                            >
                                <Check
                                    v-if="selected.has(format.extension)"
                                    class="size-3"
                                />
                            </span>
                            <bdi class="font-mono font-medium">{{
                                format.label
                            }}</bdi>
                            <bdi class="text-muted-foreground"
                                >.{{ format.extension }}</bdi
                            >
                            <ShieldAlert
                                v-if="!format.inline_safe"
                                class="ms-auto size-3 shrink-0 text-amber-600 dark:text-amber-400"
                                :aria-label="
                                    t('admin.referentiel.formats.notInlineSafe')
                                "
                            />
                        </button>
                    </div>
                </div>
            </PopoverContent>
        </Popover>

        <!-- Derived MIME types: what the pipeline will actually accept. -->
        <div
            v-if="derivedMimes.length > 0"
            class="space-y-1.5 rounded-lg border border-border/80 bg-muted/40 p-2.5"
        >
            <p class="text-[11px] font-medium text-foreground">
                {{ t('admin.referentiel.formats.derivedMimes') }}
            </p>
            <div class="flex flex-wrap gap-1">
                <bdi
                    v-for="mime in derivedMimes"
                    :key="mime"
                    class="rounded bg-background px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
                    >{{ mime }}</bdi
                >
            </div>
            <p class="text-[10px] leading-relaxed text-muted-foreground">
                {{ t('admin.referentiel.formats.derivedMimesHelp') }}
            </p>
        </div>

        <p
            v-if="notInlineSafe.length > 0"
            class="flex items-start gap-1.5 text-[11px] leading-relaxed text-muted-foreground"
        >
            <ShieldAlert
                class="mt-0.5 size-3 shrink-0 text-amber-600 dark:text-amber-400"
            />
            <span>
                {{
                    t('admin.referentiel.formats.notInlineSafeHelp', {
                        formats: notInlineSafe.map((f) => f.label).join(', '),
                    })
                }}
            </span>
        </p>
    </div>
</template>
