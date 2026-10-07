import { formatDate } from '@/lib/format';

export interface LabelSource {
    uuid: string;
    title: string | null;
    college_name?: string | null;
    created_at: string;
}

/**
 * What to call an oeuvre wherever a title is shown. The create page no
 * longer asks for one, so a new oeuvre is known by its classification until a
 * later step names it:
 *
 *   title                         when set
 *   "{collège} — {creation date}" otherwise
 *   "{untitled} · {uuid prefix}"  for a row with neither
 */
export function oeuvreLabel(
    oeuvre: LabelSource,
    locale: string,
    untitled: string,
): string {
    const title = oeuvre.title?.trim();

    if (title) {
        return title;
    }

    const college = oeuvre.college_name?.trim();

    if (college) {
        return `${college} — ${formatDate(oeuvre.created_at, locale)}`;
    }

    return `${untitled} · ${oeuvre.uuid.slice(0, 8)}`;
}
