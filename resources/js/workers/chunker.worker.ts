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
 */
import { crc32Hex } from '@/lib/crc32';
import type { WorkerResponse, WorkerSliceRequest } from '@/types/upload';

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
