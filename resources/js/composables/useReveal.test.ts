// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { observeReveal, vReveal } from './useReveal';

type Callback = (entries: Partial<IntersectionObserverEntry>[]) => void;

class FakeObserver {
    static instances: FakeObserver[] = [];

    observed: Element[] = [];
    disconnected = false;

    constructor(
        public callback: Callback,
        public options?: IntersectionObserverInit,
    ) {
        FakeObserver.instances.push(this);
    }

    observe(element: Element): void {
        this.observed.push(element);
    }

    unobserve(element: Element): void {
        this.observed = this.observed.filter((e) => e !== element);
    }

    disconnect(): void {
        this.disconnected = true;
        this.observed = [];
    }

    enter(target: Element): void {
        this.callback([{ isIntersecting: true, target }]);
    }
}

function reducedMotion(reduce: boolean): void {
    vi.stubGlobal(
        'matchMedia',
        vi.fn((query: string) => ({
            matches: reduce && query.includes('prefers-reduced-motion'),
        })),
    );
}

/** Lay the element out at `top` px from the top of the viewport. */
function placeAt<T extends Element>(element: T, top: number): T {
    element.getBoundingClientRect = () =>
        ({
            top,
            bottom: top + 40,
            left: 0,
            right: 300,
            width: 300,
            height: 40,
            x: 0,
            y: top,
            toJSON: () => ({}),
        }) as DOMRect;

    return element;
}

/** Below the fold of happy-dom's 768 px viewport. */
const belowFold = <T extends Element>(element: T): T =>
    placeAt(element, window.innerHeight + 200);

beforeEach(() => {
    FakeObserver.instances = [];
    vi.stubGlobal('IntersectionObserver', FakeObserver);
});

afterEach(() => vi.unstubAllGlobals());

describe('observeReveal', () => {
    it('does nothing under reduced motion: no observer, element never hidden', () => {
        reducedMotion(true);
        const element = document.createElement('section');

        observeReveal(element);

        expect(FakeObserver.instances).toHaveLength(0);
        expect(element.classList.contains('motion-reveal')).toBe(false);
    });

    it('does nothing when disabled (a list longer than 50 rows)', () => {
        reducedMotion(false);
        const element = document.createElement('li');

        observeReveal(element, { disabled: true });

        expect(FakeObserver.instances).toHaveLength(0);
        expect(element.className).toBe('');
    });

    it('hides, reveals on entering the viewport, then stops observing', () => {
        reducedMotion(false);
        const element = belowFold(document.createElement('section'));

        observeReveal(element);

        const [observer] = FakeObserver.instances;

        expect(observer.options?.threshold).toBe(0.15);
        expect(observer.observed).toEqual([element]);
        expect(element.classList.contains('motion-reveal')).toBe(true);
        expect(element.classList.contains('is-revealed')).toBe(false);

        observer.enter(element);

        expect(element.classList.contains('is-revealed')).toBe(true);
        expect(observer.observed).toEqual([]);
        expect(observer.disconnected).toBe(true);
    });

    it('ignores an entry that is not intersecting', () => {
        reducedMotion(false);
        const element = belowFold(document.createElement('section'));

        observeReveal(element);
        FakeObserver.instances[0].callback([
            { isIntersecting: false, target: element },
        ]);

        expect(element.classList.contains('is-revealed')).toBe(false);
        expect(FakeObserver.instances[0].disconnected).toBe(false);
    });

    it('never hides content where IntersectionObserver is missing', () => {
        reducedMotion(false);
        vi.stubGlobal('IntersectionObserver', undefined);
        const element = belowFold(document.createElement('section'));

        observeReveal(element);

        expect(element.classList.contains('motion-reveal')).toBe(false);
    });

    // Regression (filter-not-rendering): an element was hidden at mount and
    // only an IntersectionObserver callback could ever show it again. A row
    // a filter inserts into the visible part of a list must be readable at
    // once, whatever the observer does.
    it('never hides an element that is already on screen when it mounts', () => {
        reducedMotion(false);
        const element = placeAt(document.createElement('tr'), 120);

        observeReveal(element);

        expect(FakeObserver.instances).toHaveLength(0);
        expect(element.classList.contains('motion-reveal')).toBe(false);
    });

    it('never hides an element with no layout box yet', () => {
        reducedMotion(false);
        const element = document.createElement('li');

        observeReveal(element);

        expect(element.classList.contains('motion-reveal')).toBe(false);
    });
});

describe('vReveal', () => {
    it('leaves rows inserted on screen after the first render visible', () => {
        reducedMotion(false);
        const list = document.createElement('ul');
        const first = placeAt(document.createElement('li'), 100);
        vReveal.mounted(first, {});
        list.append(first);

        // A filter's response swaps in new rows: mounted later, still on screen.
        const inserted = [160, 200, 240].map((top) =>
            placeAt(document.createElement('li'), top),
        );
        inserted.forEach((row) => {
            vReveal.mounted(row, {});
            list.append(row);
        });

        for (const row of [first, ...inserted]) {
            expect(row.classList.contains('motion-reveal')).toBe(false);
        }
    });
});
