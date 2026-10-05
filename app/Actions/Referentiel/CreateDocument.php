<?php

declare(strict_types=1);

namespace App\Actions\Referentiel;

use App\Models\CollegeOeuvreFile;
use App\Models\RegisterTypeCollege;
use App\Models\User;

/**
 * A required-document slot for a college. `document_key` is chosen here and
 * frozen (it is copied into `media_files.document_key_snapshot`).
 *
 * `mime_types` is never passed: the model's `saving` hook derives it from
 * `extensions`, so the MIME list an upload is checked against can only ever
 * be the registry's, never something an admin typed.
 */
final class CreateDocument extends CreatesReferenceRow
{
    /**
     * @param  array<string, mixed>  $data  validated input (name, localised names, flags)
     */
    public function handle(User $actor, RegisterTypeCollege $college, array $data): CollegeOeuvreFile
    {
        return $this->persist($actor, new CollegeOeuvreFile, [
            'register_type_college_id' => $college->id,
            'document_key' => $data['document_key'],
            'title' => $data['title'],
            'title_ar' => $data['title_ar'] ?? null,
            'title_en' => $data['title_en'] ?? null,
            'extensions' => $data['extensions'],
            'is_required' => $data['is_required'] ?? true,
            'display_order' => $data['display_order'] ?? 1,
            'max_size_kb' => $data['max_size_kb'] ?? null,
            'allows_multiple' => $data['allows_multiple'] ?? true,
            'needs_review' => false,
        ]);
    }
}
