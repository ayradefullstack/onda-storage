import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import { arabicPluralRule, pluralRules } from './i18nPlural';

const defaultRule = (choice: number, length: number): number =>
    length === 2 ? (choice === 1 ? 0 : 1) : Math.min(Math.abs(choice), 2);

describe('arabicPluralRule with six forms (CLDR order)', () => {
    it.each([
        [0, 0],
        [1, 1],
        [2, 2],
        [3, 3],
        [10, 3],
        [11, 4],
        [99, 4],
        [100, 5],
        [101, 5],
        [102, 5],
        [103, 3],
        [111, 4],
        [200, 5],
        [1000, 5],
    ])('n=%i -> index %i', (n, index) => {
        expect(arabicPluralRule(n, 6, defaultRule)).toBe(index);
    });
});

describe('arabicPluralRule with other form counts', () => {
    it.each([
        [1, 2],
        [2, 2],
        [0, 3],
        [1, 3],
        [5, 3],
    ])('delegates n=%i (%i forms) to the default rule', (n, length) => {
        expect(arabicPluralRule(n, length, defaultRule)).toBe(
            defaultRule(n, length),
        );
    });

    it('still answers without a default rule', () => {
        expect(arabicPluralRule(1, 2)).toBe(0);
        expect(arabicPluralRule(5, 2)).toBe(1);
    });
});

describe('wiring into vue-i18n', () => {
    const i18n = createI18n({
        legacy: false,
        locale: 'ar',
        pluralRules,
        messages: {
            ar: {
                six: 'z | one | two | few | many | other',
                two: 'singular | plural',
            },
            fr: { two: 'singulier | pluriel' },
        },
    });
    const t = i18n.global.t;

    it('reads a six-form Arabic message by CLDR category', () => {
        expect(t('six', {}, 1)).toBe('one');
        expect(t('six', {}, 2)).toBe('two');
        expect(t('six', {}, 3)).toBe('few');
        expect(t('six', {}, 11)).toBe('many');
        expect(t('six', {}, 100)).toBe('other');
    });

    it('leaves a two-form Arabic message and other locales unchanged', () => {
        expect(t('two', {}, 1)).toBe('singular');
        expect(t('two', {}, 7)).toBe('plural');
        expect(t('two', {}, { locale: 'fr', plural: 7 })).toBe('pluriel');
    });
});
