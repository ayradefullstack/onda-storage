<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowRight, ListChecks } from '@lucide/vue';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import OeuvreController from '@/actions/App/Http/Controllers/Author/OeuvreController';
import type {
    ClassificationSelection,
    ClassificationTree,
} from '@/components/oeuvre/cascade';
import {
    EMPTY_SELECTION,
    findCollege,
    findGestion,
    findMember,
    findType,
    isSelectionComplete,
    toPayload,
} from '@/components/oeuvre/cascade';
import ClassificationCascade from '@/components/oeuvre/ClassificationCascade.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { create, index } from '@/routes/oeuvres';

const props = defineProps<{
    classification: ClassificationTree;
}>();

const { t, locale } = useI18n();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Works', href: index() },
            { title: 'New work', href: create() },
        ],
    },
});

const form = useForm<ClassificationSelection>({ ...EMPTY_SELECTION });

const selection = computed<ClassificationSelection>({
    get: () => ({
        register_type_id: form.register_type_id,
        type_gestion_id: form.type_gestion_id,
        register_type_college_id: form.register_type_college_id,
        register_type_member_id: form.register_type_member_id,
    }),
    set: (next) => {
        form.register_type_id = next.register_type_id;
        form.type_gestion_id = next.type_gestion_id;
        form.register_type_college_id = next.register_type_college_id;
        form.register_type_member_id = next.register_type_member_id;
    },
});

const complete = computed(() =>
    isSelectionComplete(props.classification, selection.value),
);

/** The chosen path, for a glance before submitting. */
const summary = computed(() => {
    const type = findType(props.classification, form.register_type_id);
    const gestion = type?.is_auteur
        ? findGestion(type, form.type_gestion_id)
        : undefined;
    const college = findCollege(
        type,
        type?.is_auteur ? form.type_gestion_id : null,
        form.register_type_college_id,
    );

    return {
        type,
        gestion,
        college,
        member: findMember(college, form.register_type_member_id),
    };
});

function submit(): void {
    form.transform(() => toPayload(props.classification, selection.value)).post(
        OeuvreController.store.url(),
    );
}

// Names are localised server-side; after a client-side language switch,
// refetch just the tree so option labels follow.
watch(locale, () => {
    router.reload({ only: ['classification'] });
});
</script>

<template>
    <Head :title="t('oeuvres.create.title')" />

    <div class="mx-auto w-full max-w-2xl space-y-6 p-4 sm:p-6 lg:p-8">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">
                {{ t('oeuvres.create.title') }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('oeuvres.create.subtitle') }}
            </p>
        </div>

        <Card>
            <CardContent class="space-y-6 py-6">
                <form class="space-y-6" @submit.prevent="submit">
                    <ClassificationCascade
                        v-model="selection"
                        :tree="classification"
                        :errors="form.errors"
                    />

                    <div
                        v-if="
                            complete &&
                            summary.type &&
                            summary.college &&
                            summary.member
                        "
                        class="rounded-xl border border-onda-blue-500/25 bg-onda-blue-500/5 p-4"
                    >
                        <p
                            class="mb-2 flex items-center gap-2 text-sm font-medium"
                        >
                            <ListChecks class="size-4 text-onda-blue-600" />
                            {{ t('oeuvres.create.summaryTitle') }}
                        </p>
                        <ol
                            class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"
                        >
                            <li>
                                <bdi>{{ summary.type.name }}</bdi>
                            </li>
                            <template v-if="summary.gestion">
                                <li
                                    class="text-muted-foreground"
                                    aria-hidden="true"
                                >
                                    ›
                                </li>
                                <li>
                                    <bdi>{{ summary.gestion.name }}</bdi>
                                </li>
                            </template>
                            <li
                                class="text-muted-foreground"
                                aria-hidden="true"
                            >
                                ›
                            </li>
                            <li>
                                <bdi>{{ summary.college.name }}</bdi>
                                <span
                                    class="ms-1.5 font-mono text-xs text-muted-foreground"
                                    ><bdi dir="ltr">{{
                                        summary.college.code_college
                                    }}</bdi></span
                                >
                            </li>
                            <li
                                class="text-muted-foreground"
                                aria-hidden="true"
                            >
                                ›
                            </li>
                            <li>
                                <bdi>{{ summary.member.name }}</bdi>
                            </li>
                        </ol>
                        <p class="mt-2 text-xs text-muted-foreground">
                            {{ t('oeuvres.create.summaryHint') }}
                        </p>
                    </div>

                    <div class="flex justify-end">
                        <Button
                            type="submit"
                            :disabled="!complete || form.processing"
                            class="gap-2"
                        >
                            {{
                                form.processing
                                    ? t('oeuvres.create.submitting')
                                    : t('oeuvres.create.submit')
                            }}
                            <ArrowRight class="size-4 rtl:rotate-180" />
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
