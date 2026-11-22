import { describe, expect, it } from 'vitest';
import { createAppI18n } from '@/i18n';
import type { Language } from '@/types/language';
import {
    classifyLogin429,
    formatWaitDuration,
    login429Message,
} from './loginThrottle';
import type { Translate } from './loginThrottle';

const LANGUAGES = [
    { code: 'fr', direction: 'ltr', is_default: true },
    { code: 'en', direction: 'ltr', is_default: false },
    { code: 'ar', direction: 'rtl', is_default: false },
] as unknown as Language[];

function translator(locale: string): Translate {
    const { t } = createAppI18n(locale, LANGUAGES).global;

    return (key, named, plural) =>
        plural === undefined
            ? t(key, named ?? {})
            : t(key, named ?? {}, plural);
}

const LOCKED = {
    'x-login-reason': 'locked',
    'x-locked-until': '2026-01-01T10:07:00Z',
    'x-server-time': '2026-01-01T10:00:00Z',
    'retry-after': '999',
};

describe('classifyLogin429', () => {
    it('busy wins and never carries a number', () => {
        expect(
            classifyLogin429({
                'x-login-reason': ' Busy ',
                'retry-after': '2',
            }),
        ).toEqual({ kind: 'busy' });
    });

    it('locked uses the server timestamps, not Retry-After', () => {
        expect(classifyLogin429(LOCKED)).toEqual({
            kind: 'locked',
            seconds: 420,
        });
    });

    it('rounds a partial second up', () => {
        expect(
            classifyLogin429({
                'x-login-reason': 'locked',
                'x-locked-until': '2026-01-01T10:00:00.500Z',
                'x-server-time': '2026-01-01T10:00:00Z',
            }),
        ).toEqual({ kind: 'locked', seconds: 1 });
    });

    it('locked falls back to Retry-After without a usable server time', () => {
        expect(
            classifyLogin429({
                'x-login-reason': 'locked',
                'x-locked-until': '2026-01-01T10:07:00Z',
                'retry-after': '120',
            }),
        ).toEqual({ kind: 'locked', seconds: 120 });
        expect(
            classifyLogin429({
                'x-locked-until': 'garbage',
                'x-server-time': 'garbage',
                'retry-after': '60',
            }),
        ).toEqual({ kind: 'locked', seconds: 60 });
    });

    it('x-locked-until alone is a lockout; locked reason alone has no delay', () => {
        expect(classifyLogin429({ 'x-locked-until': 'x' }).kind).toBe('locked');
        expect(classifyLogin429({ 'x-login-reason': 'locked' })).toEqual({
            kind: 'locked',
            seconds: null,
        });
    });

    it('anything else is a throttle', () => {
        expect(classifyLogin429({ 'retry-after': '30' })).toEqual({
            kind: 'throttled',
            seconds: 30,
        });
        expect(
            classifyLogin429({
                'x-login-reason': 'other',
                'retry-after': '30',
            }),
        ).toEqual({ kind: 'throttled', seconds: 30 });
    });

    it.each(['', '0', '-5', '1.5', 'abc', '12s', ' '])(
        'rejects Retry-After %j',
        (value) => {
            expect(classifyLogin429({ 'retry-after': value })).toEqual({
                kind: 'throttled',
                seconds: null,
            });
        },
    );
});

describe('formatWaitDuration', () => {
    it.each([
        [1, '1 second'],
        [42, '42 seconds'],
        [59, '59 seconds'],
        [60, '1 minute'],
        [90, '2 minutes'],
        [1800, '30 minutes'],
        [3540, '59 minutes'],
        [3599, '1 hour'],
        [3600, '1 hour'],
        [5400, '1 hour and 30 minutes'],
        [7200, '2 hours'],
    ])('en %i s -> %s', (seconds, text) => {
        expect(formatWaitDuration(seconds, translator('en'))).toBe(text);
    });

    it('fr', () => {
        const t = translator('fr');

        expect(formatWaitDuration(1, t)).toBe('1 seconde');
        expect(formatWaitDuration(42, t)).toBe('42 secondes');
        expect(formatWaitDuration(5400, t)).toBe('1 heure et 30 minutes');
    });

    it.each([
        [1, 'ثانية واحدة'],
        [2, 'ثانيتين'],
        [3, '3 ثوانٍ'],
        [10, '10 ثوانٍ'],
        [11, '11 ثانية'],
        [120, 'دقيقتين'],
        [1800, '30 دقيقة'],
        [5400, 'ساعة واحدة و30 دقيقة'],
    ])('ar %i s -> %s', (seconds, text) => {
        expect(formatWaitDuration(seconds, translator('ar'))).toBe(text);
    });
});

describe('login429Message', () => {
    it('busy shows no digit even with Retry-After', () => {
        for (const locale of ['en', 'fr', 'ar']) {
            const message = login429Message(
                { 'x-login-reason': 'busy', 'retry-after': '2' },
                translator(locale),
            );

            expect(message).not.toMatch(/\d/);
        }
    });

    it('differs between busy, throttle and lockout', () => {
        const t = translator('en');
        const busy = login429Message({ 'x-login-reason': 'busy' }, t);
        const throttle = login429Message({ 'retry-after': '2' }, t);
        const locked = login429Message(LOCKED, t);

        expect(throttle).toBe(
            'Too many sign-in attempts. Try again in 2 seconds.',
        );
        expect(locked).toBe(
            'Too many failed sign-in attempts. Try again in 7 minutes.',
        );
        expect(new Set([busy, throttle, locked]).size).toBe(3);
    });

    it('is localised', () => {
        expect(login429Message({ 'retry-after': '2' }, translator('fr'))).toBe(
            'Trop de tentatives de connexion. Réessayez dans 2 secondes.',
        );
        expect(login429Message(LOCKED, translator('ar'))).toContain('7 دقائق');
    });

    it('never produces NaN or an empty message', () => {
        const cases: Record<string, string>[] = [{}, { 'retry-after': 'abc' }];

        for (const headers of cases) {
            const message = login429Message(headers, translator('en'));

            expect(message).not.toContain('NaN');
            expect(message).not.toBe('');
        }
    });
});
