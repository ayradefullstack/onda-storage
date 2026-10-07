import { describe, expect, it } from 'vitest';
import { createLimiter, createScheduler } from './scheduler';
import type { ChunkJob, ChunkSource } from './scheduler';

/**
 * A fake transport: every job is a promise the test settles by hand, so
 * "latency" is whatever order and timing the test chooses.
 */
function harness(limits = { maxInFlight: 4, perFileInFlight: 2 }) {
    const started: string[] = [];
    const running = new Map<string, number>();
    let runningTotal = 0;
    let peakTotal = 0;
    const peakPerFile = new Map<string, number>();
    const settlers: { id: string; settle: () => void }[] = [];

    const scheduler = createScheduler(() => limits);

    function addFile(id: string, chunks: number): ChunkSource {
        let next = 0;
        const source: ChunkSource = {
            claim(): ChunkJob | null {
                if (next >= chunks) {
                    return null;
                }

                next++;

                return () =>
                    new Promise<void>((resolve) => {
                        started.push(id);
                        running.set(id, (running.get(id) ?? 0) + 1);
                        runningTotal++;
                        peakTotal = Math.max(peakTotal, runningTotal);
                        peakPerFile.set(
                            id,
                            Math.max(
                                peakPerFile.get(id) ?? 0,
                                running.get(id)!,
                            ),
                        );
                        settlers.push({
                            id,
                            settle: () => {
                                running.set(id, running.get(id)! - 1);
                                runningTotal--;
                                resolve();
                            },
                        });
                    });
            },
        };
        scheduler.register(id, source);

        return source;
    }

    /** Settles the oldest running job of `id` and lets the pool refill. */
    async function finishOne(id: string): Promise<void> {
        const at = settlers.findIndex((s) => s.id === id);
        settlers.splice(at, 1)[0]!.settle();
        await Promise.resolve();
        await Promise.resolve();
    }

    async function finishOldest(): Promise<string> {
        const first = settlers[0]!;
        await finishOne(first.id);

        return first.id;
    }

    return {
        scheduler,
        addFile,
        started,
        finishOne,
        finishOldest,
        peakTotal: () => peakTotal,
        peakFor: (id: string) => peakPerFile.get(id) ?? 0,
        runningTotal: () => runningTotal,
    };
}

describe('upload scheduler', () => {
    it('never exceeds maxInFlight globally or perFileInFlight per file', async () => {
        const h = harness({ maxInFlight: 4, perFileInFlight: 2 });
        h.addFile('big', 50);
        h.addFile('a', 5);
        h.addFile('b', 5);

        for (let i = 0; i < 40; i++) {
            expect(h.runningTotal()).toBeLessThanOrEqual(4);
            await h.finishOldest();
        }

        expect(h.peakTotal()).toBe(4);
        expect(h.peakFor('big')).toBe(2);
        expect(h.peakFor('a')).toBeLessThanOrEqual(2);
    });

    it('rotates across active files instead of draining the one with most chunks', async () => {
        const h = harness({ maxInFlight: 1, perFileInFlight: 1 });
        h.addFile('big', 20);
        h.addFile('a', 3);
        h.addFile('b', 3);

        for (let i = 0; i < 8; i++) {
            await h.finishOldest();
        }

        // 1 slot: after the first job, every window of three consecutive
        // dispatches contains all three files — a strict rotation.
        for (let at = 1; at + 3 <= h.started.length; at++) {
            expect(new Set(h.started.slice(at, at + 3)).size).toBe(3);
        }
    });

    it('lets small files finish while the large file keeps progressing', async () => {
        const h = harness({ maxInFlight: 2, perFileInFlight: 1 });
        h.addFile('video', 100);
        h.addFile('pdf', 2);
        h.addFile('docx', 2);

        let videoDone = 0;
        let pdfDone = 0;
        let docxDone = 0;

        for (let i = 0; i < 12; i++) {
            const id = await h.finishOldest();

            if (id === 'video') {
                videoDone++;
            }

            if (id === 'pdf') {
                pdfDone++;
            }

            if (id === 'docx') {
                docxDone++;
            }
        }

        expect(pdfDone).toBe(2);
        expect(docxDone).toBe(2);
        expect(videoDone).toBeGreaterThan(2); // progressed the whole time, not parked
        expect(h.started.filter((s) => s === 'video').length).toBeLessThan(100);
    });

    it('starts a file added mid-upload within one scheduling turn', async () => {
        const h = harness({ maxInFlight: 2, perFileInFlight: 2 });
        h.addFile('big', 50);
        expect(h.runningTotal()).toBe(2);

        h.addFile('late', 2);
        // Pool is full, so `late` waits for the next freed slot — and gets it
        // before `big`'s next chunk, not after big drains.
        await h.finishOne('big');

        expect(h.started.at(-1)).toBe('late');
    });

    it('a file that leaves the rotation does not stall the others', async () => {
        const h = harness({ maxInFlight: 2, perFileInFlight: 1 });
        h.addFile('doomed', 10);
        h.addFile('ok', 3);

        h.scheduler.unregister('doomed');
        await h.finishOne('doomed');
        await h.finishOne('ok');

        expect(h.started.filter((s) => s === 'ok').length).toBeGreaterThan(1);
        expect(h.scheduler.isRegistered('doomed')).toBe(false);
    });

    it('a job that throws does not corrupt the pool accounting', async () => {
        const scheduler = createScheduler(() => ({
            maxInFlight: 1,
            perFileInFlight: 1,
        }));
        let handed = 0;
        scheduler.register('x', {
            claim: () =>
                handed++ < 3 ? () => Promise.reject(new Error('boom')) : null,
        });

        await new Promise((r) => setTimeout(r, 10));

        expect(handed).toBeGreaterThanOrEqual(3);
        expect(scheduler.inFlight()).toBe(0);
    });
});

describe('init limiter', () => {
    it('runs inits in parallel, capped at 3', async () => {
        const run = createLimiter(3);
        let active = 0;
        let peak = 0;
        const gates: (() => void)[] = [];

        const tasks = Array.from({ length: 6 }, () =>
            run(async () => {
                active++;
                peak = Math.max(peak, active);
                await new Promise<void>((resolve) => gates.push(resolve));
                active--;
            }),
        );

        await Promise.resolve();
        expect(active).toBe(3);

        while (gates.length > 0) {
            gates.shift()!();
            await new Promise((r) => setTimeout(r, 0));
        }

        await Promise.all(tasks);
        expect(peak).toBe(3);
    });
});

describe('init priority', () => {
    it('starts an init at once even when every chunk slot is busy, and never lets it exceed the reserve', async () => {
        const h = harness({ maxInFlight: 2, perFileInFlight: 2 });
        h.addFile('big', 50);
        expect(h.runningTotal()).toBe(2);

        const order: string[] = [];
        const gates: (() => void)[] = [];
        const init = (name: string) =>
            h.scheduler.runPriority(
                () =>
                    new Promise<void>((resolve) => {
                        order.push(name);
                        gates.push(resolve);
                    }),
            );

        void init('init-1');
        void init('init-2');
        await Promise.resolve();

        // Pool full of chunks: one init rides the reserved slot, the next waits.
        expect(order).toEqual(['init-1']);
        expect(h.scheduler.inFlight()).toBe(3);
    });

    it('a waiting init is dispatched before the next chunk when a slot frees', async () => {
        const h = harness({ maxInFlight: 2, perFileInFlight: 2 });
        h.addFile('big', 50);

        const events: string[] = [];
        const gates: (() => void)[] = [];
        const init = (name: string) =>
            h.scheduler.runPriority(
                () =>
                    new Promise<void>((resolve) => {
                        events.push(name);
                        gates.push(resolve);
                    }),
            );

        void init('init-1'); // reserved slot
        void init('init-2'); // waits: pool full + reserve used
        await Promise.resolve();
        const chunksBefore = h.started.length;

        // A chunk finishes: the freed slot goes to the init, not the next chunk.
        await h.finishOne('big');

        expect(events).toEqual(['init-1', 'init-2']);
        expect(h.started.length).toBe(chunksBefore);

        // Once the inits are done the chunks flow again.
        gates.forEach((release) => release());
        await new Promise((r) => setTimeout(r, 0));
        expect(h.started.length).toBeGreaterThan(chunksBefore);
    });

    it('runs up to 3 inits in parallel when the pool is idle', async () => {
        const scheduler = createScheduler(() => ({
            maxInFlight: 3,
            perFileInFlight: 2,
        }));
        let active = 0;
        let peak = 0;
        const gates: (() => void)[] = [];

        const tasks = Array.from({ length: 6 }, () =>
            scheduler.runPriority(async () => {
                active++;
                peak = Math.max(peak, active);
                await new Promise<void>((resolve) => gates.push(resolve));
                active--;
            }),
        );

        await Promise.resolve();
        expect(active).toBe(3);

        while (gates.length > 0) {
            gates.shift()!();
            await new Promise((r) => setTimeout(r, 0));
        }

        await Promise.all(tasks);
        expect(peak).toBe(3);
    });

    it('settles with the task result, and a rejecting init does not corrupt the pool', async () => {
        const scheduler = createScheduler(() => ({
            maxInFlight: 1,
            perFileInFlight: 1,
        }));

        await expect(scheduler.runPriority(async () => 42)).resolves.toBe(42);
        await expect(
            scheduler.runPriority(() => Promise.reject(new Error('boom'))),
        ).rejects.toThrow('boom');
        expect(scheduler.inFlight()).toBe(0);
    });
});
