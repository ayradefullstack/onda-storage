<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
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
import { gestions as gestionsRoute } from '@/routes/admin/referentiel';
import { store, update } from '@/routes/admin/referentiel/gestions';

interface GestionRow {
    uuid: string;
    name: string;
    name_ar: string | null;
    name_en: string | null;
    type_gestion: number;
    type: string | null;
    colleges_count: number;
    reachable_colleges_count: number;
    status: number;
    is_system: boolean;
}

interface TypeOption {
    uuid: string;
    name: string;
    colleges_count: number;
    has_gestions: boolean;
}

const props = defineProps<{
    tabs: Array<{ key: string; route: string; count: number }>;
    rows: {
        data: GestionRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        per_page: number;
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { search: string };
    types: TypeOption[];
}>();

const { t } = useI18n();

const editing = ref<GestionRow | null>(null);
const creating = ref(false);
const saving = ref(false);
const errors = ref<Record<string, string>>({});
const form = ref({
    register_type: '',
    name: '',
    name_ar: '',
    name_en: '',
    status: 1,
});

const chosenType = computed(() =>
    props.types.find((type) => type.uuid === form.value.register_type),
);

// The first active gestion a type gets switches its classification flow from
// three levels to four. When that type already has colleges, none of them
// has a gestion — so they stop being offered until they are reached another
// way. The form says so before it is submitted.
const changesFlow = computed(
    () => chosenType.value !== undefined && !chosenType.value.has_gestions,
);

const openCreate = () => {
    form.value = {
        register_type: '',
        name: '',
        name_ar: '',
        name_en: '',
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
            register_type: form.value.register_type,
            name: form.value.name,
            name_ar: form.value.name_ar.trim() || null,
            name_en: form.value.name_en.trim() || null,
            status: form.value.status,
        },
        {
            preserveScroll: true,
            onError: (e) => (errors.value = e),
            onSuccess: () => (creating.value = false),
            onFinish: () => (saving.value = false),
        },
    );
};

const open = (row: GestionRow) => {
    editing.value = row;
    errors.value = {};
    form.value = {
        register_type: '',
        name: row.name,
        name_ar: row.name_ar ?? '',
        name_en: row.name_en ?? '',
        status: row.status,
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
            ...(editing.value.is_system ? {} : { name: form.value.name }),
            name_ar: form.value.name_ar.trim() || null,
            name_en: form.value.name_en.trim() || null,
            status: form.value.status,
        },
        {
            preserveScroll: true,
            onError: (e) => (errors.value = e),
            onSuccess: () => (editing.value = null),
            onFinish: () => (saving.value = false),
        },
    );
};

const hidesColleges = () =>
    editing.value !== null &&
    editing.value.reachable_colleges_count > 0 &&
    form.value.status !== 1 &&
    editing.value.status === 1;
</script>

<template>
    <ReferentielShell
        :tabs="tabs"
        active="gestions"
        :index-url="gestionsRoute().url"
        :search="filters.search"
        :legend="['status']"
    >
        <template #actions>
            <Button class="cursor-pointer" @click="openCreate">
                <Plus class="size-4" />
                {{ t('admin.referentiel.createGestion') }}
            </Button>
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
            <table class="w-full border-collapse text-xs">
                <thead>
                    <tr
                        class="border-b border-border bg-muted/40 text-muted-foreground"
                    >
                        <th class="w-12 px-3 py-2.5 text-center font-medium">
                            #
                        </th>
                        <th class="px-3 py-2.5 text-start font-medium">
                            {{ t('admin.referentiel.col.name') }}
                        </th>
                        <th class="px-3 py-2.5 text-start font-medium">
                            {{ t('admin.referentiel.col.type') }}
                        </th>
                        <th class="px-3 py-2.5 text-end font-medium">
                            {{ t('admin.referentiel.col.colleges') }}
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
                        <td class="px-3 py-2">
                            <bdi class="block font-medium">{{ row.name }}</bdi>
                            <bdi
                                v-if="row.name_ar"
                                class="block text-[11px] text-muted-foreground"
                                >{{ row.name_ar }}</bdi
                            >
                        </td>
                        <td class="px-3 py-2">
                            <bdi class="text-muted-foreground">{{
                                row.type ?? '—'
                            }}</bdi>
                        </td>
                        <td class="px-3 py-2 text-end text-muted-foreground">
                            <bdi>{{ row.colleges_count }}</bdi>
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
                                    v-if="!row.is_system"
                                    class="rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-medium text-primary"
                                    >{{
                                        t('admin.referentiel.badge.custom')
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

        <!-- Create -->
        <CreateDialog
            v-model:open="creating"
            :title="t('admin.referentiel.createGestion')"
            :saving="saving"
            @submit="create"
        >
            <div class="space-y-1.5">
                <Label for="new_gestion_type" class="text-xs">{{
                    t('admin.referentiel.form.parentType')
                }}</Label>
                <select
                    id="new_gestion_type"
                    v-model="form.register_type"
                    class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                >
                    <option value="" disabled>
                        {{ t('admin.referentiel.form.choose') }}
                    </option>
                    <option
                        v-for="type in types"
                        :key="type.uuid"
                        :value="type.uuid"
                    >
                        {{ type.name }}
                    </option>
                </select>
                <FieldError :error="errors.register_type" />
            </div>

            <div
                v-if="changesFlow"
                class="space-y-1 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-xs"
            >
                <p
                    class="flex items-center gap-1.5 font-medium text-foreground"
                >
                    <AlertTriangle
                        class="size-3.5 text-amber-600 dark:text-amber-400"
                    />
                    {{ t('admin.referentiel.hint.gestionFlow') }}
                </p>
                <p
                    v-if="(chosenType?.colleges_count ?? 0) > 0"
                    class="text-muted-foreground"
                >
                    {{
                        t(
                            'admin.referentiel.hint.gestionFlowColleges',
                            { count: chosenType?.colleges_count ?? 0 },
                            chosenType?.colleges_count ?? 0,
                        )
                    }}
                </p>
            </div>

            <div class="space-y-1.5">
                <Label for="new_gestion_name" class="text-xs">{{
                    t('admin.referentiel.col.name')
                }}</Label>
                <Input
                    id="new_gestion_name"
                    v-model="form.name"
                    dir="auto"
                    class="h-9 text-sm"
                />
                <FieldError :error="errors.name" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1.5">
                    <Label for="new_gestion_name_ar" class="text-xs">{{
                        t('admin.referentiel.col.nameAr')
                    }}</Label>
                    <Input
                        id="new_gestion_name_ar"
                        v-model="form.name_ar"
                        dir="rtl"
                        class="h-9 text-sm"
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="new_gestion_name_en" class="text-xs">{{
                        t('admin.referentiel.col.nameEn')
                    }}</Label>
                    <Input
                        id="new_gestion_name_en"
                        v-model="form.name_en"
                        dir="ltr"
                        class="h-9 text-sm"
                    />
                </div>
            </div>

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
                        t('admin.referentiel.flags.gestionStatusHelp')
                    }}</span>
                </span>
            </label>
        </CreateDialog>

        <!-- Edit -->
        <Dialog
            :open="editing !== null"
            @update:open="(o) => !o && (editing = null)"
        >
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{
                        t('admin.referentiel.editGestion')
                    }}</DialogTitle>
                    <DialogDescription>
                        <bdi>{{ editing?.name }}</bdi>
                    </DialogDescription>
                </DialogHeader>

                <LockedField
                    :label="t('admin.referentiel.col.gestionValue')"
                    :value="editing?.type_gestion ?? null"
                    reason="typeGestionValue"
                />

                <LockedField
                    v-if="editing?.is_system"
                    :label="t('admin.referentiel.col.name')"
                    :value="editing?.name ?? null"
                    reason="systemName"
                />
                <div v-else class="space-y-1.5">
                    <Label for="gestion_name" class="text-xs">{{
                        t('admin.referentiel.col.name')
                    }}</Label>
                    <Input
                        id="gestion_name"
                        v-model="form.name"
                        dir="auto"
                        class="h-9 text-sm"
                    />
                    <FieldError :error="errors.name" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="gestion_name_ar" class="text-xs">{{
                            t('admin.referentiel.col.nameAr')
                        }}</Label>
                        <Input
                            id="gestion_name_ar"
                            v-model="form.name_ar"
                            dir="rtl"
                            class="h-9 text-sm"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="gestion_name_en" class="text-xs">{{
                            t('admin.referentiel.col.nameEn')
                        }}</Label>
                        <Input
                            id="gestion_name_en"
                            v-model="form.name_en"
                            dir="ltr"
                            class="h-9 text-sm"
                        />
                    </div>
                </div>

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
                            t('admin.referentiel.flags.gestionStatusHelp')
                        }}</span>
                    </span>
                </label>

                <div
                    v-if="hidesColleges()"
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
                                'admin.referentiel.blast.gestion',
                                {
                                    count:
                                        editing?.reachable_colleges_count ?? 0,
                                },
                                editing?.reachable_colleges_count ?? 0,
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
    </ReferentielShell>
</template>
