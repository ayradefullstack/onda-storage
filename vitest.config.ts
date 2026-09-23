import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

/**
 * Separate from vite.config.ts deliberately — the app's own Vite config
 * wires up Inertia/Wayfinder/Laravel plugins that assume a running PHP
 * backend and aren't needed to unit-test plain TypeScript modules under
 * resources/js. Only the `@` alias (see tsconfig.json) is mirrored here.
 */
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
    },
});
