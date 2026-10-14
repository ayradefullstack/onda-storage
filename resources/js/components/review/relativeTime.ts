const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 365 * 24 * 3600],
    ['month', 30 * 24 * 3600],
    ['day', 24 * 3600],
    ['hour', 3600],
    ['minute', 60],
];

/**
 * "il y a 3 heures" / "منذ 3 ساعات" / "3 hours ago", with Latin digits like
 * every other date in the app. The exact date belongs in a tooltip next to
 * it — this is only for reading down a history at a glance.
 */
export function relativeTime(
    isoString: string,
    locale: string,
    now: number = Date.now(),
): string {
    const time = new Date(isoString).getTime();

    if (Number.isNaN(time)) {
        return isoString;
    }

    const seconds = Math.round((time - now) / 1000);
    const format = new Intl.RelativeTimeFormat(locale, {
        numeric: 'auto',
        numberingSystem: 'latn',
    } as Intl.RelativeTimeFormatOptions);

    for (const [unit, size] of UNITS) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.trunc(seconds / size), unit);
        }
    }

    return format.format(0, 'minute');
}

/** The exact moment, for the tooltip: date and time, Latin digits. */
export function exactDateTime(isoString: string, locale: string): string {
    const date = new Date(isoString);

    if (Number.isNaN(date.getTime())) {
        return isoString;
    }

    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'long',
        timeStyle: 'short',
        numberingSystem: 'latn',
    })
        .format(date)
        .replace(/[‎‏؜]/g, '');
}
