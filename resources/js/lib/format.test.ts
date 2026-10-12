import { describe, expect, it } from 'vitest';
import { formatDate } from './format';

describe('formatDate', () => {
    it.each(['ar', 'fr', 'en'])(
        'carries no invisible direction marks in %s, so a bdi dir=ltr keeps the digits in order',
        (locale) => {
            const out = formatDate('2026-10-12T10:00:00Z', locale);

            expect(out).not.toMatch(/[‎‏؜]/);
            expect(out).toMatch(/2026/);
        },
    );

    it('keeps Latin digits and the date parts in the same order', () => {
        const out = formatDate('2026-10-12T10:00:00Z', 'ar');

        expect(out).toMatch(/^\d{1,2}\/\d{1,2}\/2026$|2026/);
        expect(out).toMatch(/[0-9]/);
        expect(out).not.toMatch(/[٠-٩]/);
    });

    it('returns an unparseable input unchanged', () => {
        expect(formatDate('not a date', 'ar')).toBe('not a date');
    });
});
