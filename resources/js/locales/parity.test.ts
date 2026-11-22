import { describe, expect, it } from 'vitest';
import arJson from './ar.json';
import enJson from './en.json';
import frJson from './fr.json';

type Bundle = Record<string, string>;

const ar: Bundle = arJson;
const en: Bundle = enJson;
const fr: Bundle = frJson;
const bundles: Record<string, Bundle> = { ar, en, fr };
const codes = Object.keys(bundles);

/** Distinct placeholders `{name}` (plural forms repeat them), sorted. */
function placeholders(message: string): string[] {
    return [
        ...new Set(
            [...message.matchAll(/\{\s*([\w.]+)\s*\}/g)].map((m) => m[1]),
        ),
    ].sort();
}

/** Number of pipe-separated plural forms. */
function forms(message: string): number {
    return message.split('|').length;
}

describe('locale bundle parity (ar / fr / en)', () => {
    it('has the same key set in every locale', () => {
        const all = new Set(codes.flatMap((c) => Object.keys(bundles[c])));
        const missing: string[] = [];

        for (const code of codes) {
            for (const key of all) {
                if (!(key in bundles[code])) {
                    missing.push(`${code}: ${key}`);
                }
            }
        }

        expect(missing).toEqual([]);
    });

    it('has no empty values', () => {
        const empty: string[] = [];

        for (const code of codes) {
            for (const [key, value] of Object.entries(bundles[code])) {
                if (typeof value !== 'string' || value.trim() === '') {
                    empty.push(`${code}: ${key}`);
                }
            }
        }

        expect(empty).toEqual([]);
    });

    it('uses the same placeholders in every locale', () => {
        const diverging: string[] = [];

        for (const key of Object.keys(en)) {
            const reference = placeholders(en[key]).join(',');

            for (const code of ['ar', 'fr']) {
                const value = bundles[code][key];

                if (
                    typeof value === 'string' &&
                    placeholders(value).join(',') !== reference
                ) {
                    diverging.push(
                        `${code}: ${key} -> {${placeholders(value)}} vs en {${reference}}`,
                    );
                }
            }
        }

        expect(diverging).toEqual([]);
    });

    it('never writes the oe ligature', () => {
        const found = codes.flatMap((c) =>
            Object.entries(bundles[c])
                .filter(([, v]) => /[œŒ]/.test(v))
                .map(([k]) => `${c}: ${k}`),
        );

        expect(found).toEqual([]);
    });

    it('keeps plural keys consistent: fr/en forms match, ar has the six CLDR forms', () => {
        const bad: string[] = [];

        for (const key of Object.keys(en)) {
            if (!en[key].includes('|') && !fr[key]?.includes('|')) {
                continue;
            }

            if (forms(en[key]) !== forms(fr[key] ?? '')) {
                bad.push(`fr/en forms differ: ${key}`);
            }

            if (forms(ar[key] ?? '') !== 6) {
                bad.push(
                    `ar needs 6 plural forms, has ${forms(ar[key] ?? '')}: ${key}`,
                );
            }
        }

        expect(bad).toEqual([]);
    });

    it('defines every literal key the source asks for', () => {
        const sources = import.meta.glob<string>(
            ['../**/*.vue', '../**/*.ts', '!../**/*.test.ts', '!../locales/**'],
            { query: '?raw', import: 'default', eager: true },
        );
        const pattern =
            /(?:\bt|\$t|\bte|\btc)\(\s*'([a-z][A-Za-z]*(?:\.[A-Za-z0-9_]+)+)'|keypath="([^"]+)"/g;
        const missing = new Set<string>();

        for (const [file, source] of Object.entries(sources)) {
            for (const match of source.matchAll(pattern)) {
                const key = match[1] ?? match[2];

                if (!(key in en)) {
                    missing.add(`${key}  (${file})`);
                }
            }
        }

        expect([...missing]).toEqual([]);
    });
});
