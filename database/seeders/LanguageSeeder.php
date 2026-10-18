<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Language\DirectionEnum;
use App\Models\Language;
use Illuminate\Database\Seeder;

/**
 * The three languages the portal ships UI bundles for. The site is never
 * language-less: a fresh database (and, through Tests\TestCase, every
 * feature test) starts with these rows.
 *
 * Idempotent: matched on `code`, so re-running it restores the shipped
 * names, direction and order without creating duplicates. What an admin
 * decides is never overwritten: `is_active` and `is_default` are set only
 * when the row is created, and a row becomes the default only if no
 * language is the default yet — so "exactly one default" holds after every
 * run. `is_default` goes through forceFill: it is deliberately not
 * mass-assignable (LanguageService::setDefault is the runtime path).
 */
class LanguageSeeder extends Seeder
{
    private const LANGUAGES = [
        ['code' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية', 'direction' => DirectionEnum::RTL, 'is_default' => true, 'sort_order' => 1],
        ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'direction' => DirectionEnum::LTR, 'is_default' => false, 'sort_order' => 2],
        ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => DirectionEnum::LTR, 'is_default' => false, 'sort_order' => 3],
    ];

    public function run(): void
    {
        foreach (self::LANGUAGES as $language) {
            $row = Language::query()->firstOrNew(['code' => $language['code']]);

            $row->forceFill([
                'name' => $language['name'],
                'native_name' => $language['native_name'],
                'direction' => $language['direction'],
                'sort_order' => $language['sort_order'],
            ]);

            if (! $row->exists) {
                $row->forceFill([
                    'is_active' => true,
                    'is_default' => $language['is_default']
                        && ! Language::query()->where('is_default', true)->exists(),
                ]);
            }

            $row->save();
        }
    }
}
