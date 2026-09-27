<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
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
import { types as typesRoute } from '@/routes/admin/referentiel';
import { update } from '@/routes/admin/referentiel/types';

interface TypeRow {
    uuid: string;
    name: string;
    name_ar: string | null;
    name_en: string | null;
    colleges_count: number;
    gestions_count: number;
    status: number;
    is_disabled: boolean;
}

defineProps<{
    tabs: Array<{ key: string; route: string; count: number }>;
    rows: {
        data: TypeRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { search: string };
}>();

const { t } = useI18n();

const editing = ref<TypeRow | null>(null);
const saving = ref(false);
const form = ref({ name_ar: '', name_en: '', status: 1, is_disabled: false });

const open = (row: TypeRow) => {
    editing.value = row;
    form.value = {
        name_ar: row.name_ar ?? '',
        name_en: row.name_en ?? '',
        status: row.status,
        is_disabled: row.is_disabled,
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
            name_ar: form.value.name_ar.trim() || null,
            name_en: form.value.name_en.trim() || null,
            status: form.value.status,
            is_disabled: form.value.is_disabled,
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
</script>

<template>
    <ReferentielShell
        :tabs="tabs"
        active="types"
        :index-url="typesRoute().url"
        :search="filters.search"
        :legend="['status', 'is_disabled']"
    >
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
                        <th class="w-12 px-3 py-2.5 text-center font-medium">#</th>
                        <th class="px-3 py-2.5 text-start font-medium">
                            {{ t('admin.referentiel.col.name') }}
                        </th>
                        <th class="px-3 py-2.5 text-end font-medium">
                            {{ t('admin.referentiel.col.gestions') }}
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
                        <td class="w-12 px-3 py-2 text-center text-xs font-medium text-muted-foreground">
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
                        <td
                            class="px-3 py-2 text-end text-muted-foreground"
                        >
                            <bdi>{{ row.gestions_count }}</bdi>
                        </td>
                        <td
                            class="px-3 py-2 text-end text-muted-foreground"
                        >
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
                                    v-if="row.is_disabled"
                                    class="rounded bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-medium text-amber-600 dark:text-amber-400"
                                    >{{
                                        t('admin.referentiel.flags.retired')
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
                        t('admin.referentiel.editType')
                    }}</DialogTitle>
                    <DialogDescription>
                        <bdi>{{ editing?.name }}</bdi>
                    </DialogDescription>
                </DialogHeader>

                <LockedField
                    :label="t('admin.referentiel.col.name')"
                    :value="editing?.name ?? null"
                    reason="typeName"
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="type_name_ar" class="text-xs">{{
                            t('admin.referentiel.col.nameAr')
                        }}</Label>
                        <Input
                            id="type_name_ar"
                            v-model="form.name_ar"
                            dir="rtl"
                            class="h-9 text-sm"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="type_name_en" class="text-xs">{{
                            t('admin.referentiel.col.nameEn')
                        }}</Label>
                        <Input
                            id="type_name_en"
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
