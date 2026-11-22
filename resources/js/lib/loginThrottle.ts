/**
 * Turns a 429 from POST /login into the message the form shows.
 *
 * The server says WHY through `X-Login-Reason` (`busy` | `locked`); a plain
 * request throttle sends no such header. The cause is never guessed from
 * Retry-After or from the body, and the body is never displayed. Header names
 * arrive lower-cased (XhrHttpClient).
 */
export type Login429 =
    | { kind: 'busy' }
    | { kind: 'locked'; seconds: number | null }
    | { kind: 'throttled'; seconds: number | null };

export type Translate = (
    key: string,
    named?: Record<string, unknown>,
    plural?: number,
) => string;

type Headers = Record<string, string>;

function header(headers: Headers, name: string): string {
    return (headers[name] ?? '').trim();
}

/** Retry-After in whole seconds, only when it is a positive integer. */
function retryAfterSeconds(headers: Headers): number | null {
    const raw = header(headers, 'retry-after');

    if (!/^\d+$/.test(raw)) {
        return null;
    }

    const seconds = Number.parseInt(raw, 10);

    return seconds > 0 ? seconds : null;
}

/** Remaining lockout from the server's own two timestamps, never our clock. */
function lockedSeconds(headers: Headers): number | null {
    const until = Date.parse(header(headers, 'x-locked-until'));
    const now = Date.parse(header(headers, 'x-server-time'));

    if (Number.isFinite(until) && Number.isFinite(now) && until > now) {
        return Math.ceil((until - now) / 1000);
    }

    return retryAfterSeconds(headers);
}

export function classifyLogin429(headers: Headers): Login429 {
    const reason = header(headers, 'x-login-reason').toLowerCase();

    if (reason === 'busy') {
        return { kind: 'busy' };
    }

    if (reason === 'locked' || header(headers, 'x-locked-until') !== '') {
        return { kind: 'locked', seconds: lockedSeconds(headers) };
    }

    return { kind: 'throttled', seconds: retryAfterSeconds(headers) };
}

/** "30 seconds", "2 minutes", "1 hour", "1 hour and 30 minutes". */
export function formatWaitDuration(seconds: number, t: Translate): string {
    if (seconds < 60) {
        return t('authUi.err.durationSeconds', { count: seconds }, seconds);
    }

    const minutes = Math.ceil(seconds / 60);

    if (minutes < 60) {
        return t('authUi.err.durationMinutes', { count: minutes }, minutes);
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    const hoursText = t('authUi.err.durationHours', { count: hours }, hours);

    if (rest === 0) {
        return hoursText;
    }

    return t('authUi.err.durationHoursMinutes', {
        hours: hoursText,
        minutes: t('authUi.err.durationMinutes', { count: rest }, rest),
    });
}

export function login429Message(headers: Headers, t: Translate): string {
    const result = classifyLogin429(headers);

    if (result.kind === 'busy') {
        return t('authUi.err.loginBusy');
    }

    const [withDelay, noDelay] =
        result.kind === 'locked'
            ? ['authUi.err.lockedFor', 'authUi.err.lockedNoDelay']
            : ['authUi.err.tooManyFor', 'authUi.err.tooManyNoDelay'];

    return result.seconds === null
        ? t(noDelay)
        : t(withDelay, { duration: formatWaitDuration(result.seconds, t) });
}
