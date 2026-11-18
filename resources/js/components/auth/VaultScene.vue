<script setup lang="ts">
import { BookOpen, Check, Film, Music, ShieldCheck } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import type { StageState } from '@/composables/useAuthStage';
import { prefersReducedMotion } from '@/composables/useReducedMotion';

/**
 * "The vault of protected works": a stack of deposited documents under a
 * hovering shield seal, inside two slow rings. Pure CSS 3D (perspective +
 * preserve-3d) — no WebGL, no library — plus ~25 lines of pointer parallax.
 *
 * Costs nothing when it can't be seen:
 *  - paused (animation-play-state) while offscreen or the tab is hidden;
 *  - parallax only on fine pointers, one rAF loop that stops when settled;
 *  - prefers-reduced-motion: every animation is off and the particles are
 *    removed (CSS), parallax never starts (JS) — a still, fully legible scene.
 *
 * Colours are the brand constants (--onda-blue / --onda-green) and tokens, so
 * the scene follows the palette. It is decorative: aria-hidden.
 */
withDefaults(defineProps<{ state?: StageState }>(), {
    state: 'idle',
});

const scene = useTemplateRef<HTMLElement>('scene');
const rig = useTemplateRef<HTMLElement>('rig');
const paused = ref(false);

let observer: IntersectionObserver | null = null;
let resizer: ResizeObserver | null = null;
let frame = 0;
let tx = 0;
let ty = 0;
let cx = 0;
let cy = 0;
let onScreen = true;

function updatePause(): void {
    paused.value = !onScreen || document.hidden;
}

/** Fit the fixed-size rig into whatever box the layout gives the scene. */
function fit(): void {
    const box = scene.value;

    if (!box || !rig.value) {
        return;
    }

    const scale = Math.min(box.clientWidth / 640, box.clientHeight / 470, 1.1);

    rig.value.style.setProperty('--s', String(Math.max(scale, 0.28)));
}

function tick(): void {
    cx += (tx - cx) * 0.08;
    cy += (ty - cy) * 0.08;
    rig.value?.style.setProperty('--px', cx.toFixed(3));
    rig.value?.style.setProperty('--py', cy.toFixed(3));

    // Settled: stop the loop until the pointer moves again.
    frame =
        Math.abs(tx - cx) > 0.002 || Math.abs(ty - cy) > 0.002
            ? requestAnimationFrame(tick)
            : 0;
}

function onPointer(event: PointerEvent): void {
    if (paused.value || event.pointerType !== 'mouse') {
        return;
    }

    tx = (event.clientX / window.innerWidth - 0.5) * 2;
    ty = (event.clientY / window.innerHeight - 0.5) * 2;

    if (!frame) {
        frame = requestAnimationFrame(tick);
    }
}

onMounted(() => {
    fit();
    resizer = new ResizeObserver(fit);

    if (scene.value) {
        resizer.observe(scene.value);

        observer = new IntersectionObserver(([entry]) => {
            onScreen = entry.isIntersecting;
            updatePause();
        });
        observer.observe(scene.value);
    }

    document.addEventListener('visibilitychange', updatePause);

    if (
        !prefersReducedMotion() &&
        window.matchMedia('(pointer: fine)').matches
    ) {
        window.addEventListener('pointermove', onPointer, { passive: true });
    }
});

onBeforeUnmount(() => {
    observer?.disconnect();
    resizer?.disconnect();
    cancelAnimationFrame(frame);
    document.removeEventListener('visibilitychange', updatePause);
    window.removeEventListener('pointermove', onPointer);
});
</script>

<template>
    <div
        ref="scene"
        class="vault-scene"
        :class="[`is-${state}`, { 'is-paused': paused }]"
        aria-hidden="true"
    >
        <div ref="rig" class="rig">
            <div class="floor" />
            <div class="r2 ring" />
            <div class="r1 ring" />
            <div class="drop-shadow" />

            <div
                class="doc"
                style="--i: 0; --dx: -26px; --dy: 30px; --z: 22px; --rot: -9deg"
            >
                <Film />
                <i /><i /><i /><i />
            </div>
            <div
                class="doc"
                style="--i: 1; --dx: 14px; --dy: 10px; --z: 64px; --rot: 6deg"
            >
                <BookOpen />
                <i /><i /><i /><i />
            </div>
            <div
                class="doc"
                style="
                    --i: 2;
                    --dx: -8px;
                    --dy: -10px;
                    --z: 106px;
                    --rot: -3deg;
                "
            >
                <Music />
                <i /><i /><i /><i />
            </div>
            <div
                class="doc cert"
                style="--i: 3; --dx: 0px; --dy: -26px; --z: 150px; --rot: 0deg"
            >
                <div class="cert-head"><span>ONDA</span><span>©</span></div>
                <i /><i /><i /><i />
                <div class="stamp"><Check /></div>
            </div>

            <div class="seal">
                <div class="seal-inner">
                    <span class="beam" />
                    <span class="halo" />
                    <span class="halo h2" />
                    <div class="seal-core"><ShieldCheck /></div>
                </div>
            </div>

            <div class="pt" style="--x: -70px; --y: 40px">
                <span style="--d: 6s; --delay: 0s" />
            </div>
            <div class="pt" style="--x: 60px; --y: -30px">
                <span style="--d: 7s; --delay: -2s" />
            </div>
            <div class="pt" style="--x: -30px; --y: -80px">
                <span style="--d: 5.5s; --delay: -4s" />
            </div>
            <div class="pt" style="--x: 90px; --y: 50px">
                <span style="--d: 6.5s; --delay: -1s" />
            </div>
            <div class="pt" style="--x: -100px; --y: -20px">
                <span style="--d: 7.5s; --delay: -3s" />
            </div>
            <div class="pt" style="--x: 20px; --y: 90px">
                <span style="--d: 6s; --delay: -5s" />
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Scene colours: brand constants + white tints. The stage is dark in both
   themes, so these do not switch with the appearance. */
.vault-scene {
    --glow: color-mix(in srgb, var(--onda-green) 55%, white);
    --sky: color-mix(in srgb, var(--onda-blue) 40%, white);
    position: relative;
    flex: 1 1 auto;
    min-height: 13rem;
    perspective: 1200px;
    perspective-origin: 50% 35%;
}

.rig {
    --px: 0;
    --py: 0;
    --s: 1;
    position: absolute;
    left: 50%;
    top: 58%;
    width: 0;
    height: 0;
    transform-style: preserve-3d;
    transform: scale3d(var(--s), var(--s), var(--s))
        rotateX(calc(56deg + var(--py) * -5deg))
        rotateZ(calc(-38deg + var(--px) * 7deg));
}

.rig > * {
    position: absolute;
    transform-style: preserve-3d;
}

.floor {
    width: 560px;
    height: 560px;
    left: -280px;
    top: -280px;
    border-radius: 50%;
    background: radial-gradient(
        circle,
        color-mix(in srgb, var(--onda-green) 28%, transparent),
        color-mix(in srgb, var(--onda-green) 6%, transparent) 45%,
        transparent 70%
    );
}

.floor::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background-image:
        linear-gradient(rgb(255 255 255 / 0.07) 1px, transparent 1px),
        linear-gradient(90deg, rgb(255 255 255 / 0.07) 1px, transparent 1px);
    background-size: 28px 28px;
    mask-image: radial-gradient(circle, #000 25%, transparent 66%);
}

.ring {
    border-radius: 50%;
    animation: spin 40s linear infinite;
}

.ring.r1 {
    width: 380px;
    height: 380px;
    left: -190px;
    top: -190px;
    border: 1px dashed color-mix(in srgb, var(--glow) 55%, transparent);
}

.ring.r2 {
    width: 480px;
    height: 480px;
    left: -240px;
    top: -240px;
    border: 1px solid rgb(255 255 255 / 0.1);
    animation-duration: 70s;
    animation-direction: reverse;
}

.ring.r2::after {
    content: '';
    position: absolute;
    top: 50%;
    left: -4px;
    width: 8px;
    height: 8px;
    margin-top: -4px;
    border-radius: 50%;
    background: var(--glow);
    box-shadow: 0 0 12px 3px color-mix(in srgb, var(--glow) 70%, transparent);
}

@keyframes spin {
    from {
        transform: translateZ(1px) rotate(0);
    }

    to {
        transform: translateZ(1px) rotate(360deg);
    }
}

.drop-shadow {
    width: 270px;
    height: 310px;
    left: -135px;
    top: -155px;
    transform: translateZ(2px);
    background: radial-gradient(closest-side, rgb(0 8 16 / 0.55), transparent);
}

.doc {
    width: 190px;
    height: 244px;
    left: -95px;
    top: -122px;
    padding: 18px;
    border-radius: 14px;
    transform: translate3d(var(--dx), var(--dy), var(--z)) rotate(var(--rot));
    animation: bob 7s ease-in-out infinite;
    animation-delay: calc(var(--i) * -1.4s);
    background: linear-gradient(
        150deg,
        color-mix(in srgb, var(--sky) 30%, transparent),
        color-mix(in srgb, var(--onda-blue) 14%, transparent)
    );
    border: 1px solid color-mix(in srgb, var(--sky) 45%, transparent);
    box-shadow: inset 0 1px 0 rgb(255 255 255 / 0.18);
    display: flex;
    flex-direction: column;
    gap: 9px;
    color: var(--glow);
}

@keyframes bob {
    0%,
    100% {
        transform: translate3d(var(--dx), var(--dy), var(--z))
            rotate(var(--rot));
    }

    50% {
        transform: translate3d(var(--dx), var(--dy), calc(var(--z) + 14px))
            rotate(var(--rot));
    }
}

.doc > svg {
    width: 26px;
    height: 26px;
    margin-bottom: 6px;
}

.doc > i {
    display: block;
    height: 6px;
    border-radius: 3px;
    background: rgb(255 255 255 / 0.22);
}

.doc > i:nth-of-type(2) {
    width: 82%;
}

.doc > i:nth-of-type(3) {
    width: 64%;
}

.doc > i:nth-of-type(4) {
    width: 74%;
}

/* The certificate is a white plate: the same rule as the official logo. */
.doc.cert {
    background: linear-gradient(160deg, #fff, #e6eff6);
    border-color: #fff;
    color: var(--onda-blue);
    box-shadow:
        inset 0 1px 0 #fff,
        0 0 40px color-mix(in srgb, var(--glow) 30%, transparent);
}

.doc.cert > i {
    background: color-mix(in srgb, var(--onda-blue) 16%, transparent);
}

.cert-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 4px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.14em;
    color: var(--onda-blue);
}

.stamp {
    position: absolute;
    inset-inline-end: 16px;
    bottom: 16px;
    display: grid;
    place-items: center;
    width: 48px;
    height: 48px;
    border-radius: 50%;
    border: 2px dashed var(--onda-green);
    color: var(--onda-green);
    opacity: 0.25;
    transition:
        opacity 0.3s,
        transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.stamp > svg {
    width: 22px;
    height: 22px;
}

.seal {
    width: 80px;
    height: 80px;
    left: -40px;
    top: -40px;
    transform: translate3d(0, -10px, 250px) rotateZ(38deg) rotateX(-56deg);
}

.seal-inner {
    position: relative;
    width: 100%;
    height: 100%;
    animation: hover 5s ease-in-out infinite;
    will-change: transform;
}

@keyframes hover {
    0%,
    100% {
        transform: translateY(0);
    }

    50% {
        transform: translateY(-8px);
    }
}

.seal-core {
    position: relative;
    z-index: 1;
    display: grid;
    place-items: center;
    width: 100%;
    height: 100%;
    border-radius: 50%;
    color: #fff;
    background: radial-gradient(
        circle at 35% 30%,
        color-mix(in srgb, var(--onda-green) 55%, white),
        var(--onda-green) 55%,
        color-mix(in srgb, var(--onda-green) 60%, black)
    );
    box-shadow:
        0 0 0 6px color-mix(in srgb, var(--onda-green) 18%, transparent),
        0 0 46px color-mix(in srgb, var(--onda-green) 60%, transparent),
        inset 0 2px 6px rgb(255 255 255 / 0.45);
}

.seal-core > svg {
    width: 36px;
    height: 36px;
    stroke-width: 2;
}

.halo {
    position: absolute;
    inset: -6px;
    border-radius: 50%;
    border: 1.5px solid color-mix(in srgb, var(--glow) 65%, transparent);
    animation: halo 3.2s ease-out infinite;
}

.halo.h2 {
    animation-delay: 1.6s;
}

@keyframes halo {
    from {
        transform: scale(1);
        opacity: 0.9;
    }

    to {
        transform: scale(1.9);
        opacity: 0;
    }
}

.beam {
    position: absolute;
    left: 50%;
    top: 55%;
    width: 2px;
    height: 120px;
    margin-left: -1px;
    background: linear-gradient(
        color-mix(in srgb, var(--glow) 75%, transparent),
        transparent
    );
}

.pt {
    width: 0;
    height: 0;
    transform: translate3d(var(--x), var(--y), 60px) rotateZ(38deg)
        rotateX(-56deg);
}

.pt span {
    position: absolute;
    width: 4px;
    height: 4px;
    margin: -2px;
    border-radius: 50%;
    background: var(--glow);
    box-shadow: 0 0 8px 2px color-mix(in srgb, var(--glow) 70%, transparent);
    opacity: 0;
    animation: rise var(--d, 6s) ease-in infinite;
    animation-delay: var(--delay, 0s);
}

@keyframes rise {
    0% {
        transform: translateY(0);
        opacity: 0;
    }

    15% {
        opacity: 1;
    }

    100% {
        transform: translateY(-170px);
        opacity: 0;
    }
}

/* Reactions to the form: working, then stamped. */
.is-busy .ring.r1 {
    border-color: var(--glow);
}

.is-busy .halo {
    animation-duration: 1.1s;
}

.is-stamped .seal-inner {
    animation: stamp 1.1s cubic-bezier(0.22, 1, 0.36, 1) 1;
}

@keyframes stamp {
    0% {
        transform: translateY(0);
    }

    40% {
        transform: translateY(70px);
    }

    55% {
        transform: translateY(62px);
    }

    100% {
        transform: translateY(0);
    }
}

.is-stamped .stamp {
    opacity: 1;
    border-style: solid;
    background: color-mix(in srgb, var(--onda-green) 14%, transparent);
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--onda-green) 12%, transparent);
}

.is-paused * {
    animation-play-state: paused !important;
}

/* Static version: no motion at all, particles gone, still readable. */
@media (prefers-reduced-motion: reduce) {
    .vault-scene *,
    .vault-scene *::before,
    .vault-scene *::after {
        animation: none !important;
        transition: none !important;
    }

    .pt {
        display: none;
    }

    .halo {
        opacity: 0.5;
    }
}
</style>
