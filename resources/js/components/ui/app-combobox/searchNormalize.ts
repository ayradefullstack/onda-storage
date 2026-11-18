/**
 * Fold a string for type-to-filter matching in Arabic and French: case,
 * Latin accents, Arabic diacritics and tatweel, and the letter variants
 * people type interchangeably (hamza forms of alif, ta marbuta / ha, alif
 * maqsura / ya).
 */
export function normalizeSearch(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[ً-ٰٟـ]/g, '')
        .replace(/[أإآٱ]/g, 'ا')
        .replace(/ة/g, 'ه')
        .replace(/ى/g, 'ي')
        .replace(/\s+/g, ' ')
        .trim();
}

/** True when every word of the query appears in the haystack, in any order. */
export function matchesSearch(haystack: string, query: string): boolean {
    const needle = normalizeSearch(query);

    if (needle === '') {
        return true;
    }

    const folded = normalizeSearch(haystack);

    return needle.split(' ').every((word) => folded.includes(word));
}
