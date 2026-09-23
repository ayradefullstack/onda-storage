import { describe, expect, it } from 'vitest';
import {
    extensionOf,
    MAX_FILE_SIZE_BYTES,
    validateFile,
    validateFileForRequirement,
} from './uploadValidation';

function makeFile(name: string): File {
    return new File([new Uint8Array(1)], name);
}

/**
 * Named as an explicit suspect during diagnosis of the silent-upload
 * incident (Windows and iOS both routinely produce uppercase extensions,
 * e.g. `IMG_0967.MOV`). It turned out not to be the cause — this whitelist
 * check already lowercases — but it wasn't proven with a test before, so
 * it's locked in now rather than left as an assumption.
 */
describe('extensionOf', () => {
    it.each(['MOV', 'Mov', 'mov'])('lowercases a %s extension', (extension) => {
        expect(extensionOf(`IMG_0967.${extension}`)).toBe('mov');
    });
});

describe('validateFile', () => {
    it.each(['IMG_0967.MOV', 'clip.MP4', 'Manuscript.PDF', 'song.Mp3'])(
        'accepts %s despite its uppercase extension',
        (filename) => {
            expect(validateFile(makeFile(filename)).ok).toBe(true);
        },
    );

    it('rejects an extension not on the whitelist regardless of case', () => {
        const result = validateFile(makeFile('archive.ZIP'));

        expect(result).toEqual({
            ok: false,
            reason: 'extension',
            extension: 'zip',
        });
    });
});

describe('validateFileForRequirement', () => {
    const slot = {
        id: 1,
        extensions: ['pdf', 'jpg', 'jpeg', 'png'],
        maxSizeBytes: null,
    };

    it.each(['scan.PDF', 'IMG_0967.JPG', 'photo.Jpeg', 'x.png'])(
        'accepts %s against the slot list regardless of case',
        (filename) => {
            expect(
                validateFileForRequirement(makeFile(filename), slot).ok,
            ).toBe(true);
        },
    );

    it('rejects an extension the global whitelist allows but the slot does not', () => {
        expect(validateFileForRequirement(makeFile('clip.mp4'), slot)).toEqual({
            ok: false,
            reason: 'extension',
            extension: 'mp4',
        });
    });

    it('accepts an extension the slot allows but the global whitelist does not', () => {
        expect(validateFile(makeFile('photo.jpg')).ok).toBe(false);
        expect(validateFileForRequirement(makeFile('photo.jpg'), slot).ok).toBe(
            true,
        );
    });

    it('applies the slot size cap, and the global ceiling when there is none', () => {
        const big = new File([new Uint8Array(4)], 'toile.png');

        expect(
            validateFileForRequirement(big, { ...slot, maxSizeBytes: 3 }),
        ).toEqual({ ok: false, reason: 'size', extension: 'png' });
        expect(validateFileForRequirement(big, slot).ok).toBe(true);
        expect(
            validateFileForRequirement(big, {
                ...slot,
                maxSizeBytes: MAX_FILE_SIZE_BYTES * 2,
            }).ok,
        ).toBe(true);
    });
});
