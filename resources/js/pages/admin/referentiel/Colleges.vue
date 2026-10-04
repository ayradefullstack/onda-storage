<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Pencil } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/admin/EmptyState.vue';
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
import { colleges as collegesRoute } from '@/routes/admin/referentiel';
import { update } from '@/routes/admin/referentiel/colleges';

interface CollegeRow {
    uuid: string;
    code_college: string | null;
    name: string;
    name_ar: string | null;
    name_en: string | null;
    type: string | null;
    gestion: string | null;
    members_count: number;
    documents_count: number;
    oeuvres_count: number;
    in_flight_oeuvres_count: number;
    status: number;
    is_disabled: boolean;
    adhesion: boolean;
    hidden_from_registration: boolean;
}

interface Option {
    id: number;
    name: string;
}

const props = defineProps<{
    tabs: Array<{ key: string; route: string; count: number }>;
    rows: {
        data: CollegeRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { search: string; type: number | null; gestion: number | null };
    types: Option[];
    gestions: Option[];
}>();

const { t } = useI18n();

const editing = ref<CollegeRow | null>(null);
const form = ref({
    name_ar: '',
    name_en: '',
    status: 1,
    is_disabled: false,
    adhesion: false,
});
const saving = ref(false);

const open = (row: CollegeRow) => {
    editing.value = row;
    form.value = {
        name_ar: row.name_ar ?? '',
        name_en: row.name_en ?? '',
        status: row.status,
        is_disabled: row.is_disabled,
        adhesion: row.adhesion,
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
            // Empty means "no translation", which falls back to the French
            // name — not an empty label.
            name_ar: form.value.name_ar.trim() || null,
            name_en: form.value.name_en.trim() || null,
            status: form.value.status,
            is_disabled: form.value.is_disabled,
            adhesion: form.value.adhesion,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
                editing.value = null;
            },
        },
    );
};

const applyFilter = (key: 'type' | 'gestion', value: string) => {
    router.get(
        collegesRoute().url,
        {
            ...(props.filters.search ? { search: props.filters.search } : {}),
            ...(props.filters.type && key !== 'type'
                ? { type: props.filters.type }
                : {}),
            ...(props.filters.gestion && key !== 'gestion'
                ? { gestion: props.filters.gestion }
                : {}),
            ...(value !== 'all' ? { [key]: value } : {}),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
</script>

<template>
    <ReferentielShell
        :tabs="tabs"
        active="colleges"
        :index-url="collegesRoute().url"
        :search="filters.search"
        :legend="['status', 'is_disabled']"
    >
        <template #filters>
            <Select
                :model-value="String(filters.type ?? 'all')"
                @update:model-value="applyFilter('type', String($event))"
            >
                <SelectTrigger class="h-9 w-48 text-sm">
                    <SelectValue
                        :placeholder="t('admin.referentiel.allTypes')"
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{{
                        t('admin.referentiel.allTypes')
                    }}</SelectItem>
                    <SelectItem
                        v-for="type in types"
                        :key="type.id"
                        :value="String(type.id)"
                        >{{ type.name }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="String(filters.gestion ?? 'all')"
                @update:model-value="applyFilter('gestion', String($event))"
            >
                <SelectTrigger class="h-9 w-48 text-sm">
                    <SelectValue
                        :placeholder="t('admin.referentiel.allGestions')"
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{{
                        t('admin.referentiel.allGestions')
                    }}</SelectItem>
                    <SelectItem
                        v-for="gestion in gestions"
                        :key="gestion.id"
                        :value="String(gestion.id)"
                        >{{ gestion.name }}</SelectItem
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
                                {{ t('admin.referentiel.col.code') }}
                            </th>
                            <th class="px-3 py-2.5 text-start font-medium">
                                {{ t('admin.referentiel.col.name') }}
                            </th>
                            <th
                                class="hidden px-3 py-2.5 text-start font-medium lg:table-cell"
                            >
                                {{ t('admin.referentiel.col.type') }}
                            </th>
                            <th
                                class="hidden px-3 py-2.5 text-start font-medium lg:table-cell"
                            >
                                {{ t('admin.referentiel.col.gestion') }}
                            </th>
                            <th class="px-3 py-2.5 text-end font-medium">
                                {{ t('admin.referentiel.col.members') }}
                            </th>
                            <th class="px-3 py-2.5 text-end font-medium">
                                {{ t('admin.referentiel.col.documents') }}
                            </th>
                            <th class="px-3 py-2.5 text-end font-medium">
                                {{ t('admin.referentiel.col.oeuvres') }}
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
                            <td
                                class="px-3 py-2 font-mono text-[11px] whitespace-nowrap text-muted-foreground"
                            >
                                <bdi>{{ row.code_college ?? '—' }}</bdi>
                            </td>
                            <td class="max-w-[18rem] px-3 py-2">
                                <bdi class="block truncate font-medium">{{
                                    row.name
                                }}</bdi>
                                <bdi
                                    v-if="row.name_ar"
                                    class="block truncate text-[11px] text-muted-foreground"
                                    >{{ row.name_ar }}</bdi
                                >
                            </td>
                            <td
                                class="hidden max-w-[10rem] px-3 py-2 lg:table-cell"
                            >
                                <bdi
                                    class="block truncate text-muted-foreground"
                                    >{{ row.type ?? '—' }}</bdi
                                >
                            </td>
                            <td
                                class="hidden max-w-[10rem] px-3 py-2 lg:table-cell"
                            >
                                <bdi
                                    class="block truncate text-muted-foreground"
                                    >{{ row.gestion ?? '—' }}</bdi
                                >
                            </td>
                            <td
                                class="px-3 py-2 text-end text-muted-foreground"
                            >
                                <bdi>{{ row.members_count }}</bdi>
                            </td>
                            <td
                                class="px-3 py-2 text-end text-muted-foreground"
                            >
                                <bdi>{{ row.documents_count }}</bdi>
                            </td>
                            <!-- The count that decides whether disabling is
                                 safe to do casually. -->
                            <td class="px-3 py-2 text-end">
                                <bdi
                                    :class="
                                        row.oeuvres_count > 0
                                            ? 'font-semibold text-foreground'
                                            : 'text-muted-foreground'
                                    "
                                    >{{ row.oeuvres_count }}</bdi
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
                                    <!-- REFERENTIEL_HORS_ADHESION and
                                         OEUVRE_FILM are never selectable
                                         whatever the flags say. -->
                                    <span
                                        v-if="row.hidden_from_registration"
                                        class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
                                        :title="
                                            t(
                                                'admin.referentiel.flags.hiddenHelp',
                                            )
                                        "
                                        >{{
                                            t('admin.referentiel.flags.hidden')
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
                />
            </div>
        </div>

        <Dialog
            :open="editing !== null"
            @update:open="(o) => !o && (editing = null)"
        >
            <DialogContent class="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{{
                        t('admin.referentiel.editCollege')
                    }}</DialogTitle>
                    <DialogDescription>
                        <bdi>{{ editing?.name }}</bdi>
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 sm:grid-cols-2">
                    <LockedField
                        :label="t('admin.referentiel.col.code')"
                        :value="editing?.code_college ?? null"
                        reason="codeCollege"
                    />
                    <LockedField
                        :label="t('admin.referentiel.col.type')"
                        :value="editing?.type ?? null"
                        reason="registerType"
                    />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="name_ar" class="text-xs">{{
                            t('admin.referentiel.col.nameAr')
                        }}</Label>
                        <Input
                            id="name_ar"
                            v-model="form.name_ar"
                            dir="rtl"
                            class="h-9 text-sm"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="name_en" class="text-xs">{{
                            t('admin.referentiel.col.nameEn')
                        }}</Label>
                        <Input
                            id="name_en"
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
                        <Checkbox v-model="form.adhesion" />
                        <span>
                            <span class="block font-medium">{{
                                t('admin.referentiel.flags.adhesion')
                            }}</span>
                            <span class="text-muted-foreground">{{
                                t('admin.referentiel.flags.adhesionHelp')
                            }}</span>
                        </span>
                    </label>
                </div>

                <!-- Retiring a college with deposits under it. It breaks
                     nothing — existing oeuvres keep their classification
                     and stay reviewable — but the officer should see the
                     number, and especially the in-flight one. -->
                <div
                    v-if="
                        editing &&
                        form.is_disabled &&
                        !editing.is_disabled &&
                        editing.oeuvres_count > 0
                    "
                    class="space-y-1.5 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-xs"
                >
                    <p
                        class="flex items-center gap-1.5 font-medium text-foreground"
                    >
                        <AlertTriangle
                            class="size-3.5 text-amber-600 dark:text-amber-400"
                        />
                        {{
                            t('admin.referentiel.disableWarning.title', {
                                count: editing.oeuvres_count,
                            })
                        }}
                    </p>
                    <p
                        v-if="editing.in_flight_oeuvres_count > 0"
                        class="font-medium text-amber-700 dark:text-amber-300"
                    >
                        {{
                            t('admin.referentiel.disableWarning.inFlight', {
                                count: editing.in_flight_oeuvres_count,
                            })
                        }}
                    </p>
                    <p class="text-muted-foreground">
                        {{ t('admin.referentiel.disableWarning.safe') }}
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
