<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Plus } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import CreateDialog from '@/components/admin/CreateDialog.vue';
import EmptyState from '@/components/admin/EmptyState.vue';
import FieldError from '@/components/admin/FieldError.vue';
import LockedField from '@/components/admin/LockedField.vue';
import Pagination from '@/components/admin/Pagination.vue';
import ReferentielShell from '@/components/admin/ReferentielShell.vue';
import { ActionButton } from '@/components/ui/action';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { membres as membresRoute } from '@/routes/admin/referentiel';
import { store, update } from '@/routes/admin/referentiel/membres';

interface MemberRow {
    uuid: string;
    name: string;
    name_ar: string | null;
    name_en: string | null;
    is_system: boolean;
    oeuvres_count: number;
    code_qlt: string | null;
    college: string | null;
    code_college: string | null;
    status: number;
    is_disabled: boolean;
    available_in_registration: boolean;
}

const props = defineProps<{
    tabs: Array<{ key: string; route: string; count: number }>;
    rows: {
        data: MemberRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        per_page: number;
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { search: string; college: string | null };
    colleges: Array<{ uuid: string; name: string }>;
}>();

const { t } = useI18n();

const editing = ref<MemberRow | null>(null);
const saving = ref(false);
const errors = ref<Record<string, string>>({});
const form = ref({
    name: '',
    name_ar: '',
    name_en: '',
    status: 1,
    is_disabled: false,
    available_in_registration: true,
});

const open = (row: MemberRow) => {
    editing.value = row;
    errors.value = {};
    form.value = {
        name: row.name,
        name_ar: row.name_ar ?? '',
        name_en: row.name_en ?? '',
        status: row.status,
        is_disabled: row.is_disabled,
        available_in_registration: row.available_in_registration,
    };
};

const save = () => {
    if (editing.value === null) {
        return;
    }

    saving.value = true;

    router.patch(
        update(editing.value.uuid).url,
        {
            // A system row's name is a seeder match key: not sent at all.
            ...(editing.value.is_system ? {} : { name: form.value.name }),
            name_ar: form.value.name_ar.trim() || null,
            name_en: form.value.name_en.trim() || null,
            status: form.value.status,
            is_disabled: form.value.is_disabled,
            available_in_registration: form.value.available_in_registration,
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
    name: '',
    name_ar: '',
    name_en: '',
    code_qlt: '',
    available_in_registration: true,
    status: 1,
});

const openCreate = () => {
    createForm.value = {
        college: props.filters.college ?? '',
        name: '',
        name_ar: '',
        name_en: '',
        code_qlt: '',
        available_in_registration: true,
        status: 1,
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
            name: createForm.value.name,
            name_ar: createForm.value.name_ar.trim() || null,
            name_en: createForm.value.name_en.trim() || null,
            code_qlt: createForm.value.code_qlt.trim() || null,
            available_in_registration:
                createForm.value.available_in_registration,
            status: createForm.value.status,
        },
        {
            preserveScroll: true,
            onError: (e) => (errors.value = e),
            onSuccess: () => (creating.value = false),
            onFinish: () => (saving.value = false),
        },
    );
};

// Retiring a qualité hides it from step 1; the oeuvres already classified
// under it keep it. The count is what the officer should see.
const hidesMember = () =>
    editing.value !== null &&
    editing.value.oeuvres_count > 0 &&
    ((form.value.is_disabled && !editing.value.is_disabled) ||
        (form.value.status !== 1 && editing.value.status === 1) ||
        (!form.value.available_in_registration &&
            editing.value.available_in_registration));

const applyCollege = (value: string) => {
    router.get(
        membresRoute().url,
        {
            ...(props.filters.search ? { search: props.filters.search } : {}),
            ...(value !== 'all' ? { college: value } : {}),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
</script>

<template>
    <ReferentielShell
        :tabs="tabs"
        active="membres"
        :index-url="membresRoute().url"
        :search="filters.search"
        :legend="['status', 'is_disabled', 'available_in_registration']"
    >
        <template #actions>
            <Button class="cursor-pointer" @click="openCreate">
                <Plus class="size-4" />
                {{ t('admin.referentiel.createMember') }}
            </Button>
        </template>

        <template #filters>
            <Select
                :model-value="String(filters.college ?? 'all')"
                @update:model-value="applyCollege(String($event))"
            >
                <SelectTrigger class="h-9 w-56 text-sm">
                    <SelectValue
                        :placeholder="t('admin.referentiel.allColleges')"
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{{
                        t('admin.referentiel.allColleges')
                    }}</SelectItem>
                    <SelectItem
                        v-for="college in colleges"
                        :key="college.uuid"
                        :value="college.uuid"
                        >{{ college.name }}</SelectItem
                    >
                </SelectContent>
            </Select>
        </template>

        <EmptyState
            v-if="rows.data.length === 0"
            :title="t('admin.referentiel.empty')"
            :description="t('admin.referentiel.emptyDescription')"
        />

        <div
            v-else
            class="overflow-hidden rounded-lg border border-border bg-card"
        >
            <div class="overflow-x-auto">
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
                                {{ t('admin.referentiel.col.name') }}
                            </th>
                            <th class="px-3 py-2.5 text-start font-medium">
                                {{ t('admin.referentiel.col.codeQlt') }}
                            </th>
                            <th
                                class="hidden px-3 py-2.5 text-start font-medium lg:table-cell"
                            >
                                {{ t('admin.referentiel.col.college') }}
                            </th>
                            <th class="px-3 py-2.5 text-start font-medium">
                                {{ t('admin.referentiel.flags.status') }}
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
                            class="transition-colors hover:bg-accent/30"
                        >
                            <td
                                class="w-12 px-3 py-2 text-center text-xs font-medium text-muted-foreground"
                            >
                                {{ (rows.from ?? 1) + index }}
                            </td>
                            <td class="max-w-[20rem] px-3 py-2">
                                <bdi class="block truncate font-medium">{{
                                    row.name
                                }}</bdi>
                            </td>
                            <td
                                class="px-3 py-2 font-mono text-[11px] whitespace-nowrap text-muted-foreground"
                            >
                                <bdi>{{ row.code_qlt ?? '—' }}</bdi>
                            </td>
                            <td
                                class="hidden max-w-[14rem] px-3 py-2 lg:table-cell"
                            >
                                <bdi
                                    class="block truncate text-muted-foreground"
                                    >{{ row.college ?? '—' }}</bdi
                                >
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <div class="flex flex-wrap items-center gap-1">
                                    <span
                                        :class="[
                                            'rounded px-1.5 py-0.5 text-[10px] font-medium',
                                            row.status === 1
                                                ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                                : 'bg-muted text-muted-foreground',
                                        ]"
                                        >{{
                                            row.status === 1
                                                ? t(
                                                      'admin.referentiel.flags.statusOn',
                                                  )
                                                : t(
                                                      'admin.referentiel.flags.statusOff',
                                                  )
                                        }}</span
                                    >
                                    <span
                                        v-if="row.is_disabled"
                                        class="rounded bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-medium text-amber-600 dark:text-amber-400"
                                        >{{
                                            t('admin.referentiel.flags.retired')
                                        }}</span
                                    >
                                    <span
                                        v-if="!row.is_system"
                                        class="rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-medium text-primary"
                                        >{{
                                            t('admin.referentiel.badge.custom')
                                        }}</span
                                    >
                                    <span
                                        v-if="!row.available_in_registration"
                                        class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
                                        >{{
                                            t(
                                                'admin.referentiel.flags.internal',
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
            </div>

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

        <Dialog
            :open="editing !== null"
            @update:open="(o) => !o && (editing = null)"
        >
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{
                        t('admin.referentiel.editMember')
                    }}</DialogTitle>
                    <DialogDescription>
                        <bdi>{{ editing?.name }}</bdi>
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 sm:grid-cols-2">
                    <LockedField
                        :label="t('admin.referentiel.col.codeQlt')"
                        :value="editing?.code_qlt ?? null"
                        reason="codeQlt"
                    />
                    <LockedField
                        :label="t('admin.referentiel.col.college')"
                        :value="editing?.college ?? null"
                        reason="memberCollege"
                    />
                </div>

                <LockedField
                    v-if="editing?.is_system"
                    :label="t('admin.referentiel.col.name')"
                    :value="editing?.name ?? null"
                    reason="systemName"
                />
                <div v-else class="space-y-1.5">
                    <Label for="member_name" class="text-xs">{{
                        t('admin.referentiel.col.name')
                    }}</Label>
                    <Input
                        id="member_name"
                        v-model="form.name"
                        dir="auto"
                        class="h-9 text-sm"
                    />
                    <FieldError :error="errors.name" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="member_name_ar" class="text-xs">{{
                            t('admin.referentiel.col.nameAr')
                        }}</Label>
                        <Input
                            id="member_name_ar"
                            v-model="form.name_ar"
                            dir="rtl"
                            class="h-9 text-sm"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="member_name_en" class="text-xs">{{
                            t('admin.referentiel.col.nameEn')
                        }}</Label>
                        <Input
                            id="member_name_en"
                            v-model="form.name_en"
                            dir="ltr"
                            class="h-9 text-sm"
                        />
                    </div>
                </div>

                <div class="space-y-3 rounded-lg border border-border/80 p-3">
                    <label class="flex items-start gap-2.5 text-xs">
                        <Checkbox
                            :model-value="form.status === 1"
                            @update:model-value="form.status = $event ? 1 : 0"
                        />
                        <span>
                            <span class="block font-medium">{{
                                t('admin.referentiel.flags.status')
                            }}</span>
                            <span class="text-muted-foreground">{{
                                t('admin.referentiel.flags.statusHelp')
                            }}</span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2.5 text-xs">
                        <Checkbox v-model="form.is_disabled" />
                        <span>
                            <span class="block font-medium">{{
                                t('admin.referentiel.flags.is_disabled')
                            }}</span>
                            <span class="text-muted-foreground">{{
                                t('admin.referentiel.flags.is_disabledHelp')
                            }}</span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2.5 text-xs">
                        <Checkbox v-model="form.available_in_registration" />
                        <span>
                            <span class="block font-medium">{{
                                t(
                                    'admin.referentiel.flags.available_in_registration',
                                )
                            }}</span>
                            <span class="text-muted-foreground">{{
                                t(
                                    'admin.referentiel.flags.available_in_registrationHelp',
                                )
                            }}</span>
                        </span>
                    </label>
                </div>

                <div
                    v-if="hidesMember()"
                    class="space-y-1.5 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-xs"
                >
                    <p
                        class="flex items-center gap-1.5 font-medium text-foreground"
                    >
                        <AlertTriangle
                            class="size-3.5 text-amber-600 dark:text-amber-400"
                        />
                        {{
                            t(
                                'admin.referentiel.blast.member',
                                { count: editing?.oeuvres_count ?? 0 },
                                editing?.oeuvres_count ?? 0,
                            )
                        }}
                    </p>
                    <p class="text-muted-foreground">
                        {{ t('admin.referentiel.blast.safe') }}
                    </p>
                </div>

                <DialogFooter class="gap-2 sm:gap-2">
                    <Button
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
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Create -->
        <CreateDialog
            v-model:open="creating"
            :title="t('admin.referentiel.createMember')"
            :saving="saving"
            @submit="create"
        >
            <div class="space-y-1.5">
                <Label for="new_member_college" class="text-xs">{{
                    t('admin.referentiel.form.parentCollege')
                }}</Label>
                <select
                    id="new_member_college"
                    v-model="createForm.college"
                    class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                >
                    <option value="" disabled>
                        {{ t('admin.referentiel.form.choose') }}
                    </option>
                    <option
                        v-for="college in colleges"
                        :key="college.uuid"
                        :value="college.uuid"
                    >
                        {{ college.name }}
                    </option>
                </select>
                <FieldError :error="errors.college" />
            </div>

            <div class="space-y-1.5">
                <Label for="new_member_name" class="text-xs">{{
                    t('admin.referentiel.col.name')
                }}</Label>
                <Input
                    id="new_member_name"
                    v-model="createForm.name"
                    dir="auto"
                    class="h-9 text-sm"
                />
                <FieldError :error="errors.name" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1.5">
                    <Label for="new_member_name_ar" class="text-xs">{{
                        t('admin.referentiel.col.nameAr')
                    }}</Label>
                    <Input
                        id="new_member_name_ar"
                        v-model="createForm.name_ar"
                        dir="rtl"
                        class="h-9 text-sm"
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="new_member_name_en" class="text-xs">{{
                        t('admin.referentiel.col.nameEn')
                    }}</Label>
                    <Input
                        id="new_member_name_en"
                        v-model="createForm.name_en"
                        dir="ltr"
                        class="h-9 text-sm"
                    />
                </div>
            </div>

            <div class="space-y-1.5">
                <Label for="new_member_code" class="text-xs">{{
                    t('admin.referentiel.form.codeQlt')
                }}</Label>
                <Input
                    id="new_member_code"
                    v-model="createForm.code_qlt"
                    dir="ltr"
                    class="h-9 font-mono text-sm"
                />
                <FieldError :error="errors.code_qlt" />
                <p class="text-[11px] text-muted-foreground">
                    {{ t('admin.referentiel.form.codeQltHelp') }}
                </p>
            </div>

            <div class="space-y-3 rounded-lg border border-border/80 p-3">
                <label class="flex items-start gap-2.5 text-xs">
                    <Checkbox
                        :model-value="createForm.status === 1"
                        @update:model-value="createForm.status = $event ? 1 : 0"
                    />
                    <span>
                        <span class="block font-medium">{{
                            t('admin.referentiel.flags.status')
                        }}</span>
                        <span class="text-muted-foreground">{{
                            t('admin.referentiel.flags.statusHelp')
                        }}</span>
                    </span>
                </label>
                <label class="flex items-start gap-2.5 text-xs">
                    <Checkbox v-model="createForm.available_in_registration" />
                    <span>
                        <span class="block font-medium">{{
                            t(
                                'admin.referentiel.flags.available_in_registration',
                            )
                        }}</span>
                        <span class="text-muted-foreground">{{
                            t(
                                'admin.referentiel.flags.available_in_registrationHelp',
                            )
                        }}</span>
                    </span>
                </label>
            </div>
        </CreateDialog>
    </ReferentielShell>
</template>
