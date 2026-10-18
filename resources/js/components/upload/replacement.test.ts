// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    createRemovalFlow,
    focusReplacementDropzone,
    openFor,
    removeRejectedFile,
    replacementDropzoneId,
    toReplaceCount,
} from './replacement';
import type { RemovalFlowDeps } from './replacement';

/**
 * The author's side of per-file review, outside the components: what the
 * server's replacement state means, how a removal's answer is told apart,
 * and the page's "Remplacer" / "Supprimer" flow.
 */
function answer(status: number, body: unknown): void {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            ok: status >= 200 && status < 300,
            status,
            json: async () => body,
        })),
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('replacement state', () => {
    it('a slot takes what the server opened, nothing otherwise', () => {
        const state = {
            open: { '7': 2 },
            uploading: 0,
            rejected: 1,
            pending: 0,
        };

        expect(openFor(state, 7)).toBe(2);
        expect(openFor(state, 8)).toBe(0);
        // PHP serialises an empty map as a list.
        expect(openFor({ ...state, open: [] }, 7)).toBe(0);
        expect(openFor(undefined, 7)).toBe(0);
    });

    it('counts rejected files plus removed ones still to replace', () => {
        expect(
            toReplaceCount({
                open: { '7': 2 },
                uploading: 1,
                rejected: 1,
                pending: 3,
            }),
        ).toBe(3);
        expect(
            toReplaceCount({ open: [], uploading: 0, rejected: 0, pending: 1 }),
        ).toBe(0);
    });
});

describe('removeRejectedFile', () => {
    it('200 is removed', async () => {
        answer(200, { uuid: 'f1', removed: true });

        expect(await removeRejectedFile('/x')).toEqual({ kind: 'removed' });
    });

    it('sends a JSON DELETE with the XSRF token', async () => {
        document.cookie = 'XSRF-TOKEN=tok%3D';
        answer(200, {});
        await removeRejectedFile('/author/oeuvres/files/f1');

        const [url, init] = (fetch as unknown as ReturnType<typeof vi.fn>).mock
            .calls[0]!;

        expect(url).toBe('/author/oeuvres/files/f1');
        expect(init.method).toBe('DELETE');
        expect(init.headers.Accept).toBe('application/json');
        expect(init.headers['X-XSRF-TOKEN']).toBe('tok=');
    });

    it('409 file_no_longer_rejected and 409 oeuvre_decided are told apart', async () => {
        answer(409, { error: 'file_no_longer_rejected' });
        expect(await removeRejectedFile('/x')).toEqual({
            kind: 'noLongerRejected',
        });

        answer(409, { error: 'oeuvre_decided' });
        expect(await removeRejectedFile('/x')).toEqual({ kind: 'decided' });
    });

    it('422 carries the server message', async () => {
        answer(422, {
            message: 'x',
            errors: { media_file: ['Encore en traitement.'] },
        });

        expect(await removeRejectedFile('/x')).toEqual({
            kind: 'invalid',
            message: 'Encore en traitement.',
        });
    });

    it('a network error is failed, never a throw', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => Promise.reject(new Error('down'))),
        );

        expect(await removeRejectedFile('/x')).toEqual({ kind: 'failed' });
    });
});

describe('the Remplacer / Supprimer flow', () => {
    function flow(overrides: Partial<RemovalFlowDeps> = {}) {
        const deps = {
            url: (uuid: string) => `/files/${uuid}`,
            slotOf: () => 7,
            reload: vi.fn((_only: string[] | undefined, after: () => void) =>
                after(),
            ),
            reloadProps: ['mediaFiles', 'replacement'],
            focusSlot: vi.fn(),
            toast: { success: vi.fn(), info: vi.fn(), error: vi.fn() },
            t: (key: string) => key,
            ...overrides,
        };

        return { deps, flow: createRemovalFlow(deps) };
    }

    it('"Remplacer" removes, silently reloads the file props, then focuses the slot dropzone', async () => {
        answer(200, { removed: true });
        const { deps, flow: removal } = flow();

        await removal.remove('f1', true);

        expect(deps.reload).toHaveBeenCalledWith(
            ['mediaFiles', 'replacement'],
            expect.any(Function),
        );
        expect(deps.focusSlot).toHaveBeenCalledWith(7);
        expect(deps.toast.success).toHaveBeenCalledWith(
            'oeuvres.replacement.toast.removedReplace',
        );
        expect(removal.busy()).toBeNull();
    });

    it('"Supprimer" removes and leaves the slot open, without moving the focus', async () => {
        answer(200, { removed: true });
        const { deps, flow: removal } = flow();

        await removal.remove('f1', false);

        expect(deps.reload).toHaveBeenCalledWith(
            ['mediaFiles', 'replacement'],
            expect.any(Function),
        );
        expect(deps.focusSlot).not.toHaveBeenCalled();
        expect(deps.toast.success).toHaveBeenCalledWith(
            'oeuvres.replacement.toast.removed',
        );
    });

    it('409 no longer rejected: says so and refreshes the file props', async () => {
        answer(409, { error: 'file_no_longer_rejected' });
        const { deps, flow: removal } = flow();

        await removal.remove('f1', true);

        expect(deps.toast.info).toHaveBeenCalledWith(
            'oeuvres.replacement.toast.noLongerRejected',
        );
        expect(deps.reload).toHaveBeenCalledWith(
            ['mediaFiles', 'replacement'],
            expect.any(Function),
        );
        expect(deps.focusSlot).not.toHaveBeenCalled();
    });

    it('409 decided: "Le dépôt a été traité", then the whole page reloads', async () => {
        answer(409, { error: 'oeuvre_decided' });
        const { deps, flow: removal } = flow();

        await removal.remove('f1', false);

        expect(deps.toast.info).toHaveBeenCalledWith(
            'oeuvres.replacement.toast.decided',
        );
        expect(deps.reload).toHaveBeenCalledWith(
            undefined,
            expect.any(Function),
        );
    });

    it('422: the server message, word for word', async () => {
        answer(422, {
            errors: {
                media_file: ['Le traitement de ce fichier est en cours.'],
            },
        });
        const { deps, flow: removal } = flow();

        await removal.remove('f1', false);

        expect(deps.toast.error).toHaveBeenCalledWith(
            'Le traitement de ce fichier est en cours.',
        );
    });

    it('one removal at a time', async () => {
        answer(200, {});
        const { deps, flow: removal } = flow({ reload: vi.fn() });
        const busy: (string | null)[] = [];
        removal.onBusyChange((uuid) => busy.push(uuid));

        await removal.remove('f1', false);
        expect(await removal.remove('f2', false)).toBeNull();
        expect(busy).toEqual(['f1']);
        expect(deps.reload).toHaveBeenCalledTimes(1);
    });
});

describe('focusReplacementDropzone', () => {
    it('focuses the dropzone button of that slot', () => {
        document.body.innerHTML = `<div id="${replacementDropzoneId(7)}"><button>Parcourir</button></div>`;

        focusReplacementDropzone(7, true);

        expect(document.activeElement?.textContent).toBe('Parcourir');
    });
});
