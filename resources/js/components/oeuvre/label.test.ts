import { describe, expect, it } from 'vitest';
import { formatDate } from '@/lib/format';
import { oeuvreLabel } from './label';

const base = {
    uuid: '01a0a670-bfb1-72d6-b1cc-1c4b198c3e89',
    created_at: '2026-09-16T08:30:00.000000Z',
};

describe('oeuvreLabel', () => {
    it('uses the title when one is set', () => {
        expect(
            oeuvreLabel(
                {
                    ...base,
                    title: 'Aurès Symphony',
                    college_name: 'oeuvres musicales',
                },
                'fr',
                'Untitled',
            ),
        ).toBe('Aurès Symphony');
    });

    it('falls back to the collège and the creation date', () => {
        expect(
            oeuvreLabel(
                { ...base, title: null, college_name: 'oeuvres musicales' },
                'fr',
                'Untitled',
            ),
        ).toBe(`oeuvres musicales — ${formatDate(base.created_at, 'fr')}`);
    });

    it('treats a blank title as missing', () => {
        expect(
            oeuvreLabel(
                { ...base, title: '   ', college_name: 'Logiciel' },
                'en',
                'Untitled',
            ),
        ).toBe(`Logiciel — ${formatDate(base.created_at, 'en')}`);
    });

    it('names an unclassified, untitled oeuvre by its uuid prefix', () => {
        expect(
            oeuvreLabel(
                { ...base, title: null, college_name: null },
                'fr',
                'Œuvre sans titre',
            ),
        ).toBe('Œuvre sans titre · 01a0a670');
    });
});
