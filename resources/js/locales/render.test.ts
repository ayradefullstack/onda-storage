import { describe, expect, it } from 'vitest';
import { createAppI18n } from '@/i18n';
import type { Language } from '@/types/language';

const languages = [
    { code: 'fr', direction: 'ltr', is_default: true },
    { code: 'en', direction: 'ltr', is_default: false },
    { code: 'ar', direction: 'rtl', is_default: false },
] as unknown as Language[];

const tIn = (locale: string) => createAppI18n(locale, languages).global.t;

describe('locale rendering', () => {
    it('picks the right Arabic plural form for a six-form message', () => {
        const t = tIn('ar');
        const key = 'review.blockers.pendingFiles';
        const render = (count: number) => t(key, { count }, count);

        expect(render(0)).toBe('لا ملفات قيد المعالجة');
        expect(render(1)).toBe('ملف واحد قيد المعالجة');
        expect(render(2)).toBe('ملفان قيد المعالجة');
        expect(render(5)).toBe('5 ملفات قيد المعالجة');
        expect(render(11)).toBe('11 ملفًا قيد المعالجة');
        expect(render(100)).toBe('100 ملف قيد المعالجة');
    });

    it('picks singular and plural in English and French', () => {
        expect(tIn('en')('review.blockers.pendingFiles', { count: 1 }, 1)).toBe(
            '1 file still processing',
        );
        expect(tIn('en')('review.blockers.pendingFiles', { count: 3 }, 3)).toBe(
            '3 files still processing',
        );
        expect(
            tIn('fr')('review.blockers.pendingFiles', { count: 3 }, 3),
        ).toContain('3');
    });

    it('shows a server validation message verbatim, whatever it contains', () => {
        const message = 'Le fichier {name} | @x est trop volumineux <b>';

        for (const locale of ['ar', 'fr', 'en']) {
            expect(tIn(locale)('upload.error.validation', { message })).toBe(
                message,
            );
        }
    });

    it('keeps the client-side extension rejection per locale', () => {
        for (const locale of ['ar', 'fr', 'en']) {
            const text = tIn(locale)('upload.reject.extension', {
                filename: 'a.exe',
                extensions: 'pdf, mp4',
            });

            expect(text).toContain('a.exe');
            expect(text).toContain('pdf, mp4');
        }
    });

    it('compiles every message of every locale (unescaped @, | or braces break vue-i18n)', () => {
        const failures: string[] = [];

        for (const locale of ['ar', 'fr', 'en']) {
            const i18n = createAppI18n(locale, languages);
            const messages = i18n.global.getLocaleMessage(locale) as Record<
                string,
                string
            >;

            for (const [key, value] of Object.entries(messages)) {
                const named: Record<string, string> = {};

                for (const match of value.matchAll(/\{\s*([\w.]+)\s*\}/g)) {
                    named[match[1]] = 'x';
                }

                try {
                    i18n.global.t(key, named);
                } catch (error) {
                    failures.push(
                        `${locale}: ${key} (${(error as Error).message.split('\n')[0]})`,
                    );
                }
            }
        }

        expect(failures).toEqual([]);
    });
});
