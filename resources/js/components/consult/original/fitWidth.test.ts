// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { watchFitWidth } from './fitWidth';

class FakeResizeObserver {
    static instances: FakeResizeObserver[] = [];

    disconnected = false;

    constructor(public callback: ResizeObserverCallback) {
        FakeResizeObserver.instances.push(this);
    }

    observe(): void {}

    disconnect(): void {
        this.disconnected = true;
    }

    fire(width: number): void {
        this.callback(
            [{ contentRect: { width, height: 400 } } as ResizeObserverEntry],
            this as unknown as ResizeObserver,
        );
    }
}

/** A viewer container whose width the test controls. */
function container(width: number): {
    element: HTMLElement;
    resize: (w: number) => void;
} {
    const element = document.createElement('div');
    let current = width;

    Object.defineProperty(element, 'offsetWidth', { get: () => current });

    return {
        element,
        resize: (w) => {
            current = w;
            FakeResizeObserver.instances.at(-1)?.fire(w);
        },
    };
}

beforeEach(() => {
    vi.useFakeTimers();
    FakeResizeObserver.instances = [];
    vi.stubGlobal('ResizeObserver', FakeResizeObserver);
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('watchFitWidth (PDF "fit width" on resize)', () => {
    it('gives the usable width at once, then again after the container is resized', () => {
        const { element, resize } = container(848);
        const apply = vi.fn();

        watchFitWidth(element, apply, { padding: 48 });

        expect(apply).toHaveBeenCalledTimes(1);
        expect(apply).toHaveBeenLastCalledWith(800);

        // The observer's own first callback repeats the same width: no-op.
        resize(848);
        expect(apply).toHaveBeenCalledTimes(1);

        resize(1648);
        expect(apply).toHaveBeenCalledTimes(1); // debounced

        vi.advanceTimersByTime(150);
        expect(apply).toHaveBeenCalledTimes(2);
        expect(apply).toHaveBeenLastCalledWith(1600);
    });

    it('re-lays out once for a burst of resize events (window drag)', () => {
        const { element, resize } = container(848);
        const apply = vi.fn();

        watchFitWidth(element, apply, { padding: 48 });
        resize(848);

        for (const width of [900, 1000, 1100, 1248]) {
            resize(width);
            vi.advanceTimersByTime(40);
        }

        vi.advanceTimersByTime(150);

        expect(apply).toHaveBeenCalledTimes(2);
        expect(apply).toHaveBeenLastCalledWith(1200);
    });

    it('ignores sub-2px jitter and a collapsed container', () => {
        const { element, resize } = container(848);
        const apply = vi.fn();

        watchFitWidth(element, apply, { padding: 48 });
        resize(848);

        resize(849);
        vi.advanceTimersByTime(150);
        resize(120);
        vi.advanceTimersByTime(150);

        expect(apply).toHaveBeenCalledTimes(1);
    });

    it('stops observing when disposed', () => {
        const { element, resize } = container(848);
        const apply = vi.fn();

        const stop = watchFitWidth(element, apply, { padding: 48 });
        resize(848);
        resize(1648);
        stop();
        vi.advanceTimersByTime(500);

        expect(FakeResizeObserver.instances[0].disconnected).toBe(true);
        expect(apply).toHaveBeenCalledTimes(1);
    });
});
