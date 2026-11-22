<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    Building,
    Globe,
    Lock,
    Mail,
    MapPin,
    Phone,
    Sparkles,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AuthErrorSummary from '@/components/auth/AuthErrorSummary.vue';
import AuthField from '@/components/auth/AuthField.vue';
import AuthFormSection from '@/components/auth/AuthFormSection.vue';
import AuthPasswordInput from '@/components/auth/AuthPasswordInput.vue';
import AuthPhoneInput from '@/components/auth/AuthPhoneInput.vue';
import AuthSubmitButton from '@/components/auth/AuthSubmitButton.vue';
import AuthTextField from '@/components/auth/AuthTextField.vue';
import { AppCombobox } from '@/components/ui/app-combobox';
import type { ComboboxOption } from '@/components/ui/app-combobox';
import {
    arabicNameRule,
    confirmRule,
    emailRule,
    newPasswordRule,
    requiredSelect,
    requiredText,
    useAuthForm,
} from '@/composables/useAuthForm';
import { useAuthStage } from '@/composables/useAuthStage';
import { minLengthFromRules } from '@/lib/authInput';
import { login } from '@/routes';
import { store } from '@/routes/register';

/**
 * Presentation only. Field names, the `store.form()` action, the country →
 * wilaya → commune data flow and every redirect are exactly what the page
 * did before; the backend (CreateNewUser) is the authority on validity. The
 * rules in useAuthForm are a subset of the server's, only to speak earlier
 * and in the user's language.
 */
interface CountryItem {
    id: number;
    code: string;
    name_fr: string;
    name_ar: string;
    phone_code: string;
    flag_url?: string;
}

interface WilayaItem {
    id: number;
    code: string;
    name_fr: string;
    name_ar: string;
    country_id: number;
}

interface CommuneItem {
    id: number;
    wilaya_id: number;
    post_code: string | null;
    name_fr: string;
    name_ar: string;
}

const props = withDefaults(
    defineProps<{
        passwordRules?: string;
        countries?: CountryItem[];
        wilayas?: WilayaItem[];
    }>(),
    {
        passwordRules: undefined,
        countries: () => [],
        wilayas: () => [],
    },
);

const { t, locale } = useI18n();
const { setStageState } = useAuthStage();
const form = store.form();

// ------------------------------------------------------- location data ----
const isAr = computed(() => locale.value === 'ar');

// LRM keeps "+213" readable inside an Arabic label: "(‎+213‎)".
const countryOptions = computed<ComboboxOption[]>(() =>
    props.countries.map((c) => ({
        value: c.id,
        label: `${isAr.value ? c.name_ar : c.name_fr} (‎${c.phone_code}‎)`,
        subtitle: isAr.value ? c.name_fr : c.name_ar,
        badge: c.code,
        flagUrl:
            c.flag_url || `https://flagcdn.com/${c.code.toLowerCase()}.svg`,
        searchTerms: `${c.name_fr} ${c.name_ar} ${c.code} ${c.phone_code}`,
    })),
);

const wilayaOptions = computed<ComboboxOption[]>(() =>
    props.wilayas.map((w) => ({
        value: w.id,
        label: `${w.code} - ${isAr.value ? w.name_ar : w.name_fr}`,
        subtitle: isAr.value ? w.name_fr : w.name_ar,
        badge: w.code,
        searchTerms: `${w.code} ${w.name_fr} ${w.name_ar}`,
    })),
);

const communeOptions = computed<ComboboxOption[]>(() =>
    communesList.value.map((cm) => ({
        value: cm.id,
        label: isAr.value ? cm.name_ar : cm.name_fr,
        subtitle: isAr.value ? cm.name_fr : cm.name_ar,
        badge: cm.post_code ? String(cm.post_code) : undefined,
        searchTerms: `${cm.name_fr} ${cm.name_ar} ${cm.post_code || ''}`,
    })),
);

const defaultDz =
    props.countries.find((c) => c.code === 'DZ') || props.countries[0];

const selectedCountryId = ref<number | null>(defaultDz ? defaultDz.id : null);
const selectedWilayaId = ref<number | null>(null);
const selectedCommuneId = ref<number | null>(null);
const communesList = ref<CommuneItem[]>([]);
const isLoadingCommunes = ref(false);
const communesCache = new Map<number, CommuneItem[]>();

const selectedCountry = computed(() =>
    props.countries.find((c) => c.id === selectedCountryId.value),
);
const isAlgeria = computed(() =>
    selectedCountry.value ? selectedCountry.value.code === 'DZ' : true,
);
const activePhoneCode = computed(
    () => selectedCountry.value?.phone_code || '+213',
);

watch(selectedCountryId, () => {
    selectedWilayaId.value = null;
    selectedCommuneId.value = null;
    communesList.value = [];
});

watch(selectedWilayaId, (wilayaId) => {
    selectedCommuneId.value = null;

    if (wilayaId) {
        void fetchCommunes(wilayaId);
    } else {
        communesList.value = [];
    }
});

async function fetchCommunes(wilayaId: number): Promise<void> {
    if (communesCache.has(wilayaId)) {
        communesList.value = communesCache.get(wilayaId)!;

        return;
    }

    isLoadingCommunes.value = true;

    try {
        const response = await fetch(`/api/wilayas/${wilayaId}/communes`);

        if (response.ok) {
            const data: CommuneItem[] = await response.json();

            communesCache.set(wilayaId, data);
            communesList.value = data;
        }
    } catch (e) {
        console.error('Failed to load communes:', e);
    } finally {
        isLoadingCommunes.value = false;
    }
}

// ----------------------------------------------------------- validation ----
const algeriaOnly =
    (rule: typeof requiredSelect): typeof requiredSelect =>
    (value, get) =>
        isAlgeria.value ? rule(value, get) : null;

const auth = useAuthForm(
    [
        {
            name: 'first_name',
            id: 'reg-first-name',
            label: () => t('auth.firstName'),
            rule: requiredText,
            showValid: true,
        },
        {
            name: 'last_name',
            id: 'reg-last-name',
            label: () => t('auth.lastName'),
            rule: requiredText,
            showValid: true,
        },
        {
            name: 'first_name_ar',
            id: 'reg-first-name-ar',
            label: () => t('auth.firstNameAr'),
            rule: arabicNameRule,
            showValid: true,
        },
        {
            name: 'last_name_ar',
            id: 'reg-last-name-ar',
            label: () => t('auth.lastNameAr'),
            rule: arabicNameRule,
            showValid: true,
        },
        {
            name: 'country_id',
            id: 'reg-country',
            label: () => t('auth.country'),
            rule: requiredSelect,
            showValid: true,
        },
        {
            name: 'wilaya_id',
            id: 'reg-wilaya',
            label: () => t('auth.wilaya'),
            rule: algeriaOnly(requiredSelect),
            showValid: true,
        },
        {
            name: 'commune_id',
            id: 'reg-commune',
            label: () => t('auth.commune'),
            rule: algeriaOnly(requiredSelect),
            showValid: true,
        },
        { name: 'city', id: 'reg-city', label: () => t('auth.city') },
        {
            name: 'phone',
            id: 'reg-phone',
            label: () => t('auth.phone'),
            rule: requiredText,
            showValid: true,
        },
        {
            name: 'email',
            id: 'reg-email',
            label: () => t('auth.email'),
            rule: emailRule,
            showValid: true,
        },
        {
            name: 'password',
            id: 'reg-password',
            label: () => t('auth.password'),
            rule: newPasswordRule(minLengthFromRules(props.passwordRules)),
            showValid: true,
        },
        {
            name: 'password_confirmation',
            id: 'reg-password-confirmation',
            label: () => t('auth.passwordConfirmation'),
            rule: confirmRule('password'),
            showValid: true,
        },
    ],
    (key, params) => t(key, params ?? {}),
);

// Selects are controlled by refs; keep the validation state in step.
watch(
    selectedCountryId,
    (id) => auth.setValue('country_id', id === null ? '' : String(id)),
    { immediate: true },
);
watch(selectedWilayaId, (id) =>
    auth.setValue('wilaya_id', id === null ? '' : String(id)),
);
watch(selectedCommuneId, (id) =>
    auth.setValue('commune_id', id === null ? '' : String(id)),
);

const section1 = ['first_name', 'last_name', 'first_name_ar', 'last_name_ar'];
const section2 = ['country_id', 'wilaya_id', 'commune_id', 'phone', 'email'];
const section3 = ['password', 'password_confirmation'];

function onError(errors: Record<string, string>): void {
    setStageState('idle');
    auth.setServerErrors(errors);
}

onMounted(() => auth.syncFromDom());
</script>

<template>
    <Head :title="t('auth.registerTitle')" />

    <div class="auth-stagger">
        <div>
            <span class="auth-eyebrow">
                <Sparkles class="size-3.5" aria-hidden="true" />
                {{ t('authUi.eyebrowRegister') }}
            </span>
        </div>
        <h1 class="auth-title" data-auth-title tabindex="-1">
            {{ t('auth.registerTitle') }}
        </h1>
        <p class="auth-sub">{{ t('auth.registerDesc') }}</p>
    </div>

    <Form
        v-bind="form"
        novalidate
        :reset-on-success="['password', 'password_confirmation']"
        class="auth-form auth-stagger"
        v-slot="{ processing, wasSuccessful, errors }"
        @before="auth.begin"
        @start="setStageState('busy')"
        @success="setStageState('stamped')"
        @error="onError"
        @finish="auth.finish"
    >
        <!-- Form-level error (e.g. registration unavailable), not a field error. -->
        <AuthErrorSummary :message="errors.registration ?? ''" />

        <AuthErrorSummary :items="auth.summary.value" />

        <p class="auth-note">{{ t('authUi.reqNote') }}</p>

        <!-- 1. Identity -->
        <AuthFormSection
            :number="1"
            :title="t('authUi.sec1')"
            :done-label="t('authUi.secDone')"
            :complete="auth.isComplete(section1)"
        >
            <div class="auth-row">
                <AuthTextField
                    id="reg-first-name"
                    name="first_name"
                    dir="ltr"
                    :label="t('auth.firstName')"
                    autocomplete="given-name"
                    autocapitalize="words"
                    :placeholder="t('auth.firstNamePlaceholder')"
                    required
                    v-bind="auth.bind('first_name')"
                />
                <AuthTextField
                    id="reg-last-name"
                    name="last_name"
                    dir="ltr"
                    :label="t('auth.lastName')"
                    autocomplete="family-name"
                    autocapitalize="words"
                    :placeholder="t('auth.lastNamePlaceholder')"
                    required
                    v-bind="auth.bind('last_name')"
                />
            </div>
            <div class="auth-row">
                <AuthTextField
                    id="reg-first-name-ar"
                    name="first_name_ar"
                    dir="rtl"
                    lang="ar"
                    :label="t('auth.firstNameAr')"
                    :tag="t('auth.arabicOnlyNotice')"
                    autocomplete="off"
                    :placeholder="t('auth.firstNameArPlaceholder')"
                    required
                    v-bind="auth.bind('first_name_ar')"
                />
                <AuthTextField
                    id="reg-last-name-ar"
                    name="last_name_ar"
                    dir="rtl"
                    lang="ar"
                    :label="t('auth.lastNameAr')"
                    :tag="t('auth.arabicOnlyNotice')"
                    autocomplete="off"
                    :placeholder="t('auth.lastNameArPlaceholder')"
                    required
                    v-bind="auth.bind('last_name_ar')"
                />
            </div>
        </AuthFormSection>

        <!-- 2. Residence & contact -->
        <AuthFormSection
            :number="2"
            :title="t('authUi.sec2')"
            :done-label="t('authUi.secDone')"
            :complete="auth.isComplete(section2)"
        >
            <AuthField
                id="reg-country"
                :label="t('auth.country')"
                :icon="Globe"
                bare
                required
                :error="auth.error('country_id')"
            >
                <template #default="{ inputProps }">
                    <AppCombobox
                        id="reg-country"
                        v-model="selectedCountryId"
                        name="country_id"
                        :options="countryOptions"
                        :placeholder="t('auth.selectCountry')"
                        :search-placeholder="t('auth.searchCountry')"
                        :empty-text="t('common.noResults')"
                        :invalid="inputProps['aria-invalid'] === 'true'"
                        :describedby="inputProps['aria-describedby']"
                        :clearable="false"
                        required
                    />
                </template>
            </AuthField>

            <div v-if="isAlgeria" class="auth-row">
                <AuthField
                    id="reg-wilaya"
                    :label="t('auth.wilaya')"
                    :icon="MapPin"
                    bare
                    required
                    :error="auth.error('wilaya_id')"
                >
                    <template #default="{ inputProps }">
                        <AppCombobox
                            id="reg-wilaya"
                            v-model="selectedWilayaId"
                            name="wilaya_id"
                            :options="wilayaOptions"
                            :placeholder="t('auth.selectWilaya')"
                            :search-placeholder="t('auth.searchWilaya')"
                            :empty-text="t('common.noResults')"
                            :invalid="inputProps['aria-invalid'] === 'true'"
                            :describedby="inputProps['aria-describedby']"
                            :clearable="false"
                            required
                        />
                    </template>
                </AuthField>
                <AuthField
                    id="reg-commune"
                    :label="t('auth.commune')"
                    :icon="Building"
                    bare
                    required
                    :error="auth.error('commune_id')"
                >
                    <template #default="{ inputProps }">
                        <AppCombobox
                            id="reg-commune"
                            v-model="selectedCommuneId"
                            name="commune_id"
                            :options="communeOptions"
                            :placeholder="
                                isLoadingCommunes
                                    ? t('auth.loadingCommunes')
                                    : t('auth.selectCommune')
                            "
                            :search-placeholder="t('auth.searchCommune')"
                            :empty-text="t('common.noResults')"
                            :disabled="
                                isLoadingCommunes || communesList.length === 0
                            "
                            :invalid="inputProps['aria-invalid'] === 'true'"
                            :describedby="inputProps['aria-describedby']"
                            :clearable="false"
                            required
                        />
                    </template>
                </AuthField>
            </div>

            <AuthTextField
                v-else
                id="reg-city"
                name="city"
                dir="auto"
                :label="t('auth.city')"
                :icon="Building"
                autocomplete="address-level2"
                autocapitalize="words"
                :placeholder="t('auth.cityPlaceholder')"
                v-bind="auth.bind('city')"
            />

            <AuthPhoneInput
                id="reg-phone"
                name="phone"
                :label="t('auth.phone')"
                :icon="Phone"
                :dial-code="activePhoneCode"
                :iso="selectedCountry?.code"
                :flag-url="selectedCountry?.flag_url"
                :max-digits="isAlgeria ? 10 : 15"
                :group-on-blur="isAlgeria"
                :placeholder="isAlgeria ? '05 12 34 56 78' : '06 12 34 56 78'"
                :hint="t('authUi.phoneSr', { code: activePhoneCode })"
                required
                v-bind="auth.bind('phone')"
            />

            <AuthTextField
                id="reg-email"
                name="email"
                type="email"
                dir="ltr"
                :label="t('auth.email')"
                :icon="Mail"
                autocomplete="email"
                inputmode="email"
                :placeholder="t('auth.emailPlaceholder')"
                keyboard-hint="email"
                required
                v-bind="auth.bind('email')"
            />
        </AuthFormSection>

        <!-- 3. Account security -->
        <AuthFormSection
            :number="3"
            :title="t('authUi.sec3')"
            :done-label="t('authUi.secDone')"
            :complete="auth.isComplete(section3)"
        >
            <div class="auth-row">
                <AuthPasswordInput
                    id="reg-password"
                    name="password"
                    :label="t('auth.password')"
                    :icon="Lock"
                    :show-label="t('authUi.showPw')"
                    :hide-label="t('authUi.hidePw')"
                    autocomplete="new-password"
                    :placeholder="t('auth.passwordPlaceholder')"
                    strength
                    required
                    v-bind="auth.bind('password')"
                    @input="auth.revalidate('password_confirmation')"
                />
                <AuthPasswordInput
                    id="reg-password-confirmation"
                    name="password_confirmation"
                    :label="t('auth.passwordConfirmation')"
                    :show-label="t('authUi.showPwConfirm')"
                    :hide-label="t('authUi.hidePwConfirm')"
                    autocomplete="new-password"
                    :placeholder="t('auth.passwordPlaceholder')"
                    required
                    :success="
                        auth.values.password_confirmation &&
                        auth.values.password_confirmation ===
                            auth.values.password
                            ? t('authUi.err.pwMatchOk')
                            : ''
                    "
                    v-bind="auth.bind('password_confirmation')"
                />
            </div>
        </AuthFormSection>

        <AuthSubmitButton
            :processing="processing"
            :success="wasSuccessful"
            :label="t('auth.registerBtn')"
            :busy-label="t('auth.creatingAccount')"
            :done-label="t('authUi.created')"
            data-test="register-user-button"
        />
    </Form>

    <p class="auth-foot">
        <span>{{ t('auth.alreadyHaveAccount') }}</span>
        &nbsp;
        <Link :href="login()" class="auth-link">{{ t('nav.signIn') }}</Link>
    </p>
</template>
