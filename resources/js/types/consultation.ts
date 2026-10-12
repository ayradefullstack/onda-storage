/**
 * The one description of "what can be shown for this file". Built server-side
 * by `App\Actions\Consultation\ConsultationDescriptor`; the frontend never
 * inspects a filename or extension — it renders whatever `family` says.
 */
export type PreviewFamily =
    | 'pdf'
    | 'document'
    | 'presentation'
    | 'spreadsheet'
    | 'csv'
    | 'image'
    | 'text'
    | 'video'
    | 'audio'
    | 'archive'
    | 'other';

/** How the file reaches the reviewer: see config('vault.consult.delivery'). */
export type DeliveryMode = 'original_inline' | 'derivative' | 'unsupported';

/** Signed, short-lived, session-bound URL to the decrypted original, inline. */
export type ConsultationOriginal = {
    url: string;
    mime: string;
    extension: string;
};

export type ConsultationStatus = 'pending' | 'ready' | 'failed' | 'unsupported';

/** A signed, short-lived, session-bound URL to ONE derivative. */
export type ConsultationAsset = {
    kind: string;
    pageIndex: number | null;
    url: string;
};

export type ConsultationMeta = {
    filename: string;
    sizeBytes: number;
    mime: string;
    sha256: string | null;
    depositedAt: string | null;
    slotLabel: string | null;
};

export type ConsultationDescriptor = {
    family: PreviewFamily;
    mode: DeliveryMode;
    /** Present only for a ready `original_inline` file. */
    original: ConsultationOriginal | null;
    status: ConsultationStatus;
    /** Machine key for failed / unsupported / not-yet-generated. */
    reason: string | null;
    /** Machine key for a ready file that is partial (e.g. pages_truncated). */
    notice: string | null;
    assets: ConsultationAsset[];
    pageCount: number | null;
    /** Label for the CSS watermark overlay; null turns it off. */
    watermark: string | null;
    meta: ConsultationMeta;
    actions: { download: boolean };
};

/** Shape of one sheet in the spreadsheet / csv derivative. */
export type SheetData = {
    sheets: { name: string; rows: string[][]; truncated: boolean }[];
};

/** Shape of the pptx outline derivative (`outline` asset). */
export type PptxOutline = {
    slides: { index: number; texts: string[]; notes: string[] }[];
};
