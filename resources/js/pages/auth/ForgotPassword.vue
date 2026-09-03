<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Mail } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineProps<{
    status?: string;
}>();

const { t } = useI18n();
</script>

<template>
    <Head :title="t('auth.forgotPasswordTitle')" />

    <!-- Card Header -->
    <div class="mb-8 space-y-2 text-center lg:text-start">
        <h1
            class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
        >
            {{ t('auth.forgotPasswordTitle') }}
        </h1>
        <p class="text-xs leading-relaxed text-muted-foreground sm:text-sm">
            {{ t('auth.forgotPasswordDesc') }}
        </p>
    </div>

    <!-- Status Alert Notice (e.g. email sent) -->
    <div
        v-if="status"
        class="mb-6 rounded-2xl border border-emerald-500/20 bg-emerald-50 p-4 text-center text-xs font-semibold text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-950/50 dark:text-emerald-300"
    >
        {{ status }}
    </div>

    <Form
        v-bind="email.form()"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <div class="space-y-4.5">
            <!-- Email Field (Always LTR) -->
            <div class="space-y-1.5 text-start">
                <Label
                    for="email"
                    class="flex items-center gap-1.5 text-xs font-semibold text-foreground"
                >
                    <Mail
                        class="size-3.5 text-onda-blue-600 dark:text-onda-blue-400"
                    />
                    <span>{{ t('auth.email') }}</span>
                    <span class="text-destructive">*</span>
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

            <!-- Submit Button with Loading Spinner -->
            <div class="pt-2">
                <Button
                    type="submit"
                    class="h-12 w-full cursor-pointer rounded-xl bg-gradient-to-r from-onda-blue-600 to-onda-blue-700 text-sm font-semibold text-white shadow-lg shadow-onda-blue-600/25 transition-all hover:-translate-y-0.5 hover:from-onda-blue-700 hover:to-onda-blue-800 hover:shadow-onda-blue-600/40 active:translate-y-0 dark:from-onda-blue-500 dark:to-onda-blue-600 dark:hover:from-onda-blue-400 dark:hover:to-onda-blue-500"
                    :disabled="processing"
                    data-test="email-password-reset-link-button"
                >
                    <Spinner v-if="processing" class="me-2 size-4 text-white" />
                    <span>{{
                        processing
                            ? t('auth.sendingResetLink')
                            : t('auth.sendResetLinkBtn')
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
