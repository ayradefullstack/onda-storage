export type PdfMatch = { page: number; index: number };

/** Every case-insensitive occurrence of `query`, in document order. */
export function findMatches(
    pageTexts: readonly string[],
    query: string,
): PdfMatch[] {
    const needle = query.trim().toLowerCase();

    if (needle === '') {
        return [];
    }

    const matches: PdfMatch[] = [];

    pageTexts.forEach((text, pageIndex) => {
        const haystack = text.toLowerCase();
        let from = 0;
        let at = haystack.indexOf(needle, from);

        while (at !== -1) {
            matches.push({ page: pageIndex + 1, index: at });
            from = at + needle.length;
            at = haystack.indexOf(needle, from);
        }
    });

    return matches;
}

/** Wraps around: next/previous over `count` matches. */
export function stepIndex(
    current: number,
    count: number,
    delta: 1 | -1,
): number {
    return count === 0 ? 0 : (current + delta + count) % count;
}

/** Ctrl/Cmd + P or S: the keys a viewer swallows (a deterrent only). */
export function isBlockedShortcut(event: {
    key: string;
    ctrlKey: boolean;
    metaKey: boolean;
}): boolean {
    return (
        (event.ctrlKey || event.metaKey) &&
        ['p', 's'].includes(event.key.toLowerCase())
    );
}
