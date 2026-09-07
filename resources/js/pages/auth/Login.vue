<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

const { t } = useI18n();

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();
</script>

<template>
    <Head :title="t('auth.loginTitle')" />

    <!-- Card Header -->
    <div class="mb-8 space-y-2 text-center lg:text-start">
        <h1
            class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
        >
            {{ t('auth.loginTitle') }}
        </h1>
        <p class="text-xs leading-relaxed text-muted-foreground sm:text-sm">
            {{ t('auth.loginDesc') }}
        </p>
    </div>

    <!-- Status Notice (e.g. after password reset) -->
    <div
        v-if="status"
        class="mb-6 rounded-2xl border border-emerald-500/20 bg-emerald-50 p-4 text-center text-xs font-semibold text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-950/50 dark:text-emerald-300"
    >
        {{ status }}
    </div>

    <!-- Passkey Biometric Sign In -->
    <PasskeyVerify
        :label="t('auth.passkeyPrompt')"
        :loading-label="t('auth.authenticating')"
        :separator="t('auth.orEmail')"
    />

    <!-- Standard Email / Password Form -->
    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <div class="space-y-4">
            <!-- Email Field -->
            <div class="space-y-1.5 text-start">
                <Label
                    for="email"
                    class="text-xs font-semibold text-foreground"
                >
                    {{ t('auth.email') }}
                </Label>
                <div class="relative">
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        autofocus
                        :tabindex="1"
                        dir="ltr"
                        autocomplete="email"
                        placeholder="author@onda.dz"
                        class="input-premium h-11 ps-4 pe-4 text-left text-sm"
                    />
                </div>
                <InputError :message="errors.email" />
            </div>

            <!-- Password Field -->
            <div class="space-y-1.5 text-start">
                <div class="flex items-center justify-between">
                    <Label
                        for="password"
                        class="text-xs font-semibold text-foreground"
                    >
                        {{ t('auth.password') }}
                    </Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-xs font-medium text-onda-blue-600 hover:text-onda-blue-700 dark:text-onda-blue-400"
                        :tabindex="5"
                    >
                        {{ t('auth.forgotPassword') }}
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    :placeholder="t('auth.passwordPlaceholder')"
                    class="input-premium h-11 ps-8 text-sm"
                />
                <InputError :message="errors.password" />
            </div>

            <!-- Remember Me -->
            <div class="flex items-center justify-between pt-1">
                <label
                    for="remember"
                    class="flex cursor-pointer items-center gap-2.5 text-xs text-muted-foreground select-none"
                >
                    <Checkbox id="remember" name="remember" :tabindex="3" />
                    <span>{{ t('auth.rememberMe') }}</span>
                </label>
            </div>

            <!-- Submit Button (ONDA Royal Blue) -->
            <Button
                type="submit"
                class="mt-3 h-12 w-full cursor-pointer rounded-xl bg-gradient-to-r from-onda-blue-600 to-onda-blue-700 text-sm font-semibold text-white shadow-lg shadow-onda-blue-600/25 transition-all hover:-translate-y-0.5 hover:from-onda-blue-700 hover:to-onda-blue-800 hover:shadow-onda-blue-600/40 active:translate-y-0 dark:from-onda-blue-500 dark:to-onda-blue-600 dark:hover:from-onda-blue-400 dark:hover:to-onda-blue-500"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                <span v-else class="flex items-center justify-center gap-2">
                    <span>{{ t('auth.signInBtn') }}</span>
                    <ArrowRight class="size-4 rtl:rotate-180" />
                </span>
            </Button>
        </div>

        <!-- Register Link -->
        <div
            class="mt-4 border-t border-border pt-5 text-center text-xs text-muted-foreground"
        >
            <span>{{ t('auth.noAccount') }}&nbsp;</span>
            <TextLink
                :href="register()"
                class="font-semibold text-onda-teal-600 hover:text-onda-teal-700 dark:text-onda-teal-400"
                :tabindex="6"
            >
                {{ t('auth.createAccount') }}
            </TextLink>
        </div>
    </Form>
</template>
