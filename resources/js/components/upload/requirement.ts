/**
 * A required-document slot as the oeuvre page receives it
 * (`OeuvreController::requirementSlots()`), and the small pure helpers its
 * card and dropzone share.
 */
import { MAX_FILE_SIZE_BYTES } from '@/lib/uploadValidation';
import type { UploadRequirement } from '@/types/upload';

export interface RequirementSlot {
    id: number;
    document_key: string;
    /** In the request locale, falling back to the French title. */
    title: string;
    extensions: string[];
    is_required: boolean;
    max_size_kb: number | null;
    allows_multiple: boolean;
    /**
     * The source system's raw show_when / hide_when rule. Not evaluated: the
     * declaration fields it tests do not exist yet. Non-null only means the
     * document may not apply to this work.
     */
    conditions: Record<string, unknown> | null;
}

export function toUploadRequirement(slot: RequirementSlot): UploadRequirement {
    return {
        id: slot.id,
        extensions: slot.extensions,
        maxSizeBytes:
            slot.max_size_kb === null ? null : slot.max_size_kb * 1024,
    };
}

/** "PDF, JPG, PNG" — how the formats are written for people. */
export function formatExtensions(extensions: readonly string[]): string {
    return extensions.map((extension) => extension.toUpperCase()).join(', ');
}

/** `accept=".pdf,.jpg"` — a hint to the picker; the server still decides. */
export function acceptAttribute(extensions: readonly string[]): string {
    return extensions
        .map((extension) => `.${extension.toLowerCase()}`)
        .join(',');
}

export function sizeLimitBytes(slot: RequirementSlot): number {
    return slot.max_size_kb === null
        ? MAX_FILE_SIZE_BYTES
        : Math.min(slot.max_size_kb * 1024, MAX_FILE_SIZE_BYTES);
}

/** The raw condition, readable in a tooltip — shown verbatim, never parsed. */
export function describeConditions(
    conditions: Record<string, unknown>,
): string {
    return Object.entries(conditions)
        .map(([key, value]) => `${key}: ${JSON.stringify(value)}`)
        .join('\n');
}
