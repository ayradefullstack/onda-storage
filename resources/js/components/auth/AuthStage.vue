<script setup lang="ts">
import { Activity, Lock, ShieldCheck } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import VaultScene from '@/components/auth/VaultScene.vue';
import { useAuthStage } from '@/composables/useAuthStage';

/**
 * The brand panel beside (desktop) or above (mobile) the form card: identity,
 * the vault scene, three guarantees and the institution line. It stays dark in
 * both appearances — it is the brand anchor — and takes its colours from the
 * `--brand-panel*` tokens. The official logo is never recoloured: it sits on a
 * white plate.
 */
const { t } = useI18n();
const { stageState } = useAuthStage();

const features = [
    { icon: ShieldCheck, key: 'auth.pill1' },
    { icon: Activity, key: 'auth.pill2' },
    { icon: Lock, key: 'auth.pill3' },
] as const;
</script>

<template>
    <aside class="auth-stage" aria-labelledby="auth-stage-title">
        <div class="stage-top">
            <div class="brand">
                <span class="plate">
                    <img
                        src="/assets/logos/onda-logo.png"
                        alt="ONDA"
                        width="1181"
                        height="671"
                    />
                </span>
            </div>
            <span class="badge">
                <span class="pulse" aria-hidden="true" />
                <span>{{ t('auth.securePortal') }}</span>
            </span>
        </div>

        <div class="stage-text">
            <h2 id="auth-stage-title">{{ t('auth.sideHeading') }}</h2>
            <p class="lead">{{ t('auth.sideSubtitle') }}</p>
        </div>

        <VaultScene :state="stageState" />

        <ul class="features">
            <li v-for="feature in features" :key="feature.key" class="feature">
                <span class="feature-ico" aria-hidden="true">
                    <component :is="feature.icon" />
                </span>
                <span>{{ t(feature.key) }}</span>
            </li>
        </ul>

        <div class="stage-foot">
            <span>{{ t('authUi.republic') }}</span>
            <span lang="fr" dir="ltr"
                >Office National des Droits d'Auteur et des Droits Voisins</span
            >
        </div>
    </aside>
</template>

<style scoped>
.auth-stage {
    --glow: color-mix(in srgb, var(--onda-green) 55%, white);
    position: sticky;
    top: 1.25rem;
    align-self: start;
    display: flex;
    flex-direction: column;
    height: calc(100dvh - 7.5rem);
    min-height: 37.5rem;
    max-height: 57.5rem;
    padding: clamp(1.75rem, 3vw, 2.75rem);
    overflow: hidden;
    isolation: isolate;
    border-radius: 1.75rem;
    color: var(--brand-panel-foreground);
    background:
        radial-gradient(
            120% 70% at 85% 0%,
            color-mix(in srgb, var(--onda-blue) 45%, transparent),
            transparent 60%
        ),
        radial-gradient(
            90% 60% at 0% 100%,
            var(--brand-panel-glow),
            transparent 65%
        ),
        var(--brand-panel);
    box-shadow:
        0 50px 100px -50px rgb(2 12 24 / 0.75),
        inset 0 1px 0 rgb(255 255 255 / 0.08),
        inset 0 0 0 1px rgb(255 255 255 / 0.04);
    animation: stage-in 1s cubic-bezier(0.22, 1, 0.36, 1) both;
}

@keyframes stage-in {
    from {
        opacity: 0;
        transform: translateY(16px) scale(0.985);
    }
}

.stage-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem;
}

/* White plate: the logo is never recoloured, on dark or brand surfaces. */
.plate {
    display: inline-flex;
    align-items: center;
    padding: 0.4rem 0.75rem;
    border-radius: 0.875rem;
    background: #fff;
    box-shadow: 0 6px 18px -8px rgb(0 0 0 / 0.5);
}

.plate img {
    display: block;
    height: 2.25rem;
    width: auto;
}

.badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.3125rem 0.75rem;
    border-radius: 999px;
    background: color-mix(in srgb, var(--onda-green) 16%, transparent);
    border: 1px solid color-mix(in srgb, var(--glow) 40%, transparent);
    color: var(--glow);
    font-size: 0.78rem;
    font-weight: 600;
}

.pulse {
    position: relative;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--glow);
}

.pulse::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background: var(--glow);
    animation: pulse 2.4s ease-out infinite;
}

@keyframes pulse {
    to {
        transform: scale(3);
        opacity: 0;
    }
}

h2 {
    margin: 1.75rem 0 0.5rem;
    max-width: 35rem;
    font-size: clamp(1.625rem, 2.5vw, 2.25rem);
    line-height: 1.3;
    font-weight: 700;
    color: #fff;
}

:global(:lang(en)) h2,
:global(:lang(fr)) h2 {
    letter-spacing: -0.02em;
}

.lead {
    margin: 0;
    max-width: 31rem;
    color: var(--brand-panel-muted);
    font-size: 0.97rem;
}

.features {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.625rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.feature {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
    padding: 0.875rem;
    border-radius: 1rem;
    background: linear-gradient(
        160deg,
        rgb(255 255 255 / 0.07),
        rgb(255 255 255 / 0.02)
    );
    border: 1px solid rgb(255 255 255 / 0.09);
    font-size: 0.8rem;
    font-weight: 600;
    line-height: 1.5;
    color: var(--brand-panel-foreground);
    transition:
        transform 0.3s cubic-bezier(0.22, 1, 0.36, 1),
        border-color 0.3s;
}

.feature:hover {
    transform: translateY(-2px);
    border-color: color-mix(in srgb, var(--glow) 40%, transparent);
}

.feature-ico {
    display: grid;
    place-items: center;
    width: 2.125rem;
    height: 2.125rem;
    border-radius: 0.625rem;
    background: color-mix(in srgb, var(--onda-green) 20%, transparent);
    color: var(--glow);
}

.feature-ico svg {
    width: 1.125rem;
    height: 1.125rem;
}

.stage-foot {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-top: 1rem;
    padding-top: 0.875rem;
    border-top: 1px solid rgb(255 255 255 / 0.1);
    font-size: 0.75rem;
    color: var(--brand-panel-muted);
}

@media (max-height: 780px) and (min-width: 900px) {
    .features {
        display: none;
    }

    h2 {
        margin-top: 1.25rem;
    }
}

@media (max-width: 1100px) and (min-width: 900px) {
    .features {
        grid-template-columns: 1fr;
        gap: 0.375rem;
    }

    .feature {
        flex-direction: row;
        align-items: center;
        padding: 0.625rem 0.75rem;
    }
}

/* Mobile and tablet: a compact header band above the form. */
@media (max-width: 899px) {
    .auth-stage {
        position: relative;
        top: auto;
        height: auto;
        min-height: 0;
        max-height: none;
        padding: 1.25rem;
        border-radius: 1.375rem;
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 0.8fr);
        grid-template-areas:
            'top top'
            'text scene';
        column-gap: 0.5rem;
    }

    .stage-top {
        grid-area: top;
    }

    .stage-text {
        grid-area: text;
        align-self: center;
    }

    :deep(.vault-scene) {
        grid-area: scene;
        height: 12.5rem;
        min-height: 0;
    }

    h2 {
        font-size: 1.375rem;
        margin: 1rem 0 0.375rem;
    }

    .lead {
        font-size: 0.875rem;
    }

    .features,
    .stage-foot {
        display: none;
    }
}

@media (max-width: 599px) {
    .auth-stage {
        grid-template-columns: minmax(0, 1fr);
        grid-template-areas:
            'top'
            'scene'
            'text';
        padding: 1.125rem;
    }

    .lead {
        display: none;
    }

    h2 {
        margin: 0.125rem 0 0;
        font-size: 1.1875rem;
        text-align: center;
    }

    :deep(.vault-scene) {
        height: 9.375rem;
    }

    .plate img {
        height: 1.875rem;
    }
}

@media (prefers-reduced-motion: reduce) {
    .auth-stage,
    .pulse::after {
        animation: none;
    }

    .feature {
        transition: none;
    }
}
</style>
