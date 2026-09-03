/**
 * Component-facing surface over the app-level `stores/uploads.ts` state.
 * Registers the `beforeunload` guard exactly once no matter how many
 * components call this — it's a `window` listener, not something that
 * belongs to any one component's lifecycle.
 */
import { computed } from 'vue';
import { useUploadStore } from '@/stores/uploads';
import type { UploadFileState } from '@/types/upload';

let beforeUnloadRegistered = false;

function registerBeforeUnloadGuard(hasActiveUploads: () => boolean): void {
    if (beforeUnloadRegistered || typeof window === 'undefined') {
        return;
    }

    beforeUnloadRegistered = true;

    window.addEventListener('beforeunload', (event: BeforeUnloadEvent) => {
        if (!hasActiveUploads()) {
            return;
        }

        event.preventDefault();
        // Chrome requires this legacy assignment for the confirmation
        // prompt to appear; the string itself is ignored by modern browsers.
        event.returnValue = '';
    });
}

export interface FileRejection {
    filename: string;
    reason: 'extension' | 'size';
}

export function useUploadQueue() {
    const store = useUploadStore();

    registerBeforeUnloadGuard(() => store.hasActiveUploads.value);

    const pendingResumes = computed<UploadFileState[]>(() =>
        store.files.value.filter((f) => f.needsFileReselect),
    );

    function selectFiles(
        fileList: FileList | File[],
        workId: number,
    ): FileRejection[] {
        const rejections: FileRejection[] = [];

        for (const file of Array.from(fileList)) {
            const result = store.enqueueFile(file, workId);

            if (!result.ok) {
                rejections.push({ filename: file.name, reason: result.reason });
            }
        }

        return rejections;
    }

    return {
        files: store.files,
        hasActiveUploads: store.hasActiveUploads,
        maxConcurrentFiles: store.maxConcurrentFiles,
        pendingResumes,
        selectFiles,
        pauseFile: store.pauseFile,
        resumeFile: store.resumeFile,
        cancelFile: store.cancelFile,
        resumeWithReselectedFile: store.resumeWithReselectedFile,
    };
}
