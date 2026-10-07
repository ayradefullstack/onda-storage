<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
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
import { makeDefault, store, update } from '@/routes/admin/languages';

interface LanguageRow {
    code: string;
    name: string;
    native_name: string;
    direction: 'ltr' | 'rtl';
    is_default: boolean;
    is_active: boolean;
    sort_order: number;
    has_bundle: boolean;
}

defineProps<{ languages: LanguageRow[] }>();

const { t } = useI18n();

type FormState = {
    code: string;
    name: string;
    native_name: string;
    direction: 'ltr' | 'rtl';
    is_active: boolean;
    sort_order: number;
};

const blank = (): FormState => ({
    code: '',
    name: '',
    native_name: '',
    direction: 'ltr',
    is_active: true,
    sort_order: 0,
});

// `null` = closed, `'new'` = creating, otherwise the language being edited.
const dialog = ref<LanguageRow | 'new' | null>(null);
const form = ref<FormState>(blank());
const errors = ref<Record<string, string>>({});
const saving = ref(false);

const creating = computed(() => dialog.value === 'new');
const editing = computed(() =>
    dialog.value === null || dialog.value === 'new' ? null : dialog.value,
);

// Server errors are either an `admin.languages.error.*` key (a rule the
// service refused) or a plain Laravel validation message.
const message = (error: string | undefined) =>
    error?.startsWith('admin.languages.') ? t(error) : error;

const openCreate = () => {
    form.value = blank();
    errors.value = {};
    dialog.value = 'new';
};

const openEdit = (row: LanguageRow) => {
    form.value = {
        code: row.code,
        name: row.name,
        native_name: row.native_name,
        direction: row.direction,
        is_active: row.is_active,
        sort_order: row.sort_order,
    };
    errors.value = {};
    dialog.value = row;
};

const close = () => (dialog.value = null);

const onError = (e: Record<string, string>) => (errors.value = e);

const save = () => {
    saving.value = true;

    const options = {
        preserveScroll: true,
        onError,
        onSuccess: close,
        onFinish: () => (saving.value = false),
    };

    if (editing.value) {
        // `code` is immutable once created, so it is never sent on edit.
        const { name, native_name, direction, is_active, sort_order } =
            form.value;
        router.patch(
            update(editing.value.code).url,
            { name, native_name, direction, is_active, sort_order },
            options,
        );
    } else {
        router.post(store().url, form.value, options);
    }
};

const setDefault = (row: LanguageRow) =>
    router.post(makeDefault(row.code).url, {}, { preserveScroll: true });

const toggle = (row: LanguageRow) =>
    router.patch(
        update(row.code).url,
        { is_active: !row.is_active },
        { preserveScroll: true, onError },
    );
</script>

<template>
    <Head :title="t('admin.languages.title')" />

    <div class="space-y-4 p-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">
                    {{ t('admin.languages.title') }}
                </h1>
                <p class="text-xs text-muted-foreground">
                    {{ t('admin.languages.subtitle') }}
                </p>
            </div>
            <Button class="cursor-pointer" @click="openCreate">
                <Plus class="size-4" />
                {{ t('admin.languages.add') }}
            </Button>
        </div>

        <p
            v-if="errors.language && dialog === null"
            class="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-destructive"
            role="alert"
        >
            {{ message(errors.language) }}
        </p>

        <div class="overflow-hidden rounded-lg border border-border bg-card">
            <table class="w-full border-collapse text-xs">
                <thead>
                    <tr
                        class="border-b border-border bg-muted/40 text-muted-foreground"
                    >
                        <th class="px-3 py-2.5 text-start font-medium">
                            {{ t('admin.languages.col.language') }}
                        </th>
                        <th class="px-3 py-2.5 text-start font-medium">
                            {{ t('admin.languages.col.code') }}
                        </th>
                        <th class="px-3 py-2.5 text-start font-medium">
                            {{ t('admin.languages.col.direction') }}
                        </th>
                        <th class="px-3 py-2.5 text-start font-medium">
                            {{ t('admin.languages.col.status') }}
                        </th>
                        <th class="px-3 py-2.5 text-end font-medium">
                            {{ t('admin.languages.col.actions') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/70">
                    <tr
                        v-for="row in languages"
                        :key="row.code"
                        class="transition-colors hover:bg-accent/30"
                    >
                        <td class="px-3 py-2">
                            <bdi class="block font-medium">{{
                                row.native_name
                            }}</bdi>
                            <bdi
                                class="block text-[11px] text-muted-foreground"
                                >{{ row.name }}</bdi
                            >
                        </td>
                        <td class="px-3 py-2 font-mono">
                            <bdi>{{ row.code }}</bdi>
                        </td>
                        <td class="px-3 py-2 uppercase">
                            <bdi>{{ row.direction }}</bdi>
                        </td>
                        <td class="space-x-1 px-3 py-2 rtl:space-x-reverse">
                            <span
                                v-if="row.is_default"
                                class="rounded bg-primary/10 px-1.5 py-0.5 text-[11px] font-medium text-primary"
                                >{{ t('admin.languages.default') }}</span
                            >
                            <span
                                class="rounded px-1.5 py-0.5 text-[11px] font-medium"
                                :class="
                                    row.is_active
                                        ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                        : 'bg-muted text-muted-foreground'
                                "
                                >{{
                                    row.is_active
                                        ? t('admin.languages.active')
                                        : t('admin.languages.inactive')
                                }}</span
                            >
                            <span
                                v-if="!row.has_bundle"
                                class="rounded bg-amber-500/10 px-1.5 py-0.5 text-[11px] font-medium text-amber-700 dark:text-amber-300"
                                :title="t('admin.languages.noBundleHint')"
                                >{{ t('admin.languages.noBundle') }}</span
                            >
                        </td>
                        <td
                            class="space-x-1 px-3 py-2 text-end rtl:space-x-reverse"
                        >
                            <Button
                                v-if="!row.is_default && row.is_active"
                                variant="outline"
                                size="sm"
                                class="cursor-pointer"
                                @click="setDefault(row)"
                                >{{ t('admin.languages.makeDefault') }}</Button
                            >
                            <Button
                                v-if="!row.is_default"
                                variant="outline"
                                size="sm"
                                class="cursor-pointer"
                                @click="toggle(row)"
                                >{{
                                    row.is_active
                                        ? t('admin.languages.deactivate')
                                        : t('admin.languages.activate')
                                }}</Button
                            >
                            <ActionButton
                                action="edit"
                                size="sm"
                                :label="t('admin.languages.edit')"
                                @click="openEdit(row)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Dialog :open="dialog !== null" @update:open="(o) => !o && close()">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{
                        creating
                            ? t('admin.languages.add')
                            : t('admin.languages.edit')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        t('admin.languages.dialogHint')
                    }}</DialogDescription>
                </DialogHeader>

                <p
                    v-if="errors.language"
                    class="text-xs text-destructive"
                    role="alert"
                >
                    {{ message(errors.language) }}
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="lang_code" class="text-xs">{{
                            t('admin.languages.col.code')
                        }}</Label>
                        <Input
                            id="lang_code"
                            v-model="form.code"
                            :disabled="!creating"
                            dir="ltr"
                            placeholder="es, pt-br"
                            class="h-9 text-sm"
                        />
                        <p v-if="errors.code" class="text-xs text-destructive">
                            {{ message(errors.code) }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="lang_direction" class="text-xs">{{
                            t('admin.languages.col.direction')
                        }}</Label>
                        <select
                            id="lang_direction"
                            v-model="form.direction"
                            class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                        >
                            <option value="ltr">LTR</option>
                            <option value="rtl">RTL</option>
                        </select>
                        <p
                            v-if="errors.direction"
                            class="text-xs text-destructive"
                        >
                            {{ message(errors.direction) }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="lang_name" class="text-xs">{{
                            t('admin.languages.name')
                        }}</Label>
                        <Input
                            id="lang_name"
                            v-model="form.name"
                            dir="ltr"
                            class="h-9 text-sm"
                        />
                        <p v-if="errors.name" class="text-xs text-destructive">
                            {{ message(errors.name) }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="lang_native" class="text-xs">{{
                            t('admin.languages.nativeName')
                        }}</Label>
                        <Input
                            id="lang_native"
                            v-model="form.native_name"
                            :dir="form.direction"
                            class="h-9 text-sm"
                        />
                        <p
                            v-if="errors.native_name"
                            class="text-xs text-destructive"
                        >
                            {{ message(errors.native_name) }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="lang_sort" class="text-xs">{{
                            t('admin.languages.sortOrder')
                        }}</Label>
                        <Input
                            id="lang_sort"
                            v-model.number="form.sort_order"
                            type="number"
                            min="0"
                            class="h-9 text-sm"
                        />
                    </div>
                    <label
                        v-if="creating || !editing?.is_default"
                        class="flex items-center gap-2 self-end pb-2 text-xs"
                    >
                        <input v-model="form.is_active" type="checkbox" />
                        {{ t('admin.languages.active') }}
                    </label>
                </div>

                <DialogFooter class="gap-2 sm:gap-2">
                    <Button
                        variant="outline"
                        class="cursor-pointer"
                        :disabled="saving"
                        @click="close"
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
    </div>
</template>
