import type { InjectionKey, Ref } from 'vue';

/**
 * What the oeuvre page offers to every deposit card below it, so a card can
 * open the side Viewer and build its new-tab link without each intermediate
 * component forwarding props.
 */
export interface FilePreviewContext {
    oeuvreUuid: string;
    selectedUuid: Ref<string | null>;
    open: (uuid: string) => void;
}

export const FILE_PREVIEW: InjectionKey<FilePreviewContext> =
    Symbol('file-preview');
