/// <reference types="node" />

import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Playwright globalTeardown: the suite really uploads files into the dev
 * vault, so after the run `php artisan e2e:cleanup --apply` purges exactly the
 * deposits it created (named `e2e-*`, uploaded by the demo author) and frees
 * their bytes through the same guarded release path the app uses. Set
 * E2E_SKIP_CLEANUP=1 to keep them for inspection.
 */
export default async function globalTeardown(): Promise<void> {
    if (process.env.E2E_SKIP_CLEANUP) {
        return;
    }

    const root = path.join(
        path.dirname(fileURLToPath(import.meta.url)),
        '../..',
    );
    const args = ['artisan', 'e2e:cleanup', '--apply'];

    if (process.env.E2E_EMAIL) {
        args.push(`--email=${process.env.E2E_EMAIL}`);
    }

    try {
        const output = execFileSync('php', args, {
            cwd: root,
            encoding: 'utf8',
        });
        console.log(output.trim());
    } catch (error) {
        // Never fail the run because cleanup did: report and carry on.
        console.warn('e2e cleanup failed:', (error as Error).message);
    }
}
