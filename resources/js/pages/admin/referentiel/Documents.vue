<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Info, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import CreateDialog from '@/components/admin/CreateDialog.vue';
import EmptyState from '@/components/admin/EmptyState.vue';
import FieldError from '@/components/admin/FieldError.vue';
import FormatMultiSelect from '@/components/admin/FormatMultiSelect.vue';
import type { FormatGroup } from '@/components/admin/FormatMultiSelect.vue';
import LockedField from '@/components/admin/LockedField.vue';
import Pagination from '@/components/admin/Pagination.vue';
import ReferentielShell from '@/components/admin/ReferentielShell.vue';
import { ActionButton } from '@/components/ui/action';
import { AppCombobox } from '@/components/ui/app-combobox';
import type { ComboboxOption } from '@/components/ui/app-combobox';
import { AppDialog } from '@/components/ui/app-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { TableScroll } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useListFilters } from '@/composables/useListFilters';
import { documents as documentsRoute } from '@/routes/admin/referentiel';
import { impact, store, update } from '@/routes/admin/referentiel/documents';

interface DocumentRow {
    uuid: string;
    document_key: string;
    title: string;
    title_ar: string | null;
    title_en: string | null;
    college: string | null;
    code_college: string | null;
    extensions: string[];
    /** Derived from `extensions` by the server; read-only here. */
    mime_types: string[];
    is_required: boolean;
    display_order: number;
    max_size_kb: number | null;
    allows_multiple: boolean;
    needs_review: boolean;
    has_conditions: boolean;
}

const props = defineProps<{
    tabs: Array<{ key: string; route: string; count: number }>;
    rows: {
        data: DocumentRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        per_page: number;
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { search: string; college: string | null; needs_review: boolean };
    colleges: Array<{ uuid: string; name: string }>;
    /** The file-format registry, grouped by category. Server-owned. */
    formats: FormatGroup[];
}>();

// The controls of this tab's list (search in the shell, selects below);
// the rows are read straight from `props.rows`.
const list = useListFilters({
    url: () => documentsRoute().url,
    filters: () => props.filters,
    defaults: { search: '', college: '', needs_review: '' },
    only: ['rows'],
});
const { values: filterValues } = list;

// Colleges are a long list: a searchable combobox, not a plain select.
const collegeOptions = computed<ComboboxOption[]>(() =>
    props.colleges.map((college) => ({
        value: college.uuid,
        label: college.name,
    })),
);

const { t } = useI18n();

const editing = ref<DocumentRow | null>(null);
const saving = ref(false);
const affectedDrafts = ref<number | null>(null);
const errors = ref<Record<string, string>>({});

const form = ref({
    title_ar: '',
    title_en: '',
    /** Chosen through the registry multi-select — always registry keys. */
    extensions: [] as string[],
    is_required: false,
    display_order: 1,
    max_size_kb: '' as string,
    allows_multiple: true,
});

const open = (row: DocumentRow) => {
    editing.value = row;
    errors.value = {};
    affectedDrafts.value = null;
    form.value = {
        title_ar: row.title_ar ?? '',
        title_en: row.title_en ?? '',
        extensions: [...row.extensions],
        is_required: row.is_required,
        display_order: row.display_order,
        max_size_kb: row.max_size_kb === null ? '' : String(row.max_size_kb),
        allows_multiple: row.allows_multiple,
    };
};

/**
 * Turning `is_required` on makes existing drafts un-submittable until
 * their authors upload this file. The officer sees how many before saving,
 * not afterwards from support calls.
 */
watch(
    () => form.value.is_required,
    async (required) => {
        if (editing.value === null || !required || editing.value.is_required) {
            affectedDrafts.value = null;

            return;
        }

        try {
            const response = await fetch(impact(editing.value.uuid).url, {
                headers: { Accept: 'application/json' },
            });
            const payload = await response.json();
            affectedDrafts.value = payload.affected_drafts ?? null;
        } catch {
            // A failed count must not block the edit; the warning simply
            // does not appear.
            affectedDrafts.value = null;
        }
    },
);

const save = () => {
    if (editing.value === null) {
        return;
    }

    saving.value = true;

    router.patch(
        update(editing.value.uuid).url,
        {
            title_ar: form.value.title_ar.trim() || null,
            title_en: form.value.title_en.trim() || null,
            // Already registry keys — lowercase, dot-free, and known to the
            // server. No parsing step to get wrong.
            extensions: form.value.extensions,
            is_required: form.value.is_required,
            display_order: form.value.display_order,
            max_size_kb:
                form.value.max_size_kb.trim() === ''
                    ? null
                    : Number(form.value.max_size_kb),
            allows_multiple: form.value.allows_multiple,
        },
        {
            preserveScroll: true,
            onError: (e) => (errors.value = e),
            onSuccess: () => (editing.value = null),
            onFinish: () => (saving.value = false),
        },
    );
};

// --- create -------------------------------------------------------------

const creating = ref(false);
const createForm = ref({
    college: '',
    document_key: '',
    title: '',
    title_ar: '',
    title_en: '',
    extensions: [] as string[],
    is_required: true,
    display_order: 1,
    max_size_kb: '' as string,
    allows_multiple: true,
});

const openCreate = () => {
    createForm.value = {
        college: props.filters.college ?? '',
        document_key: '',
        title: '',
        title_ar: '',
        title_en: '',
        extensions: [],
        is_required: true,
        display_order: 1,
        max_size_kb: '',
        allows_multiple: true,
    };
    errors.value = {};
    creating.value = true;
};

const create = () => {
    saving.value = true;

    router.post(
        store().url,
        {
            college: createForm.value.college,
            document_key: createForm.value.document_key.trim(),
            title: createForm.value.title,
            title_ar: createForm.value.title_ar.trim() || null,
            title_en: createForm.value.title_en.trim() || null,
            extensions: createForm.value.extensions,
            is_required: createForm.value.is_required,
            display_order: createForm.value.display_order,
            max_size_kb:
                createForm.value.max_size_kb.trim() === ''
                    ? null
                    : Number(createForm.value.max_size_kb),
            allows_multiple: createForm.value.allows_multiple,
        },
        {
            preserveScroll: true,
            onError: (e) => (errors.value = e),
            onSuccess: () => (creating.value = false),
            onFinish: () => (saving.value = false),
        },
    );
};
</script>

<template>
    <ReferentielShell :tabs="tabs" active="documents" :list="list">
        <template #actions>
            <Button class="cursor-pointer" @click="openCreate">
                <Plus class="size-4" />
                {{ t('admin.referentiel.createDocument') }}
            </Button>
        </template>

        <template #filters>
            <div class="w-64">
                <AppCombobox
                    size="sm"
                    :model-value="filterValues.college || null"
                    :options="collegeOptions"
                    :placeholder="t('admin.referentiel.allColleges')"
                    @update:model-value="
                        (value) =>
                            list.set({
                                college: value === null ? '' : String(value),
                            })
                    "
                />
            </div>

            <Button
                size="sm"
                :variant="filterValues.needs_review ? 'default' : 'outline'"
                class="h-9 cursor-pointer gap-1.5 text-xs"
                @click="
                    list.set({
                        needs_review: filterValues.needs_review ? '' : '1',
                    })
                "
            >
                <AlertTriangle class="size-3.5" />
                {{ t('admin.referentiel.needsReviewFilter') }}
            </Button>
        </template>

        <EmptyState
            data-test="list-empty"
            v-if="rows.data.length === 0"
            :title="t('admin.referentiel.empty')"
            :description="t('admin.referentiel.emptyDescription')"
        >
            <Button
                v-if="list.isActive.value"
                variant="outline"
                size="sm"
                class="h-8 cursor-pointer text-xs"
                data-test="list-empty-clear"
                @click="list.clear()"
            >
                {{ t('common.clearFilters') }}
            </Button>
        </EmptyState>

        <div
            v-else
            class="overflow-hidden rounded-lg border border-border bg-card"
        >
            <TableScroll>
                <table class="w-full border-collapse text-xs">
                    <thead>
                        <tr
                            class="border-b border-border bg-muted/40 text-muted-foreground"
                        >
                            <th
                                class="w-12 px-3 py-2.5 text-center font-medium"
                            >
                                #
                            </th>
                            <th class="px-3 py-2.5 text-start font-medium">
                                {{ t('admin.referentiel.col.key') }}
                            </th>
                            <th class="px-3 py-2.5 text-start font-medium">
                                {{ t('admin.referentiel.col.title') }}
                            </th>
                            <th
                                class="hidden px-3 py-2.5 text-start font-medium lg:table-cell"
                            >
                                {{ t('admin.referentiel.col.college') }}
                            </th>
                            <th class="px-3 py-2.5 text-start font-medium">
                                {{ t('admin.referentiel.col.extensions') }}
                            </th>
                            <th class="px-3 py-2.5 text-end font-medium">
                                {{ t('admin.referentiel.col.order') }}
                            </th>
                            <th class="px-3 py-2.5 text-start font-medium">
                                {{ t('admin.referentiel.col.rules') }}
                            </th>
                            <th class="px-3 py-2.5 text-end font-medium">
                                {{ t('admin.referentiel.col.actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/70">
                        <tr
                            v-for="(row, index) in rows.data"
                            :key="row.uuid"
                            :class="[
                                'transition-colors hover:bg-accent/30',
                                row.needs_review
                                    ? 'bg-amber-500/[0.04] dark:bg-amber-500/[0.06]'
                                    : '',
                            ]"
                        >
                            <td
                                class="w-12 px-3 py-2 text-center text-xs font-medium text-muted-foreground"
                            >
                                {{ (rows.from ?? 1) + index }}
                            </td>
                            <td
                                class="px-3 py-2 font-mono text-[11px] whitespace-nowrap text-muted-foreground"
                            >
                                <bdi dir="ltr">{{ row.document_key }}</bdi>
                            </td>
                            <td class="max-w-[16rem] px-3 py-2">
                                <bdi class="block truncate font-medium">{{
                                    row.title
                                }}</bdi>
                                <bdi
                                    v-if="row.title_ar"
                                    class="block truncate text-[11px] text-muted-foreground"
                                    >{{ row.title_ar }}</bdi
                                >
                            </td>
                            <td
                                class="hidden max-w-[12rem] px-3 py-2 lg:table-cell"
                            >
                                <bdi
                                    class="block truncate text-muted-foreground"
                                    >{{ row.college ?? '—' }}</bdi
                                >
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="ext in row.extensions"
                                        :key="ext"
                                        class="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
                                        ><bdi>{{ ext }}</bdi></span
                                    >
                                </div>
                            </td>
                            <td
                                class="px-3 py-2 text-end text-muted-foreground"
                            >
                                <bdi>{{ row.display_order }}</bdi>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <div class="flex flex-wrap items-center gap-1">
                                    <span
                                        v-if="row.is_required"
                                        class="rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-medium text-primary"
                                        >{{
                                            t('admin.referentiel.col.required')
                                        }}</span
                                    >
                                    <span
                                        v-if="row.allows_multiple"
                                        class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
                                        >{{
                                            t('admin.referentiel.col.multiple')
                                        }}</span
                                    >
                                    <!-- A conditional requirement never
                                         blocks submission — the officer
                                         judges it at review time. -->
                                    <span
                                        v-if="row.has_conditions"
                                        class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
                                        :title="
                                            t(
                                                'admin.referentiel.col.conditionalHelp',
                                            )
                                        "
                                        >{{
                                            t(
                                                'admin.referentiel.col.conditional',
                                            )
                                        }}</span
                                    >
                                    <span
                                        v-if="row.needs_review"
                                        class="rounded bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-medium text-amber-600 dark:text-amber-400"
                                        >{{
                                            t(
                                                'admin.referentiel.col.needsReview',
                                            )
                                        }}</span
                                    >
                                </div>
                            </td>
                            <td class="px-3 py-2 text-end">
                                <ActionButton
                                    action="edit"
                                    size="sm"
                                    :label="t('admin.referentiel.edit')"
                                    @click="open(row)"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </TableScroll>

            <div class="px-3 pb-3">
                <Pagination
                    :links="rows.links"
                    :from="rows.from"
                    :to="rows.to"
                    :total="rows.total"
                    :per-page="rows.per_page"
                />
            </div>
        </div>

        <AppDialog
            :open="editing !== null"
            @update:open="(o) => !o && (editing = null)"
            size="lg"
            :busy="saving"
        >
            <template #title>{{
                t('admin.referentiel.editDocument')
            }}</template>
            <template #description
                ><bdi>{{ editing?.title }}</bdi></template
            >
            <div class="grid gap-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <LockedField
                        :label="t('admin.referentiel.col.key')"
                        :value="editing?.document_key ?? null"
                        reason="documentKey"
                    />
                    <LockedField
                        :label="t('admin.referentiel.col.college')"
                        :value="editing?.college ?? null"
                        reason="documentCollege"
                    />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="title_ar" class="text-xs">{{
                            t('admin.referentiel.col.titleAr')
                        }}</Label>
                        <Input
                            id="title_ar"
                            v-model="form.title_ar"
                            dir="rtl"
                            class="h-9 text-sm"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="title_en" class="text-xs">{{
                            t('admin.referentiel.col.titleEn')
                        }}</Label>
                        <Input
                            id="title_en"
                            v-model="form.title_en"
                            dir="ltr"
                            class="h-9 text-sm"
                        />
                    </div>
                </div>

                <div class="space-y-1.5">
                    <Label class="text-xs">{{
                        t('admin.referentiel.col.extensions')
                    }}</Label>
                    <FormatMultiSelect
                        v-model="form.extensions"
                        :groups="formats"
                    />
                    <!-- Tightening a rule does not reach back in time, and
                         must not: a file already deposited was valid when
                         it was deposited. -->
                    <p
                        class="flex items-start gap-1.5 text-[11px] leading-relaxed text-muted-foreground"
                    >
                        <Info class="mt-0.5 size-3 shrink-0" />
                        {{ t('admin.referentiel.notRetroactive') }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="display_order" class="text-xs">{{
                            t('admin.referentiel.col.order')
                        }}</Label>
                        <Input
                            id="display_order"
                            v-model.number="form.display_order"
                            type="number"
                            min="1"
                            class="h-9 text-sm"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="max_size_kb" class="text-xs">{{
                            t('admin.referentiel.col.maxSize')
                        }}</Label>
                        <Input
                            id="max_size_kb"
                            v-model="form.max_size_kb"
                            type="number"
                            min="1"
                            :placeholder="t('admin.referentiel.noLimit')"
                            class="h-9 text-sm"
                        />
                    </div>
                </div>

                <div class="space-y-3 rounded-lg border border-border/80 p-3">
                    <label class="flex items-start gap-2.5 text-xs">
                        <Checkbox v-model="form.is_required" />
                        <span>
                            <span class="block font-medium">{{
                                t('admin.referentiel.col.required')
                            }}</span>
                            <span class="text-muted-foreground">{{
                                t('admin.referentiel.requiredHelp')
                            }}</span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2.5 text-xs">
                        <Checkbox v-model="form.allows_multiple" />
                        <span>
                            <span class="block font-medium">{{
                                t('admin.referentiel.col.multiple')
                            }}</span>
                            <span class="text-muted-foreground">{{
                                t('admin.referentiel.multipleHelp')
                            }}</span>
                        </span>
                    </label>
                </div>

                <!-- How many drafts this would block, asked before saving. -->
                <div
                    v-if="affectedDrafts !== null && affectedDrafts > 0"
                    class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-xs"
                >
                    <p
                        class="flex items-center gap-1.5 font-medium text-foreground"
                    >
                        <AlertTriangle
                            class="size-3.5 text-amber-600 dark:text-amber-400"
                        />
                        {{
                            t('admin.referentiel.requiredImpact', {
                                count: affectedDrafts,
                            })
                        }}
                    </p>
                </div>

                <p
                    v-if="editing?.needs_review"
                    class="rounded-lg border border-border/80 bg-muted/40 p-3 text-[11px] leading-relaxed text-muted-foreground"
                >
                    {{ t('admin.referentiel.needsReviewClears') }}
                </p>
            </div>
            <template #footer
                ><Button
                    variant="outline"
                    class="cursor-pointer"
                    :disabled="saving"
                    @click="editing = null"
                    >{{ t('admin.referentiel.cancel') }}</Button
                >
                <Button
                    class="cursor-pointer"
                    :disabled="saving"
                    @click="save"
                    >{{ t('admin.referentiel.save') }}</Button
                ></template
            >
        </AppDialog>

        <!-- Create — without this a new college could never be enabled. -->
        <CreateDialog
            v-model:open="creating"
            :title="t('admin.referentiel.createDocument')"
            :description="t('admin.referentiel.form.documentHint')"
            :saving="saving"
            @submit="create"
        >
            <div class="space-y-1.5">
                <Label for="new_doc_college" class="text-xs">{{
                    t('admin.referentiel.form.parentCollege')
                }}</Label>
                <AppCombobox
                    id="new_doc_college"
                    size="sm"
                    :model-value="createForm.college || null"
                    :options="collegeOptions"
                    :placeholder="t('admin.referentiel.form.choose')"
                    :clearable="false"
                    @update:model-value="
                        (value) => (createForm.college = String(value ?? ''))
                    "
                />
                <FieldError :error="errors.college" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1.5">
                    <Label for="new_doc_key" class="text-xs">{{
                        t('admin.referentiel.form.documentKey')
                    }}</Label>
                    <Input
                        id="new_doc_key"
                        v-model="createForm.document_key"
                        dir="ltr"
                        class="h-9 font-mono text-sm"
                        placeholder="justificatif_identite"
                    />
                    <FieldError :error="errors.document_key" />
                    <p class="text-[11px] text-muted-foreground">
                        {{ t('admin.referentiel.form.documentKeyHelp') }}
                    </p>
                </div>
                <div class="space-y-1.5">
                    <Label for="new_doc_title" class="text-xs">{{
                        t('admin.referentiel.col.title')
                    }}</Label>
                    <Input
                        id="new_doc_title"
                        v-model="createForm.title"
                        dir="auto"
                        class="h-9 text-sm"
                    />
                    <FieldError :error="errors.title" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1.5">
                    <Label for="new_doc_title_ar" class="text-xs">{{
                        t('admin.referentiel.col.titleAr')
                    }}</Label>
                    <Input
                        id="new_doc_title_ar"
                        v-model="createForm.title_ar"
                        dir="rtl"
                        class="h-9 text-sm"
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="new_doc_title_en" class="text-xs">{{
                        t('admin.referentiel.col.titleEn')
                    }}</Label>
                    <Input
                        id="new_doc_title_en"
                        v-model="createForm.title_en"
                        dir="ltr"
                        class="h-9 text-sm"
                    />
                </div>
            </div>

            <div class="space-y-1.5">
                <Label class="text-xs">{{
                    t('admin.referentiel.col.extensions')
                }}</Label>
                <FormatMultiSelect
                    v-model="createForm.extensions"
                    :groups="formats"
                />
                <FieldError :error="errors.extensions" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1.5">
                    <Label for="new_doc_order" class="text-xs">{{
                        t('admin.referentiel.col.order')
                    }}</Label>
                    <Input
                        id="new_doc_order"
                        v-model.number="createForm.display_order"
                        type="number"
                        min="1"
                        class="h-9 text-sm"
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="new_doc_max" class="text-xs">{{
                        t('admin.referentiel.col.maxSize')
                    }}</Label>
                    <Input
                        id="new_doc_max"
                        v-model="createForm.max_size_kb"
                        type="number"
                        min="1"
                        class="h-9 text-sm"
                        :placeholder="t('admin.referentiel.noLimit')"
                    />
                </div>
            </div>

            <div class="space-y-3 rounded-lg border border-border/80 p-3">
                <label class="flex items-start gap-2.5 text-xs">
                    <Checkbox v-model="createForm.is_required" />
                    <span class="font-medium">{{
                        t('admin.referentiel.col.required')
                    }}</span>
                </label>
                <label class="flex items-start gap-2.5 text-xs">
                    <Checkbox v-model="createForm.allows_multiple" />
                    <span class="font-medium">{{
                        t('admin.referentiel.col.multiple')
                    }}</span>
                </label>
            </div>
        </CreateDialog>
    </ReferentielShell>
</template>
