<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import {
    ArrowRight,
    Globe,
    MapPin,
    Building,
    Phone,
    Mail,
    Lock,
} from '@lucide/vue';
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SearchableSelect from '@/components/ui/SearchableSelect.vue';
import type { SelectOption } from '@/components/ui/SearchableSelect.vue';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

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
        countries: () => [],
        wilayas: () => [],
    },
);

const { t, locale } = useI18n();

// Initialize form reactive state
const form = store.form();

// Available list of countries and wilayas with fallbacks
const countriesList = computed<CountryItem[]>(() => props.countries);
const wilayasList = computed<WilayaItem[]>(() => props.wilayas);

// Format Options with Search terms for SearchableSelect
const countryOptions = computed<SelectOption[]>(() => {
    return countriesList.value.map((c) => ({
        value: c.id,
        label: `${locale.value === 'ar' ? c.name_ar : c.name_fr} (${c.phone_code})`,
        subtitle: locale.value === 'ar' ? c.name_fr : c.name_ar,
        badge: c.code,
        flagUrl:
            c.flag_url || `https://flagcdn.com/${c.code.toLowerCase()}.svg`,
        searchTerms: `${c.name_fr} ${c.name_ar} ${c.code} ${c.phone_code}`,
    }));
});

const wilayaOptions = computed<SelectOption[]>(() => {
    return wilayasList.value.map((w) => ({
        value: w.id,
        label: `${w.code} - ${locale.value === 'ar' ? w.name_ar : w.name_fr}`,
        subtitle: locale.value === 'ar' ? w.name_fr : w.name_ar,
        badge: w.code,
        searchTerms: `${w.code} ${w.name_fr} ${w.name_ar}`,
    }));
});

const communeOptions = computed<SelectOption[]>(() => {
    return communesList.value.map((cm) => ({
        value: cm.id,
        label: locale.value === 'ar' ? cm.name_ar : cm.name_fr,
        subtitle: locale.value === 'ar' ? cm.name_fr : cm.name_ar,
        badge: cm.post_code ? String(cm.post_code) : undefined,
        searchTerms: `${cm.name_fr} ${cm.name_ar} ${cm.post_code || ''}`,
    }));
});

// Identify default Algeria country
const defaultDz =
    props.countries.find((c) => c.code === 'DZ') || props.countries[0];

// State for dynamic location - Wilaya and Commune default to null
const selectedCountryId = ref<number | null>(defaultDz ? defaultDz.id : null);
const selectedWilayaId = ref<number | null>(null);
const selectedCommuneId = ref<number | null>(null);
const communesList = ref<CommuneItem[]>([]);
const isLoadingCommunes = ref<boolean>(false);
const communesCache = new Map<number, CommuneItem[]>();

// Determine currently selected country object
const selectedCountry = computed<CountryItem | undefined>(() => {
    return countriesList.value.find((c) => c.id === selectedCountryId.value);
});

// Check if currently selected country is Algeria
const isAlgeria = computed<boolean>(() => {
    return selectedCountry.value ? selectedCountry.value.code === 'DZ' : true;
});

// Active country phone dialing code
const activePhoneCode = computed<string>(() => {
    return selectedCountry.value?.phone_code || '+213';
});

// Watch Country Selection
watch(selectedCountryId, () => {
    selectedWilayaId.value = null;
    selectedCommuneId.value = null;
    communesList.value = [];
});

// Watch Wilaya Selection to fetch communes
watch(selectedWilayaId, (newWilayaId) => {
    selectedCommuneId.value = null;

    if (newWilayaId) {
        fetchCommunes(newWilayaId);
    } else {
        communesList.value = [];
    }
});

// Fetch communes for a given wilaya without pre-selecting a commune (keep null by default)
async function fetchCommunes(wilayaId: number) {
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

// Strictly enforce Arabic characters on Arabic inputs
function handleArabicInput(event: Event) {
    const input = event.target as HTMLInputElement;
    input.value = input.value.replace(/[^\u0600-\u06FF\s]/g, '');
}

// Format phone number with a space every two digits (e.g. 05 49 02 04 55)
function handlePhoneInput(event: Event) {
    const input = event.target as HTMLInputElement;
    const rawDigits = input.value.replace(/\D/g, '');
    const maxDigits = isAlgeria.value ? 10 : 15;
    const truncated = rawDigits.slice(0, maxDigits);
    const formatted = (truncated.match(/.{1,2}/g) || []).join(' ');
    input.value = formatted;
}
</script>

<template>
    <Head :title="t('auth.registerTitle')" />

    <!-- Card Header -->
    <div class="mb-7 space-y-2 text-center lg:text-start">
        <h1
            class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
        >
            {{ t('auth.registerTitle') }}
        </h1>
        <p class="text-xs leading-relaxed text-muted-foreground sm:text-sm">
            {{ t('auth.registerDesc') }}
        </p>
    </div>

    <Form
        v-bind="form"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <div class="space-y-4.5">
            <!-- SECTION 1: NAMES IN LATIN (FR/EN) - ALWAYS LTR -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <!-- First Name (Latin) -->
                <div class="space-y-1.5 text-start">
                    <Label
                        for="first_name"
                        class="text-xs font-semibold text-foreground"
                    >
                        {{ t('auth.firstName') }}
                        <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="first_name"
                        type="text"
                        required
                        autofocus
                        :tabindex="1"
                        dir="ltr"
                        autocomplete="given-name"
                        name="first_name"
                        placeholder="Ex: Mohamed"
                        class="input-premium h-10.5 text-left text-sm"
                    />
                    <InputError :message="errors.first_name" />
                </div>

                <!-- Last Name (Latin) -->
                <div class="space-y-1.5 text-start">
                    <Label
                        for="last_name"
                        class="text-xs font-semibold text-foreground"
                    >
                        {{ t('auth.lastName') }}
                        <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="last_name"
                        type="text"
                        required
                        :tabindex="2"
                        dir="ltr"
                        autocomplete="family-name"
                        name="last_name"
                        placeholder="Ex: Benali"
                        class="input-premium h-10.5 text-left text-sm"
                    />
                    <InputError :message="errors.last_name" />
                </div>
            </div>

            <!-- SECTION 2: NAMES IN ARABIC (ALWAYS RTL WITH ARABIC FILTER) -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <!-- First Name in Arabic -->
                <div class="space-y-1.5 text-start">
                    <div class="flex items-center justify-between">
                        <Label
                            for="first_name_ar"
                            class="text-xs font-semibold text-foreground"
                        >
                            {{ t('auth.firstNameAr') }}
                            <span class="text-destructive">*</span>
                        </Label>
                        <span
                            class="font-arabic text-[10px] font-medium text-onda-teal-600 dark:text-onda-teal-400"
                            >{{ t('auth.arabicOnlyNotice') }}</span
                        >
                    </div>
                    <Input
                        id="first_name_ar"
                        type="text"
                        required
                        :tabindex="3"
                        dir="rtl"
                        name="first_name_ar"
                        placeholder="مثال: محمد"
                        @input="handleArabicInput"
                        class="input-premium-teal font-arabic h-10.5 text-right text-sm"
                    />
                    <InputError :message="errors.first_name_ar" />
                </div>

                <!-- Last Name in Arabic -->
                <div class="space-y-1.5 text-start">
                    <div class="flex items-center justify-between">
                        <Label
                            for="last_name_ar"
                            class="text-xs font-semibold text-foreground"
                        >
                            {{ t('auth.lastNameAr') }}
                            <span class="text-destructive">*</span>
                        </Label>
                        <span
                            class="font-arabic text-[10px] font-medium text-onda-teal-600 dark:text-onda-teal-400"
                            >{{ t('auth.arabicOnlyNotice') }}</span
                        >
                    </div>
                    <Input
                        id="last_name_ar"
                        type="text"
                        required
                        :tabindex="4"
                        dir="rtl"
                        name="last_name_ar"
                        placeholder="مثال: بن علي"
                        @input="handleArabicInput"
                        class="input-premium-teal font-arabic h-10.5 text-right text-sm"
                    />
                    <InputError :message="errors.last_name_ar" />
                </div>
            </div>

            <!-- SECTION 3: COUNTRY SELECTION -->
            <div class="space-y-1.5 text-start">
                <Label
                    for="country_id"
                    class="flex items-center gap-1.5 text-xs font-semibold text-foreground"
                >
                    <Globe
                        class="size-3.5 text-onda-blue-600 dark:text-onda-blue-400"
                    />
                    <span>{{ t('auth.country') }}</span>
                    <span class="text-destructive">*</span>
                </Label>
                <SearchableSelect
                    id="country_id"
                    name="country_id"
                    v-model="selectedCountryId"
                    :options="countryOptions"
                    :placeholder="t('auth.selectCountry')"
                    :search-placeholder="t('auth.searchCountry')"
                    :empty-text="t('common.noResults')"
                    :tabindex="5"
                    required
                />
                <InputError :message="errors.country_id" />
            </div>

            <!-- SECTION 4: LOCATION (WILAYA + COMMUNE FOR ALGERIA, OR FREE CITY FIELD FOR OTHERS) -->
            <!-- Case A: Algeria Selected -->
            <div v-if="isAlgeria" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <!-- Wilaya Selector -->
                <div class="space-y-1.5 text-start">
                    <Label
                        for="wilaya_id"
                        class="flex items-center gap-1.5 text-xs font-semibold text-foreground"
                    >
                        <MapPin class="size-3.5 text-onda-teal-600" />
                        <span>{{ t('auth.wilaya') }}</span>
                        <span class="text-destructive">*</span>
                    </Label>
                    <SearchableSelect
                        id="wilaya_id"
                        name="wilaya_id"
                        v-model="selectedWilayaId"
                        :options="wilayaOptions"
                        :placeholder="t('auth.selectWilaya')"
                        :search-placeholder="t('auth.searchWilaya')"
                        :empty-text="t('common.noResults')"
                        :tabindex="6"
                        required
                    />
                    <InputError :message="errors.wilaya_id" />
                </div>

                <!-- Commune Selector -->
                <div class="space-y-1.5 text-start">
                    <Label
                        for="commune_id"
                        class="flex items-center gap-1.5 text-xs font-semibold text-foreground"
                    >
                        <Building class="size-3.5 text-onda-teal-600" />
                        <span>{{ t('auth.commune') }}</span>
                        <span class="text-destructive">*</span>
                    </Label>
                    <SearchableSelect
                        id="commune_id"
                        name="commune_id"
                        v-model="selectedCommuneId"
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
                        :tabindex="7"
                        required
                    />
                    <InputError :message="errors.commune_id" />
                </div>
            </div>

            <!-- Case B: Foreign Country Selected -->
            <div v-else class="space-y-1.5 text-start">
                <Label
                    for="city"
                    class="flex items-center gap-1.5 text-xs font-semibold text-foreground"
                >
                    <Building class="size-3.5 text-onda-blue-600" />
                    <span>{{ t('auth.city') }}</span>
                </Label>
                <Input
                    id="city"
                    type="text"
                    name="city"
                    :tabindex="6"
                    :placeholder="t('auth.cityPlaceholder')"
                    class="input-premium h-10.5 text-sm"
                />
                <InputError :message="errors.city" />
            </div>

            <!-- SECTION 5: PHONE NUMBER (ALWAYS LTR WITH DIALING CODE ON LEFT) -->
            <div class="space-y-1.5 text-start">
                <Label
                    for="phone"
                    class="flex items-center gap-1.5 text-xs font-semibold text-foreground"
                >
                    <Phone class="size-3.5 text-onda-blue-600" />
                    <span>{{ t('auth.phone') }}</span>
                    <span class="text-destructive">*</span>
                </Label>
                <div
                    dir="ltr"
                    class="input-premium relative flex overflow-hidden"
                >
                    <!-- Dialing code prefix badge locked to the left in LTR with flag -->
                    <span
                        class="inline-flex items-center gap-1.5 border-r border-border bg-muted/80 px-3 font-mono text-xs font-bold text-foreground select-none"
                    >
                        <img
                            v-if="selectedCountry?.flag_url"
                            :src="selectedCountry.flag_url"
                            :alt="selectedCountry.code"
                            class="size-4 shrink-0 rounded-xs border border-border/40 object-cover shadow-xs"
                            loading="lazy"
                        />
                        <span>{{ activePhoneCode }}</span>
                    </span>
                    <input
                        id="phone"
                        type="tel"
                        required
                        :tabindex="8"
                        dir="ltr"
                        autocomplete="tel"
                        name="phone"
                        :placeholder="
                            isAlgeria ? '05 12 34 56 78' : '06 12 34 56 78'
                        "
                        @input="handlePhoneInput"
                        class="h-10.5 flex-1 bg-transparent px-3.5 text-left font-mono text-sm text-foreground placeholder:text-muted-foreground focus:outline-none"
                    />
                </div>
                <InputError :message="errors.phone" />
            </div>

            <!-- SECTION 6: EMAIL ADDRESS (ALWAYS LTR) -->
            <div class="space-y-1.5 text-start">
                <Label
                    for="email"
                    class="flex items-center gap-1.5 text-xs font-semibold text-foreground"
                >
                    <Mail class="size-3.5 text-onda-blue-600" />
                    <span>{{ t('auth.email') }}</span>
                    <span class="text-destructive">*</span>
                </Label>
                <Input
                    id="email"
                    type="email"
                    required
                    :tabindex="9"
                    dir="ltr"
                    autocomplete="email"
                    name="email"
                    placeholder="author@onda.dz"
                    class="input-premium h-10.5 text-left text-sm"
                />
                <InputError :message="errors.email" />
            </div>

            <!-- SECTION 7: PASSWORD & CONFIRMATION -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <!-- Password -->
                <div class="space-y-1.5 text-start">
                    <Label
                        for="password"
                        class="flex items-center gap-1.5 text-xs font-semibold text-foreground"
                    >
                        <Lock class="size-3.5 text-onda-blue-600" />
                        <span>{{ t('auth.password') }}</span>
                        <span class="text-destructive">*</span>
                    </Label>
                    <PasswordInput
                        id="password"
                        required
                        :tabindex="10"
                        autocomplete="new-password"
                        name="password"
                        placeholder="••••••••"
                        :passwordrules="passwordRules"
                        class="input-premium h-10.5 ps-8 text-sm"
                    />
                    <InputError :message="errors.password" />
                </div>

                <!-- Password Confirmation -->
                <div class="space-y-1.5 text-start">
                    <Label
                        for="password_confirmation"
                        class="text-xs font-semibold text-foreground"
                    >
                        {{ t('auth.passwordConfirmation') }}
                        <span class="text-destructive">*</span>
                    </Label>
                    <PasswordInput
                        id="password_confirmation"
                        required
                        :tabindex="11"
                        autocomplete="new-password"
                        name="password_confirmation"
                        placeholder="••••••••"
                        :passwordrules="passwordRules"
                        class="input-premium h-10.5 ps-8 text-sm"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>
            </div>

            <!-- SUBMIT BUTTON -->
            <Button
                type="submit"
                class="mt-3 h-12 w-full cursor-pointer rounded-xl bg-gradient-to-r from-onda-blue-600 to-onda-blue-700 text-sm font-semibold text-white shadow-lg shadow-onda-blue-600/25 transition-all hover:-translate-y-0.5 hover:from-onda-blue-700 hover:to-onda-blue-800 hover:shadow-onda-blue-600/40 active:translate-y-0 dark:from-onda-blue-500 dark:to-onda-blue-600 dark:hover:from-onda-blue-400 dark:hover:to-onda-blue-500"
                :tabindex="12"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                <span v-else class="flex items-center justify-center gap-2">
                    <span>{{ t('auth.registerBtn') }}</span>
                    <ArrowRight
                        class="size-4.5 transition-transform group-hover:translate-x-1 rtl:rotate-180 rtl:group-hover:-translate-x-1"
                    />
                </span>
            </Button>
        </div>

        <!-- LINK TO LOGIN -->
        <div
            class="mt-3 border-t border-border/80 pt-4 text-center text-xs text-muted-foreground"
        >
            <span>{{ t('auth.alreadyHaveAccount') }}&nbsp;</span>
            <TextLink
                :href="login()"
                class="font-semibold text-onda-teal-600 hover:text-onda-teal-700 dark:text-onda-teal-400"
                :tabindex="13"
            >
                {{ t('nav.signIn') }}
            </TextLink>
        </div>
    </Form>
</template>
