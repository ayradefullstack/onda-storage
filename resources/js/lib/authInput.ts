/**
 * Pure helpers behind the Login / Register inputs. No DOM, no Vue: every rule
 * here is unit-tested (authInput.test.ts) and mirrors what the backend
 * already enforces — they only give the user an earlier, localised message.
 */

const ARABIC_INDIC = /[٠-٩]/g;
const EXTENDED_ARABIC_INDIC = /[۰-۹]/g;
const ARABIC_LETTERS = /[؀-ۿ]/;

/** Same pattern as `CreateNewUser` (`first_name_ar` / `last_name_ar`). */
export const ARABIC_NAME = /^[؀-ۿ\s]+$/u;

/** ٠-٩ and ۰-۹ → 0-9, one-for-one so string length never changes. */
export function normalizeDigits(value: string): string {
    return value
        .replace(ARABIC_INDIC, (c) => String(c.charCodeAt(0) - 0x660))
        .replace(EXTENDED_ARABIC_INDIC, (c) => String(c.charCodeAt(0) - 0x6f0));
}

/** An Arabic-script character typed into a field that expects Latin input. */
export function hasArabicLetters(value: string): boolean {
    return ARABIC_LETTERS.test(value);
}

export type CaretEdit = { value: string; caret: number };

/**
 * Keeps digits and single spaces only, converts Arabic-Indic digits, caps the
 * digit count, and moves the caret so it stays after the same digit it was
 * after before the edit (a plain `value = …` would throw it to the end).
 */
export function sanitizePhone(
    raw: string,
    caret: number,
    maxDigits: number,
): CaretEdit {
    const source = normalizeDigits(raw);
    let value = '';
    let digits = 0;
    let nextCaret = 0;

    for (let i = 0; i < source.length; i++) {
        const char = source[i];
        const keep = /\d/.test(char)
            ? digits < maxDigits
            : /\s/.test(char) && value !== '' && !value.endsWith(' ');

        if (keep) {
            value += /\s/.test(char) ? ' ' : char;
            digits += /\d/.test(char) ? 1 : 0;
        }

        if (i + 1 === caret) {
            nextCaret = value.length;
        }
    }

    if (caret === 0) {
        nextCaret = 0;
    } else if (caret >= source.length) {
        nextCaret = value.length;
    }

    return { value, caret: nextCaret };
}

/** `0512345678` → `05 12 34 56 78`; anything else is returned untouched. */
export function groupPhone(value: string): string {
    const digits = normalizeDigits(value).replace(/\D/g, '');

    return /^0\d{9}$/.test(digits)
        ? digits.replace(/(\d{2})(?=\d)/g, '$1 ')
        : value;
}

/** 0 (very weak) … 4 (strong). Advisory only: the server decides. */
export function passwordStrength(value: string): number {
    if (value === '') {
        return 0;
    }

    let score = 0;

    if (value.length >= 8) {
        score++;
    }

    if (value.length >= 12) {
        score++;
    }

    if (/[a-z]/.test(value) && /[A-Z]/.test(value)) {
        score++;
    }

    if (/\d/.test(value) && /[^A-Za-z0-9]/.test(value)) {
        score++;
    }

    return score;
}

/**
 * Minimum length from the `passwordRules` string the server already sends
 * (`Password::defaults()->toPasswordRulesString()`, e.g.
 * `minlength: 12; required: lower, upper, digit, special;`). Reading it keeps
 * the client message in step with the real rule in every environment.
 */
export function minLengthFromRules(rules?: string): number | null {
    const match = rules?.match(/minlength:\s*(\d+)/i);

    return match ? Number(match[1]) : null;
}

/**
 * Splits a message into plain and Latin parts: `[[Caps Lock]]` marks a Latin
 * fragment that must be wrapped in <bdi> inside an Arabic sentence. Done by
 * the component, so no message is ever rendered as HTML.
 */
export function splitBidi(message: string): { text: string; latin: boolean }[] {
    return message
        .split(/(\[\[[^\]]*\]\])/)
        .filter((part) => part !== '')
        .map((part) =>
            part.startsWith('[[')
                ? { text: part.slice(2, -2), latin: true }
                : { text: part, latin: false },
        );
}
