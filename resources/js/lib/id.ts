/**
 * `crypto.randomUUID()` is only exposed in secure contexts — HTTPS, or the
 * literal hostname `localhost`. A custom local dev domain such as
 * `onda-storage.test` resolves to 127.0.0.1 but is a *different* hostname,
 * so plain HTTP against it is not a secure context and `randomUUID` is
 * `undefined` there — calling it unconditionally threw uncaught inside
 * `enqueueFile()`, silently killing file selection with no error shown
 * anywhere (the file never reached the store, so no rejection could even
 * be rendered).
 *
 * `crypto.getRandomValues()` carries no such restriction, so this builds an
 * RFC 4122 v4 UUID from it whenever the native generator is unavailable.
 * Every caller here only needs a client-local, effectively-unique key (the
 * server mints its own session uuid independently), so the fallback is
 * exactly as good as the native one for that purpose.
 */
export function generateClientId(): string {
    if (typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0'));

    return [
        hex.slice(0, 4).join(''),
        hex.slice(4, 6).join(''),
        hex.slice(6, 8).join(''),
        hex.slice(8, 10).join(''),
        hex.slice(10, 16).join(''),
    ].join('-');
}
