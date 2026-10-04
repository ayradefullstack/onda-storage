<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    BadgeCheck,
    Check,
    FileUp,
    Landmark,
    ListChecks,
    Scale,
    Shield,
    ShieldCheck,
    Sparkles,
    UserRound,
} from '@lucide/vue';
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
import Spinner from '@/components/ui/spinner/Spinner.vue';
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

const chosenType = computed(() =>
    findType(props.classification, form.register_type_id),
);

const isAuteur = computed(() => chosenType.value?.is_auteur === true);

const totalSteps = computed(() => (isAuteur.value ? 4 : 3));

const completedSteps = computed(() => {
    let count = 0;

    if (form.register_type_id !== null) {
        count++;
    }

    if (isAuteur.value && form.type_gestion_id !== null) {
        count++;
    }

    if (form.register_type_college_id !== null) {
        count++;
    }

    if (form.register_type_member_id !== null) {
        count++;
    }

    return count;
});

const progressPercent = computed(() => {
    return Math.round((completedSteps.value / totalSteps.value) * 100);
});

/** The chosen path, for a glance before submitting. */
const summary = computed(() => {
    const type = chosenType.value;
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

    <div class="mx-auto w-full max-w-5xl space-y-8 p-4 sm:p-6 lg:p-8">
        <!-- Top Hero Header -->
        <div class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div
                    class="inline-flex items-center gap-2 rounded-full border border-onda-blue-500/20 bg-onda-blue-500/10 px-3 py-1 text-xs font-medium text-onda-blue-700 dark:text-onda-blue-300"
                >
                    <Sparkles class="size-3.5" />
                    <span>ONDA • Espace Auteur & Dépôt Légal</span>
                </div>

                <Link
                    :href="index()"
                    class="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft class="size-3.5 rtl:rotate-180" />
                    <span>Retour à la liste des œuvres</span>
                </Link>
            </div>

            <div
                class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between"
            >
                <div>
                    <h1
                        class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
                    >
                        {{ t('oeuvres.create.title') }}
                    </h1>
                    <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                        {{ t('oeuvres.create.subtitle') }}
                    </p>
                </div>

                <!-- Step Progress Badge -->
                <div class="mt-3 flex items-center gap-3 sm:mt-0">
                    <div class="text-end">
                        <span class="text-xs font-semibold text-foreground">
                            {{ completedSteps }} / {{ totalSteps }} étapes
                        </span>
                        <div class="text-[11px] text-muted-foreground">
                            {{ complete ? 'Prêt pour le dépôt' : 'En cours' }}
                        </div>
                    </div>
                    <div
                        class="relative flex size-10 items-center justify-center rounded-full border border-border bg-card shadow-xs"
                    >
                        <span
                            class="text-xs font-bold text-onda-blue-600 dark:text-onda-blue-400"
                        >
                            {{ progressPercent }}%
                        </span>
                    </div>
                </div>
            </div>

            <!-- Global Progress Bar -->
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full bg-gradient-to-r from-onda-blue-600 to-onda-teal-500 transition-all duration-500 ease-out"
                    :style="{ width: `${progressPercent}%` }"
                />
            </div>
        </div>

        <!-- Main Workspace (Grid layout) -->
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-12 lg:items-start">
            <!-- Left Column: Progressive Stepper Cascade (7 cols) -->
            <div class="space-y-6 lg:col-span-7 xl:col-span-7">
                <Card class="border-border/80 shadow-onda-card">
                    <CardContent class="p-5 sm:p-6">
                        <form class="space-y-6" @submit.prevent="submit">
                            <!-- Progressive Classification Stepper -->
                            <ClassificationCascade
                                v-model="selection"
                                :tree="classification"
                                :errors="form.errors"
                            />

                            <!-- Help Callout -->
                            <div
                                class="flex items-start gap-3 rounded-xl border border-onda-blue-500/20 bg-onda-blue-500/5 p-4 text-xs text-muted-foreground"
                            >
                                <Shield
                                    class="mt-0.5 size-4 shrink-0 text-onda-blue-600 dark:text-onda-blue-400"
                                />
                                <div>
                                    <span class="font-medium text-foreground"
                                        >Garantie juridique ONDA :</span
                                    >
                                    Chaque choix détermine précisément les
                                    pièces requises et garantit l'opposabilité
                                    légale de votre certificat de dépôt sous
                                    l'Ordonnance 03-05.
                                </div>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>

            <!-- Right Column: Sticky Summary & Action Card (5 cols) -->
            <div
                class="space-y-6 lg:sticky lg:top-6 lg:col-span-5 xl:col-span-5"
            >
                <Card class="overflow-hidden border-border/80 shadow-onda-card">
                    <div
                        class="border-b border-border/80 bg-muted/30 px-5 py-4"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <ListChecks
                                    class="size-4 text-onda-blue-600 dark:text-onda-blue-400"
                                />
                                <h2
                                    class="text-sm font-semibold tracking-tight text-foreground"
                                >
                                    {{ t('oeuvres.create.summaryTitle') }}
                                </h2>
                            </div>

                            <span
                                v-if="complete"
                                class="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700 dark:text-emerald-400"
                            >
                                <Check class="size-3" />
                                Complet
                            </span>
                            <span
                                v-else
                                class="inline-flex items-center gap-1.5 rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-0.5 text-[11px] font-medium text-amber-700 dark:text-amber-400"
                            >
                                <span
                                    class="size-1.5 animate-pulse rounded-full bg-amber-500"
                                />
                                En attente
                            </span>
                        </div>
                    </div>

                    <CardContent class="space-y-5 p-5">
                        <!-- Path Recap List -->
                        <div class="space-y-3">
                            <!-- Type -->
                            <div
                                class="flex items-center justify-between gap-3 text-xs"
                            >
                                <div
                                    class="flex items-center gap-2 text-muted-foreground"
                                >
                                    <UserRound class="size-3.5" />
                                    <span>{{
                                        t('oeuvres.classification.type')
                                    }}</span>
                                </div>
                                <span
                                    v-if="summary.type"
                                    class="font-semibold text-foreground"
                                >
                                    <bdi dir="auto">{{
                                        summary.type.name
                                    }}</bdi>
                                </span>
                                <span
                                    v-else
                                    class="text-muted-foreground/60 italic"
                                    >Non sélectionné</span
                                >
                            </div>

                            <!-- Gestion (Auteur only) -->
                            <div
                                v-if="isAuteur"
                                class="flex items-center justify-between gap-3 text-xs"
                            >
                                <div
                                    class="flex items-center gap-2 text-muted-foreground"
                                >
                                    <Scale class="size-3.5" />
                                    <span>{{
                                        t('oeuvres.classification.gestion')
                                    }}</span>
                                </div>
                                <span
                                    v-if="summary.gestion"
                                    class="font-semibold text-foreground"
                                >
                                    <bdi dir="auto">{{
                                        summary.gestion.name
                                    }}</bdi>
                                </span>
                                <span
                                    v-else
                                    class="text-muted-foreground/60 italic"
                                    >Non sélectionné</span
                                >
                            </div>

                            <!-- College -->
                            <div
                                class="flex items-center justify-between gap-3 text-xs"
                            >
                                <div
                                    class="flex items-center gap-2 text-muted-foreground"
                                >
                                    <Landmark class="size-3.5" />
                                    <span>{{
                                        t('oeuvres.classification.college')
                                    }}</span>
                                </div>
                                <div
                                    v-if="summary.college"
                                    class="flex items-center gap-1.5 text-end"
                                >
                                    <span class="font-semibold text-foreground">
                                        <bdi dir="auto">{{
                                            summary.college.name
                                        }}</bdi>
                                    </span>
                                    <span
                                        class="font-mono text-[10px] text-muted-foreground"
                                    >
                                        <bdi dir="ltr"
                                            >[{{
                                                summary.college.code_college
                                            }}]</bdi
                                        >
                                    </span>
                                </div>
                                <span
                                    v-else
                                    class="text-muted-foreground/60 italic"
                                    >Non sélectionné</span
                                >
                            </div>

                            <!-- Qualité -->
                            <div
                                class="flex items-center justify-between gap-3 text-xs"
                            >
                                <div
                                    class="flex items-center gap-2 text-muted-foreground"
                                >
                                    <BadgeCheck class="size-3.5" />
                                    <span>{{
                                        t('oeuvres.classification.member')
                                    }}</span>
                                </div>
                                <span
                                    v-if="summary.member"
                                    class="font-semibold text-foreground"
                                >
                                    <bdi dir="auto">{{
                                        summary.member.name
                                    }}</bdi>
                                </span>
                                <span
                                    v-else
                                    class="text-muted-foreground/60 italic"
                                    >Non sélectionné</span
                                >
                            </div>
                        </div>

                        <!-- Next Step Preview Box -->
                        <div
                            class="space-y-1.5 rounded-xl border border-border/80 bg-muted/30 p-3.5"
                        >
                            <div
                                class="flex items-center gap-2 text-xs font-semibold text-foreground"
                            >
                                <FileUp
                                    class="size-4 text-onda-blue-600 dark:text-onda-blue-400"
                                />
                                <span>Étape suivante : Dépôt des fichiers</span>
                            </div>
                            <p
                                class="text-[11px] leading-relaxed text-muted-foreground"
                            >
                                Les emplacements de téléversement (manuscrits,
                                partitions, enregistrements audio...) seront
                                générés automatiquement dès la validation de
                                cette étape.
                            </p>
                        </div>

                        <p class="text-[11px] text-muted-foreground">
                            {{ t('oeuvres.create.summaryHint') }}
                        </p>

                        <!-- Primary Submit Button -->
                        <div class="space-y-2 pt-1">
                            <Button
                                type="button"
                                :disabled="!complete || form.processing"
                                class="h-11 w-full gap-2 text-xs font-semibold shadow-onda-card"
                                @click="submit"
                            >
                                <Spinner
                                    v-if="form.processing"
                                    class="size-4 text-white"
                                />
                                <span>
                                    {{
                                        form.processing
                                            ? t('oeuvres.create.submitting')
                                            : t('oeuvres.create.submit')
                                    }}
                                </span>
                                <ArrowRight
                                    v-if="!form.processing"
                                    class="size-4 rtl:rotate-180"
                                />
                            </Button>

                            <div
                                class="flex items-center justify-center gap-1.5 pt-1 text-[11px] text-muted-foreground"
                            >
                                <ShieldCheck
                                    class="size-3.5 text-emerald-600 dark:text-emerald-400"
                                />
                                <span
                                    >Chiffrement et empreinte SHA-256
                                    certifiée</span
                                >
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
