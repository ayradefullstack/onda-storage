<?php

declare(strict_types=1);

namespace App\Actions\Referentiel;

use App\Models\RegisterType;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * A declarant type created by an admin: no colleges yet, so it reaches
 * step 1 only once it gets an enabled college.
 */
final class CreateRegisterType extends CreatesReferenceRow
{
    /**
     * @param  array<string, mixed>  $data  validated input (name, localised names, flags)
     */
    public function handle(User $actor, array $data): RegisterType
    {
        return $this->persist($actor, new RegisterType, [
            'name' => $data['name'],
            'slug' => $this->uniqueSlug((string) $data['name']),
            'name_ar' => $data['name_ar'] ?? null,
            'name_en' => $data['name_en'] ?? null,
            'status' => $data['status'] ?? RegisterType::STATUS_ACTIVE,
            'is_disabled' => false,
        ]);
    }

    /**
     * Generated from the name, then frozen. Checked against soft-deleted
     * rows as well: the unique index covers them, and a retired type's slug
     * is still what its oeuvres were filed under.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'type';
        $slug = $base;
        $suffix = 2;

        while (RegisterType::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
