/**
 * Locale-aware number formatting for the upload UI. Digits stay Latin
 * across all three locales (matching `i18n.ts`'s `numberFormats` override
 * for Arabic) — mixing numbering systems inside a byte/speed/ETA readout
 * would be harder to read, not more localized.
 */
const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'] as const;

export function formatBytes(bytes: number, locale: string): string {
    if (!Number.isFinite(bytes) || bytes <= 0) {
        return `${new Intl.NumberFormat(locale, { numberingSystem: 'latn' }).format(0)} B`;
    }

    const power = Math.min(
        UNITS.length - 1,
        Math.floor(Math.log(bytes) / Math.log(1024)),
    );
    const value = bytes / 1024 ** power;
    const formatted = new Intl.NumberFormat(locale, {
        numberingSystem: 'latn',
        maximumFractionDigits: power === 0 ? 0 : 1,
    }).format(value);

    return `${formatted} ${UNITS[power]}`;
}

export function formatSpeed(bytesPerSecond: number, locale: string): string {
    return `${formatBytes(bytesPerSecond, locale)}/s`;
}

/** Locale month/weekday names, Latin digits — same digit policy as the rest of this file. */
export function formatDate(isoString: string, locale: string): string {
    const date = new Date(isoString);

    if (Number.isNaN(date.getTime())) {
        return isoString;
    }

    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        numberingSystem: 'latn',
    }).format(date);
}

export function formatDuration(totalSeconds: number | null): string {
    if (
        totalSeconds === null ||
        !Number.isFinite(totalSeconds) ||
        totalSeconds < 0
    ) {
        return '--:--';
    }

    const seconds = Math.round(totalSeconds);
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remainingSeconds = seconds % 60;
    const pad = (n: number) => String(n).padStart(2, '0');

    return hours > 0
        ? `${hours}:${pad(minutes)}:${pad(remainingSeconds)}`
        : `${minutes}:${pad(remainingSeconds)}`;
}

/**
 * Truncates from the middle of the basename, keeping the extension intact —
 * an RTL paragraph reordering a name truncated from the end can otherwise
 * hide or misplace the extension. `maxLength` counts characters, not bytes.
 */
export function truncateFilenameMiddle(
    filename: string,
    maxLength = 40,
): string {
    if (filename.length <= maxLength) {
        return filename;
    }

    const dot = filename.lastIndexOf('.');
    const hasExtension =
        dot > 0 && dot < filename.length - 1 && filename.length - dot <= 12;
    const extension = hasExtension ? filename.slice(dot) : '';
    const base = hasExtension ? filename.slice(0, dot) : filename;

    const keep = maxLength - extension.length - 1; // 1 for the ellipsis

    if (keep <= 2) {
        return `…${extension}`;
    }

    const headLength = Math.ceil(keep / 2);
    const tailLength = Math.floor(keep / 2);

    return `${base.slice(0, headLength)}…${base.slice(base.length - tailLength)}${extension}`;
}
