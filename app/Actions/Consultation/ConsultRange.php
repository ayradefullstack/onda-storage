<?php

declare(strict_types=1);

namespace App\Actions\Consultation;

use App\Domain\Vault\Value\ByteRange;

/**
 * Resolves the Range header of the consult-original endpoint into the ONE
 * slice to serve. Unlike a plain Range parser it never answers with the whole
 * file: every slice is capped at `$cap` bytes, and a request without a usable
 * Range gets the first capped slice (a 206, never a 200 with everything).
 *
 *  - no header / unknown unit / syntactically invalid spec -> first slice
 *  - `bytes=a-b`, `bytes=a-`  -> [a, min(b, a+cap-1, total-1)]
 *  - `bytes=-n` (suffix)       -> the last n bytes, capped
 *  - start beyond EOF          -> unsatisfiable (416, `Content-Range: bytes * /total`)
 *  - several ranges            -> ONLY THE FIRST is served (permitted by RFC
 *    9110 §14.2; a multipart/byteranges body would need buffering we avoid)
 */
final class ConsultRange
{
    /**
     * @return ByteRange|null null = unsatisfiable (answer 416)
     */
    public static function resolve(?string $header, int $total, int $cap): ?ByteRange
    {
        if ($total <= 0) {
            return null;
        }

        $cap = max(1, $cap);
        $first = new ByteRange(0, min($total, $cap) - 1);

        if ($header === null || ! preg_match('/^\s*bytes\s*=\s*(.+)$/i', $header, $m)) {
            return $first;
        }

        $spec = trim(explode(',', $m[1], 2)[0]);

        if (! preg_match('/^(\d*)-(\d*)$/', $spec, $parts) || ($parts[1] === '' && $parts[2] === '')) {
            return $first;
        }

        if ($parts[1] === '') {
            // Suffix: the last n bytes.
            $length = (int) $parts[2];

            if ($length <= 0) {
                return null;
            }

            $start = max(0, $total - $length);

            return new ByteRange($start, min($total - 1, $start + $cap - 1));
        }

        $start = (int) $parts[1];

        if ($start >= $total) {
            return null;
        }

        if ($parts[2] !== '' && (int) $parts[2] < $start) {
            // `bytes=5-2` is not a valid spec: ignored, per RFC.
            return $first;
        }

        $end = $parts[2] === '' ? $total - 1 : min((int) $parts[2], $total - 1);

        return new ByteRange($start, min($end, $start + $cap - 1));
    }
}
