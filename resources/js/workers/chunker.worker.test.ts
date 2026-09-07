import { describe, expect, it } from 'vitest';
import source from './chunker.worker.ts?raw';

/**
 * Not a test of Worker *behavior* — a real cross-origin dev-server Worker
 * construction failure (the actual root cause of the chunk-zero incident)
 * is a browser-level restriction Node has no concept of; jsdom/happy-dom
 * don't enforce it either, so a Node-based test exercising a real Worker
 * here would prove nothing and could pass while the real bug regresses.
 *
 * What *is* both real and checkable without a browser: `stores/uploads.ts`
 * only works around that restriction by fetching this file's dev-server
 * text and constructing a `Worker` from a same-origin `Blob` of it — which
 * only resolves correctly if this file has zero module imports (see the
 * comment at the top of the file itself, and in `createChunkerWorker`).
 * This guards that exact invariant so a reintroduced import fails a test
 * instead of silently breaking every upload in local dev again.
 */
describe('chunker.worker.ts', () => {
    it('has no runtime (value) module imports', () => {
        // `import type` is fully erased by the compiler — it produces no
        // runtime import in the transformed output Vite actually serves,
        // so it doesn't break the fetch+blob loading this guards. Only a
        // value import would.
        const runtimeImportLines = source
            .split('\n')
            .filter(
                (line: string) =>
                    /^\s*import\b/.test(line) &&
                    !/^\s*import\s+type\b/.test(line),
            );

        expect(runtimeImportLines).toEqual([]);
    });
});
