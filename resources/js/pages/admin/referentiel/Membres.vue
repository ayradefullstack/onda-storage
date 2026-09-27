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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { membres as membresRoute } from '@/routes/admin/referentiel';
import { update } from '@/routes/admin/referentiel/membres';

interface MemberRow {
    uuid: string;
    name: string;
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
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { search: string; college: number | null };
    colleges: Array<{ id: number; name: string }>;
}>();

const { t } = useI18n();

const editing = ref<MemberRow | null>(null);
const saving = ref(false);
const form = ref({
    status: 1,
    is_disabled: false,
    available_in_registration: true,
});

const open = (row: MemberRow) => {
    editing.value = row;
    form.value = {
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

    router.patch(update(editing.value.uuid).url, { ...form.value }, {
        preserveScroll: true,
        onFinish: () => {
            saving.value = false;
            editing.value = null;
        },
    });
};

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
                        :key="college.id"
                        :value="String(college.id)"
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
                            <th class="w-12 px-3 py-2.5 text-center font-medium">#</th>
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
                            <td class="w-12 px-3 py-2 text-center text-xs font-medium text-muted-foreground">
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
                                        v-if="!row.available_in_registration"
                                        class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
                                        >{{
                                            t('admin.referentiel.flags.internal')
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
