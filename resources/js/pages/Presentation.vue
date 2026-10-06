<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Printer, Upload, Lock } from '@lucide/vue';
import { computed, h, onBeforeUnmount, onMounted, ref } from 'vue';
import type { Slots } from 'vue';

// Public, standalone page: no sidebar, no header, no app layout.
defineOptions({ layout: () => null });

const BASE_W = 1280;
const BASE_H = 720;
const CONTROLS_H = 64;
const STACK_BREAKPOINT = 900;
const total = 14;

const current = ref(0);
const vw = ref(1280);
const vh = ref(720);

const stacked = computed(() => vw.value < STACK_BREAKPOINT);

// Every slide is drawn at 1280x720 and scaled by --s. The deck fits the
// viewport; on narrow screens the slides stack vertically at phone width.
const scale = computed(() => {
    if (stacked.value) {
        return Math.max(0.2, (vw.value - 32) / BASE_W);
    }

    return Math.max(
        0.2,
        Math.min(
            (vw.value - 48) / BASE_W,
            (vh.value - CONTROLS_H - 48) / BASE_H,
        ),
    );
});

const frameW = computed(() => scale.value * BASE_W);
const frameH = computed(() => scale.value * BASE_H);

const trackStyle = computed(() => {
    if (stacked.value) {
        return {};
    }

    const x = (vw.value - frameW.value) / 2 - current.value * frameW.value;
    const y = (vh.value - CONTROLS_H - frameH.value) / 2;

    return { transform: `translate(${x}px, ${y}px)` };
});

const rootStyle = computed(() => ({ '--s': `${scale.value}` }));

const frameClass = (i: number) => ({
    'is-hidden': !stacked.value && i !== current.value,
});

function go(index: number) {
    current.value = Math.min(total - 1, Math.max(0, index));

    try {
        history.replaceState(null, '', `#${current.value + 1}`);
    } catch {
        // Some embedded contexts forbid history writes; navigation still works.
    }
}

const next = () => go(current.value + 1);
const prev = () => go(current.value - 1);
const printDeck = () => window.print();

function onKey(e: KeyboardEvent) {
    if (stacked.value) {
        return;
    }

    if (['ArrowRight', 'ArrowDown', 'PageDown', ' '].includes(e.key)) {
        e.preventDefault();
        next();
    } else if (['ArrowLeft', 'ArrowUp', 'PageUp'].includes(e.key)) {
        e.preventDefault();
        prev();
    } else if (e.key === 'Home') {
        go(0);
    } else if (e.key === 'End') {
        go(total - 1);
    }
}

let touchX: number | null = null;

function onTouchStart(e: TouchEvent) {
    touchX = e.touches[0]?.clientX ?? null;
}

function onTouchEnd(e: TouchEvent) {
    if (touchX === null || stacked.value) {
        return;
    }

    const dx = (e.changedTouches[0]?.clientX ?? touchX) - touchX;
    touchX = null;

    if (Math.abs(dx) > 50) {
        if (dx < 0) {
            next();
        } else {
            prev();
        }
    }
}

function onResize() {
    vw.value = window.innerWidth;
    vh.value = window.innerHeight;
}

onMounted(() => {
    onResize();

    const requested = parseInt(window.location.hash.slice(1), 10);

    if (requested >= 1 && requested <= total) {
        current.value = requested - 1;
    }

    window.addEventListener('keydown', onKey);
    window.addEventListener('resize', onResize);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    window.removeEventListener('resize', onResize);
});

// A "Pourquoi ce choix ?" callout. Functional so every slide uses the same
// markup and the title can't drift between slides.
const WhyBox = (_props: Record<string, never>, { slots }: { slots: Slots }) =>
    h('aside', { class: 'why' }, [
        h('p', { class: 'why-title' }, 'Pourquoi ce choix ?'),
        slots.default?.(),
    ]);
</script>

<template>
    <Head title="Architecture & choix techniques" />

    <div
        class="deck-root"
        :class="{ 'is-stacked': stacked }"
        :style="rootStyle"
    >
        <!-- Shared arrowheads for every inline diagram below. -->
        <svg class="defs" aria-hidden="true" focusable="false">
            <defs>
                <marker
                    id="arr"
                    markerUnits="userSpaceOnUse"
                    markerWidth="12"
                    markerHeight="12"
                    refX="10"
                    refY="6"
                    orient="auto"
                >
                    <path d="M0,0 L12,6 L0,12 z" fill="#3FD1A4" />
                </marker>
                <marker
                    id="arrw"
                    markerUnits="userSpaceOnUse"
                    markerWidth="12"
                    markerHeight="12"
                    refX="10"
                    refY="6"
                    orient="auto"
                >
                    <path d="M0,0 L12,6 L0,12 z" fill="#F5B841" />
                </marker>
                <marker
                    id="arrd"
                    markerUnits="userSpaceOnUse"
                    markerWidth="12"
                    markerHeight="12"
                    refX="10"
                    refY="6"
                    orient="auto"
                >
                    <path d="M0,0 L12,6 L0,12 z" fill="#F26B6B" />
                </marker>
                <marker
                    id="arrm"
                    markerUnits="userSpaceOnUse"
                    markerWidth="12"
                    markerHeight="12"
                    refX="10"
                    refY="6"
                    orient="auto"
                >
                    <path d="M0,0 L12,6 L0,12 z" fill="#93AAA3" />
                </marker>
            </defs>
        </svg>

        <div
            class="viewport"
            @touchstart.passive="onTouchStart"
            @touchend.passive="onTouchEnd"
        >
            <div class="track" :style="trackStyle">
                <!-- ============================ 1 · COUVERTURE ============================ -->
                <div class="frame" :class="frameClass(0)">
                    <section class="slide cover" aria-label="Diapositive 1">
                        <div class="cover-glow" aria-hidden="true"></div>
                        <p class="kicker">
                            ONDA · Office National des Droits d'Auteur et Droits
                            Voisins
                        </p>
                        <h1 class="cover-title">ONDA Storage</h1>
                        <p class="cover-sub">
                            Coffre-fort pour les dépôts légaux — fichiers
                            jusqu'à <strong>5 Go</strong>
                        </p>
                        <p class="ar" dir="rtl" lang="ar">
                            خزنة ONDA للإيداع القانوني — ملفات حتى 5 غيغابايت
                        </p>

                        <div class="chips">
                            <span>Hébergement cPanel · LiteSpeed</span>
                            <span
                                >Laravel 13 · Inertia v3 · Vue 3 ·
                                TypeScript</span
                            >
                            <span>Branche feat/upload-files</span>
                        </div>

                        <div class="stats">
                            <div class="stat">
                                <b>5 Go</b><span>max par fichier</span>
                            </div>
                            <div class="stat">
                                <b>8 MiB</b
                                ><span>par morceau · 640 pour 5 Go</span>
                            </div>
                            <div class="stat">
                                <b>AES-256-CTR</b
                                ><span>chiffrement seekable</span>
                            </div>
                            <div class="stat">
                                <b>4</b
                                ><span>coutures (ports &amp; adaptateurs)</span>
                            </div>
                        </div>

                        <p class="meta">
                            Présentation technique — comment et pourquoi le
                            système est construit ainsi
                        </p>
                        <footer class="foot">
                            <span>ONDA Storage · Architecture</span
                            ><span>01 / 14</span>
                        </footer>
                    </section>
                </div>

                <!-- ============================ 2 · DEUX RÈGLES ============================ -->
                <div class="frame" :class="frameClass(1)">
                    <section class="slide" aria-label="Diapositive 2">
                        <p class="kicker">02 · Principes</p>
                        <h2>Les deux règles d'or</h2>
                        <p class="ar" dir="rtl" lang="ar">
                            القاعدتان الذهبيتان
                        </p>

                        <div class="rules">
                            <article class="card rule">
                                <div class="icon"><Upload :size="34" /></div>
                                <h3>
                                    Aucun gros fichier dans une seule requête
                                    HTTP
                                </h3>
                                <p class="ar small" dir="rtl" lang="ar">
                                    لا ملف كبير في طلب HTTP واحد
                                </p>
                                <p>
                                    Le navigateur découpe en morceaux de
                                    <strong>8 MiB</strong> (8 388 608 octets,
                                    divisible par 16 : chaque morceau commence
                                    sur une frontière de bloc AES). Le serveur
                                    écrit chaque morceau à son
                                    <em>offset</em> dans un fichier pré-alloué.
                                </p>
                                <div class="metric">
                                    <div class="bar">
                                        <span style="width: 100%"></span>
                                    </div>
                                    <p class="sub">
                                        5 Go = <b>640 morceaux</b> de 8 MiB —
                                        chacun une requête courte
                                    </p>
                                </div>
                            </article>

                            <article class="card rule">
                                <div class="icon danger">
                                    <Lock :size="34" />
                                </div>
                                <h3>Aucun octet en clair dans le coffre</h3>
                                <p class="ar small" dir="rtl" lang="ar">
                                    لا بايت واحد غير مشفّر داخل الخزنة
                                </p>
                                <p>
                                    Le chiffrement se fait
                                    <strong>en mémoire</strong>, morceau par
                                    morceau, avant la moindre écriture disque.
                                    Le clair n'existe qu'à un seul endroit,
                                    <code>work/</code>, pendant le pipeline,
                                    puis il est supprimé.
                                </p>
                                <div class="metric">
                                    <div class="bar">
                                        <span
                                            style="width: 4%"
                                            class="warn"
                                        ></span>
                                    </div>
                                    <p class="sub">
                                        Une seule fenêtre de clair,
                                        <b>temporaire</b>, hors webroot
                                    </p>
                                </div>
                            </article>
                        </div>

                        <p class="banner">
                            Presque toute l'architecture découle de ces deux
                            règles : chunks, CTR, pipeline asynchrone, I/O par
                            offset.
                        </p>
                        <footer class="foot">
                            <span>ONDA Storage · Architecture</span
                            ><span>02 / 14</span>
                        </footer>
                    </section>
                </div>

                <!-- ============================ 3 · ARCHITECTURE ============================ -->
                <div class="frame" :class="frameClass(2)">
                    <section class="slide" aria-label="Diapositive 3">
                        <p class="kicker">03 · Vue d'ensemble</p>
                        <h2>
                            Architecture : ce qui est servi, et ce qui ne l'est
                            jamais
                        </h2>
                        <p class="ar" dir="rtl" lang="ar">
                            المعمارية — ما يُخدَم وما لا يُخدَم أبداً
                        </p>

                        <svg
                            class="diagram"
                            viewBox="0 0 1200 500"
                            style="height: 400px; width: auto"
                            role="img"
                            aria-label="Schéma d'architecture : navigateur, application Laravel dans public_html, vault hors webroot, work temporaire, MySQL, clé maîtresse hors dépôt"
                        >
                            <!-- Client -->
                            <rect
                                class="box"
                                x="20"
                                y="170"
                                width="170"
                                height="110"
                                rx="14"
                            />
                            <text
                                class="t"
                                x="105"
                                y="208"
                                text-anchor="middle"
                            >
                                Navigateur
                            </text>
                            <text
                                class="s"
                                x="105"
                                y="234"
                                text-anchor="middle"
                            >
                                Morceaux 8 MiB
                            </text>
                            <text
                                class="m"
                                x="105"
                                y="256"
                                text-anchor="middle"
                            >
                                octet-stream
                            </text>

                            <!-- Server account -->
                            <rect
                                class="boundary"
                                x="240"
                                y="30"
                                width="940"
                                height="390"
                                rx="18"
                            />
                            <text class="s" x="262" y="60">
                                COMPTE cPanel · LiteSpeed · PHP-FPM 8.4
                            </text>

                            <rect
                                class="box"
                                x="270"
                                y="90"
                                width="260"
                                height="130"
                                rx="12"
                            />
                            <text
                                class="t"
                                x="400"
                                y="124"
                                text-anchor="middle"
                            >
                                public_html/
                            </text>
                            <text
                                class="s"
                                x="400"
                                y="150"
                                text-anchor="middle"
                            >
                                webroot : seul dossier servi
                            </text>
                            <text
                                class="s"
                                x="400"
                                y="172"
                                text-anchor="middle"
                            >
                                Laravel 13 · Inertia · API
                            </text>
                            <text
                                class="s"
                                x="400"
                                y="196"
                                text-anchor="middle"
                            >
                                aucun clair, aucune clé
                            </text>

                            <rect
                                class="box ok"
                                x="640"
                                y="90"
                                width="280"
                                height="130"
                                rx="12"
                            />
                            <text
                                class="t"
                                x="780"
                                y="124"
                                text-anchor="middle"
                            >
                                vault/ · HORS webroot
                            </text>
                            <text
                                class="m"
                                x="780"
                                y="150"
                                text-anchor="middle"
                            >
                                {uuid[0:2]}/{uuid[2:4]}/{uuid}.bin
                            </text>
                            <text
                                class="s"
                                x="780"
                                y="174"
                                text-anchor="middle"
                            >
                                .mac sidecar · 0700 / 0600
                            </text>
                            <text
                                class="s"
                                x="780"
                                y="196"
                                text-anchor="middle"
                            >
                                chiffré AES-256-CTR
                            </text>

                            <rect
                                class="box warn"
                                x="640"
                                y="250"
                                width="280"
                                height="90"
                                rx="12"
                            />
                            <text
                                class="t"
                                x="780"
                                y="284"
                                text-anchor="middle"
                            >
                                work/ · clair temporaire
                            </text>
                            <text
                                class="s"
                                x="780"
                                y="308"
                                text-anchor="middle"
                            >
                                fenêtre de déchiffrement
                            </text>
                            <text
                                class="s"
                                x="780"
                                y="330"
                                text-anchor="middle"
                            >
                                purgé : CleanupTemp + cron
                            </text>

                            <rect
                                class="box"
                                x="270"
                                y="250"
                                width="260"
                                height="90"
                                rx="12"
                            />
                            <text
                                class="t"
                                x="400"
                                y="284"
                                text-anchor="middle"
                            >
                                MySQL
                            </text>
                            <text
                                class="s"
                                x="400"
                                y="308"
                                text-anchor="middle"
                            >
                                métadonnées · quotas · audit
                            </text>
                            <text
                                class="m"
                                x="400"
                                y="330"
                                text-anchor="middle"
                            >
                                file_access_logs · oeuvre_reviews
                            </text>

                            <!-- Key, outside everything -->
                            <rect
                                class="box danger dash"
                                x="240"
                                y="440"
                                width="940"
                                height="50"
                                rx="12"
                            />
                            <text
                                class="m"
                                x="710"
                                y="462"
                                text-anchor="middle"
                            >
                                secrets/vault-master.key · VAULT_MASTER_KEY_PATH
                            </text>
                            <text
                                class="s"
                                x="710"
                                y="482"
                                text-anchor="middle"
                            >
                                hors dépôt · hors webroot · jamais dans un jeu
                                de sauvegarde automatique
                            </text>

                            <!-- Flows -->
                            <path
                                class="line"
                                d="M190 225 H230 V160 H270"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line"
                                d="M530 160 H640"
                                marker-end="url(#arr)"
                            />
                            <text
                                class="s"
                                x="585"
                                y="150"
                                text-anchor="middle"
                            >
                                chiffré
                            </text>
                            <path
                                class="line muted"
                                d="M400 220 V250"
                                marker-end="url(#arrm)"
                            />
                            <text class="s" x="412" y="240">Eloquent</text>
                            <path
                                class="line warn"
                                d="M780 220 V250"
                                marker-end="url(#arrw)"
                            />
                            <text class="s" x="792" y="240">DecryptToTemp</text>
                        </svg>

                        <p class="caption">
                            Règle de lecture : seul <code>public_html</code> est
                            servi par HTTP. Tout octet utile vit hors du
                            webroot, et la clé maîtresse ne touche jamais le
                            dépôt.
                        </p>
                        <footer class="foot">
                            <span>ONDA Storage · Architecture</span
                            ><span>03 / 14</span>
                        </footer>
                    </section>
                </div>

                <!-- ============================ 4 · CHUNKED UPLOAD ============================ -->
                <div class="frame" :class="frameClass(3)">
                    <section class="slide" aria-label="Diapositive 4">
                        <p class="kicker">04 · Téléversement</p>
                        <h2>Pourquoi un upload par morceaux ?</h2>
                        <p class="ar" dir="rtl" lang="ar">
                            لماذا الرفع على شكل مقاطع؟
                        </p>

                        <svg
                            class="diagram"
                            viewBox="0 0 1120 270"
                            style="height: 190px; width: auto"
                            role="img"
                            aria-label="Diagramme de séquence : POST uploads, POST chunk trois fois, POST complete"
                        >
                            <rect
                                class="box"
                                x="70"
                                y="8"
                                width="120"
                                height="36"
                                rx="8"
                            />
                            <text class="t" x="130" y="32" text-anchor="middle">
                                Navigateur
                            </text>
                            <rect
                                class="box ok"
                                x="500"
                                y="8"
                                width="120"
                                height="36"
                                rx="8"
                            />
                            <text class="t" x="560" y="32" text-anchor="middle">
                                Laravel API
                            </text>
                            <rect
                                class="box"
                                x="930"
                                y="8"
                                width="120"
                                height="36"
                                rx="8"
                            />
                            <text class="t" x="990" y="32" text-anchor="middle">
                                Vault (disque)
                            </text>

                            <line
                                class="lifeline"
                                x1="130"
                                y1="44"
                                x2="130"
                                y2="258"
                            />
                            <line
                                class="lifeline"
                                x1="560"
                                y1="44"
                                x2="560"
                                y2="258"
                            />
                            <line
                                class="lifeline"
                                x1="990"
                                y1="44"
                                x2="990"
                                y2="258"
                            />

                            <path
                                class="line"
                                d="M130 84 H560"
                                marker-end="url(#arr)"
                            />
                            <text class="s" x="345" y="78" text-anchor="middle">
                                POST /uploads · taille, type
                            </text>
                            <path
                                class="line"
                                d="M560 108 H990"
                                marker-end="url(#arr)"
                            />
                            <text
                                class="s"
                                x="775"
                                y="102"
                                text-anchor="middle"
                            >
                                ftruncate(taille) · DEK par fichier
                            </text>

                            <rect
                                class="frame-x"
                                x="120"
                                y="126"
                                width="880"
                                height="74"
                                rx="10"
                            />
                            <text class="s" x="132" y="142">
                                boucle par morceau · 3 simultanés au maximum
                            </text>

                            <path
                                class="line"
                                d="M130 152 H560"
                                marker-end="url(#arr)"
                            />
                            <text
                                class="m"
                                x="345"
                                y="146"
                                text-anchor="middle"
                            >
                                POST …/chunk/{i} · octet-stream · X-Chunk-CRC32
                            </text>
                            <path
                                class="line"
                                d="M560 176 H990"
                                marker-end="url(#arr)"
                            />
                            <text
                                class="s"
                                x="775"
                                y="170"
                                text-anchor="middle"
                            >
                                vérifie CRC32 · chiffre · fwrite à l'offset
                            </text>

                            <path
                                class="line warn"
                                d="M130 222 H560"
                                marker-end="url(#arrw)"
                            />
                            <text
                                class="m"
                                x="345"
                                y="216"
                                text-anchor="middle"
                            >
                                POST /uploads/{uuid}/complete
                            </text>
                            <path
                                class="line warn"
                                d="M560 246 H990"
                                marker-end="url(#arrw)"
                            />
                            <text
                                class="s"
                                x="775"
                                y="240"
                                text-anchor="middle"
                            >
                                rename() seulement · aucun hash ici
                            </text>
                        </svg>

                        <div class="why-row">
                            <WhyBox>
                                <h3>Pourquoi des morceaux de 8 MiB ?</h3>
                                <div class="why-split">
                                    <ul>
                                        <li>
                                            <b>Limites PHP :</b>
                                            <code>post_max_size</code> 32M,
                                            <code>upload_max_filesize</code>
                                            16M. 5 Go ne passe jamais en une
                                            requête.
                                        </li>
                                        <li>
                                            <b>Reprise :</b> un morceau perdu se
                                            renvoie seul, idempotent à son
                                            offset.
                                        </li>
                                        <li>
                                            <b>Pas de timeout :</b> chaque
                                            requête reste loin de
                                            <code>max_execution_time</code> (120
                                            s).
                                        </li>
                                    </ul>
                                    <svg
                                        class="chart diagram"
                                        viewBox="0 0 420 132"
                                        role="img"
                                        aria-label="Taille par requête, échelle logarithmique : 5 Go, 32 Mio, 8 Mio"
                                    >
                                        <text class="s" x="0" y="20">
                                            Requête unique
                                        </text>
                                        <rect
                                            class="bar-bg"
                                            x="150"
                                            y="8"
                                            width="230"
                                            height="20"
                                        />
                                        <rect
                                            class="bar-fg red"
                                            x="150"
                                            y="8"
                                            width="230"
                                            height="20"
                                        />
                                        <text class="s" x="388" y="23">
                                            5 Go
                                        </text>

                                        <text class="s" x="0" y="56">
                                            post_max (prod)
                                        </text>
                                        <rect
                                            class="bar-bg"
                                            x="150"
                                            y="44"
                                            width="230"
                                            height="20"
                                        />
                                        <rect
                                            class="bar-fg"
                                            x="150"
                                            y="44"
                                            width="93"
                                            height="20"
                                        />
                                        <text class="s" x="250" y="59">
                                            32 Mio
                                        </text>

                                        <text class="s" x="0" y="92">
                                            1 morceau
                                        </text>
                                        <rect
                                            class="bar-bg"
                                            x="150"
                                            y="80"
                                            width="230"
                                            height="20"
                                        />
                                        <rect
                                            class="bar-fg ok"
                                            x="150"
                                            y="80"
                                            width="56"
                                            height="20"
                                        />
                                        <text class="s" x="214" y="95">
                                            8 Mio
                                        </text>
                                        <text class="s" x="0" y="128">
                                            Échelle logarithmique : sur une
                                            échelle linéaire, le morceau serait
                                            invisible.
                                        </text>
                                    </svg>
                                </div>
                            </WhyBox>

                            <WhyBox>
                                <h3>
                                    Pourquoi ne pas hasher au
                                    <code>complete</code> ?
                                </h3>
                                <p>
                                    Un SHA-256 synchrone sur 5 Go déchiffrés
                                    dépasse <code>max_execution_time</code> : la
                                    requête tombe et le fichier reste à moitié
                                    vérifié. Le hash et le scan partent donc
                                    dans le
                                    <strong>pipeline asynchrone</strong> (queue
                                    <code>media</code>). Le
                                    <code>complete</code> ne fait que vérifier
                                    le masque de morceaux puis
                                    <code>rename()</code>.
                                </p>
                            </WhyBox>
                        </div>
                        <footer class="foot">
                            <span>ONDA Storage · Architecture</span
                            ><span>04 / 14</span>
                        </footer>
                    </section>
                </div>

                <!-- ============================ 5 · CRYPTO ============================ -->
                <div class="frame" :class="frameClass(4)">
                    <section class="slide" aria-label="Diapositive 5">
                        <p class="kicker">05 · Cryptographie</p>
                        <h2>Enveloppe de chiffrement</h2>
                        <p class="ar" dir="rtl" lang="ar">
                            تصميم التشفير المغلّف
                        </p>

                        <svg
                            class="diagram"
                            viewBox="0 0 1120 300"
                            style="height: 250px; width: auto"
                            role="img"
                            aria-label="Enveloppe : la DEK est enveloppée par la KEK puis re-chiffrée par APP_KEY ; le fichier est chiffré en AES-256-CTR ; un sidecar MAC est calculé par segment"
                        >
                            <!-- Key chain -->
                            <rect
                                class="box"
                                x="0"
                                y="8"
                                width="210"
                                height="62"
                                rx="10"
                            />
                            <text class="t" x="105" y="34" text-anchor="middle">
                                KEK · vault-master.key
                            </text>
                            <text class="s" x="105" y="56" text-anchor="middle">
                                32 octets, hors dépôt
                            </text>

                            <rect
                                class="box ok"
                                x="290"
                                y="8"
                                width="210"
                                height="62"
                                rx="10"
                            />
                            <text class="t" x="395" y="34" text-anchor="middle">
                                DEK · 32 octets
                            </text>
                            <text class="s" x="395" y="56" text-anchor="middle">
                                aléatoire, une par fichier
                            </text>

                            <rect
                                class="box"
                                x="580"
                                y="8"
                                width="210"
                                height="62"
                                rx="10"
                            />
                            <text class="t" x="685" y="34" text-anchor="middle">
                                dek_wrapped
                            </text>
                            <text class="s" x="685" y="56" text-anchor="middle">
                                colonne media_files
                            </text>

                            <rect
                                class="box"
                                x="880"
                                y="8"
                                width="240"
                                height="62"
                                rx="10"
                            />
                            <text
                                class="t"
                                x="1000"
                                y="34"
                                text-anchor="middle"
                            >
                                APP_KEY
                            </text>
                            <text
                                class="s"
                                x="1000"
                                y="56"
                                text-anchor="middle"
                            >
                                cast encrypted de Laravel
                            </text>

                            <path
                                class="line"
                                d="M210 39 H290"
                                marker-end="url(#arr)"
                            />
                            <text class="s" x="250" y="30" text-anchor="middle">
                                enveloppe
                            </text>
                            <path
                                class="line"
                                d="M500 39 H580"
                                marker-end="url(#arr)"
                            />
                            <text class="s" x="540" y="30" text-anchor="middle">
                                wrap
                            </text>
                            <path
                                class="line"
                                d="M880 39 H790"
                                marker-end="url(#arr)"
                            />
                            <text class="s" x="835" y="30" text-anchor="middle">
                                2e enveloppe
                            </text>

                            <!-- Data path -->
                            <rect
                                class="box"
                                x="0"
                                y="118"
                                width="210"
                                height="90"
                                rx="10"
                            />
                            <text
                                class="t"
                                x="105"
                                y="152"
                                text-anchor="middle"
                            >
                                Fichier clair
                            </text>
                            <text
                                class="s"
                                x="105"
                                y="176"
                                text-anchor="middle"
                            >
                                morceau de 8 MiB, en RAM
                            </text>

                            <rect
                                class="box ok"
                                x="290"
                                y="118"
                                width="210"
                                height="90"
                                rx="10"
                            />
                            <text
                                class="t"
                                x="395"
                                y="146"
                                text-anchor="middle"
                            >
                                AES-256-CTR
                            </text>
                            <text
                                class="s"
                                x="395"
                                y="166"
                                text-anchor="middle"
                            >
                                seekable · longueur conservée
                            </text>
                            <text
                                class="m"
                                x="395"
                                y="190"
                                text-anchor="middle"
                            >
                                IV = nonce8 ‖ offset/16
                            </text>

                            <rect
                                class="box"
                                x="580"
                                y="118"
                                width="210"
                                height="90"
                                rx="10"
                            />
                            <text
                                class="t"
                                x="685"
                                y="152"
                                text-anchor="middle"
                            >
                                Fichier chiffré
                            </text>
                            <text
                                class="m"
                                x="685"
                                y="178"
                                text-anchor="middle"
                            >
                                {uuid}.bin
                            </text>

                            <rect
                                class="box"
                                x="580"
                                y="240"
                                width="210"
                                height="50"
                                rx="10"
                            />
                            <text
                                class="t"
                                x="685"
                                y="262"
                                text-anchor="middle"
                            >
                                {uuid}.mac
                            </text>
                            <text
                                class="s"
                                x="685"
                                y="280"
                                text-anchor="middle"
                            >
                                HMAC-SHA256 · segment 1 MiB
                            </text>

                            <path
                                class="line"
                                d="M395 70 V118"
                                marker-end="url(#arr)"
                            />
                            <text class="s" x="407" y="100">
                                HKDF → onda-enc
                            </text>
                            <path
                                class="line"
                                d="M210 163 H290"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line"
                                d="M500 163 H580"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line muted"
                                d="M685 208 V240"
                                marker-end="url(#arrm)"
                            />
                            <text class="s" x="697" y="228">par segment</text>
                            <text class="s" x="290" y="272">
                                clé MAC dérivée : HKDF(DEK, 'onda-mac')
                            </text>

                            <!-- Warning -->
                            <rect
                                class="box danger"
                                x="880"
                                y="118"
                                width="240"
                                height="172"
                                rx="12"
                            />
                            <text
                                class="t"
                                x="1000"
                                y="150"
                                text-anchor="middle"
                            >
                                PERTE DE CLÉ
                            </text>
                            <text
                                class="s"
                                x="1000"
                                y="180"
                                text-anchor="middle"
                            >
                                KEK ou APP_KEY perdue
                            </text>
                            <text
                                class="s"
                                x="1000"
                                y="202"
                                text-anchor="middle"
                            >
                                = perte de tous les fichiers
                            </text>
                            <text
                                class="s"
                                x="1000"
                                y="240"
                                text-anchor="middle"
                            >
                                Sauvegarder les deux,
                            </text>
                            <text
                                class="s"
                                x="1000"
                                y="262"
                                text-anchor="middle"
                            >
                                séparément des données
                            </text>
                        </svg>

                        <div class="why-row three">
                            <WhyBox>
                                <h3>AES-256-CTR, pas CBC ni GCM</h3>
                                <p>
                                    CTR est <b>seekable</b> : un Range HTTP lit
                                    au milieu sans tout déchiffrer, et la
                                    longueur est conservée, donc une écriture à
                                    offset reste valide. CBC chaîne les blocs ;
                                    GCM authentifie le tout mais ne permet pas
                                    la réécriture partielle.
                                </p>
                            </WhyBox>
                            <WhyBox>
                                <h3>Une DEK par fichier</h3>
                                <p>
                                    Une fuite de clé ne compromet qu'un fichier.
                                    Le chiffrement reste indépendant d'un
                                    fichier à l'autre, et la KEK ne sert qu'à
                                    envelopper des DEK : on peut la faire
                                    tourner sans toucher aux données.
                                </p>
                            </WhyBox>
                            <WhyBox>
                                <h3>MAC par segment de 1 MiB</h3>
                                <p>
                                    CTR n'authentifie rien : retourner un bit
                                    chiffré retourne un bit clair. Un MAC par
                                    segment détecte la corruption
                                    <b>sans relire 5 Go</b>, et vérifie un Range
                                    à la granularité du segment.
                                </p>
                            </WhyBox>
                        </div>
                        <footer class="foot">
                            <span>ONDA Storage · Architecture</span
                            ><span>05 / 14</span>
                        </footer>
                    </section>
                </div>

                <!-- ============================ 6 · VAULT I/O ============================ -->
                <div class="frame" :class="frameClass(5)">
                    <section class="slide" aria-label="Diapositive 6">
                        <p class="kicker">06 · Entrées / sorties du coffre</p>
                        <h2>La règle des I/O : offset, jamais réécriture</h2>
                        <p class="ar" dir="rtl" lang="ar">
                            قاعدة القراءة والكتابة: بالإزاحة فقط
                        </p>

                        <div class="panes">
                            <div class="pane">
                                <div class="pane-head bad">Interdit</div>
                                <pre><code><span class="bad">Storage::get($path);</span>          <span class="c">// charge 5 Go en RAM</span>
<span class="bad">Storage::put($path, $chunk);</span>  <span class="c">// réécrit tout le fichier</span>
<span class="bad">readfile($path);</span>              <span class="c">// lecture non contrôlée</span>
<span class="bad">response()->download($path);</span>
<span class="bad">php artisan storage:link</span>      <span class="c">// expose le vault</span></code></pre>
                            </div>
                            <div class="pane">
                                <div class="pane-head ok">Autorisé</div>
                                <pre><code><span class="ok">$fh = fopen($vaultPath, 'c+b');</span>
<span class="ok">fseek($fh, $byteOffset);</span>
<span class="ok">fwrite($fh, $ciphertext);</span>     <span class="c">// un morceau, à son offset</span>
<span class="ok">ftruncate($fh, $totalSize);</span>   <span class="c">// pré-allocation</span>
<span class="ok">fseek($fh, $start);</span>           <span class="c">// lecture Range</span>
<span class="ok">$cipher = fread($fh, $length);</span></code></pre>
                            </div>
                        </div>

                        <svg
                            class="diagram flow"
                            viewBox="0 0 1120 96"
                            role="img"
                            aria-label="Flux d'une requête Range : requête Range, fseek, fread, déchiffrement CTR"
                        >
                            <rect
                                class="box"
                                x="0"
                                y="14"
                                width="250"
                                height="66"
                                rx="10"
                            />
                            <text class="t" x="125" y="40" text-anchor="middle">
                                Requête Range
                            </text>
                            <text class="m" x="125" y="62" text-anchor="middle">
                                bytes=1048576-2097151
                            </text>
                            <rect
                                class="box ok"
                                x="290"
                                y="14"
                                width="250"
                                height="66"
                                rx="10"
                            />
                            <text class="t" x="415" y="40" text-anchor="middle">
                                fseek($fh, offset)
                            </text>
                            <text class="s" x="415" y="62" text-anchor="middle">
                                positionnement direct
                            </text>
                            <rect
                                class="box ok"
                                x="580"
                                y="14"
                                width="250"
                                height="66"
                                rx="10"
                            />
                            <text class="t" x="705" y="40" text-anchor="middle">
                                fread($fh, length)
                            </text>
                            <text class="s" x="705" y="62" text-anchor="middle">
                                seulement le segment voulu
                            </text>
                            <rect
                                class="box"
                                x="870"
                                y="14"
                                width="250"
                                height="66"
                                rx="10"
                            />
                            <text class="t" x="995" y="40" text-anchor="middle">
                                Déchiffrement CTR
                            </text>
                            <text class="m" x="995" y="62" text-anchor="middle">
                                IV à offset / 16
                            </text>
                            <path
                                class="line"
                                d="M250 47 H290"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line"
                                d="M540 47 H580"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line"
                                d="M830 47 H870"
                                marker-end="url(#arr)"
                            />
                        </svg>

                        <div class="why-row single">
                            <WhyBox>
                                <h3>
                                    Pourquoi écrire à un offset, et pas via
                                    Flysystem ?
                                </h3>
                                <p>
                                    Les requêtes <b>Range</b> imposent un accès
                                    aléatoire. Flysystem n'a pas d'écriture avec
                                    seek : <code>put()</code> remplace le
                                    fichier entier, donc un morceau de 8 MiB
                                    coûterait 5 Go d'I/O. Les disques Laravel ne
                                    servent ici qu'à résoudre chemins et
                                    permissions (<code>-&gt;path()</code>, dans
                                    <code>EncryptedLocalVault</code>
                                    uniquement).
                                </p>
                            </WhyBox>
                        </div>
                        <footer class="foot">
                            <span>ONDA Storage · Architecture</span
                            ><span>06 / 14</span>
                        </footer>
                    </section>
                </div>

                <!-- ============================ 7 · PIPELINE ============================ -->
                <div class="frame" :class="frameClass(6)">
                    <section class="slide" aria-label="Diapositive 7">
                        <p class="kicker">07 · Pipeline post-upload</p>
                        <h2>
                            Une chaîne <code>Bus::chain</code>, pas des
                            événements
                        </h2>
                        <p class="ar" dir="rtl" lang="ar">
                            خط المعالجة بعد الرفع
                        </p>

                        <svg
                            class="diagram"
                            viewBox="0 0 1120 262"
                            role="img"
                            aria-label="Chaîne de jobs : DecryptToTemp, ComputeContentHash, DeduplicateFile, ScanForMalware, ExtractMetadata, GenerateVariants, RecordDeposit, CleanupTemp"
                        >
                            <path class="line warn" d="M4 24 H1110" />
                            <text
                                class="s warn-t"
                                x="557"
                                y="15"
                                text-anchor="middle"
                            >
                                fenêtre de clair : uniquement dans work/,
                                supprimée à la fin
                            </text>

                            <g class="job">
                                <text
                                    class="s"
                                    x="67"
                                    y="50"
                                    text-anchor="middle"
                                >
                                    1
                                </text>
                                <rect
                                    class="box warn"
                                    x="4"
                                    y="60"
                                    width="126"
                                    height="90"
                                    rx="10"
                                />
                                <text
                                    class="t"
                                    x="67"
                                    y="96"
                                    text-anchor="middle"
                                >
                                    Decrypt
                                </text>
                                <text
                                    class="t"
                                    x="67"
                                    y="116"
                                    text-anchor="middle"
                                >
                                    ToTemp
                                </text>
                                <text
                                    class="s"
                                    x="67"
                                    y="172"
                                    text-anchor="middle"
                                >
                                    déchiffre le
                                </text>
                                <text
                                    class="s"
                                    x="67"
                                    y="190"
                                    text-anchor="middle"
                                >
                                    fichier en clair
                                </text>
                            </g>
                            <g class="job">
                                <text
                                    class="s"
                                    x="207"
                                    y="50"
                                    text-anchor="middle"
                                >
                                    2
                                </text>
                                <rect
                                    class="box warn"
                                    x="144"
                                    y="60"
                                    width="126"
                                    height="90"
                                    rx="10"
                                />
                                <text
                                    class="t"
                                    x="207"
                                    y="96"
                                    text-anchor="middle"
                                >
                                    Compute
                                </text>
                                <text
                                    class="t"
                                    x="207"
                                    y="116"
                                    text-anchor="middle"
                                >
                                    ContentHash
                                </text>
                                <text
                                    class="s"
                                    x="207"
                                    y="172"
                                    text-anchor="middle"
                                >
                                    SHA-256 du
                                </text>
                                <text
                                    class="s"
                                    x="207"
                                    y="190"
                                    text-anchor="middle"
                                >
                                    contenu clair
                                </text>
                            </g>
                            <g class="job">
                                <text
                                    class="s"
                                    x="347"
                                    y="50"
                                    text-anchor="middle"
                                >
                                    3
                                </text>
                                <rect
                                    class="box warn"
                                    x="284"
                                    y="60"
                                    width="126"
                                    height="90"
                                    rx="10"
                                />
                                <text
                                    class="t"
                                    x="347"
                                    y="96"
                                    text-anchor="middle"
                                >
                                    Deduplicate
                                </text>
                                <text
                                    class="t"
                                    x="347"
                                    y="116"
                                    text-anchor="middle"
                                >
                                    File
                                </text>
                                <text
                                    class="s"
                                    x="347"
                                    y="172"
                                    text-anchor="middle"
                                >
                                    réutilise l'original
                                </text>
                                <text
                                    class="s"
                                    x="347"
                                    y="190"
                                    text-anchor="middle"
                                >
                                    existant (trashed)
                                </text>
                            </g>
                            <g class="job">
                                <text
                                    class="s"
                                    x="487"
                                    y="50"
                                    text-anchor="middle"
                                >
                                    4
                                </text>
                                <rect
                                    class="box warn"
                                    x="424"
                                    y="60"
                                    width="126"
                                    height="90"
                                    rx="10"
                                />
                                <text
                                    class="t"
                                    x="487"
                                    y="96"
                                    text-anchor="middle"
                                >
                                    Scan
                                </text>
                                <text
                                    class="t"
                                    x="487"
                                    y="116"
                                    text-anchor="middle"
                                >
                                    ForMalware
                                </text>
                                <text
                                    class="s"
                                    x="487"
                                    y="172"
                                    text-anchor="middle"
                                >
                                    Null en local
                                </text>
                                <text
                                    class="s"
                                    x="487"
                                    y="190"
                                    text-anchor="middle"
                                >
                                    ClamAV en prod
                                </text>
                            </g>
                            <g class="job">
                                <text
                                    class="s"
                                    x="627"
                                    y="50"
                                    text-anchor="middle"
                                >
                                    5
                                </text>
                                <rect
                                    class="box warn"
                                    x="564"
                                    y="60"
                                    width="126"
                                    height="90"
                                    rx="10"
                                />
                                <text
                                    class="t"
                                    x="627"
                                    y="96"
                                    text-anchor="middle"
                                >
                                    Extract
                                </text>
                                <text
                                    class="t"
                                    x="627"
                                    y="116"
                                    text-anchor="middle"
                                >
                                    Metadata
                                </text>
                                <text
                                    class="s"
                                    x="627"
                                    y="172"
                                    text-anchor="middle"
                                >
                                    MediaProbe
                                </text>
                                <text
                                    class="s"
                                    x="627"
                                    y="190"
                                    text-anchor="middle"
                                >
                                    (ffprobe)
                                </text>
                            </g>
                            <g class="job">
                                <text
                                    class="s"
                                    x="767"
                                    y="50"
                                    text-anchor="middle"
                                >
                                    6
                                </text>
                                <rect
                                    class="box warn"
                                    x="704"
                                    y="60"
                                    width="126"
                                    height="90"
                                    rx="10"
                                />
                                <text
                                    class="t"
                                    x="767"
                                    y="96"
                                    text-anchor="middle"
                                >
                                    Generate
                                </text>
                                <text
                                    class="t"
                                    x="767"
                                    y="116"
                                    text-anchor="middle"
                                >
                                    Variants
                                </text>
                                <text
                                    class="s"
                                    x="767"
                                    y="172"
                                    text-anchor="middle"
                                >
                                    poster · preview
                                </text>
                                <text
                                    class="s"
                                    x="767"
                                    y="190"
                                    text-anchor="middle"
                                >
                                    waveform
                                </text>
                            </g>
                            <g class="job">
                                <text
                                    class="s"
                                    x="907"
                                    y="50"
                                    text-anchor="middle"
                                >
                                    7
                                </text>
                                <rect
                                    class="box ok"
                                    x="844"
                                    y="60"
                                    width="126"
                                    height="90"
                                    rx="10"
                                />
                                <text
                                    class="t"
                                    x="907"
                                    y="96"
                                    text-anchor="middle"
                                >
                                    Record
                                </text>
                                <text
                                    class="t"
                                    x="907"
                                    y="116"
                                    text-anchor="middle"
                                >
                                    Deposit
                                </text>
                                <text
                                    class="s"
                                    x="907"
                                    y="172"
                                    text-anchor="middle"
                                >
                                    journal chaîné
                                </text>
                                <text
                                    class="s"
                                    x="907"
                                    y="190"
                                    text-anchor="middle"
                                >
                                    (hash-chain)
                                </text>
                            </g>
                            <g class="job">
                                <text
                                    class="s"
                                    x="1047"
                                    y="50"
                                    text-anchor="middle"
                                >
                                    8
                                </text>
                                <rect
                                    class="box"
                                    x="984"
                                    y="60"
                                    width="126"
                                    height="90"
                                    rx="10"
                                />
                                <text
                                    class="t"
                                    x="1047"
                                    y="96"
                                    text-anchor="middle"
                                >
                                    Cleanup
                                </text>
                                <text
                                    class="t"
                                    x="1047"
                                    y="116"
                                    text-anchor="middle"
                                >
                                    Temp
                                </text>
                                <text
                                    class="s"
                                    x="1047"
                                    y="172"
                                    text-anchor="middle"
                                >
                                    supprime work/
                                </text>
                                <text
                                    class="s"
                                    x="1047"
                                    y="190"
                                    text-anchor="middle"
                                >
                                    fichiers du job
                                </text>
                            </g>

                            <path
                                class="line"
                                d="M130 105 H144"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line"
                                d="M270 105 H284"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line"
                                d="M410 105 H424"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line"
                                d="M550 105 H564"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line"
                                d="M690 105 H704"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line"
                                d="M830 105 H844"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line"
                                d="M970 105 H984"
                                marker-end="url(#arr)"
                            />

                            <text
                                class="m"
                                x="560"
                                y="240"
                                text-anchor="middle"
                            >
                                queue media · ShouldBeUnique(uuid) · public int
                                $timeout · retry_after 3600
                            </text>
                        </svg>

                        <div class="why-row">
                            <WhyBox>
                                <h3>
                                    Pourquoi une chaîne et non des événements ?
                                </h3>
                                <p>
                                    Les étapes sont <b>ordonnées</b> et chacune
                                    dépend de la précédente : un échec arrête la
                                    chaîne et se rejoue proprement. Les
                                    événements restent réservés aux effets de
                                    bord (notifications, audit, quota).
                                </p>
                            </WhyBox>
                            <WhyBox>
                                <h3>
                                    Pourquoi le <code>TempFile</code> n'est pas
                                    sérialisé ?
                                </h3>
                                <p>
                                    Un handle de fichier ne se sérialise pas
                                    proprement. Le chemin est
                                    <b>déterministe</b> :
                                    <code>work/{uuid}.tmp</code> est recalculé
                                    par chaque job, donc la chaîne survit à un
                                    redémarrage de worker.
                                </p>
                            </WhyBox>
                        </div>
                        <footer class="foot">
                            <span>ONDA Storage · Architecture</span
                            ><span>07 / 14</span>
                        </footer>
                    </section>
                </div>

                <!-- ============================ 8 · PORTS & ADAPTERS ============================ -->
                <div class="frame" :class="frameClass(7)">
                    <section class="slide" aria-label="Diapositive 8">
                        <p class="kicker">08 · Architecture hexagonale</p>
                        <h2>
                            Ports &amp; adaptateurs : quatre coutures, pas plus
                        </h2>
                        <p class="ar" dir="rtl" lang="ar">
                            أربعة مفاصل فقط للتبديل بين البيئتين
                        </p>

                        <svg
                            class="diagram"
                            viewBox="0 0 1120 350"
                            style="height: 300px; width: auto"
                            role="img"
                            aria-label="Quatre contrats avec leurs implémentations locale et production"
                        >
                            <rect
                                class="box ok"
                                x="0"
                                y="40"
                                width="190"
                                height="300"
                                rx="14"
                            />
                            <text class="t" x="95" y="180" text-anchor="middle">
                                Domain / Actions
                            </text>
                            <text class="s" x="95" y="206" text-anchor="middle">
                                dépendances
                            </text>
                            <text class="s" x="95" y="226" text-anchor="middle">
                                vers le bas only
                            </text>
                            <text class="s" x="95" y="262" text-anchor="middle">
                                Domain n'importe
                            </text>
                            <text class="s" x="95" y="282" text-anchor="middle">
                                ni Http ni Infrastructure
                            </text>

                            <g
                                v-for="(row, idx) in [
                                    {
                                        c: 70,
                                        name: 'Delivery',
                                        role: 'livraison des fichiers',
                                        local: 'StreamDelivery',
                                        prod: 'LiteSpeedDelivery',
                                    },
                                    {
                                        c: 150,
                                        name: 'Scanner',
                                        role: 'analyse antivirus',
                                        local: 'NullScanner',
                                        prod: 'ClamavScanner',
                                    },
                                    {
                                        c: 230,
                                        name: 'ChunkTracker',
                                        role: 'morceaux reçus',
                                        local: 'DatabaseChunkTracker',
                                        prod: 'RedisChunkTracker',
                                    },
                                    {
                                        c: 310,
                                        name: 'MediaProbe',
                                        role: 'lecture des métadonnées',
                                        local: 'FfmpegProbe',
                                        prod: 'FfmpegProbe ou NullProbe',
                                    },
                                ]"
                                :key="idx"
                            >
                                <path
                                    class="line"
                                    :d="`M190 190 L260 ${row.c}`"
                                    marker-end="url(#arr)"
                                />
                                <rect
                                    class="box ok"
                                    x="260"
                                    :y="row.c - 30"
                                    width="250"
                                    height="60"
                                    rx="10"
                                />
                                <text
                                    class="m strong"
                                    x="385"
                                    :y="row.c - 4"
                                    text-anchor="middle"
                                >
                                    {{ row.name }}
                                </text>
                                <text
                                    class="s"
                                    x="385"
                                    :y="row.c + 18"
                                    text-anchor="middle"
                                >
                                    {{ row.role }}
                                </text>
                                <path
                                    class="line muted"
                                    :d="`M510 ${row.c} L600 ${row.c - 16}`"
                                />
                                <path
                                    class="line muted"
                                    :d="`M510 ${row.c} L600 ${row.c + 16}`"
                                />
                                <rect
                                    class="chip"
                                    x="600"
                                    :y="row.c - 29"
                                    width="520"
                                    height="26"
                                    rx="6"
                                />
                                <text
                                    class="s chip-label"
                                    x="614"
                                    :y="row.c - 11"
                                >
                                    LOCAL · Herd
                                </text>
                                <text class="m" x="730" :y="row.c - 11">
                                    {{ row.local }}
                                </text>
                                <rect
                                    class="chip prod"
                                    x="600"
                                    :y="row.c + 3"
                                    width="520"
                                    height="26"
                                    rx="6"
                                />
                                <text
                                    class="s chip-label"
                                    x="614"
                                    :y="row.c + 21"
                                >
                                    PROD · cPanel
                                </text>
                                <text class="m" x="730" :y="row.c + 21">
                                    {{ row.prod }}
                                </text>
                            </g>
                        </svg>

                        <p class="caption">
                            Aucun repository : Eloquent est la couche données.
                            Une Query Object n'existe que si une requête est
                            réellement complexe.
                        </p>
                        <div class="why-row single">
                            <WhyBox>
                                <h3>Pourquoi exactement quatre ?</h3>
                                <p>
                                    Herd sous Windows et cPanel diffèrent
                                    <b>matériellement</b> sur exactement ces
                                    quatre points : diffusion des fichiers,
                                    antivirus, suivi des morceaux, probe média.
                                    Partout ailleurs, une interface n'ajouterait
                                    que du cérémonial.
                                </p>
                            </WhyBox>
                        </div>
                        <footer class="foot">
                            <span>ONDA Storage · Architecture</span
                            ><span>08 / 14</span>
                        </footer>
                    </section>
                </div>

                <!-- ============================ 9 · DEPOSIT STEP 1 ============================ -->
                <div class="frame" :class="frameClass(8)">
                    <section class="slide" aria-label="Diapositive 9">
                        <p class="kicker">09 · Parcours de dépôt · étape 1</p>
                        <h2>Classification en cascade</h2>
                        <p class="ar" dir="rtl" lang="ar">
                            التصنيف المتدرّج: النوع ← التسيير ← الكلية ← الصفة
                        </p>

                        <svg
                            class="diagram"
                            viewBox="0 0 1120 250"
                            role="img"
                            aria-label="Cascade : type, gestion conditionnelle, collège, qualité, puis snapshot du collège"
                        >
                            <rect
                                class="box ok"
                                x="0"
                                y="60"
                                width="220"
                                height="110"
                                rx="12"
                            />
                            <text class="t" x="110" y="98" text-anchor="middle">
                                Type de déclarant
                            </text>
                            <text
                                class="m"
                                x="110"
                                y="122"
                                text-anchor="middle"
                            >
                                register_types
                            </text>
                            <text
                                class="s"
                                x="110"
                                y="146"
                                text-anchor="middle"
                            >
                                4 types
                            </text>

                            <rect
                                class="box dash"
                                x="290"
                                y="60"
                                width="220"
                                height="110"
                                rx="12"
                            />
                            <text class="t" x="400" y="98" text-anchor="middle">
                                Type de gestion
                            </text>
                            <text
                                class="m"
                                x="400"
                                y="122"
                                text-anchor="middle"
                            >
                                type_gestions
                            </text>
                            <text
                                class="s"
                                x="400"
                                y="146"
                                text-anchor="middle"
                            >
                                affiché si le type en a
                            </text>

                            <rect
                                class="box ok"
                                x="580"
                                y="60"
                                width="220"
                                height="110"
                                rx="12"
                            />
                            <text class="t" x="690" y="98" text-anchor="middle">
                                Collège
                            </text>
                            <text
                                class="m"
                                x="690"
                                y="122"
                                text-anchor="middle"
                            >
                                register_type_colleges
                            </text>
                            <text
                                class="s"
                                x="690"
                                y="146"
                                text-anchor="middle"
                            >
                                22 collèges
                            </text>

                            <rect
                                class="box ok"
                                x="870"
                                y="60"
                                width="220"
                                height="110"
                                rx="12"
                            />
                            <text class="t" x="980" y="98" text-anchor="middle">
                                Qualité
                            </text>
                            <text
                                class="m"
                                x="980"
                                y="122"
                                text-anchor="middle"
                            >
                                register_type_members
                            </text>
                            <text
                                class="s"
                                x="980"
                                y="146"
                                text-anchor="middle"
                            >
                                rôle au sein du collège
                            </text>

                            <path
                                class="line"
                                d="M220 115 H290"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line"
                                d="M510 115 H580"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line"
                                d="M800 115 H870"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line dash muted"
                                d="M110 60 V26 H690 V60"
                                marker-end="url(#arrm)"
                            />
                            <text class="s" x="400" y="20" text-anchor="middle">
                                type sans gestion : passe directement au collège
                            </text>

                            <path
                                class="line warn"
                                d="M690 170 V196"
                                marker-end="url(#arrw)"
                            />
                            <rect
                                class="box warn"
                                x="580"
                                y="200"
                                width="220"
                                height="44"
                                rx="10"
                            />
                            <text
                                class="m"
                                x="690"
                                y="228"
                                text-anchor="middle"
                            >
                                code_college_snapshot
                            </text>
                            <text class="s warn-t" x="712" y="190">
                                figé à la soumission
                            </text>

                            <rect
                                class="box"
                                x="0"
                                y="190"
                                width="540"
                                height="54"
                                rx="10"
                            />
                            <text class="t" x="20" y="214">
                                69 règles college_oeuvre_files
                            </text>
                            <text class="s" x="20" y="234">
                                une définition de document requis par collège et
                                par type de dépôt
                            </text>
                        </svg>

                        <div class="why-row">
                            <WhyBox>
                                <h3>Pourquoi la gestion selon les données ?</h3>
                                <p>
                                    Coder <code>type_id === 1</code> dans
                                    l'interface casse dès qu'un type est ajouté
                                    ou réordonné. Un affichage piloté par la
                                    présence de <code>type_gestions</code> suit
                                    la configuration, sans nouveau code.
                                </p>
                            </WhyBox>
                            <WhyBox>
                                <h3>Pourquoi un snapshot du collège ?</h3>
                                <p>
                                    Le référentiel reste modifiable. Un dépôt
                                    légal doit garder le code valide
                                    <b>au moment du dépôt</b> ; sans snapshot,
                                    la preuve se réécrit silencieusement quand
                                    le référentiel change.
                                </p>
                            </WhyBox>
                        </div>
                        <footer class="foot">
                            <span>ONDA Storage · Architecture</span
                            ><span>09 / 14</span>
                        </footer>
                    </section>
                </div>

                <!-- ============================ 10 · DEPOSIT STEP 2 ============================ -->
                <div class="frame" :class="frameClass(9)">
                    <section class="slide" aria-label="Diapositive 10">
                        <p class="kicker">10 · Parcours de dépôt · étape 2</p>
                        <h2>Un emplacement par document requis</h2>
                        <p class="ar" dir="rtl" lang="ar">
                            خانة لكل وثيقة مطلوبة وآلة حالات صارمة
                        </p>

                        <div class="two">
                            <div class="card">
                                <h3 class="card-title">Slots de documents</h3>
                                <div class="slot">
                                    <span class="slot-id">Slot 1</span>
                                    <span class="m"
                                        >college_oeuvre_file_id +
                                        document_key_snapshot</span
                                    >
                                    <span class="pill ok">fichier vérifié</span>
                                </div>
                                <div class="slot">
                                    <span class="slot-id">Slot 2</span>
                                    <span class="m"
                                        >une ligne par exigence du collège</span
                                    >
                                    <span class="pill">en attente</span>
                                </div>
                                <div class="slot">
                                    <span class="slot-id">Slot 3</span>
                                    <span class="m"
                                        >media_files rattaché au slot</span
                                    >
                                    <span class="pill">en attente</span>
                                </div>

                                <h3 class="card-title">Contrôle d'extension</h3>
                                <pre
                                    class="mini"
                                ><code><span class="ok">strtolower($ext)</span> <span class="c">// PDF = pdf</span>
<span class="ok">in_array($ext, $allowed, true)</span></code></pre>
                                <p class="sub">
                                    Vérification insensible à la casse. Les
                                    <code>conditions</code> JSON sont
                                    <b>consultatives</b> : elles avertissent,
                                    elles ne bloquent pas le dépôt.
                                </p>
                            </div>

                            <div class="card">
                                <h3 class="card-title">
                                    Machine à états du dépôt
                                </h3>
                                <svg
                                    class="diagram"
                                    viewBox="0 0 620 300"
                                    role="img"
                                    aria-label="Transitions : draft vers submitted vers under_review, puis registered (terminal) ou rejected, rejected vers submitted"
                                >
                                    <rect
                                        class="box"
                                        x="0"
                                        y="120"
                                        width="140"
                                        height="60"
                                        rx="10"
                                    />
                                    <text
                                        class="m strong"
                                        x="70"
                                        y="146"
                                        text-anchor="middle"
                                    >
                                        draft
                                    </text>
                                    <text
                                        class="s"
                                        x="70"
                                        y="166"
                                        text-anchor="middle"
                                    >
                                        brouillon
                                    </text>

                                    <rect
                                        class="box"
                                        x="190"
                                        y="120"
                                        width="150"
                                        height="60"
                                        rx="10"
                                    />
                                    <text
                                        class="m strong"
                                        x="265"
                                        y="146"
                                        text-anchor="middle"
                                    >
                                        submitted
                                    </text>
                                    <text
                                        class="s"
                                        x="265"
                                        y="166"
                                        text-anchor="middle"
                                    >
                                        en file
                                    </text>

                                    <rect
                                        class="box"
                                        x="380"
                                        y="120"
                                        width="170"
                                        height="60"
                                        rx="10"
                                    />
                                    <text
                                        class="m strong"
                                        x="465"
                                        y="146"
                                        text-anchor="middle"
                                    >
                                        under_review
                                    </text>
                                    <text
                                        class="s"
                                        x="465"
                                        y="166"
                                        text-anchor="middle"
                                    >
                                        tenu par un admin
                                    </text>

                                    <rect
                                        class="box ok"
                                        x="380"
                                        y="20"
                                        width="170"
                                        height="60"
                                        rx="10"
                                    />
                                    <rect
                                        class="box ok"
                                        x="386"
                                        y="26"
                                        width="158"
                                        height="48"
                                        rx="8"
                                    />
                                    <text
                                        class="m strong"
                                        x="465"
                                        y="48"
                                        text-anchor="middle"
                                    >
                                        registered
                                    </text>
                                    <text
                                        class="s"
                                        x="465"
                                        y="66"
                                        text-anchor="middle"
                                    >
                                        TERMINAL
                                    </text>

                                    <rect
                                        class="box danger"
                                        x="380"
                                        y="220"
                                        width="170"
                                        height="60"
                                        rx="10"
                                    />
                                    <text
                                        class="m strong"
                                        x="465"
                                        y="246"
                                        text-anchor="middle"
                                    >
                                        rejected
                                    </text>
                                    <text
                                        class="s"
                                        x="465"
                                        y="266"
                                        text-anchor="middle"
                                    >
                                        motif obligatoire
                                    </text>

                                    <path
                                        class="line"
                                        d="M140 150 H190"
                                        marker-end="url(#arr)"
                                    />
                                    <text
                                        class="s"
                                        x="165"
                                        y="140"
                                        text-anchor="middle"
                                    >
                                        auteur
                                    </text>
                                    <path
                                        class="line"
                                        d="M340 150 H380"
                                        marker-end="url(#arr)"
                                    />
                                    <text
                                        class="s"
                                        x="360"
                                        y="172"
                                        text-anchor="middle"
                                    >
                                        admin
                                    </text>
                                    <path
                                        class="line ok-l"
                                        d="M465 120 V80"
                                        marker-end="url(#arr)"
                                    />
                                    <text class="s" x="478" y="104">
                                        admin détenteur
                                    </text>
                                    <path
                                        class="line danger-l"
                                        d="M465 180 V220"
                                        marker-end="url(#arrd)"
                                    />
                                    <text class="s" x="478" y="204">rejet</text>
                                    <path
                                        class="line dash danger-l"
                                        d="M380 250 H265 V180"
                                        marker-end="url(#arrd)"
                                    />
                                    <text
                                        class="s"
                                        x="320"
                                        y="272"
                                        text-anchor="middle"
                                    >
                                        auteur · gate OK
                                    </text>
                                </svg>
                            </div>
                        </div>

                        <div class="why-row single">
                            <WhyBox>
                                <h3>
                                    Pourquoi une machine à états explicite ?
                                </h3>
                                <p>
                                    <code>registered</code> n'a
                                    <b>aucune transition</b> : ce n'est pas une
                                    garde ajoutée au-dessus, c'est l'absence de
                                    chemin. Tout changement de statut passe par
                                    <code>OeuvreStatusMachine</code>, qui écrit
                                    une ligne dans <code>oeuvre_reviews</code> :
                                    l'historique est la preuve.
                                </p>
                            </WhyBox>
                        </div>
                        <footer class="foot">
                            <span>ONDA Storage · Architecture</span
                            ><span>10 / 14</span>
                        </footer>
                    </section>
                </div>

                <!-- ============================ 11 · SCHEMA ============================ -->
                <div class="frame" :class="frameClass(10)">
                    <section class="slide" aria-label="Diapositive 11">
                        <p class="kicker">11 · Schéma &amp; conventions</p>
                        <h2>Soft delete : qui, et qui jamais</h2>
                        <p class="ar" dir="rtl" lang="ar">
                            الحذف المنطقي: من يُسمح له ومن لا
                        </p>

                        <div class="two">
                            <div class="card">
                                <svg
                                    class="diagram tight"
                                    viewBox="0 0 560 64"
                                    role="img"
                                    aria-label="33 tables classées : 12 avec soft delete, 21 jamais"
                                >
                                    <rect
                                        class="bar-bg"
                                        x="0"
                                        y="18"
                                        width="560"
                                        height="28"
                                        rx="6"
                                    />
                                    <rect
                                        class="bar-fg ok"
                                        x="0"
                                        y="18"
                                        width="218"
                                        height="28"
                                        rx="6"
                                    />
                                    <rect
                                        class="bar-fg red"
                                        x="218"
                                        y="18"
                                        width="342"
                                        height="28"
                                        rx="6"
                                    />
                                    <text class="s" x="0" y="12">
                                        Soft delete · 12 tables
                                    </text>
                                    <text
                                        class="s"
                                        x="560"
                                        y="12"
                                        text-anchor="end"
                                    >
                                        Jamais · 21 tables
                                    </text>
                                </svg>

                                <p class="sub">
                                    Keep (<code>deleted_at</code> + trait) :
                                </p>
                                <div class="tags ok">
                                    <span>users</span><span>oeuvres</span
                                    ><span>media_files</span
                                    ><span>countries</span><span>wilayas</span
                                    ><span>communes</span>
                                    <span>register_types</span
                                    ><span>type_gestions</span
                                    ><span>register_type_colleges</span
                                    ><span>register_type_members</span
                                    ><span>register_role_auteurs</span
                                    ><span>college_oeuvre_files</span>
                                </div>

                                <p class="sub">Never :</p>
                                <div class="tags danger">
                                    <span>upload_sessions</span
                                    ><span>file_access_logs</span
                                    ><span>media_variants</span
                                    ><span>storage_quotas</span
                                    ><span>oeuvre_reviews</span
                                    ><span>languages</span> <span>passkeys</span
                                    ><span>roles</span><span>permissions</span
                                    ><span>sessions</span
                                    ><span>notifications</span><span>jobs</span>
                                    <span>cache</span
                                    ><span>password_reset_tokens</span
                                    ><span>model_has_roles</span><span>…</span>
                                </div>
                            </div>

                            <div class="col">
                                <div class="card">
                                    <h3 class="card-title">Conventions</h3>
                                    <ul>
                                        <li>
                                            <code>id</code> bigint pour les FK,
                                            <code>uuid</code> UUIDv7 comme clé
                                            de route : aucun id séquentiel dans
                                            une URL.
                                        </li>
                                        <li>
                                            <code>sha256_plain</code> : index
                                            simple, <b>jamais unique</b>. Une
                                            ligne soft-supprimée ne doit pas
                                            bloquer un ré-upload.
                                        </li>
                                        <li>
                                            Soft delete ≠ libération disque :
                                            les octets restent jusqu'à
                                            <code>purged_at</code> (job P7, pas
                                            encore écrit).
                                        </li>
                                    </ul>
                                </div>
                                <WhyBox>
                                    <h3>
                                        Disque <code>work</code> ≠ table
                                        <code>oeuvres</code>
                                    </h3>
                                    <p>
                                        La table s'appelait <code>works</code> ;
                                        elle a été renommée en
                                        <code>oeuvres</code> (migration
                                        <code>2026_09_14_090000</code>). Le
                                        disque <code>work</code> (clair
                                        temporaire, <code>WORK_DISK_ROOT</code>)
                                        garde son nom : c'est un stockage, pas
                                        une entité métier.
                                    </p>
                                </WhyBox>
                            </div>
                        </div>
                        <footer class="foot">
                            <span>ONDA Storage · Architecture</span
                            ><span>11 / 14</span>
                        </footer>
                    </section>
                </div>

                <!-- ============================ 12 · PRODUCTION ============================ -->
                <div class="frame" :class="frameClass(11)">
                    <section class="slide" aria-label="Diapositive 12">
                        <p class="kicker">12 · Durcissement en production</p>
                        <h2>Checklist cPanel</h2>
                        <p class="ar" dir="rtl" lang="ar">
                            قائمة التحصين لبيئة الإنتاج
                        </p>

                        <svg
                            class="diagram"
                            viewBox="0 0 1120 340"
                            style="height: 290px; width: auto"
                            role="img"
                            aria-label="Six volets de durcissement autour de l'hébergement cPanel"
                        >
                            <g class="node">
                                <rect
                                    class="box"
                                    x="0"
                                    y="0"
                                    width="330"
                                    height="92"
                                    rx="12"
                                />
                                <text class="t" x="18" y="28">
                                    PHP · php.ini
                                </text>
                                <text class="m" x="18" y="52">
                                    post 32M · upload 16M · memory 256M
                                </text>
                                <text class="m" x="18" y="72">
                                    max_execution_time 120
                                </text>
                            </g>
                            <g class="node">
                                <rect
                                    class="box"
                                    x="0"
                                    y="124"
                                    width="330"
                                    height="92"
                                    rx="12"
                                />
                                <text class="t" x="18" y="152">
                                    Queue · cron
                                </text>
                                <text class="m" x="18" y="176">
                                    queue:work --queue=media,default
                                </text>
                                <text class="m" x="18" y="196">
                                    --max-time=3600 --stop-when-empty
                                </text>
                            </g>
                            <g class="node">
                                <rect
                                    class="box danger"
                                    x="0"
                                    y="248"
                                    width="330"
                                    height="92"
                                    rx="12"
                                />
                                <text class="t" x="18" y="276">
                                    Binaires · root
                                </text>
                                <text class="s" x="18" y="300">
                                    ffmpeg et ClamAV : installation
                                </text>
                                <text class="s" x="18" y="320">
                                    nécessite les droits root
                                </text>
                            </g>

                            <g class="node">
                                <rect
                                    class="box"
                                    x="790"
                                    y="0"
                                    width="330"
                                    height="92"
                                    rx="12"
                                />
                                <text class="t" x="808" y="28">
                                    .env &amp; secrets
                                </text>
                                <text class="s" x="808" y="52">
                                    .htaccess : 403 sur .env
                                </text>
                                <text class="s" x="808" y="72">
                                    secrets/ hors racine web
                                </text>
                            </g>
                            <g class="node">
                                <rect
                                    class="box"
                                    x="790"
                                    y="124"
                                    width="330"
                                    height="92"
                                    rx="12"
                                />
                                <text class="t" x="808" y="152">
                                    Sauvegardes
                                </text>
                                <text class="s" x="808" y="176">
                                    work/ jamais sauvegardé
                                </text>
                                <text class="s" x="808" y="196">
                                    clé maîtresse hors du jeu auto
                                </text>
                            </g>
                            <g class="node">
                                <rect
                                    class="box"
                                    x="790"
                                    y="248"
                                    width="330"
                                    height="92"
                                    rx="12"
                                />
                                <text class="t" x="808" y="276">
                                    Réseau · /etc/hosts
                                </text>
                                <text class="s" x="808" y="300">
                                    résolution locale du domaine
                                </text>
                                <text class="s" x="808" y="320">
                                    contournement du NAT
                                </text>
                            </g>

                            <path
                                class="line"
                                d="M330 46 L385 120"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line"
                                d="M330 170 L385 170"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line"
                                d="M330 294 L385 220"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line"
                                d="M790 46 L735 120"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line"
                                d="M790 170 L735 170"
                                marker-end="url(#arr)"
                            />
                            <path
                                class="line"
                                d="M790 294 L735 220"
                                marker-end="url(#arr)"
                            />

                            <rect
                                class="hub"
                                x="385"
                                y="100"
                                width="350"
                                height="140"
                                rx="20"
                            />
                            <text
                                class="b"
                                x="560"
                                y="150"
                                text-anchor="middle"
                            >
                                Hébergement cPanel
                            </text>
                            <text
                                class="s"
                                x="560"
                                y="176"
                                text-anchor="middle"
                            >
                                Laravel · queue media · LiteSpeed
                            </text>
                            <text
                                class="s"
                                x="560"
                                y="198"
                                text-anchor="middle"
                            >
                                production
                            </text>
                        </svg>

                        <div class="why-row single">
                            <WhyBox>
                                <h3>Pourquoi ces valeurs ?</h3>
                                <p>
                                    <code>memory_limit</code> à 256M : si ce
                                    n'est pas suffisant, un fichier est
                                    <b>bufferisé en entier</b>, et c'est ce
                                    bug-là qu'il faut corriger, pas la limite.
                                    <code>max_execution_time</code> à 120 s :
                                    aucune requête HTTP ne fait de travail
                                    lourd, c'est la queue qui le fait.
                                </p>
                            </WhyBox>
                        </div>
                        <footer class="foot">
                            <span>ONDA Storage · Architecture</span
                            ><span>12 / 14</span>
                        </footer>
                    </section>
                </div>

                <!-- ============================ 13 · BUGS ============================ -->
                <div class="frame" :class="frameClass(12)">
                    <section class="slide" aria-label="Diapositive 13">
                        <p class="kicker">13 · Retour d'expérience</p>
                        <h2>Bugs &amp; leçons apprises</h2>
                        <p class="ar" dir="rtl" lang="ar">
                            الأخطاء والدروس المستفادة
                        </p>

                        <div class="timeline">
                            <div class="tl-item">
                                <span class="tl-dot"></span>
                                <span class="tl-n">Étape 1</span>
                                <h3>public/build périmé</h3>
                                <p class="sub">
                                    <b>Symptôme :</b> l'interface ne reflète pas
                                    le code livré.
                                </p>
                                <p class="sub">
                                    <b>Leçon :</b>
                                    <code>npm run build</code> après toute
                                    modification front. Le build servi n'est pas
                                    recompilé à la requête.
                                </p>
                            </div>
                            <div class="tl-item">
                                <span class="tl-dot"></span>
                                <span class="tl-n">Étape 2</span>
                                <h3>Herd : SAPI désaccordée, PHP 8.2 vs 8.4</h3>
                                <p class="sub">
                                    <b>Symptôme :</b> limites différentes entre
                                    la CLI et le serveur web.
                                </p>
                                <p class="sub">
                                    <b>Leçon :</b> vérifier le
                                    <code>php.ini</code> chargé par la SAPI qui
                                    sert les requêtes, pas celui de la CLI.
                                </p>
                            </div>
                            <div class="tl-item">
                                <span class="tl-dot warn"></span>
                                <span class="tl-n">Étape 3</span>
                                <h3>Erreur de Worker cross-origin avalée</h3>
                                <p class="sub">
                                    <b>Symptôme :</b> l'upload échouait sans
                                    message exploitable.
                                </p>
                                <p class="sub">
                                    <b>Leçon :</b> écouter explicitement
                                    <code>error</code> et
                                    <code>messageerror</code> du Worker, et
                                    remonter l'erreur à l'interface.
                                </p>
                            </div>
                            <div class="tl-item">
                                <span class="tl-dot danger"></span>
                                <span class="tl-n">Étape 4</span>
                                <h3>
                                    Des agents qui plient le code de prod pour
                                    passer les tests
                                </h3>
                                <p class="sub">
                                    <b>Symptôme :</b> tests verts, comportement
                                    de production altéré.
                                </p>
                                <p class="sub">
                                    <b>Leçon :</b> un test rouge est une
                                    information. On corrige le test s'il est
                                    faux, jamais la prod pour le faire passer.
                                    Revue du diff obligatoire.
                                </p>
                            </div>
                        </div>

                        <div class="why-row single">
                            <WhyBox>
                                <h3>Le fil rouge</h3>
                                <p>
                                    Trois des quatre bugs se voient dans
                                    l'environnement, pas dans le code :
                                    <b>build, SAPI, Worker</b>. Une checklist
                                    d'environnement vaut souvent plus qu'un test
                                    de plus.
                                </p>
                            </WhyBox>
                        </div>
                        <footer class="foot">
                            <span>ONDA Storage · Architecture</span
                            ><span>13 / 14</span>
                        </footer>
                    </section>
                </div>

                <!-- ============================ 14 · NEXT STEPS ============================ -->
                <div class="frame" :class="frameClass(13)">
                    <section class="slide" aria-label="Diapositive 14">
                        <p class="kicker">14 · Décisions &amp; suite</p>
                        <h2>Décisions ouvertes &amp; prochaines étapes</h2>
                        <p class="ar" dir="rtl" lang="ar">
                            القرارات المفتوحة والخطوات التالية
                        </p>

                        <div class="two">
                            <div class="col">
                                <div class="card decision">
                                    <span class="pill warn">à trancher</span>
                                    <h3>Drapeau <code>is_system</code></h3>
                                    <p class="sub">
                                        Rôle exact à définir : distinguer les
                                        lignes amorcées par seeder de celles
                                        ajoutées par l'admin.
                                    </p>
                                </div>
                                <div class="card decision">
                                    <span class="pill warn">proposition</span>
                                    <h3>Collège désactivé par défaut</h3>
                                    <p class="sub">
                                        Un collège créé reste inactif tant que
                                        ses règles de documents ne sont pas en
                                        place.
                                    </p>
                                </div>
                                <div class="card decision">
                                    <span class="pill ok">décidé</span>
                                    <h3>Gestion pilotée par les données</h3>
                                    <p class="sub">
                                        Affichée si le type possède des
                                        <code>type_gestions</code>, jamais selon
                                        <code>type_id === 1</code>.
                                    </p>
                                </div>
                            </div>

                            <div class="card">
                                <h3 class="card-title">Prochaines étapes</h3>
                                <ul class="todo">
                                    <li>
                                        <span>Lien API</span
                                        ><span class="pill warn"
                                            >en attente</span
                                        >
                                    </li>
                                    <li>
                                        <span>Test de charge 5 Go</span
                                        ><span class="pill">à faire</span>
                                    </li>
                                    <li>
                                        <span
                                            >Nettoyage des uploads abandonnés
                                            (placeholders non récupérés)</span
                                        ><span class="pill">à faire</span>
                                    </li>
                                    <li>
                                        <span
                                            >Job de purge
                                            (<code>purged_at</code>, rétention
                                            30 jours)</span
                                        ><span class="pill">phase P7</span>
                                    </li>
                                </ul>

                                <WhyBox>
                                    <h3>Pourquoi cet ordre ?</h3>
                                    <p>
                                        Le test de charge vient avant la purge :
                                        il dira si le modèle à morceaux tient
                                        réellement à 5 Go, et combien de disque
                                        la purge devra rendre.
                                    </p>
                                </WhyBox>
                            </div>
                        </div>
                        <footer class="foot">
                            <span>ONDA Storage · Architecture</span
                            ><span>14 / 14</span>
                        </footer>
                    </section>
                </div>
            </div>
        </div>

        <nav class="controls" aria-label="Navigation de la présentation">
            <button
                type="button"
                class="btn"
                :disabled="current === 0"
                aria-label="Diapositive précédente"
                @click="prev"
            >
                <ChevronLeft :size="18" />
            </button>

            <div class="dots">
                <button
                    v-for="i in total"
                    :key="i"
                    type="button"
                    class="dot"
                    :class="{ on: current === i - 1 }"
                    :aria-label="`Aller à la diapositive ${i}`"
                    :aria-current="current === i - 1 ? 'step' : undefined"
                    @click="go(i - 1)"
                ></button>
            </div>

            <span class="count">{{ current + 1 }} / {{ total }}</span>

            <button type="button" class="btn accent" @click="printDeck">
                <Printer :size="16" /> Imprimer / PDF
            </button>

            <button
                type="button"
                class="btn"
                :disabled="current === total - 1"
                aria-label="Diapositive suivante"
                @click="next"
            >
                <ChevronRight :size="18" />
            </button>
        </nav>
    </div>
</template>

<style scoped>
/* Palette: ONDA green on near-black. Declared once, read everywhere. */
.deck-root {
    --bg: #07110e;
    --panel: #0e1d19;
    --line: rgba(255, 255, 255, 0.09);
    --text: #e8f3ef;
    --muted: #93aaa3;
    --accent: #3fd1a4;
    --brand: #1c9976;
    --amber: #f5b841;
    --red: #f26b6b;

    position: fixed;
    inset: 0;
    display: flex;
    flex-direction: column;
    background: var(--bg);
    color: var(--text);
    font-family: 'IBM Plex Sans', 'IBM Plex Sans Arabic', system-ui, sans-serif;
    overflow: hidden;
    -webkit-font-smoothing: antialiased;
}

.defs {
    position: absolute;
    width: 0;
    height: 0;
    overflow: hidden;
}

.viewport {
    position: relative;
    flex: 1;
    overflow: hidden;
}

.track {
    position: absolute;
    top: 0;
    left: 0;
    display: flex;
    transition: transform 0.6s cubic-bezier(0.2, 0.7, 0.2, 1);
    will-change: transform;
}

/* One frame = the scaled footprint of a 1280x720 slide. */
.frame {
    position: relative;
    flex: none;
    width: calc(var(--s) * 1280px);
    height: calc(var(--s) * 720px);
    overflow: hidden;
}

.frame.is-hidden {
    visibility: hidden;
}

.slide {
    position: absolute;
    top: 0;
    left: 0;
    width: 1280px;
    height: 720px;
    padding: 44px 72px 26px;
    display: flex;
    flex-direction: column;
    transform: scale(var(--s));
    transform-origin: 0 0;
    background:
        radial-gradient(
            900px 480px at 88% -12%,
            rgba(28, 153, 118, 0.22),
            transparent 60%
        ),
        linear-gradient(160deg, #0b1a16 0%, #07110e 100%);
    overflow: hidden;
}

.slide h2 {
    margin: 6px 0 2px;
    font-size: 40px;
    font-weight: 650;
    line-height: 1.1;
    letter-spacing: -0.01em;
}

.slide h3 {
    margin: 0 0 6px;
    font-size: 19px;
    font-weight: 650;
    line-height: 1.25;
}

.slide p {
    margin: 0;
    font-size: 17px;
    line-height: 1.45;
    color: var(--text);
}

.slide .sub {
    color: var(--muted);
    font-size: 15px;
    line-height: 1.45;
}

.slide .small {
    font-size: 15px;
}

.slide code,
.slide .m {
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    font-size: 0.86em;
    color: var(--accent);
}

.slide .kicker {
    font-size: 14px;
    font-weight: 600;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--accent);
}

.slide .ar {
    font-family: 'IBM Plex Sans Arabic', 'IBM Plex Sans', sans-serif;
    font-size: 21px;
    color: var(--muted);
    margin-top: 2px;
}

.slide .caption {
    margin-top: 14px;
    font-size: 16px;
    color: var(--muted);
}

.foot {
    margin-top: auto;
    display: flex;
    justify-content: space-between;
    padding-top: 12px;
    border-top: 1px solid var(--line);
    font-size: 13px;
    color: var(--muted);
}

.card {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 20px 24px;
}

.card-title {
    margin-bottom: 10px;
    font-size: 15px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--accent);
}

.card ul,
.why ul {
    margin: 0;
    padding-left: 18px;
    font-size: 16px;
    line-height: 1.5;
}

.card li + li,
.why li + li {
    margin-top: 6px;
}

.two {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-top: 20px;
    flex: 1;
    min-height: 0;
}

.col {
    display: flex;
    flex-direction: column;
    gap: 16px;
    min-height: 0;
}

.why-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin-top: 16px;
}

.why-row.three {
    grid-template-columns: 1fr 1fr 1fr;
}

.why-row.single {
    grid-template-columns: 1fr;
}

/* WhyBox's wrapper is rendered by h(), outside this component's scope id. */
:global(.why) {
    background: linear-gradient(
        135deg,
        rgba(63, 209, 164, 0.13),
        rgba(63, 209, 164, 0.03)
    );
    border: 1px solid rgba(63, 209, 164, 0.36);
    border-radius: 14px;
    padding: 14px 20px;
}

.why h3 {
    font-size: 17px;
}

.why p {
    font-size: 15px;
    line-height: 1.45;
}

:global(.why-title) {
    margin-bottom: 4px !important;
    font-size: 12px !important;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--accent) !important;
}

.why-split {
    display: grid;
    grid-template-columns: 1.1fr 1fr;
    gap: 18px;
    align-items: center;
}

.why .why-split ul {
    font-size: 15px;
}

.chart {
    width: 100%;
    height: auto;
    margin: 0 !important;
}

.banner {
    margin-top: 18px;
    padding: 14px 20px;
    border-left: 3px solid var(--accent);
    background: rgba(63, 209, 164, 0.07);
    border-radius: 8px;
    font-size: 18px;
}

/* ---------- Diagrams ---------- */
.diagram {
    display: block;
    flex: none;
    width: 100%;
    height: auto;
    margin: 18px auto 0;
}

.diagram.flow {
    height: auto;
    margin-top: 14px;
}

.diagram.tight {
    height: 64px;
    margin-top: 0;
}

.diagram .t {
    fill: var(--text);
    font-size: 15px;
    font-weight: 600;
}

.diagram .b {
    fill: var(--text);
    font-size: 19px;
    font-weight: 700;
}

.diagram .s {
    fill: var(--muted);
    font-size: 13px;
}

.diagram .m {
    fill: var(--accent);
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    font-size: 12.5px;
}

.diagram .m.strong {
    font-size: 14px;
    font-weight: 600;
}

.diagram .warn-t {
    fill: var(--amber);
}

.diagram .box {
    fill: #0e1d19;
    stroke: rgba(63, 209, 164, 0.5);
    stroke-width: 1.5;
}

.diagram .box.ok {
    fill: #0d2a22;
    stroke: var(--accent);
}

.diagram .box.warn {
    fill: #2a2110;
    stroke: var(--amber);
}

.diagram .box.danger {
    fill: #2a1414;
    stroke: var(--red);
}

.diagram .box.dash {
    stroke-dasharray: 6 5;
}

.diagram .boundary {
    fill: none;
    stroke: rgba(147, 170, 163, 0.5);
    stroke-dasharray: 8 6;
    stroke-width: 1.5;
}

.diagram .frame-x {
    fill: rgba(63, 209, 164, 0.04);
    stroke: rgba(63, 209, 164, 0.35);
    stroke-dasharray: 5 5;
}

.diagram .hub {
    fill: #0d2a22;
    stroke: var(--accent);
    stroke-width: 2;
}

.diagram .chip {
    fill: #0e1d19;
    stroke: rgba(63, 209, 164, 0.45);
}

.diagram .chip.prod {
    stroke: rgba(147, 170, 163, 0.5);
}

.diagram .chip-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.06em;
}

.diagram .line {
    fill: none;
    stroke: var(--accent);
    stroke-width: 2;
}

.diagram .line.warn,
.diagram .line.warn-l {
    stroke: var(--amber);
}

.diagram .line.danger-l {
    stroke: var(--red);
}

.diagram .line.ok-l {
    stroke: var(--accent);
}

.diagram .line.muted {
    stroke: var(--muted);
}

.diagram .line.dash {
    stroke-dasharray: 6 5;
}

.diagram .lifeline {
    stroke: rgba(147, 170, 163, 0.4);
    stroke-dasharray: 4 5;
}

.diagram .bar-bg {
    fill: rgba(255, 255, 255, 0.06);
}

.diagram .bar-fg {
    fill: var(--accent);
}

.diagram .bar-fg.red {
    fill: var(--red);
}

.diagram .bar-fg.ok {
    fill: var(--accent);
}

.diagram .bar-fg.warn {
    fill: var(--amber);
}

.diagram text.s.warn-t {
    fill: var(--amber);
}

/* ---------- Slide-specific ---------- */
.cover {
    justify-content: center;
}

.cover-glow {
    position: absolute;
    right: -120px;
    top: -140px;
    width: 720px;
    height: 720px;
    border-radius: 50%;
    background: radial-gradient(
        closest-side,
        rgba(63, 209, 164, 0.28),
        transparent
    );
    pointer-events: none;
}

.cover-title {
    margin: 10px 0 8px;
    font-size: 104px;
    font-weight: 700;
    line-height: 1;
    letter-spacing: -0.02em;
    color: var(--text);
}

.cover-sub {
    font-size: 30px !important;
    color: var(--text) !important;
}

.cover-sub strong {
    color: var(--accent);
}

.cover .ar {
    font-size: 24px;
    margin-top: 10px;
}

.chips {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 28px;
}

.chips span,
.tags span,
.pill {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 999px;
    border: 1px solid var(--line);
    font-size: 13px;
    color: var(--muted);
}

.stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-top: 40px;
}

.stat {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 18px 20px;
}

.stat b {
    display: block;
    font-size: 30px;
    font-weight: 700;
    color: var(--accent);
}

.stat span {
    font-size: 14px;
    color: var(--muted);
}

.meta {
    margin-top: auto;
    font-size: 15px;
    color: var(--muted);
}

.rules {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 22px;
    margin-top: 24px;
    flex: 1;
}

.rule {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.rule h3 {
    font-size: 24px;
    margin-top: 6px;
}

.rule p {
    font-size: 17px;
}

.icon {
    width: 62px;
    height: 62px;
    border-radius: 16px;
    display: grid;
    place-items: center;
    background: rgba(63, 209, 164, 0.14);
    color: var(--accent);
}

.icon.danger {
    background: rgba(242, 107, 107, 0.14);
    color: var(--red);
}

.metric {
    margin-top: auto;
}

.bar {
    height: 10px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.07);
    overflow: hidden;
}

.bar span {
    display: block;
    height: 100%;
    background: var(--accent);
    border-radius: 999px;
}

.bar span.warn {
    background: var(--amber);
}

.metric .sub {
    margin-top: 8px;
}

.panes {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-top: 18px;
}

.pane {
    border-radius: 14px;
    overflow: hidden;
    border: 1px solid var(--line);
    background: #0a1512;
}

.pane-head {
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.pane-head.bad {
    background: rgba(242, 107, 107, 0.16);
    color: var(--red);
}

.pane-head.ok {
    background: rgba(63, 209, 164, 0.16);
    color: var(--accent);
}

pre {
    margin: 0;
    padding: 14px 18px;
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    font-size: 14px;
    line-height: 1.55;
    color: var(--text);
    white-space: pre;
    overflow: hidden;
}

pre .bad {
    color: #ffb4b4;
}

pre .ok {
    color: #9ef0cf;
}

pre .c {
    color: var(--muted);
}

.mini {
    padding: 10px 14px;
    border-radius: 10px;
    background: #0a1512;
    border: 1px solid var(--line);
    font-size: 13px;
}

.slot {
    display: grid;
    grid-template-columns: 74px 1fr auto;
    gap: 12px;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--line);
    font-size: 14px;
}

.slot-id {
    font-weight: 700;
    color: var(--text);
}

.slot .m {
    font-size: 12px;
    color: var(--muted);
}

.pill.ok {
    border-color: rgba(63, 209, 164, 0.5);
    color: var(--accent);
}

.pill.warn {
    border-color: rgba(245, 184, 65, 0.5);
    color: var(--amber);
}

.pill.danger {
    border-color: rgba(242, 107, 107, 0.5);
    color: var(--red);
}

.tags {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 8px 0 16px;
}

.tags.ok span {
    border-color: rgba(63, 209, 164, 0.45);
    color: var(--accent);
}

.tags.danger span {
    border-color: rgba(242, 107, 107, 0.4);
    color: #f7a3a3;
}

.tags span {
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    font-size: 12px;
    padding: 3px 10px;
}

.timeline {
    position: relative;
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
    margin-top: 26px;
    padding-top: 30px;
    flex: 1;
    min-height: 0;
}

.timeline::before {
    content: '';
    position: absolute;
    top: 9px;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(
        90deg,
        var(--brand),
        var(--accent),
        var(--amber),
        var(--red)
    );
    opacity: 0.8;
}

.tl-item {
    position: relative;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 16px 18px;
}

.tl-dot {
    position: absolute;
    top: -30px;
    left: 18px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: var(--accent);
    box-shadow: 0 0 0 4px var(--bg);
}

.tl-dot.warn {
    background: var(--amber);
}

.tl-dot.danger {
    background: var(--red);
}

.tl-n {
    display: block;
    font-size: 12px;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 4px;
}

.tl-item h3 {
    font-size: 17px;
}

.tl-item .sub {
    font-size: 14px;
    margin-top: 6px;
}

.decision {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 16px 20px;
}

.decision .pill {
    align-self: flex-start;
}

.decision h3 {
    margin: 2px 0 0;
    font-size: 18px;
}

.todo {
    list-style: none;
    padding: 0 !important;
    margin: 0;
}

.todo li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    padding: 12px 0;
    border-bottom: 1px solid var(--line);
    font-size: 16px;
}

.todo li + li {
    margin-top: 0 !important;
}

.todo .pill {
    flex: none;
}

/* ---------- Controls (hidden in print) ---------- */
.controls {
    flex: none;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 14px;
    padding: 0 16px;
    border-top: 1px solid var(--line);
    background: rgba(7, 17, 14, 0.9);
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    height: 38px;
    min-width: 38px;
    justify-content: center;
    padding: 0 14px;
    border-radius: 10px;
    border: 1px solid var(--line);
    background: var(--panel);
    color: var(--text);
    font-size: 14px;
    cursor: pointer;
}

.btn:hover:not(:disabled) {
    border-color: var(--accent);
}

.btn:disabled {
    opacity: 0.35;
    cursor: default;
}

.btn.accent {
    background: var(--brand);
    border-color: var(--brand);
    color: #fff;
}

.btn.accent:hover {
    background: var(--accent);
    border-color: var(--accent);
    color: #04110c;
}

.dots {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    justify-content: center;
    max-width: 480px;
}

.dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    border: none;
    padding: 0;
    background: rgba(255, 255, 255, 0.22);
    cursor: pointer;
}

.dot.on {
    background: var(--accent);
    transform: scale(1.3);
}

.count {
    min-width: 58px;
    text-align: center;
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    font-size: 14px;
    color: var(--muted);
}

/* ---------- Narrow screens: stacked, scrolling, phone-width ---------- */
.is-stacked.deck-root {
    position: relative;
    inset: auto;
    min-height: 100dvh;
    height: auto;
    overflow: visible;
}

.is-stacked .viewport {
    flex: none;
    overflow: visible;
}

.is-stacked .track {
    position: relative;
    flex-direction: column;
    align-items: center;
    gap: 18px;
    padding: 16px 0 24px;
    transform: none !important;
    transition: none;
}

.is-stacked .frame {
    width: calc(var(--s) * 1280px);
    height: calc(var(--s) * 720px);
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.35);
    border-radius: 10px;
}

.is-stacked .controls {
    position: sticky;
    bottom: 0;
    z-index: 5;
}

.is-stacked .dots {
    display: none;
}

/* ---------- Print: one 16:9 page per slide, dark colours kept ---------- */
@media print {
    @page {
        size: 1280px 720px;
        margin: 0;
    }

    .deck-root,
    .is-stacked.deck-root {
        position: static !important;
        inset: auto !important;
        height: auto !important;
        min-height: 0 !important;
        overflow: visible !important;
        background: #07110e;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .controls,
    .defs {
        display: none !important;
    }

    .viewport {
        height: auto !important;
        overflow: visible !important;
        flex: none !important;
    }

    .track {
        position: static !important;
        display: block !important;
        transform: none !important;
        transition: none !important;
        padding: 0 !important;
        gap: 0 !important;
    }

    .frame,
    .is-stacked .frame {
        width: 1280px !important;
        height: 720px !important;
        visibility: visible !important;
        margin: 0 !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        break-after: page;
        page-break-after: always;
    }

    .frame:last-child {
        break-after: auto;
        page-break-after: auto;
    }

    .slide,
    .is-stacked .slide {
        transform: none !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>
