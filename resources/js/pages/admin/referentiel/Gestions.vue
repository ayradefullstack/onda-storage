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
import { update } from '@/routes/admin/referentiel/gestions';

interface GestionRow {
    uuid: string;
    name: string;
    name_ar: string | null;
    name_en: string | null;
    type_gestion: number;
    type: string | null;
    colleges_count: number;
}

defineProps<{
    tabs: Array<{ key: string; route: string; count: number }>;
    rows: {
        data: GestionRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { search: string };
}>();

const { t } = useI18n();

const editing = ref<GestionRow | null>(null);
const saving = ref(false);
const form = ref({ name_ar: '', name_en: '' });

const open = (row: GestionRow) => {
    editing.value = row;
    form.value = { name_ar: row.name_ar ?? '', name_en: row.name_en ?? '' };
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
        active="gestions"
        :index-url="gestionsRoute().url"
        :search="filters.search"
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
                        <th class="px-3 py-2.5 text-start font-medium">
                            {{ t('admin.referentiel.col.type') }}
                        </th>
                        <th class="px-3 py-2.5 text-end font-medium">
                            {{ t('admin.referentiel.col.colleges') }}
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
                        <td class="px-3 py-2">
                            <bdi class="text-muted-foreground">{{
                                row.type ?? '—'
                            }}</bdi>
                        </td>
                        <td
                            class="px-3 py-2 text-end text-muted-foreground"
                        >
                            <bdi>{{ row.colleges_count }}</bdi>
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
