import { describe, expect, it } from 'vitest';
import {
    ARABIC_NAME,
    groupPhone,
    hasArabicLetters,
    minLengthFromRules,
    normalizeDigits,
    passwordStrength,
    sanitizePhone,
    splitBidi,
} from './authInput';

describe('normalizeDigits', () => {
    it('converts Arabic-Indic and Persian digits one for one', () => {
        expect(normalizeDigits('٠٥٤٩٠٢')).toBe('054902');
        expect(normalizeDigits('۰۵۴۹')).toBe('0549');
        expect(normalizeDigits('05 ٤٩ ab')).toBe('05 49 ab');
    });
});

describe('hasArabicLetters', () => {
    it('detects an Arabic keyboard layout in a Latin field', () => {
        expect(hasArabicLetters('محمد@mail.com')).toBe(true);
        expect(hasArabicLetters('name@mail.com')).toBe(false);
    });
});

describe('ARABIC_NAME', () => {
    it('matches the backend rule: Arabic letters and spaces only', () => {
        expect(ARABIC_NAME.test('محمد')).toBe(true);
        expect(ARABIC_NAME.test('بن علي')).toBe(true);
        expect(ARABIC_NAME.test('Mohamed')).toBe(false);
        expect(ARABIC_NAME.test('محمد1')).toBe(false);
    });
});

describe('sanitizePhone', () => {
    it('normalises digits and keeps the caret after the same digit', () => {
        // user typed an Arabic-Indic 5 after "0": caret sits after it
        expect(sanitizePhone('0٥', 2, 10)).toEqual({ value: '05', caret: 2 });
    });

    it('keeps the caret in the middle of the value', () => {
        // editing the 2nd digit of "0512": caret after index 2
        expect(sanitizePhone('0٥12', 2, 10)).toEqual({
            value: '0512',
            caret: 2,
        });
    });

    it('drops letters and shifts the caret back accordingly', () => {
        expect(sanitizePhone('05a12', 3, 10)).toEqual({
            value: '0512',
            caret: 2,
        });
    });

    it('caps the digit count', () => {
        expect(sanitizePhone('0512345678999', 13, 10).value).toBe('0512345678');
    });

    it('collapses repeated and leading spaces', () => {
        expect(sanitizePhone('  05  12', 8, 10).value).toBe('05 12');
    });
});

describe('groupPhone', () => {
    it('groups a 10-digit national number by two', () => {
        expect(groupPhone('0512345678')).toBe('05 12 34 56 78');
        expect(groupPhone('٠٥١٢٣٤٥٦٧٨')).toBe('05 12 34 56 78');
    });

    it('leaves anything else alone', () => {
        expect(groupPhone('12345')).toBe('12345');
        expect(groupPhone('+33 6 12 34 56 78')).toBe('+33 6 12 34 56 78');
    });
});

describe('passwordStrength', () => {
    it('is 0 when empty and grows with length and variety', () => {
        expect(passwordStrength('')).toBe(0);
        expect(passwordStrength('abc')).toBe(0);
        expect(passwordStrength('abcdefgh')).toBe(1);
        expect(passwordStrength('abcdefghijkl')).toBe(2);
        expect(passwordStrength('Abcdefghijkl')).toBe(3);
        expect(passwordStrength('Abcdefghijk1!')).toBe(4);
    });
});

describe('minLengthFromRules', () => {
    it('reads the server-provided rules string', () => {
        expect(minLengthFromRules('minlength: 8;')).toBe(8);
        expect(
            minLengthFromRules(
                'minlength: 12; required: lower, upper, digit, special;',
            ),
        ).toBe(12);
        expect(minLengthFromRules(undefined)).toBeNull();
        expect(minLengthFromRules('required: lower;')).toBeNull();
    });
});

describe('splitBidi', () => {
    it('marks Latin fragments for <bdi>', () => {
        expect(splitBidi('زر [[Caps Lock]] مفعّل')).toEqual([
            { text: 'زر ', latin: false },
            { text: 'Caps Lock', latin: true },
            { text: ' مفعّل', latin: false },
        ]);
        expect(splitBidi('plain')).toEqual([{ text: 'plain', latin: false }]);
    });
});
