<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    BadgeCheck,
    Check,
    Landmark,
    Scale,
    UserRound,
} from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import SearchableSelect, { type SelectOption } from '@/components/ui/SearchableSelect.vue';
import type { ClassificationSelection, ClassificationTree } from './cascade';
import {
    collegesFor,
    findCollege,
    findType,
    membersFor,
    selectCollege,
    selectGestion,
    selectMember,
    selectType,
    showsGestion,
} from './cascade';

/**
 * Redesigned classification cascade with progressive disclosure ("select by select").
 * Each level only appears once the preceding selection is made.
 * When an earlier level changes, subsequent levels are reset cleanly via cascade.ts.
 */
const props = defineProps<{
    tree: ClassificationTree;
    errors: Partial<Record<keyof ClassificationSelection, string>>;
}>();

const selection = defineModel<ClassificationSelection>({ required: true });

const { t, te } = useI18n();

const type = computed(() =>
    findType(props.tree, selection.value.register_type_id),
);
const isAuteur = computed(() => showsGestion(type.value));
const gestionForColleges = computed(() =>
    isAuteur.value ? selection.value.type_gestion_id : null,
);
const colleges = computed(() =>
    collegesFor(type.value, gestionForColleges.value),
);
const college = computed(() =>
    findCollege(
        type.value,
        gestionForColleges.value,
        selection.value.register_type_college_id,
    ),
);
const members = computed(() => membersFor(college.value));

// Progressive disclosure visibility gates
const showGestionStep = computed(() => {
    return selection.value.register_type_id !== null && isAuteur.value;
});

const showCollegeStep = computed(() => {
    if (!type.value) return false;
    if (isAuteur.value) {
        return selection.value.type_gestion_id !== null;
    }
    return selection.value.register_type_id !== null;
});

const showMemberStep = computed(() => {
    return showCollegeStep.value && selection.value.register_type_college_id !== null;
});

// Dynamic step numbers
const gestionStepNumber = 2;
const collegeStepNumber = computed(() => (isAuteur.value ? 3 : 2));
const memberStepNumber = computed(() => (isAuteur.value ? 4 : 3));
const totalSteps = computed(() => (isAuteur.value ? 4 : 3));

// Formatted select options with search terms, icons and badges
const typeOptions = computed<SelectOption[]>(() =>
    props.tree.types.map((item) => ({
        value: item.id,
        label: item.name,
        disabled: item.is_disabled,
        icon: UserRound,
        badge: item.is_auteur ? '4 étapes' : '3 étapes',
        searchTerms: item.name,
    })),
);

const gestionOptions = computed<SelectOption[]>(() =>
    (type.value?.gestions ?? []).map((item) => ({
        value: item.id,
        label: item.name,
        icon: Scale,
        searchTerms: item.name,
    })),
);

const collegeOptions = computed<SelectOption[]>(() =>
    colleges.value.map((item) => ({
        value: item.id,
        label: item.name,
        code: item.code_college,
        badge: item.code_college,
        disabled: item.is_disabled,
        icon: Landmark,
        searchTerms: `${item.name} ${item.code_college}`,
    })),
);

const memberOptions = computed<SelectOption[]>(() =>
    members.value.map((item) => ({
        value: item.id,
        label: item.name,
        icon: BadgeCheck,
        searchTerms: item.name,
    })),
);

function onSelectType(val: string | number | null): void {
    const id = val === null || val === '' ? null : Number(val);
    selection.value = selectType(selection.value, id);
}

function onSelectGestion(val: string | number | null): void {
    const id = val === null || val === '' ? null : Number(val);
    selection.value = selectGestion(selection.value, id);
}

function onSelectCollege(val: string | number | null): void {
    const id = val === null || val === '' ? null : Number(val);
    selection.value = selectCollege(selection.value, id);
}

function onSelectMember(val: string | number | null): void {
    const id = val === null || val === '' ? null : Number(val);
    selection.value = selectMember(selection.value, id);
}

/** Server messages are translation keys; anything else is shown as sent. */
function errorFor(field: keyof ClassificationSelection): string | undefined {
    const message = props.errors[field];
    return message && te(message) ? t(message) : message;
}
</script>

<template>
    <div class="relative space-y-5">
        <!-- STEP 1: Type de déclarant -->
        <div
            class="group relative rounded-2xl border p-4 sm:p-5 transition-all duration-300"
            :class="[
                selection.register_type_id !== null
                    ? 'border-border/80 bg-card/70 shadow-xs'
                    : 'border-onda-blue-500/50 bg-card ring-2 ring-onda-blue-500/15 shadow-sm'
            ]"
        >
            <div class="mb-3.5 flex items-start justify-between gap-3">
                <div class="flex items-center gap-2.5">
                    <div
                        class="flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold transition-colors duration-200"
                        :class="[
                            selection.register_type_id !== null
                                ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                : 'bg-onda-blue-600 text-white shadow-xs'
                        ]"
                    >
                        <Check v-if="selection.register_type_id !== null" class="size-4 stroke-[2.5]" />
                        <span v-else>1</span>
                    </div>

                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-semibold tracking-tight text-foreground">
                                {{ t('oeuvres.classification.type') }}
                            </h3>
                            <span class="text-[11px] text-muted-foreground/80 font-mono">
                                (1/{{ totalSteps }})
                            </span>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Sélectionnez la catégorie légale sous laquelle vous déposez votre œuvre.
                        </p>
                    </div>
                </div>

                <span
                    v-if="type"
                    class="hidden sm:inline-flex items-center gap-1 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-0.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-300"
                >
                    <bdi dir="auto">{{ type.name }}</bdi>
                </span>
            </div>

            <div class="space-y-1.5">
                <SearchableSelect
                    id="register_type_id"
                    name="register_type_id"
                    :model-value="selection.register_type_id"
                    :options="typeOptions"
                    :placeholder="t('oeuvres.classification.typePlaceholder')"
                    search-placeholder="Rechercher un type de déclarant..."
                    :clearable="true"
                    @update:model-value="onSelectType"
                />
                <InputError :message="errorFor('register_type_id')" />
            </div>
        </div>

        <!-- STEP 2: Type de gestion (Auteur only — progressive reveal) -->
        <transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="transform -translate-y-2 opacity-0"
            enter-to-class="transform translate-y-0 opacity-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="transform translate-y-0 opacity-100"
            leave-to-class="transform -translate-y-2 opacity-0"
        >
            <div
                v-if="showGestionStep"
                class="group relative rounded-2xl border p-4 sm:p-5 transition-all duration-300"
                :class="[
                    selection.type_gestion_id !== null
                        ? 'border-border/80 bg-card/70 shadow-xs'
                        : 'border-onda-blue-500/50 bg-card ring-2 ring-onda-blue-500/15 shadow-sm'
                ]"
            >
                <div class="mb-3.5 flex items-start justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <div
                            class="flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold transition-colors duration-200"
                            :class="[
                                selection.type_gestion_id !== null
                                    ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                    : 'bg-onda-blue-600 text-white shadow-xs'
                            ]"
                        >
                            <Check v-if="selection.type_gestion_id !== null" class="size-4 stroke-[2.5]" />
                            <span v-else>{{ gestionStepNumber }}</span>
                        </div>

                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold tracking-tight text-foreground">
                                    {{ t('oeuvres.classification.gestion') }}
                                </h3>
                                <span class="text-[11px] text-muted-foreground/80 font-mono">
                                    (2/{{ totalSteps }})
                                </span>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Déterminez le mode d'administration et de gestion des droits patrimoniaux.
                            </p>
                        </div>
                    </div>

                    <span
                        v-if="selection.type_gestion_id && type?.gestions.find(g => g.id === selection.type_gestion_id)"
                        class="hidden sm:inline-flex items-center gap-1 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-0.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-300"
                    >
                        <bdi dir="auto">{{ type.gestions.find(g => g.id === selection.type_gestion_id)?.name }}</bdi>
                    </span>
                </div>

                <div class="space-y-1.5">
                    <SearchableSelect
                        id="type_gestion_id"
                        name="type_gestion_id"
                        :model-value="selection.type_gestion_id"
                        :options="gestionOptions"
                        :placeholder="t('oeuvres.classification.gestionPlaceholder')"
                        search-placeholder="Rechercher un mode de gestion..."
                        :clearable="true"
                        @update:model-value="onSelectGestion"
                    />
                    <InputError :message="errorFor('type_gestion_id')" />
                </div>
            </div>
        </transition>

        <!-- STEP 3 (or 2): Collège / Discipline artistique (progressive reveal) -->
        <transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="transform -translate-y-2 opacity-0"
            enter-to-class="transform translate-y-0 opacity-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="transform translate-y-0 opacity-100"
            leave-to-class="transform -translate-y-2 opacity-0"
        >
            <div
                v-if="showCollegeStep"
                class="group relative rounded-2xl border p-4 sm:p-5 transition-all duration-300"
                :class="[
                    selection.register_type_college_id !== null
                        ? 'border-border/80 bg-card/70 shadow-xs'
                        : 'border-onda-blue-500/50 bg-card ring-2 ring-onda-blue-500/15 shadow-sm'
                ]"
            >
                <div class="mb-3.5 flex items-start justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <div
                            class="flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold transition-colors duration-200"
                            :class="[
                                selection.register_type_college_id !== null
                                    ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                    : 'bg-onda-blue-600 text-white shadow-xs'
                            ]"
                        >
                            <Check v-if="selection.register_type_college_id !== null" class="size-4 stroke-[2.5]" />
                            <span v-else>{{ collegeStepNumber }}</span>
                        </div>

                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold tracking-tight text-foreground">
                                    {{ t('oeuvres.classification.college') }}
                                </h3>
                                <span class="text-[11px] text-muted-foreground/80 font-mono">
                                    ({{ collegeStepNumber }}/{{ totalSteps }})
                                </span>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Choisissez la discipline artistique ou le domaine spécifique de l'œuvre.
                            </p>
                        </div>
                    </div>

                    <span
                        v-if="college"
                        class="hidden sm:inline-flex items-center gap-1.5 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-0.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-300"
                    >
                        <bdi dir="auto">{{ college.name }}</bdi>
                        <span class="font-mono text-[10px] text-muted-foreground"><bdi dir="ltr">[{{ college.code_college }}]</bdi></span>
                    </span>
                </div>

                <div class="space-y-1.5">
                    <SearchableSelect
                        id="register_type_college_id"
                        name="register_type_college_id"
                        :model-value="selection.register_type_college_id"
                        :options="collegeOptions"
                        :placeholder="t('oeuvres.classification.collegePlaceholder')"
                        search-placeholder="Rechercher un collège ou code (ex. Musique, Dramatique...)..."
                        :clearable="true"
                        @update:model-value="onSelectCollege"
                    />
                    <InputError :message="errorFor('register_type_college_id')" />
                </div>
            </div>
        </transition>

        <!-- STEP 4 (or 3): Qualité du membre (progressive reveal) -->
        <transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="transform -translate-y-2 opacity-0"
            enter-to-class="transform translate-y-0 opacity-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="transform translate-y-0 opacity-100"
            leave-to-class="transform -translate-y-2 opacity-0"
        >
            <div
                v-if="showMemberStep"
                class="group relative rounded-2xl border p-4 sm:p-5 transition-all duration-300"
                :class="[
                    selection.register_type_member_id !== null
                        ? 'border-border/80 bg-card/70 shadow-xs'
                        : 'border-onda-blue-500/50 bg-card ring-2 ring-onda-blue-500/15 shadow-sm'
                ]"
            >
                <div class="mb-3.5 flex items-start justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <div
                            class="flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold transition-colors duration-200"
                            :class="[
                                selection.register_type_member_id !== null
                                    ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                    : 'bg-onda-blue-600 text-white shadow-xs'
                            ]"
                        >
                            <Check v-if="selection.register_type_member_id !== null" class="size-4 stroke-[2.5]" />
                            <span v-else>{{ memberStepNumber }}</span>
                        </div>

                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold tracking-tight text-foreground">
                                    {{ t('oeuvres.classification.member') }}
                                </h3>
                                <span class="text-[11px] text-muted-foreground/80 font-mono">
                                    ({{ memberStepNumber }}/{{ totalSteps }})
                                </span>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Précisez votre qualité d'intervention sur l'œuvre (Auteur, Compositeur, etc.).
                            </p>
                        </div>
                    </div>

                    <span
                        v-if="selection.register_type_member_id && members.find(m => m.id === selection.register_type_member_id)"
                        class="hidden sm:inline-flex items-center gap-1 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-0.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-300"
                    >
                        <bdi dir="auto">{{ members.find(m => m.id === selection.register_type_member_id)?.name }}</bdi>
                    </span>
                </div>

                <div class="space-y-1.5">
                    <SearchableSelect
                        id="register_type_member_id"
                        name="register_type_member_id"
                        :model-value="selection.register_type_member_id"
                        :options="memberOptions"
                        :placeholder="t('oeuvres.classification.memberPlaceholder')"
                        search-placeholder="Rechercher une qualité (Auteur, Compositeur, Adaptateur...)..."
                        :clearable="true"
                        @update:model-value="onSelectMember"
                    />
                    <InputError :message="errorFor('register_type_member_id')" />
                </div>
            </div>
        </transition>
    </div>
</template>
