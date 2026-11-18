/**
 * The DOCX is rendered into an iframe the parent writes into. Nothing inside
 * can execute: no `allow-scripts` (so even a payload that survived rendering
 * is inert), and a CSP that forbids every network fetch.
 */
export const DOCX_IFRAME_SANDBOX = 'allow-same-origin';

export const DOCX_IFRAME_CSP =
    "default-src 'none'; img-src blob: data:; style-src 'unsafe-inline'; font-src blob: data:";

export const DOCX_IFRAME_SRCDOC = `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="${DOCX_IFRAME_CSP}"><style>html,body{margin:0;background:#e5e7eb}a{pointer-events:none;color:inherit;text-decoration:none;cursor:default}</style></head><body></body></html>`;

/** Hyperlinks become plain text: no href, no target, no click. */
export function makeLinksInert(doc: Document): void {
    for (const link of Array.from(doc.querySelectorAll('a'))) {
        link.removeAttribute('href');
        link.removeAttribute('target');
        link.setAttribute('tabindex', '-1');
    }

    doc.addEventListener('click', (event) => event.preventDefault(), true);
}
