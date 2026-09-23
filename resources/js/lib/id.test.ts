import { afterEach, describe, expect, it, vi } from 'vitest';
import { generateClientId } from './id';

const UUID_V4_PATTERN =
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('generateClientId', () => {
    it('uses the native crypto.randomUUID when the browser exposes it', () => {
        const native = '11111111-1111-4111-8111-111111111111';
        vi.stubGlobal('crypto', {
            randomUUID: () => native,
            getRandomValues: crypto.getRandomValues.bind(crypto),
        });

        expect(generateClientId()).toBe(native);
    });

    /**
     * The exact incident this fixes: `crypto.randomUUID` is `undefined`
     * outside a secure context — e.g. plain HTTP against a custom local
     * hostname such as `onda-storage.test`, which resolves to 127.0.0.1
     * but is not literally "localhost", so the browser does not treat it
     * as secure. Before this fix, `enqueueFile()` called
     * `crypto.randomUUID()` unconditionally; that threw a TypeError
     * uncaught, and since it happened *inside* the file-selection handler,
     * the file never reached the store and no error was ever shown —
     * exactly what the recording captured. This test reproduces that
     * global (no `randomUUID` on `crypto`) and fails against the old
     * unconditional call.
     */
    it('falls back to crypto.getRandomValues when randomUUID is unavailable (insecure context)', () => {
        vi.stubGlobal('crypto', {
            getRandomValues: crypto.getRandomValues.bind(crypto),
        });

        let id = '';

        expect(() => {
            id = generateClientId();
        }).not.toThrow();

        expect(id).toMatch(UUID_V4_PATTERN);
    });

    it('produces distinct ids across calls in the fallback path', () => {
        vi.stubGlobal('crypto', {
            getRandomValues: crypto.getRandomValues.bind(crypto),
        });

        expect(generateClientId()).not.toBe(generateClientId());
    });
});
