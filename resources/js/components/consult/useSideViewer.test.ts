import { describe, expect, it } from 'vitest';
import type { ConsultationDescriptor } from '@/types/consultation';
import { useSideViewer } from './useSideViewer';

function descriptorFor(name: string): ConsultationDescriptor {
    return {
        family: 'text',
        status: 'ready',
        reason: null,
        notice: null,
        assets: [],
        pageCount: null,
        watermark: null,
        meta: {
            filename: name,
            sizeBytes: 1,
            mime: 'text/plain',
            sha256: null,
            depositedAt: null,
            slotLabel: null,
        },
        actions: { download: false },
    };
}

function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((r) => {
        resolve = r;
    });

    return { promise, resolve };
}

describe('useSideViewer', () => {
    it('selects the first file by default and loads it', async () => {
        const viewer = useSideViewer('a', async (uuid) => descriptorFor(uuid));

        expect(viewer.selectedUuid.value).toBe('a');

        await viewer.refresh();

        expect(viewer.descriptor.value?.meta.filename).toBe('a');
    });

    it('shows the file that was selected, not the one that answered last', async () => {
        const slow = deferred<ConsultationDescriptor>();
        const loads: Record<string, Promise<ConsultationDescriptor>> = {
            a: slow.promise,
            b: Promise.resolve(descriptorFor('b')),
        };
        const viewer = useSideViewer('a', (uuid) => loads[uuid]);

        const first = viewer.refresh();
        viewer.select('b');
        await Promise.resolve();
        await Promise.resolve();

        expect(viewer.selectedUuid.value).toBe('b');
        expect(viewer.descriptor.value?.meta.filename).toBe('b');

        // The slow reply for the previously selected file arrives late.
        slow.resolve(descriptorFor('a'));
        await first;

        expect(viewer.selectedUuid.value).toBe('b');
        expect(viewer.descriptor.value?.meta.filename).toBe('b');
    });

    it('clears the previous preview the moment another file is selected', () => {
        const viewer = useSideViewer('a', () => new Promise(() => undefined));
        viewer.descriptor.value = descriptorFor('a');

        viewer.select('b');

        expect(viewer.descriptor.value).toBeNull();
    });

    it('ignores a failed request and keeps what it had', async () => {
        const viewer = useSideViewer('a', async () => null);
        viewer.descriptor.value = descriptorFor('a');

        await viewer.refresh();

        expect(viewer.descriptor.value?.meta.filename).toBe('a');
    });
});
