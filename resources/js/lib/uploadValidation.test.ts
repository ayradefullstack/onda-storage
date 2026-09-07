import { describe, expect, it } from 'vitest';
import { extensionOf, validateFile } from './uploadValidation';

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
