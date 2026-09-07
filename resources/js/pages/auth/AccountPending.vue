<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';

const { t } = useI18n();
</script>

<template>
    <Head :title="t('auth.accountPendingTitle')" />

    <div class="mb-8 space-y-2 text-center lg:text-start">
        <h1
            class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
        >
            {{ t('auth.accountPendingTitle') }}
        </h1>
        <p class="text-xs leading-relaxed text-muted-foreground sm:text-sm">
            {{ t('auth.accountPendingDesc') }}
        </p>
    </div>

    <div
        class="mb-6 rounded-2xl border border-amber-500/20 bg-amber-50 p-4 text-center text-xs font-semibold text-amber-800 dark:border-amber-500/30 dark:bg-amber-950/50 dark:text-amber-300"
    >
        <bdi dir="ltr">{{ t('sidebar.hotline.number') }}</bdi>
    </div>

    <Form v-bind="logout.form()" class="flex flex-col gap-5">
        <Button
            type="submit"
            variant="outline"
            class="h-12 w-full cursor-pointer rounded-xl text-sm font-semibold"
            data-test="logout-button"
        >
            {{ t('auth.logOut') }}
        </Button>
    </Form>
</template>
