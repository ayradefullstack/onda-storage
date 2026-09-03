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
