import type { UploadRequirement } from '@/types/upload';

/**
 * Mirrors `App\Actions\Upload\InitUpload::ALLOWED` — client-side validation
 * is a UX courtesy (fail fast, before any request), not the source of
 * truth; the backend re-validates independently.
 */
const ALLOWED_EXTENSIONS: Record<string, readonly string[]> = {
    mp4: ['video/mp4'],
    mov: ['video/quicktime'],
    avi: ['video/x-msvideo', 'video/avi'],
    mkv: ['video/x-matroska'],
    webm: ['video/webm'],
    mp3: ['audio/mpeg', 'audio/mp3'],
    wav: ['audio/wav', 'audio/x-wav', 'audio/wave'],
    flac: ['audio/flac', 'audio/x-flac'],
    aac: ['audio/aac', 'audio/x-aac'],
    ogg: ['audio/ogg'],
    pdf: ['application/pdf'],
    pptx: [
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ],
};

/** CLAUDE.md's hard per-file ceiling (5 GiB). */
export const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024 * 1024;

export function extensionOf(filename: string): string {
    const dot = filename.lastIndexOf('.');

    return dot === -1 ? '' : filename.slice(dot + 1).toLowerCase();
}

export type FileValidationResult =
    | { ok: true }
    | { ok: false; reason: 'extension' | 'size'; extension: string };

export function validateFile(file: File): FileValidationResult {
    const extension = extensionOf(file.name);

    if (!(extension in ALLOWED_EXTENSIONS)) {
        return { ok: false, reason: 'extension', extension };
    }

    if (file.size <= 0 || file.size > MAX_FILE_SIZE_BYTES) {
        return { ok: false, reason: 'size', extension };
    }

    return { ok: true };
}

/**
 * Mirrors InitUpload's per-slot check for a classified oeuvre: the slot's own
 * extension list (compared case-insensitively — `IMG_0967.MOV`), its own
 * size cap where it sets one, and the global ceiling either way.
 */
export function validateFileForRequirement(
    file: File,
    requirement: UploadRequirement,
): FileValidationResult {
    const extension = extensionOf(file.name);
    const accepted = requirement.extensions.map((e) => e.toLowerCase());

    if (!accepted.includes(extension)) {
        return { ok: false, reason: 'extension', extension };
    }

    const limit = Math.min(
        requirement.maxSizeBytes ?? MAX_FILE_SIZE_BYTES,
        MAX_FILE_SIZE_BYTES,
    );

    if (file.size <= 0 || file.size > limit) {
        return { ok: false, reason: 'size', extension };
    }

    return { ok: true };
}

export function allowedExtensionList(): string[] {
    return Object.keys(ALLOWED_EXTENSIONS);
}
