import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
    DOCX_IFRAME_CSP,
    DOCX_IFRAME_SANDBOX,
    DOCX_IFRAME_SRCDOC,
} from './docxFrame';

const consultDir = join(__dirname, '..');

/** Comments may mention what is absent; only code counts. */
function code(text: string): string {
    return text
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/^\s*\/\/.*$/gm, '');
}

function sources(dir: string): { file: string; text: string }[] {
    return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const path = join(dir, entry.name);

        if (entry.isDirectory()) {
            return sources(path);
        }

        return /\.(vue|ts)$/.test(entry.name) &&
            !entry.name.endsWith('.test.ts')
            ? [{ file: path, text: code(readFileSync(path, 'utf8')) }]
            : [];
    });
}

describe('the docx iframe', () => {
    it('is sandboxed WITHOUT allow-scripts', () => {
        expect(DOCX_IFRAME_SANDBOX).toBe('allow-same-origin');
        expect(DOCX_IFRAME_SANDBOX).not.toContain('allow-scripts');
    });

    it('is bound to that constant in the viewer, with no other sandbox value', () => {
        const viewer = code(
            readFileSync(join(consultDir, 'viewers', 'DocxViewer.vue'), 'utf8'),
        );

        expect(viewer).toContain(':sandbox="DOCX_IFRAME_SANDBOX"');
        expect(viewer).not.toContain('allow-scripts');
        expect(viewer).toContain('<iframe');
    });

    it('carries a CSP that forbids every external resource', () => {
        expect(DOCX_IFRAME_CSP).toContain("default-src 'none'");
        expect(DOCX_IFRAME_CSP).toContain('img-src blob: data:');
        expect(DOCX_IFRAME_CSP).toContain("style-src 'unsafe-inline'");
        expect(DOCX_IFRAME_SRCDOC).toContain(
            'http-equiv="Content-Security-Policy"',
        );
        expect(DOCX_IFRAME_SRCDOC).not.toContain('<script');
    });
});

describe('the viewers', () => {
    it('never use v-html, innerHTML or document.write', () => {
        for (const { file, text } of sources(consultDir)) {
            expect(text, file).not.toMatch(
                /v-html|innerHTML|outerHTML|document\.write|insertAdjacentHTML/,
            );
        }
    });

    it('never offer a download, a print or the stock pdf.js viewer UI', () => {
        const pdf = code(
            readFileSync(join(consultDir, 'viewers', 'PdfViewer.vue'), 'utf8'),
        );

        expect(pdf).not.toMatch(
            /download|window\.print|pdf_viewer|PDFViewer\b/i,
        );
        expect(pdf).toContain('isBlockedShortcut');
    });
});
