<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import WorkController from '@/actions/App/Http/Controllers/WorkController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create, index } from '@/routes/works';

const { t } = useI18n();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Works', href: index() },
            { title: 'New work', href: create() },
        ],
    },
});
</script>

<template>
    <Head :title="t('works.create.title')" />

    <div class="mx-auto w-full max-w-xl space-y-6 p-4 sm:p-6 lg:p-8">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">
                {{ t('works.create.title') }}
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ t('works.create.subtitle') }}
            </p>
        </div>

        <Form
            v-bind="WorkController.store.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="title">{{ t('works.create.titleLabel') }}</Label>
                <Input
                    id="title"
                    name="title"
                    required
                    autofocus
                    :placeholder="t('works.create.titlePlaceholder')"
                />
                <InputError :message="errors.title" />
            </div>

            <div class="grid gap-2">
                <Label for="description">{{
                    t('works.create.descriptionLabel')
                }}</Label>
                <textarea
                    id="description"
                    name="description"
                    rows="4"
                    class="flex w-full rounded-xl border border-input bg-background/50 px-3.5 py-2 text-base shadow-xs outline-none focus-visible:border-onda-blue-600 focus-visible:ring-4 focus-visible:ring-onda-blue-600/20 md:text-sm dark:bg-input/20"
                    :placeholder="t('works.create.descriptionPlaceholder')"
                />
                <InputError :message="errors.description" />
            </div>

            <Button type="submit" :disabled="processing">
                {{
                    processing
                        ? t('works.create.submitting')
                        : t('works.create.submit')
                }}
            </Button>
        </Form>
    </div>
</template>
