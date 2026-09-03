<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import {
    Mail,
    ShieldCheck,
    FileCheck,
    Lock,
    CheckCircle2,
    Send,
    LogOut,
    HelpCircle,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineProps<{
    status?: string;
}>();

const { t } = useI18n();
</script>

<template>
    <Head :title="t('auth.verifyEmailTitle')" />

    <!-- Animated Email Illustration Header -->
    <div class="mb-7 flex flex-col items-center text-center">
        <!-- Floating Ambient Icon -->
        <div class="relative mb-5 flex size-20 items-center justify-center">
            <div
                class="absolute inset-0 animate-pulse rounded-3xl bg-gradient-to-tr from-onda-blue-600/30 to-onda-teal-500/30 blur-xl"
            />
            <div
                class="relative flex size-18 items-center justify-center rounded-2xl border border-onda-blue-500/30 bg-gradient-to-br from-onda-blue-900/60 via-card to-onda-teal-900/40 shadow-xl backdrop-blur-md"
            >
                <Mail
                    class="size-8 text-onda-blue-500 dark:text-onda-blue-400"
                />
                <span
                    class="absolute -end-1 -top-1 flex size-5 items-center justify-center rounded-full bg-onda-teal-500 text-[10px] font-bold text-white shadow-md"
                >
                    ✓
                </span>
            </div>
        </div>

        <h1
            class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
        >
            {{ t('auth.verifyEmailTitle') }}
        </h1>
        <p
            class="mt-2.5 max-w-md text-xs leading-relaxed text-muted-foreground sm:text-sm"
        >
            {{ t('auth.verifyEmailSubtitle') }}
        </p>
    </div>

    <!-- Status Alert Notice (New Link Sent) -->
    <transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="transform -translate-y-2 opacity-0"
        enter-to-class="transform translate-y-0 opacity-100"
    >
        <div
            v-if="status === 'verification-link-sent'"
            class="mb-6 flex items-center gap-3 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-start text-xs font-semibold text-emerald-800 shadow-xs backdrop-blur-xs dark:text-emerald-300"
        >
            <CheckCircle2
                class="size-5 shrink-0 text-emerald-600 dark:text-emerald-400"
            />
            <span>{{ t('auth.verificationLinkSent') }}</span>
        </div>
    </transition>

    <!-- Context & Feature Benefits Cards -->
    <div class="mb-7 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <!-- Benefit 1 -->
        <div
            class="rounded-2xl border border-border/80 bg-muted/30 p-3.5 text-start transition-all hover:border-onda-blue-500/30 hover:bg-muted/50"
        >
            <div
                class="mb-2 flex size-7 items-center justify-center rounded-lg bg-onda-blue-500/10 text-onda-blue-600 dark:text-onda-blue-400"
            >
                <ShieldCheck class="size-4" />
            </div>
            <h2 class="text-xs leading-snug font-semibold text-foreground">
                {{ t('auth.verifyPill1Title') }}
            </h2>
            <p class="mt-1 text-[11px] leading-normal text-muted-foreground">
                {{ t('auth.verifyPill1Desc') }}
            </p>
        </div>

        <!-- Benefit 2 -->
        <div
            class="rounded-2xl border border-border/80 bg-muted/30 p-3.5 text-start transition-all hover:border-onda-teal-500/30 hover:bg-muted/50"
        >
            <div
                class="mb-2 flex size-7 items-center justify-center rounded-lg bg-onda-teal-500/10 text-onda-teal-600 dark:text-onda-teal-400"
            >
                <FileCheck class="size-4" />
            </div>
            <h2 class="text-xs leading-snug font-semibold text-foreground">
                {{ t('auth.verifyPill2Title') }}
            </h2>
            <p class="mt-1 text-[11px] leading-normal text-muted-foreground">
                {{ t('auth.verifyPill2Desc') }}
            </p>
        </div>

        <!-- Benefit 3 -->
        <div
            class="rounded-2xl border border-border/80 bg-muted/30 p-3.5 text-start transition-all hover:border-onda-blue-500/30 hover:bg-muted/50"
        >
            <div
                class="mb-2 flex size-7 items-center justify-center rounded-lg bg-onda-blue-500/10 text-onda-blue-600 dark:text-onda-blue-400"
            >
                <Lock class="size-4" />
            </div>
            <h2 class="text-xs leading-snug font-semibold text-foreground">
                {{ t('auth.verifyPill3Title') }}
            </h2>
            <p class="mt-1 text-[11px] leading-normal text-muted-foreground">
                {{ t('auth.verifyPill3Desc') }}
            </p>
        </div>
    </div>

    <!-- Actions Section -->
    <Form
        v-bind="send.form()"
        class="flex flex-col gap-4 text-center"
        v-slot="{ processing }"
    >
        <!-- Resend Action Button -->
        <Button
            type="submit"
            class="h-12 w-full cursor-pointer rounded-xl bg-gradient-to-r from-onda-blue-600 to-onda-blue-700 text-sm font-semibold text-white shadow-lg shadow-onda-blue-600/25 transition-all hover:-translate-y-0.5 hover:from-onda-blue-700 hover:to-onda-blue-800 hover:shadow-onda-blue-600/40 active:translate-y-0 dark:from-onda-blue-500 dark:to-onda-blue-600 dark:hover:from-onda-blue-400 dark:hover:to-onda-blue-500"
            :disabled="processing"
            data-test="resend-verification-email-button"
        >
            <Spinner v-if="processing" class="me-2 size-4 text-white" />
            <Send v-else class="me-2 size-4" />
            <span>{{
                processing
                    ? t('auth.resendingVerification')
                    : t('auth.resendVerificationBtn')
            }}</span>
        </Button>

        <!-- Helpful Spam Notice -->
        <div
            class="flex items-start gap-2.5 rounded-xl border border-border/60 bg-muted/20 p-3 text-start"
        >
            <HelpCircle
                class="mt-0.5 size-4 shrink-0 text-muted-foreground/70"
            />
            <span class="text-[11px] leading-relaxed text-muted-foreground">
                {{ t('auth.spamNotice') }}
            </span>
        </div>

        <!-- Log Out Link -->
        <div class="pt-2">
            <TextLink
                :href="logout()"
                as="button"
                class="inline-flex cursor-pointer items-center gap-1.5 text-xs font-semibold text-muted-foreground transition-colors hover:text-destructive"
            >
                <LogOut class="size-3.5 rtl:rotate-180" />
                <span>{{ t('auth.logOut') }}</span>
            </TextLink>
        </div>
    </Form>
</template>
