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

export function allowedExtensionList(): string[] {
    return Object.keys(ALLOWED_EXTENSIONS);
}
