import { fileURLToPath } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

/**
 * Separate from vite.config.ts deliberately — the app's own Vite config
 * wires up Inertia/Wayfinder/Laravel plugins that assume a running PHP
 * backend and aren't needed to unit-test plain TypeScript modules under
 * resources/js. Only the `@` alias (see tsconfig.json) is mirrored here.
 */
export default defineConfig({
    // Only so a .vue page can be server-rendered in a test; no DOM env needed.
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
    },
});
