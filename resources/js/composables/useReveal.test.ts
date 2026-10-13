// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { observeReveal } from './useReveal';

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
        const element = document.createElement('section');

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
        const element = document.createElement('section');

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
        const element = document.createElement('section');

        observeReveal(element);

        expect(element.classList.contains('motion-reveal')).toBe(false);
    });
});
