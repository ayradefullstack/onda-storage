/// <reference types="node" />

import { randomBytes, randomUUID } from 'node:crypto';
import {
    closeSync,
    mkdtempSync,
    openSync,
    readFileSync,
    rmSync,
    writeFileSync,
    writeSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import type { Locator, Page, Response } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * A second upload must not be rejected while a large one is running.
 *
 * Regression: `throttle:10,1` on init and `throttle:1200,1` on chunk shared
 * ONE per-user counter, so a running upload pushed it past the init's
 * ceiling of 10 within seconds and every other file's init got a 429.
 *
 * Needs a local seeded database (the demo author and a draft oeuvre with its
 * required-document slots), a running queue worker and a current
 * `npm run build`. Configuration, all optional:
 *
 *   E2E_BASE_URL     site under test            (default http://onda-storage.test)
 *   E2E_EMAIL        demo author                (default author1@onda.dz)
 *   E2E_PASSWORD     its password               (default: read from the seeder)
 *   E2E_OEUVRE_UUID  a draft oeuvre to upload into (default: the author's first)
 *   E2E_LARGE_MB     size of the large file     (default 300)
 *   E2E_UPLOAD_MBPS  emulated upload bandwidth  (default 3)
 *   E2E_START_PCT    how far the large file gets before the small ones start (default 30)
 */
const here = path.dirname(fileURLToPath(import.meta.url));
const EMAIL = process.env.E2E_EMAIL ?? 'author1@onda.dz';
const LARGE_MB = Number(process.env.E2E_LARGE_MB ?? 300);
const UPLOAD_BPS = Number(process.env.E2E_UPLOAD_MBPS ?? 3) * 1024 * 1024;
const START_PCT = Number(process.env.E2E_START_PCT ?? 30);
const CHUNK_BYTES = 8 * 1024 * 1024; // the protocol's chunk size

// Unique per run: the page lists every earlier deposit, and a name shared
// with a previous run's card would be matched instead of this run's.
const RUN = randomUUID().slice(0, 8);
const LARGE = `e2e-${RUN}-large.mp4`;
const OTHER = `e2e-${RUN}-other-slot.pdf`;
const SAME = `e2e-${RUN}-same-slot.pdf`;

/** The seeded demo password lives in the seeder, not in this file. */
function seededPassword(): string {
    if (process.env.E2E_PASSWORD) {
        return process.env.E2E_PASSWORD;
    }

    const seeder = readFileSync(
        path.join(here, '../../database/seeders/RoleAndUserSeeder.php'),
        'utf8',
    );
    const match = seeder.match(/author1@onda\.dz[\s\S]*?bcrypt\('([^']+)'/);

    if (!match?.[1]) {
        throw new Error('Set E2E_PASSWORD: the seeded password was not found.');
    }

    return match[1];
}

function pdf(label: string): Buffer {
    return Buffer.from(
        `%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n% ${label} ${randomUUID()}\ntrailer<</Root 1 0 R>>\n%%EOF\n`,
    );
}

interface Recorded {
    method: string;
    path: string;
    status: number;
}

test.describe('concurrent uploads', () => {
    let dir = '';
    let largePath = '';
    let otherSlotPath = '';
    let sameSlotPath = '';

    // Created here, not while the file is merely being loaded: the temp
    // folder (about 300 MB) exists only for the run that uses it.
    test.beforeAll(() => {
        dir = mkdtempSync(path.join(tmpdir(), 'onda-e2e-'));
        largePath = path.join(dir, LARGE);
        otherSlotPath = path.join(dir, OTHER);
        sameSlotPath = path.join(dir, SAME);

        // A real MP4 header (so the content check accepts it) followed by
        // random padding up to the requested size.
        const head = readFileSync(path.join(here, '../fixtures/sample.mp4'));
        const fd = openSync(largePath, 'w');
        writeSync(fd, head);
        let written = head.length;
        const target = LARGE_MB * 1024 * 1024;

        while (written < target) {
            const piece = randomBytes(Math.min(1024 * 1024, target - written));
            writeSync(fd, piece);
            written += piece.length;
        }

        closeSync(fd);
        writeFileSync(otherSlotPath, pdf('other'));
        writeFileSync(sameSlotPath, pdf('same'));
    });

    test.afterAll(() => {
        if (dir !== '') {
            rmSync(dir, { recursive: true, force: true });
        }
    });

    test('small files in other and same slots are deposited while a large file uploads', async ({
        page,
        context,
        baseURL,
    }) => {
        const uploads: Recorded[] = [];
        // upload session uuid -> file name, and file name -> when its
        // `/complete` was accepted (201): the server-side "deposited".
        const fileOfSession = new Map<string, string>();
        const completedAt = new Map<string, number>();
        let largeCompleteStatus: number | null = null;
        let largeChunksDone = 0;

        page.on('response', async (response: Response) => {
            const url = new URL(response.url());

            if (!/^\/uploads(\/|$)/.test(url.pathname)) {
                return;
            }

            const method = response.request().method();
            uploads.push({
                method,
                path: url.pathname.replace(/[0-9a-f-]{36}/, ':uuid'),
                status: response.status(),
            });

            if (
                method === 'POST' &&
                url.pathname === '/uploads' &&
                response.status() === 201
            ) {
                const { filename } = response.request().postDataJSON() as {
                    filename: string;
                };
                fileOfSession.set(
                    ((await response.json()) as { uuid: string }).uuid,
                    filename,
                );
            }

            const chunk = url.pathname.match(
                /^\/uploads\/([0-9a-f-]{36})\/chunk\/\d+$/,
            );

            if (
                chunk &&
                response.status() === 200 &&
                fileOfSession.get(chunk[1] as string) === LARGE
            ) {
                largeChunksDone++;
            }

            const complete = url.pathname.match(
                /^\/uploads\/([0-9a-f-]{36})\/complete$/,
            );
            const name = complete
                ? fileOfSession.get(complete[1] as string)
                : undefined;

            if (name) {
                if (response.status() === 201) {
                    completedAt.set(name, Date.now());
                }

                if (name === LARGE) {
                    largeCompleteStatus = response.status();
                }
            }
        });

        // English strings, so the assertions below read the same everywhere.
        await context.addCookies([
            { name: 'locale', value: 'en', url: baseURL as string },
        ]);

        // The real login form, as the seeded demo author.
        await page.goto('/login');
        await page.fill('input[name=email]', EMAIL);
        await page.fill('input[name=password]', seededPassword());
        await page.click('[data-test=login-button]');
        await page.waitForURL((url) => !url.pathname.startsWith('/login'));

        await openOeuvre(page);

        // Find the slots by what they accept: the video slot takes .mov,
        // the other one (png/jpg/pdf) does not.
        const inputs = page.locator('input[type=file]');
        await expect(inputs.first()).toBeAttached();
        let videoSlot: Locator | null = null;
        let otherSlot: Locator | null = null;

        for (let i = 0; i < (await inputs.count()); i++) {
            const accept = (await inputs.nth(i).getAttribute('accept')) ?? '';

            if (!videoSlot && accept.includes('.mov')) {
                videoSlot = inputs.nth(i);
            } else if (
                !otherSlot &&
                accept.includes('.png') &&
                !accept.includes('.mov')
            ) {
                otherSlot = inputs.nth(i);
            }
        }

        expect(videoSlot, 'a multi-file video slot').not.toBeNull();
        expect(otherSlot, 'a second slot that takes PDFs').not.toBeNull();

        // Throttle the upload so the large file lasts long enough.
        const cdp = await context.newCDPSession(page);
        await cdp.send('Network.enable');
        await cdp.send('Network.emulateNetworkConditions', {
            offline: false,
            latency: 0,
            downloadThroughput: -1,
            uploadThroughput: UPLOAD_BPS,
        });

        const entry = (name: string) =>
            page.locator('li', { hasText: name }).last();

        await videoSlot!.setInputFiles(largePath);

        // "Past START_PCT" is counted from the chunk responses the server
        // acknowledged (instant), not scraped from the card, which lags.
        const totalChunks = Math.ceil((LARGE_MB * 1024 * 1024) / CHUNK_BYTES);
        await expect
            .poll(() => (largeChunksDone / totalChunks) * 100, {
                timeout: 3 * 60_000,
                intervals: [200],
            })
            .toBeGreaterThanOrEqual(START_PCT);

        // A PDF in a different slot, and a PDF in the SAME multi-file slot.
        // Chosen together: while a 300 MB body is being handed to fetch() the
        // page's main thread answers browser-automation calls slowly, so one
        // after the other would let the large file win by sheer delay.
        await Promise.all([
            otherSlot!.setInputFiles(otherSlotPath),
            videoSlot!.setInputFiles(sameSlotPath),
        ]);

        // Both small files are accepted by the server (their `/complete`
        // returns 201) while the large file is still sending chunks.
        await expect
            .poll(() => completedAt.has(OTHER) && completedAt.has(SAME), {
                timeout: 90_000,
                intervals: [250],
            })
            .toBe(true);
        expect(
            completedAt.has(LARGE),
            'the large file must still be uploading',
        ).toBe(false);
        await expect(entry(LARGE)).not.toContainText(/Deposited/);

        // The large file then completes.
        await expect
            .poll(() => largeCompleteStatus, {
                timeout: 8 * 60_000,
                intervals: [1000],
            })
            .toBe(201);

        // Its completion refreshes the page, and by then the two small files
        // are through the processing chain: all of them read "Deposited".
        await expect(entry(OTHER)).toContainText(/Deposited/, {
            timeout: 90_000,
        });
        await expect(entry(SAME)).toContainText(/Deposited/, {
            timeout: 90_000,
        });

        // Nothing failed on the way, except pushback that recovered by
        // itself: every file above ended deposited, so a 429/503 here was
        // retried and succeeded.
        const unexpected = uploads.filter(
            (r) => r.status >= 400 && r.status !== 429 && r.status !== 503,
        );
        expect(unexpected).toEqual([]);

        // The client retries a 429 on its own, which would hide the original
        // defect: chunk traffic must not push an init into the limiter at all.
        const inits = uploads.filter(
            (r) => r.method === 'POST' && r.path === '/uploads',
        );
        expect(inits.length).toBeGreaterThanOrEqual(3);
        expect(inits.map((r) => r.status)).toEqual(inits.map(() => 201));

        const chunks = uploads.filter((r) => r.path.includes('/chunk/'));
        expect(chunks.length).toBeGreaterThanOrEqual(LARGE_MB / 8);
    });
});

async function openOeuvre(page: Page): Promise<void> {
    const wanted = process.env.E2E_OEUVRE_UUID;

    if (wanted) {
        await page.goto(`/author/oeuvres/${wanted}`);

        return;
    }

    // No oeuvre given: take the first of the author's own that accepts files
    // (a draft). The list renders after hydration, so wait for its links.
    await page.goto('/author/oeuvres');
    const uuid = /\/author\/oeuvres\/([0-9a-f]{8}-[0-9a-f-]{27})(?:[/?#]|$)/;
    const hrefs = async (): Promise<string[]> =>
        (
            await page
                .locator('a')
                .evaluateAll((links) =>
                    links.map((a) => a.getAttribute('href') ?? ''),
                )
        )
            .map((href) => uuid.exec(href)?.[1])
            .filter((id): id is string => id !== undefined);

    await expect
        .poll(async () => (await hrefs()).length, { timeout: 15_000 })
        .toBeGreaterThan(0);

    for (const id of new Set(await hrefs())) {
        await page.goto(`/author/oeuvres/${id}`);

        const hasInputs = await page
            .locator('input[type=file]')
            .first()
            .waitFor({ state: 'attached', timeout: 5_000 })
            .then(() => true)
            .catch(() => false);

        if (hasInputs) {
            return;
        }
    }

    throw new Error(
        'No oeuvre that accepts files was found for the demo author: set E2E_OEUVRE_UUID.',
    );
}
