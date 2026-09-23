<?php

declare(strict_types=1);

namespace App\Actions\Oeuvre;

use App\Domain\Deposit\Exceptions\RemovalRefused;
use App\Domain\Deposit\RemovalGate;
use App\Models\Oeuvre;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a whole deposit, with its files.
 *
 * The files are soft-deleted explicitly, in a loop, rather than left to
 * the database: `media_files.oeuvre_id` is `cascadeOnDelete`, and a DB
 * cascade only fires on a *hard* delete. Soft-deleting the oeuvre alone
 * would leave every `media_files` row live and pointing at a trashed
 * parent — the exact orphan CLAUDE.md warns about ("Soft-deleting an
 * oeuvre does not cascade to its media_files").
 *
 * Bytes are untouched, and quota does not recover — see RemoveMediaFile
 * for why, and say so in the confirmation dialog. An author who deletes a
 * 5 GB draft to make room and finds their quota unchanged will call
 * support.
 *
 * @throws RemovalRefused
 */
final class DeleteOeuvre
{
    public function __construct(
        private readonly RemovalGate $gate,
    ) {}

    public function handle(Oeuvre $oeuvre): void
    {
        $this->gate->assertOeuvreDeletable($oeuvre);

        DB::transaction(function () use ($oeuvre): void {
            // Chunked: a deposit can hold hundreds of files, and each
            // delete() is a row update.
            $oeuvre->mediaFiles()->chunkById(200, function ($mediaFiles): void {
                foreach ($mediaFiles as $mediaFile) {
                    $mediaFile->delete();
                }
            });

            $oeuvre->delete();
        });
    }
}
