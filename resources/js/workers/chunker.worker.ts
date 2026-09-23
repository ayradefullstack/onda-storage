/**
 * Off-main-thread chunk prep: reads a sliced `Blob` into an `ArrayBuffer`
 * and computes its CRC32 — hashing 8 MiB on the UI thread visibly freezes
 * the browser. The result is posted back with the `ArrayBuffer` transferred
 * (not copied).
 *
 * Typed via a minimal local interface rather than the ambient
 * `DedicatedWorkerGlobalScope` — the project's tsconfig only includes the
 * "DOM" lib (shared with every other file `vue-tsc` checks), and DOM's
 * `self: Window` conflicts with the WebWorker lib's `self` type if both are
 * merged. `Blob`/`ArrayBuffer`/`MessageEvent` are already DOM-lib types, so
 * nothing here actually needs the WebWorker lib to type-check correctly.
 *
 * CRC32 is duplicated here from `@/lib/crc32` (rather than imported)
 * deliberately: this file must have zero runtime (value) module imports —
 * a type-only import is fine, since the compiler erases it completely and
 * it never appears in the transformed output any environment actually
 * serves. In local dev, the app's origin (e.g. `onda-storage.test`) and
 * the Vite dev server's origin (`http://[::1]:5173`) differ, and the
 * browser refuses to *construct* a Worker from a cross-origin script URL
 * even with CORS headers present — unlike a plain `<script type=module>`
 * import, which tolerates it. Vite's `?worker&inline` suffix promises a
 * same-origin blob-based worker in both dev and build, but in dev it
 * still constructs the Worker directly against the dev-server URL
 * (confirmed via the actual `SecurityError` this caused: "Failed to
 * construct 'Worker': Script ... cannot be accessed from origin ..."), so
 * `stores/uploads.ts` instead fetches this file's dev-transformed text
 * itself and constructs the Worker from a `Blob` it creates — which *is*
 * same-origin, since a blob: URL takes the origin of the document that
 * created it. That only works if the fetched text has no further runtime
 * imports to resolve; production still uses the real (working)
 * `?worker&inline` bundle, where CRC32 is inlined by Rollup regardless.
 */
import type { WorkerResponse, WorkerSliceRequest } from '@/types/upload';

let crcTable: Uint32Array | null = null;

function getCrcTable(): Uint32Array {
    if (crcTable) {
        return crcTable;
    }

    const generated = new Uint32Array(256);

    for (let n = 0; n < 256; n++) {
        let c = n;

        for (let k = 0; k < 8; k++) {
            c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        }

        generated[n] = c >>> 0;
    }

    crcTable = generated;

    return generated;
}

function crc32Hex(buffer: ArrayBuffer): string {
    const bytes = new Uint8Array(buffer);
    const table = getCrcTable();
    let crc = 0xffffffff;

    for (let i = 0; i < bytes.length; i++) {
        crc = (table[(crc ^ bytes[i]) & 0xff] as number) ^ (crc >>> 8);
    }

    return ((crc ^ 0xffffffff) >>> 0).toString(16).padStart(8, '0');
}

interface MinimalWorkerScope {
    postMessage(message: WorkerResponse, transfer: Transferable[]): void;
    addEventListener(
        type: 'message',
        listener: (event: MessageEvent<WorkerSliceRequest>) => void,
    ): void;
}

const scope = self as unknown as MinimalWorkerScope;

scope.addEventListener('message', (event) => {
    void handleSliceRequest(event.data);
});

async function handleSliceRequest(request: WorkerSliceRequest): Promise<void> {
    try {
        const buffer = await request.blob.arrayBuffer();
        const crc32 = crc32Hex(buffer);

        scope.postMessage(
            {
                type: 'sliced',
                fileId: request.fileId,
                index: request.index,
                buffer,
                crc32,
            },
            [buffer],
        );
    } catch (error) {
        scope.postMessage(
            {
                type: 'error',
                fileId: request.fileId,
                index: request.index,
                message:
                    error instanceof Error
                        ? error.message
                        : 'Unknown worker error.',
            },
            [],
        );
    }
}
