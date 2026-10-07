/**
 * CRC-32 (the "crc32b" / ISO-3309 / zlib variant) — the exact algorithm
 * `App\Actions\Upload\StoreChunk` verifies the `X-Chunk-CRC32` header
 * against via PHP's `hash('crc32b', $bytes)`. No library: this is a
 * ~15-line table-based implementation, not worth a dependency.
 *
 * `workers/chunker.worker.ts` duplicates this rather than importing it —
 * that file must have zero module imports so it can be loaded cross-origin
 * in dev via `fetch` + `Blob` (see the comment there). This copy exists so
 * the algorithm stays unit-testable without instantiating a real Worker.
 */

let table: Uint32Array | null = null;

function crcTable(): Uint32Array {
    if (table) {
        return table;
    }

    const generated = new Uint32Array(256);

    for (let n = 0; n < 256; n++) {
        let c = n;

        for (let k = 0; k < 8; k++) {
            c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        }

        generated[n] = c >>> 0;
    }

    table = generated;

    return generated;
}

export function crc32(buffer: ArrayBuffer): number {
    const bytes = new Uint8Array(buffer);
    const t = crcTable();
    let crc = 0xffffffff;

    for (let i = 0; i < bytes.length; i++) {
        crc = (t[(crc ^ bytes[i]) & 0xff] as number) ^ (crc >>> 8);
    }

    return (crc ^ 0xffffffff) >>> 0;
}

/** Lowercase 8-hex-digit form, matching PHP's `hash('crc32b', ...)` output. */
export function crc32Hex(buffer: ArrayBuffer): string {
    return crc32(buffer).toString(16).padStart(8, '0');
}
