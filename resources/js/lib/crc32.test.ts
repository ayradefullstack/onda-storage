import { describe, expect, it } from 'vitest';
import { crc32, crc32Hex } from './crc32';

describe('crc32', () => {
    /**
     * The standard CRC-32/ISO-HDLC check value for the ASCII string
     * "123456789" is 0xCBF43926 — the same well-known test vector zlib and
     * PHP's `hash('crc32b', ...)` (what `StoreChunk` verifies against) are
     * checked against. A hard-coded external vector, not a value derived
     * from this same implementation, is what actually proves correctness
     * here.
     */
    it('matches the standard CRC-32/ISO-HDLC check value for "123456789"', () => {
        const buffer = new TextEncoder().encode('123456789').buffer;

        expect(crc32(buffer)).toBe(0xcbf43926);
        expect(crc32Hex(buffer)).toBe('cbf43926');
    });

    it('returns the all-ones complement (0) for an empty buffer', () => {
        expect(crc32(new ArrayBuffer(0))).toBe(0);
        expect(crc32Hex(new ArrayBuffer(0))).toBe('00000000');
    });
});
