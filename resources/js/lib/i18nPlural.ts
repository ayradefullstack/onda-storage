import type { PluralizationRule } from 'vue-i18n';

/**
 * Arabic has six CLDR plural categories. A message written with six
 * pipe-separated forms is read in CLDR order: zero | one | two | few | many |
 * other. Any other form count (the 2/3-form Arabic keys that already exist)
 * is left to vue-i18n's default rule, which the library hands us as `orgRule`.
 * Hand-written on purpose: no Intl dependency, same result in every browser.
 */
export const arabicPluralRule: PluralizationRule = (
    choice,
    choicesLength,
    orgRule,
) => {
    if (choicesLength !== 6) {
        return orgRule ? orgRule(choice, choicesLength) : choice === 1 ? 0 : 1;
    }

    const n = Math.abs(choice);
    const mod100 = n % 100;

    if (n === 0) {
        return 0;
    }

    if (n === 1) {
        return 1;
    }

    if (n === 2) {
        return 2;
    }

    if (mod100 >= 3 && mod100 <= 10) {
        return 3;
    }

    if (mod100 >= 11) {
        return 4;
    }

    return 5;
};

export const pluralRules = { ar: arabicPluralRule };
