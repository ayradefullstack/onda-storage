<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Mail, Lock } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { update } from '@/routes/password';

const props = defineProps<{
    token: string;
    email: string;
    passwordRules: string;
}>();

const { t } = useI18n();
const inputEmail = ref(props.email);
</script>

<template>
    <Head :title="t('auth.resetPasswordTitle')" />

    <!-- Card Header -->
    <div class="mb-8 space-y-2 text-center lg:text-start">
        <h1
            class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
        >
            {{ t('auth.resetPasswordTitle') }}
        </h1>
        <p class="text-xs leading-relaxed text-muted-foreground sm:text-sm">
            {{ t('auth.resetPasswordDesc') }}
        </p>
    </div>

    <Form
        v-bind="update.form()"
        :transform="(data) => ({ ...data, token, email })"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <div class="space-y-4.5">
            <!-- Email Field (Read-only, Always LTR) -->
            <div class="space-y-1.5 text-start">
                <Label
                    for="email"
                    class="flex items-center gap-1.5 text-xs font-semibold text-foreground"
                >
                    <Mail
                        class="size-3.5 text-onda-blue-600 dark:text-onda-blue-400"
                    />
                    <span>{{ t('auth.email') }}</span>
                </Label>
                <div class="relative">
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        autocomplete="email"
                        v-model="inputEmail"
                        dir="ltr"
                        readonly
                        class="input-premium h-11 cursor-not-allowed bg-muted/40 ps-4 pe-4 text-left text-sm opacity-80"
                    />
                </div>
                <InputError :message="errors.email" />
            </div>

            <!-- New Password Field -->
            <div class="space-y-1.5 text-start">
                <Label
                    for="password"
                    class="flex items-center gap-1.5 text-xs font-semibold text-foreground"
                >
                    <Lock
                        class="size-3.5 text-onda-blue-600 dark:text-onda-blue-400"
                    />
                    <span>{{ t('auth.newPassword') }}</span>
                    <span class="text-destructive">*</span>
                </Label>
                <PasswordInput
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    autofocus
                    :tabindex="1"
                    :placeholder="t('auth.passwordPlaceholder')"
                    :passwordrules="passwordRules"
                    class="input-premium h-11 ps-8 text-sm"
                />
                <InputError :message="errors.password" />
            </div>

            <!-- Confirm New Password Field -->
            <div class="space-y-1.5 text-start">
                <Label
                    for="password_confirmation"
                    class="flex items-center gap-1.5 text-xs font-semibold text-foreground"
                >
                    <Lock
                        class="size-3.5 text-onda-blue-600 dark:text-onda-blue-400"
                    />
                    <span>{{ t('auth.confirmNewPassword') }}</span>
                    <span class="text-destructive">*</span>
                </Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    autocomplete="new-password"
                    :tabindex="2"
                    :placeholder="t('auth.passwordPlaceholder')"
                    :passwordrules="passwordRules"
                    class="input-premium h-11 ps-8 text-sm"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <!-- Submit Button with Loading Spinner -->
            <div class="pt-2">
                <Button
                    type="submit"
                    class="h-12 w-full cursor-pointer rounded-xl bg-gradient-to-r from-onda-blue-600 to-onda-blue-700 text-sm font-semibold text-white shadow-lg shadow-onda-blue-600/25 transition-all hover:-translate-y-0.5 hover:from-onda-blue-700 hover:to-onda-blue-800 hover:shadow-onda-blue-600/40 active:translate-y-0 dark:from-onda-blue-500 dark:to-onda-blue-600 dark:hover:from-onda-blue-400 dark:hover:to-onda-blue-500"
                    :tabindex="3"
                    :disabled="processing"
                    data-test="reset-password-button"
                >
                    <Spinner v-if="processing" class="me-2 size-4 text-white" />
                    <span>{{
                        processing
                            ? t('auth.resettingPassword')
                            : t('auth.resetPasswordBtn')
                    }}</span>
                </Button>
            </div>
        </div>

        <!-- Return to Login Link -->
        <div
            class="mt-2 flex items-center justify-center gap-1.5 text-center text-xs text-muted-foreground"
        >
            <span>{{ t('auth.orReturnToLogin') }}</span>
            <TextLink
                :href="login()"
                class="font-semibold text-onda-blue-600 hover:underline dark:text-onda-blue-400"
            >
                {{ t('auth.backToLogin') }}
            </TextLink>
        </div>
    </Form>
</template>
