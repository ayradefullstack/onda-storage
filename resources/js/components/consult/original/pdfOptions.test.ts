import { describe, expect, it } from 'vitest';
import {
    PDF_WORKER_URL,
    RANGE_CHUNK_SIZE,
    pdfDocumentOptions,
} from './pdfOptions';
import { findMatches, isBlockedShortcut, stepIndex } from './pdfSearch';

describe('PDF.js configuration', () => {
    it('disables eval and XFA, and loads progressively over 1 MiB ranges', () => {
        expect(pdfDocumentOptions()).toMatchObject({
            isEvalSupported: false,
            enableXfa: false,
            disableAutoFetch: true,
            rangeChunkSize: 1024 * 1024,
        });
        expect(RANGE_CHUNK_SIZE).toBe(1024 * 1024);
    });

    it('serves the worker from our own build, never a CDN', () => {
        expect(PDF_WORKER_URL).toContain('pdf.worker');
        expect(PDF_WORKER_URL).not.toMatch(/^(https?:)?\/\//);
        expect(PDF_WORKER_URL).not.toMatch(/cdn|unpkg|jsdelivr|cloudflare/i);
    });
});

describe('PDF search', () => {
    it('counts every case-insensitive match in document order', () => {
        const matches = findMatches(
            ['Alpha beta', 'ALPHA alpha', 'none'],
            'alpha',
        );

        expect(matches.map((m) => m.page)).toEqual([1, 2, 2]);
        expect(findMatches(['x'], '  ')).toEqual([]);
    });

    it('wraps next / previous', () => {
        expect(stepIndex(2, 3, 1)).toBe(0);
        expect(stepIndex(0, 3, -1)).toBe(2);
        expect(stepIndex(0, 0, 1)).toBe(0);
    });

    it('swallows only Ctrl/Cmd+P and Ctrl/Cmd+S', () => {
        const key = (k: string, ctrlKey = true, metaKey = false) => ({
            key: k,
            ctrlKey,
            metaKey,
        });

        expect(isBlockedShortcut(key('p'))).toBe(true);
        expect(isBlockedShortcut(key('S'))).toBe(true);
        expect(isBlockedShortcut(key('s', false, true))).toBe(true);
        expect(isBlockedShortcut(key('f'))).toBe(false);
        expect(isBlockedShortcut(key('p', false))).toBe(false);
    });
});
