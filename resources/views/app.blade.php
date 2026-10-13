<!DOCTYPE html>
@php
    $htmlLang = $locale ?? app()->getLocale();
@endphp
<html lang="{{ $htmlLang }}" dir="{{ $direction ?? 'ltr' }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css
             Values must match --background in resources/css/app.css exactly, in both
             modes — this is the only thing painted before Tailwind's CSS loads. --}}
        <style>
            html {
                background-color: #f1f3f5;
            }

            html.dark {
                background-color: #15130f;
            }
        </style>

        {{-- First-paint loader (see the element at the top of <body>): the
             same design as components/AppLoader.vue, in a static version.
             Plain HTML + CSS: it is on screen before any script or stylesheet
             has loaded, so it cannot use Tailwind or the theme variables. Its
             colours are the original preloader's own values (dark) and the
             VALUES of the light tokens of resources/css/app.css; keep them in
             step with AppLoader.vue. Dark comes from the same `dark` class as
             the rest of the page (server cookie, or the inline script above
             for "system"), so dark mode never flashes white. --}}
        <style>
            #app-initial-loader {
                position: fixed;
                inset: 0;
                z-index: 2147483000;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                overflow: hidden;
                background-color: #f8fafc;
                color: #0f172a;
                font-family: 'IBM Plex Sans Arabic', 'IBM Plex Sans', ui-sans-serif, system-ui, sans-serif;
                text-align: center;
                opacity: 1;
                transition: opacity 200ms ease-in;
                /* Never trap the user: if the app has not mounted after 12 s
                   (script blocked, network down) the loader lets go. */
                animation: app-initial-loader-release 300ms linear 12s forwards;
            }

            html.dark #app-initial-loader {
                background-color: #070b14;
                color: #ffffff;
            }

            #app-initial-loader[data-leaving] {
                opacity: 0;
                pointer-events: none;
            }

            #app-initial-loader .ail-glow {
                position: absolute;
                width: 384px;
                height: 384px;
                border-radius: 9999px;
                filter: blur(120px);
                pointer-events: none;
            }

            #app-initial-loader .ail-glow-a {
                top: -128px;
                left: -128px;
                background: rgb(27 102 157 / 0.1);
            }

            #app-initial-loader .ail-glow-b {
                right: -128px;
                bottom: -128px;
                background: rgb(37 180 141 / 0.1);
            }

            html.dark #app-initial-loader .ail-glow-a {
                background: rgb(27 102 157 / 0.2);
            }

            html.dark #app-initial-loader .ail-glow-b {
                background: rgb(37 180 141 / 0.2);
            }

            #app-initial-loader .ail-mesh {
                position: absolute;
                inset: 0;
                pointer-events: none;
                background-image: radial-gradient(#475569 1px, transparent 1px);
                background-size: 24px 24px;
                opacity: 0.15;
            }

            html.dark #app-initial-loader .ail-mesh {
                background-image: radial-gradient(#1e293b 1px, transparent 1px);
                opacity: 0.3;
            }

            #app-initial-loader .ail-center {
                position: relative;
                z-index: 1;
                display: flex;
                flex-direction: column;
                align-items: center;
                padding: 0 24px;
            }

            #app-initial-loader .ail-stage {
                position: relative;
                width: 176px;
                height: 176px;
                margin-bottom: 32px;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            #app-initial-loader .ail-ring {
                position: absolute;
                inset: 0;
                width: 100%;
                height: 100%;
                color: rgb(15 23 42 / 0.1);
            }

            html.dark #app-initial-loader .ail-ring {
                color: rgb(255 255 255 / 0.08);
            }

            #app-initial-loader .ail-dashes {
                position: absolute;
                inset: 8px;
                width: calc(100% - 16px);
                height: calc(100% - 16px);
            }

            #app-initial-loader .ail-tile {
                position: relative;
                width: 112px;
                height: 112px;
                box-sizing: border-box;
                padding: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 24px;
                border: 1px solid rgb(255 255 255 / 0.15);
                background: linear-gradient(to bottom right, rgb(15 23 42 / 0.9), rgb(2 6 23 / 0.95), rgb(15 23 42 / 0.9));
                box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.25);
            }

            #app-initial-loader .ail-tile img {
                height: 56px;
                width: auto;
                filter: drop-shadow(0 8px 16px rgba(27, 102, 157, 0.5));
            }

            #app-initial-loader .ail-badge {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 4px 14px;
                border-radius: 9999px;
                border: 1px solid rgb(37 180 141 / 0.3);
                background: rgb(37 180 141 / 0.1);
                color: #187a5f;
                font-size: 11px;
                font-weight: 600;
            }

            html.dark #app-initial-loader .ail-badge {
                color: #7de1c5;
            }

            #app-initial-loader .ail-badge i {
                width: 6px;
                height: 6px;
                border-radius: 9999px;
                background: #1c9976;
            }

            html.dark #app-initial-loader .ail-badge i {
                background: #45ceaa;
            }

            #app-initial-loader h2 {
                margin: 6px 0 0;
                font-size: 18px;
                line-height: 28px;
                font-weight: 700;
                letter-spacing: 0.025em;
            }

            #app-initial-loader .ail-sub {
                margin: 6px 0 24px;
                font-size: 14px;
                line-height: 20px;
                font-weight: 500;
                color: #475569;
            }

            html.dark #app-initial-loader .ail-sub {
                color: #94a3b8;
            }

            #app-initial-loader .ail-progress {
                width: 288px;
                max-width: 100%;
            }

            #app-initial-loader .ail-track {
                height: 6px;
                box-sizing: border-box;
                overflow: hidden;
                border-radius: 9999px;
                border: 1px solid #e2e8f0;
                background: #f1f5f9;
            }

            html.dark #app-initial-loader .ail-track {
                border-color: rgb(51 65 85 / 0.5);
                background: rgb(30 41 59 / 0.8);
            }

            #app-initial-loader .ail-fill {
                width: 15%;
                height: 100%;
                border-radius: 9999px;
                background: linear-gradient(to right, #2587c0, #38bdf8, #45ceaa);
                box-shadow: 0 0 12px rgba(28, 153, 118, 0.8);
            }

            #app-initial-loader .ail-status {
                margin-top: 10px;
                display: flex;
                align-items: center;
                gap: 6px;
                font-family: 'IBM Plex Mono', ui-monospace, Menlo, Consolas, monospace;
                font-size: 11px;
                font-weight: 500;
                color: #475569;
            }

            html.dark #app-initial-loader .ail-status {
                color: #94a3b8;
            }

            #app-initial-loader .ail-status i {
                width: 6px;
                height: 6px;
                border-radius: 9999px;
                background: #1b669d;
            }

            html.dark #app-initial-loader .ail-status i {
                background: #4ba4d5;
            }

            #app-initial-loader .ail-foot {
                position: absolute;
                bottom: 24px;
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 10px;
                font-weight: 500;
                letter-spacing: 0.05em;
                color: #475569;
            }

            html.dark #app-initial-loader .ail-foot {
                color: #64748b;
            }

            @keyframes app-initial-loader-release {
                to { opacity: 0; visibility: hidden; }
            }

            @media (prefers-reduced-motion: reduce) {
                #app-initial-loader { transition-duration: 120ms; }
            }
        </style>
        <noscript><style>#app-initial-loader { display: none; }</style></noscript>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        @php
            $loaderLocale = in_array($htmlLang, ['ar', 'fr', 'en'], true) ? $htmlLang : 'en';
            // Same strings as loader.loading, common.portalTitle and
            // common.loading in resources/js/locales/*.json.
            $loaderLabel = ['ar' => 'جارٍ التحميل…', 'fr' => 'Chargement…', 'en' => 'Loading…'][$loaderLocale];
            $loaderPortal = [
                'ar' => 'البوابة الرقمية لحقوق المؤلف والحقوق المجاورة',
                'fr' => "Portail Numérique Officiel des Droits d'Auteur",
                'en' => 'Official Digital Copyright Portal',
            ][$loaderLocale];
            $loaderStatus = [
                'ar' => 'جاري تحميل المنصة الرسمية...',
                'fr' => "Chargement de l'espace ONDA...",
                'en' => 'Loading ONDA Digital Portal...',
            ][$loaderLocale];
        @endphp
        {{-- Removed by AppLoader.vue as soon as the Vue app has mounted. --}}
        <div id="app-initial-loader" role="status" aria-live="polite" aria-label="{{ $loaderLabel }}">
            <div class="ail-glow ail-glow-a"></div>
            <div class="ail-glow ail-glow-b"></div>
            <div class="ail-mesh"></div>
            <div class="ail-center">
                <div class="ail-stage">
                    <svg class="ail-ring" viewBox="0 0 160 160" fill="none" aria-hidden="true">
                        <circle cx="80" cy="80" r="74" stroke="currentColor" stroke-width="3" />
                        <circle cx="80" cy="80" r="74" stroke="url(#ail-gradient)" stroke-width="3.5" stroke-linecap="round" stroke-dasharray="140 320" />
                        <defs>
                            <linearGradient id="ail-gradient" x1="0" y1="0" x2="160" y2="160" gradientUnits="userSpaceOnUse">
                                <stop stop-color="#1B669D" />
                                <stop offset="0.5" stop-color="#38bdf8" />
                                <stop offset="1" stop-color="#1C9976" />
                            </linearGradient>
                        </defs>
                    </svg>
                    <svg class="ail-dashes" viewBox="0 0 140 140" fill="none" aria-hidden="true">
                        <circle cx="70" cy="70" r="64" stroke="rgba(28, 153, 118, 0.4)" stroke-width="1.5" stroke-dasharray="6 10" />
                    </svg>
                    <div class="ail-tile">
                        <img src="/assets/logos/logo.png" alt="">
                    </div>
                </div>
                <div class="ail-badge"><i></i><span>{{ $loaderPortal }}</span></div>
                <h2 lang="ar">الديوان الوطني لحقوق المؤلف والحقوق المجاورة</h2>
                <p class="ail-sub" lang="fr">Office National des Droits d'Auteur et des Droits Voisins</p>
                <div class="ail-progress">
                    <div class="ail-track"><div class="ail-fill"></div></div>
                    <div class="ail-status"><i></i><span>{{ $loaderStatus }}</span></div>
                </div>
            </div>
            <div class="ail-foot">
                <span>الجمهورية الجزائرية الديمقراطية الشعبية</span>
                <span>•</span>
                <span>ONDA Algérie</span>
            </div>
        </div>
        <x-inertia::app />
    </body>
</html>
