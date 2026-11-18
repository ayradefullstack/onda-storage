import { ref } from 'vue';
import type { ConsultationDescriptor } from '@/types/consultation';

/**
 * Selection state for the side Viewer. The selected uuid is the single source
 * of truth: the highlight in the list and the Viewer both read it, and a
 * reply for a file that is no longer selected is dropped — so a slow response
 * can never put one file's preview under another file's highlight.
 *
 * `load` fetches the descriptor of one file (null on a failed request).
 */
export function useSideViewer(
    firstUuid: string | null,
    load: (uuid: string) => Promise<ConsultationDescriptor | null>,
) {
    const selectedUuid = ref<string | null>(firstUuid);
    const descriptor = ref<ConsultationDescriptor | null>(null);

    async function refresh(): Promise<void> {
        const uuid = selectedUuid.value;

        if (uuid === null) {
            return;
        }

        const loaded = await load(uuid);

        if (loaded !== null && selectedUuid.value === uuid) {
            descriptor.value = loaded;
        }
    }

    function select(uuid: string): void {
        if (uuid === selectedUuid.value) {
            return;
        }

        descriptor.value = null;
        selectedUuid.value = uuid;
        void refresh();
    }

    return { selectedUuid, descriptor, select, refresh };
}
