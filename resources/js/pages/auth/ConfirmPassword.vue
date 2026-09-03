<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ShieldCheck, Lock } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';

const { t } = useI18n();
</script>

<template>
    <Head :title="t('auth.confirmPasswordTitle')" />

    <!-- Header Section -->
    <div class="mb-7 space-y-2 text-center lg:text-start">
        <div
            class="mb-2 inline-flex items-center gap-2 rounded-full border border-onda-teal-500/30 bg-onda-teal-500/10 px-3 py-1 text-xs font-semibold text-onda-teal-600 dark:text-onda-teal-300"
        >
            <Lock class="size-3.5" />
            <span>{{ t('auth.securePortal') }}</span>
        </div>
        <h1
            class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
        >
            {{ t('auth.confirmPasswordTitle') }}
        </h1>
        <p class="text-xs leading-relaxed text-muted-foreground sm:text-sm">
            {{ t('auth.confirmPasswordDesc') }}
        </p>
    </div>

    <!-- Passkey Confirmation Component -->
    <PasskeyVerify
        :routes="{
            options: confirmOptions(),
            submit: confirmStore(),
        }"
        :label="t('auth.confirmWithPasskey')"
        :loading-label="t('auth.confirmingPassword')"
        :separator="t('auth.orConfirmWithPassword')"
    />

    <!-- Password Confirmation Form -->
    <Form
        v-bind="store.form()"
        reset-on-success
        v-slot="{ errors, processing }"
    >
        <div class="space-y-6">
            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label
                        for="password"
                        class="text-xs font-semibold text-foreground sm:text-sm"
                    >
                        {{ t('auth.password') }}
                    </Label>
                    <span class="font-mono text-[11px] text-muted-foreground">
                        (LTR)
                    </span>
                </div>

                <div class="relative">
                    <PasswordInput
                        id="password"
                        name="password"
                        dir="ltr"
                        class="input-premium block h-11 w-full rounded-xl border border-input bg-background/50 px-3.5 text-left font-mono text-sm text-foreground shadow-xs transition-all duration-200 focus:border-onda-blue-500 focus:bg-background focus:ring-4 focus:ring-onda-blue-500/15 focus:outline-none"
                        required
                        autocomplete="current-password"
                        autofocus
                    />
                </div>

                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center pt-2">
                <Button
                    type="submit"
                    class="h-12 w-full cursor-pointer rounded-xl bg-gradient-to-r from-onda-blue-600 to-onda-blue-700 text-sm font-semibold text-white shadow-lg shadow-onda-blue-600/25 transition-all hover:-translate-y-0.5 hover:from-onda-blue-700 hover:to-onda-blue-800 hover:shadow-onda-blue-600/40 active:translate-y-0 dark:from-onda-blue-500 dark:to-onda-blue-600 dark:hover:from-onda-blue-400 dark:hover:to-onda-blue-500"
                    :disabled="processing"
                    data-test="confirm-password-button"
                >
                    <Spinner v-if="processing" class="me-2 size-4 text-white" />
                    <ShieldCheck v-else class="me-2 size-4" />
                    <span>{{
                        processing
                            ? t('auth.confirmingPassword')
                            : t('auth.confirmPasswordBtn')
                    }}</span>
                </Button>
            </div>
        </div>
    </Form>
</template>
