import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Guards resources/css/onda-tokens.css: every foreground/background pair the
 * app relies on must meet WCAG 2.2 (text 4.5:1, UI edges 3:1) in both modes,
 * and the OS-driven "system" block must not drift from `.dark`.
 */

const css = readFileSync(
    join(__dirname, '../../css/onda-tokens.css'),
    'utf8',
).replace(/\/\*[\s\S]*?\*\//g, '');

type Tokens = Record<string, string>;

/** Body of the first `{ … }` that follows `header` (braces balanced). */
function block(header: string | RegExp): string {
    const start =
        typeof header === 'string'
            ? css.indexOf(header)
            : (header.exec(css)?.index ?? -1);

    expect(start, `block ${String(header)} not found`).toBeGreaterThanOrEqual(
        0,
    );

    const open = css.indexOf('{', start);
    let depth = 0;

    for (let i = open; i < css.length; i++) {
        if (css[i] === '{') {
            depth++;
        } else if (css[i] === '}' && --depth === 0) {
            return css.slice(open + 1, i);
        }
    }

    throw new Error(`unbalanced block ${String(header)}`);
}

function declarations(body: string): Tokens {
    const out: Tokens = {};

    for (const m of body.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) {
        // Prettier lower-cases hex and re-wraps long values: compare normalised.
        out[m[1]] = m[2].replace(/\s+/g, ' ').trim().toLowerCase();
    }

    return out;
}

const light = declarations(block(/^:root,\s*\.light\s*\{/m));
const dark = declarations(block(/^\.dark \{/m));
const system = declarations(block(/:root:not\(\.light\):not\(\.dark\)\s*\{/));

function resolve(tokens: Tokens, name: string): string {
    let value = tokens[`--${name}`];

    expect(value, `--${name} is not defined`).toBeDefined();

    while (value.startsWith('var(')) {
        value = tokens[value.slice(4, -1)];

        expect(value, 'dangling var() reference').toBeDefined();
    }

    return value;
}

type Rgb = [number, number, number];

function parse(color: string, over?: Rgb): Rgb {
    if (color.startsWith('#')) {
        return [1, 3, 5].map((i) => parseInt(color.slice(i, i + 2), 16)) as Rgb;
    }

    const m = color.match(/rgba\((\d+),\s*(\d+),\s*(\d+),\s*([\d.]+)\)/);

    expect(m, `cannot parse ${color}`).not.toBeNull();
    expect(over, `${color} needs a backdrop`).toBeDefined();

    const [r, g, b, a] = [m![1], m![2], m![3], m![4]].map(Number);

    return [r, g, b].map((c, i) => c * a + over![i] * (1 - a)) as Rgb;
}

function luminance([r, g, b]: Rgb): number {
    const lin = (c: number) => {
        const s = c / 255;

        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
}

function ratio(tokens: Tokens, fg: string, bg: string, backdrop = 'surface') {
    const back = parse(resolve(tokens, backdrop));
    const bgRgb = parse(resolve(tokens, bg), back);
    const fgRgb = parse(resolve(tokens, fg), bgRgb);
    const [hi, lo] = [luminance(fgRgb), luminance(bgRgb)].sort((a, b) => b - a);

    return (hi + 0.05) / (lo + 0.05);
}

/** [foreground token, background token] */
const TEXT: [string, string][] = [
    ['foreground', 'background'],
    ['foreground', 'surface'],
    ['foreground', 'input'],
    ['foreground', 'surface-sunken'],
    ['foreground', 'muted-surface'],
    ['foreground', 'primary-soft'],
    ['muted-foreground', 'background'],
    ['muted-foreground', 'surface'],
    ['muted-foreground', 'input'],
    ['muted-foreground', 'muted-surface'],
    ['primary-foreground', 'primary-solid'],
    ['primary-foreground', 'primary-solid-hover'],
    ['primary-text', 'background'],
    ['primary-text', 'surface'],
    ['primary-text', 'primary-soft'],
    ['accent-text', 'surface'],
    ['accent-text', 'accent-soft'],
    ['accent-strong-foreground', 'accent-strong'],
    ['danger', 'surface'],
    ['danger', 'danger-soft'],
    ['warning', 'surface'],
    ['warning', 'warning-soft'],
    ['danger-foreground', 'danger'],
    ['warning-foreground', 'warning'],
    ['success-foreground', 'success'],
    ['info-foreground', 'info'],
];

/** UI edges and indicators: WCAG 1.4.11, 3:1. */
const UI: [string, string][] = [
    ['input-border', 'surface'],
    ['input-border', 'input'],
    ['input-border', 'background'],
    ['ring', 'surface'],
    ['ring', 'background'],
    ['accent', 'surface'],
];

describe.each([
    ['light', light],
    ['dark', dark],
] as const)('onda tokens — %s', (_mode, tokens) => {
    it.each(TEXT)('text %s on %s is at least 4.5:1', (fg, bg) => {
        expect(ratio(tokens, fg, bg)).toBeGreaterThanOrEqual(4.5);
    });

    it.each(UI)('edge %s on %s is at least 3:1', (fg, bg) => {
        expect(ratio(tokens, fg, bg)).toBeGreaterThanOrEqual(3);
    });
});

describe('onda tokens — structure', () => {
    it('keeps the brand constants', () => {
        expect(light['--onda-blue']).toBeUndefined(); // declared once, in :root
        expect(css).toContain('--onda-blue: #1b669d;');
        expect(css).toContain('--onda-green: #1c9976;');
    });

    it('system mode mirrors dark for every shared token', () => {
        for (const [name, value] of Object.entries(system)) {
            if (name === 'color-scheme') {
                continue;
            }

            expect(dark[name], name).toBe(value);
        }
    });

    it('defines every light token in dark as well', () => {
        for (const name of Object.keys(light)) {
            expect(dark[name], `${name} missing in .dark`).toBeDefined();
        }
    });
});
