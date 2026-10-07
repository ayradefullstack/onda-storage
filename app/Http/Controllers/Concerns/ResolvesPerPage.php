<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * The `per_page` query value every paginated table accepts.
 *
 * An allow-list, not a clamp: the value reaches `paginate()`, so a crafted
 * `?per_page=100000` must fall back to the table's default rather than turn
 * one request into a full-table read.
 */
trait ResolvesPerPage
{
    /** @var list<int> */
    public const PER_PAGE_OPTIONS = [10, 15, 25, 50, 100];

    protected function perPage(Request $request, int $default): int
    {
        $asked = $request->integer('per_page');

        return in_array($asked, self::PER_PAGE_OPTIONS, true) ? $asked : $default;
    }
}
