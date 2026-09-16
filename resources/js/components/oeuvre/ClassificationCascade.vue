<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
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
 * The classification selects on one card. A level appears once the type is
 * chosen; a select whose parent is still unanswered stays disabled with a
 * placeholder saying what to pick first. The gestion select is only ever
 * rendered for Auteur (v-if), never merely hidden for the other types.
 *
 * Native <select>s: they render a disabled option natively and follow the
 * page's dir; each option keeps its own bidi context, so French names read
 * correctly inside an Arabic layout.
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

const collegeLocked = computed(
    () => isAuteur.value && selection.value.type_gestion_id === null,
);
const memberLocked = computed(() => college.value === undefined);

const selectClass =
    'flex h-11 w-full rounded-xl border border-input bg-background/50 px-3.5 text-base shadow-xs transition-colors outline-none focus-visible:border-onda-blue-600 focus-visible:ring-4 focus-visible:ring-onda-blue-600/20 disabled:cursor-not-allowed disabled:opacity-60 md:text-sm dark:bg-input/20';
const optionClass = 'bg-background text-foreground';

function toId(event: Event): number | null {
    const value = (event.target as HTMLSelectElement).value;

    return value === '' ? null : Number(value);
}

function optionLabel(name: string, isDisabled: boolean): string {
    return isDisabled
        ? `${name} (${t('oeuvres.classification.unavailable')})`
        : name;
}

/** Server messages are translation keys; anything else is shown as sent. */
function errorFor(field: keyof ClassificationSelection): string | undefined {
    const message = props.errors[field];

    return message && te(message) ? t(message) : message;
}
</script>

<template>
    <div class="grid gap-5">
        <!-- 1 · Type de déclarant -->
        <div class="grid gap-2">
            <Label for="register_type_id">
                <span class="text-muted-foreground">1</span>
                {{ t('oeuvres.classification.type') }}
            </Label>
            <select
                id="register_type_id"
                :class="selectClass"
                :value="selection.register_type_id ?? ''"
                @change="selection = selectType(selection, toId($event))"
            >
                <option value="" disabled :class="optionClass">
                    {{ t('oeuvres.classification.typePlaceholder') }}
                </option>
                <option
                    v-for="option in tree.types"
                    :key="option.id"
                    :value="option.id"
                    :disabled="option.is_disabled"
                    :class="optionClass"
                    dir="auto"
                >
                    {{ optionLabel(option.name, option.is_disabled) }}
                </option>
            </select>
            <InputError :message="errorFor('register_type_id')" />
        </div>

        <!-- 2 · Type de gestion — Auteur only; absent for every other type -->
        <div v-if="isAuteur" class="grid gap-2">
            <Label for="type_gestion_id">
                <span class="text-muted-foreground">2</span>
                {{ t('oeuvres.classification.gestion') }}
            </Label>
            <select
                id="type_gestion_id"
                :class="selectClass"
                :value="selection.type_gestion_id ?? ''"
                @change="selection = selectGestion(selection, toId($event))"
            >
                <option value="" disabled :class="optionClass">
                    {{ t('oeuvres.classification.gestionPlaceholder') }}
                </option>
                <option
                    v-for="gestion in type?.gestions ?? []"
                    :key="gestion.id"
                    :value="gestion.id"
                    :class="optionClass"
                    dir="auto"
                >
                    {{ gestion.name }}
                </option>
            </select>
            <InputError :message="errorFor('type_gestion_id')" />
        </div>

        <template v-if="type">
            <!-- Collège -->
            <div class="grid gap-2">
                <Label for="register_type_college_id">
                    <span class="text-muted-foreground">{{
                        isAuteur ? 3 : 2
                    }}</span>
                    {{ t('oeuvres.classification.college') }}
                </Label>
                <select
                    id="register_type_college_id"
                    :class="selectClass"
                    :disabled="collegeLocked"
                    :value="selection.register_type_college_id ?? ''"
                    @change="selection = selectCollege(selection, toId($event))"
                >
                    <option value="" disabled :class="optionClass">
                        {{
                            collegeLocked
                                ? t(
                                      'oeuvres.classification.collegeNeedsGestion',
                                  )
                                : t('oeuvres.classification.collegePlaceholder')
                        }}
                    </option>
                    <option
                        v-for="option in colleges"
                        :key="option.id"
                        :value="option.id"
                        :disabled="option.is_disabled"
                        :class="optionClass"
                        dir="auto"
                    >
                        {{ optionLabel(option.name, option.is_disabled) }}
                    </option>
                </select>
                <InputError :message="errorFor('register_type_college_id')" />
            </div>

            <!-- Qualité -->
            <div class="grid gap-2">
                <Label for="register_type_member_id">
                    <span class="text-muted-foreground">{{
                        isAuteur ? 4 : 3
                    }}</span>
                    {{ t('oeuvres.classification.member') }}
                </Label>
                <select
                    id="register_type_member_id"
                    :class="selectClass"
                    :disabled="memberLocked"
                    :value="selection.register_type_member_id ?? ''"
                    @change="selection = selectMember(selection, toId($event))"
                >
                    <option value="" disabled :class="optionClass">
                        {{
                            memberLocked
                                ? t('oeuvres.classification.memberNeedsCollege')
                                : t('oeuvres.classification.memberPlaceholder')
                        }}
                    </option>
                    <option
                        v-for="option in members"
                        :key="option.id"
                        :value="option.id"
                        :class="optionClass"
                        dir="auto"
                    >
                        {{ option.name }}
                    </option>
                </select>
                <InputError :message="errorFor('register_type_member_id')" />
            </div>
        </template>
    </div>
</template>
