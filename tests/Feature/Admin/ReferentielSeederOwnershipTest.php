<?php

declare(strict_types=1);

use App\Models\CollegeOeuvreFile;
use App\Models\RegisterType;
use App\Models\RegisterTypeCollege;
use App\Models\RegisterTypeMember;
use App\Models\TypeGestion;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The seeders and the admin console share these tables. These tests re-run
 * both seeders over a database an admin has already changed and added to,
 * and assert that nothing the admin owns moves.
 */
beforeEach(function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
});

function ownershipRerun(): void
{
    test()->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
}

/** An admin-created college under the seeded Auteur / collective branch, as the admin form would leave it. */
function ownershipAdminCollege(string $code = 'ADMIN_COLLEGE'): RegisterTypeCollege
{
    $type = RegisterType::where('slug', 'auteur')->firstOrFail();
    $gestion = TypeGestion::where('register_type_id', $type->id)->where('type_gestion', 1)->firstOrFail();

    $college = new RegisterTypeCollege;
    $college->fill([
        'register_type_id' => $type->id,
        'type_gestion_id' => $gestion->id,
        'type_gestion' => 1,
        'code_college' => $code,
        'name' => 'Collège ajouté par un admin',
        'status' => 1,
        'is_disabled' => false,
    ])->save();

    return $college;
}

test('every seeded row is marked as a system row', function () {
    expect(RegisterType::where('is_system', false)->count())->toBe(0)
        ->and(TypeGestion::where('is_system', false)->count())->toBe(0)
        ->and(RegisterTypeCollege::where('is_system', false)->count())->toBe(0)
        ->and(RegisterTypeMember::where('is_system', false)->count())->toBe(0)
        ->and(RegisterTypeCollege::count())->toBe(22)
        ->and(RegisterTypeMember::count())->toBe(100);
});

test('re-running the seeders keeps an admin-created college\'s code_college', function () {
    $college = ownershipAdminCollege();

    ownershipRerun();

    expect($college->fresh())
        ->code_college->toBe('ADMIN_COLLEGE')
        ->is_system->toBeFalse()
        ->name->toBe('Collège ajouté par un admin');

    // And the seeded colleges are still coded: the reset only touched system rows.
    expect(RegisterTypeCollege::where('is_system', true)->whereNull('code_college')->count())->toBe(0);
});

test('re-running the seeders keeps an admin\'s status flags on a system row', function () {
    $college = RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail();
    $member = RegisterTypeMember::where('code_qlt', 'C')->where('register_type_college_id', $college->id)->firstOrFail();
    $type = RegisterType::where('slug', 'auteur')->firstOrFail();
    $gestion = TypeGestion::where('register_type_id', $type->id)->firstOrFail();

    $college->update(['is_disabled' => true, 'status' => 0, 'adhesion' => false, 'name_ar' => 'اسم']);
    $member->update(['is_disabled' => true, 'available_in_registration' => false, 'status' => 0]);
    $type->update(['status' => 0, 'is_disabled' => true]);
    $gestion->update(['status' => 0]);

    ownershipRerun();

    expect($college->fresh())->is_disabled->toBeTrue()->status->toBe(0)->name_ar->toBe('اسم')
        ->and($member->fresh())->is_disabled->toBeTrue()->available_in_registration->toBeFalse()->status->toBe(0)
        ->and($type->fresh())->status->toBe(0)->is_disabled->toBeTrue()
        ->and($gestion->fresh()->status)->toBe(0);
});

test('re-running the seeders creates no duplicate of a system row nor of an admin row', function () {
    $adminCollege = ownershipAdminCollege();

    $adminMember = new RegisterTypeMember;
    $adminMember->fill([
        'register_type_college_id' => $adminCollege->id, 'name' => 'Qualité admin', 'code_qlt' => 'ADM',
        'status' => 1, 'is_disabled' => false, 'available_in_registration' => true,
    ])->save();

    $before = [
        RegisterType::count(), TypeGestion::count(), RegisterTypeCollege::count(),
        RegisterTypeMember::count(), CollegeOeuvreFile::count(),
    ];

    ownershipRerun();
    ownershipRerun();

    expect([
        RegisterType::count(), TypeGestion::count(), RegisterTypeCollege::count(),
        RegisterTypeMember::count(), CollegeOeuvreFile::count(),
    ])->toBe($before)
        ->and(RegisterTypeCollege::where('code_college', 'ADMIN_COLLEGE')->count())->toBe(1)
        ->and(RegisterTypeMember::where('code_qlt', 'ADM')->count())->toBe(1)
        ->and($adminMember->fresh())->name->toBe('Qualité admin')->is_system->toBeFalse();
});

test('a seeded name that an admin row also carries never adopts the admin row', function () {
    // An admin college that happens to share the seeded match key
    // (type, name, gestion) of a seeded one — possible only because the
    // names are compared exactly by the seeder, not trimmed.
    $college = ownershipAdminCollege('LOOKALIKE');
    $college->update(['name' => 'oeuvres musicales']);

    ownershipRerun();

    expect($college->fresh())->code_college->toBe('LOOKALIKE')->is_system->toBeFalse()
        ->and(RegisterTypeCollege::where('code_college', 'MUSIQUE')->value('is_system'))->toBeTrue();
});

test('admin-created documents are not touched by the documents seeder', function () {
    $college = ownershipAdminCollege();

    $document = new CollegeOeuvreFile;
    $document->fill([
        'register_type_college_id' => $college->id, 'document_key' => 'piece_admin', 'title' => 'Pièce admin',
        'extensions' => ['pdf'], 'is_required' => true, 'display_order' => 1, 'allows_multiple' => true, 'needs_review' => false,
    ])->save();

    $seeded = CollegeOeuvreFile::where('document_key', 'justificatif_exploitation')->firstOrFail();
    $seeded->update(['is_required' => ! $seeded->is_required, 'title_en' => 'Edited by an officer']);
    $expected = [$seeded->is_required, 'Edited by an officer'];

    ownershipRerun();

    expect($document->fresh())->title->toBe('Pièce admin')->document_key->toBe('piece_admin')
        ->and([$seeded->fresh()->is_required, $seeded->fresh()->title_en])->toBe($expected);
});

// --- the migration backfill -----------------------------------------------------------

test('after the migration every pre-existing row is a system row', function () {
    $path = 'database/migrations/2026_10_05_090000_add_is_system_to_referentiel_tables.php';

    // Take the database back to how it was before the column existed: rows
    // seeded, no `is_system` anywhere…
    Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);

    foreach (['register_types', 'type_gestions', 'register_type_colleges', 'register_type_members'] as $table) {
        expect(Schema::hasColumn($table, 'is_system'))->toBeFalse();
    }

    // …plus one row a seeder never wrote, so the backfill is shown to apply
    // to whatever exists, not to the seeded set only.
    DB::table('register_types')->insert([
        'uuid' => (string) Str::uuid(), 'name' => 'Pre-existing', 'slug' => 'pre-existing',
        'status' => 1, 'is_disabled' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $types = DB::table('register_types')->count();
    $members = DB::table('register_type_members')->count();

    Artisan::call('migrate', ['--path' => $path, '--force' => true]);

    expect(DB::table('register_types')->where('is_system', false)->count())->toBe(0)
        ->and(DB::table('register_types')->where('is_system', true)->count())->toBe($types)
        ->and(DB::table('type_gestions')->where('is_system', false)->count())->toBe(0)
        ->and(DB::table('register_type_colleges')->where('is_system', false)->count())->toBe(0)
        ->and(DB::table('register_type_members')->where('is_system', true)->count())->toBe($members)
        // The new columns' defaults: gestions stay active, members gain nullable names.
        ->and(DB::table('type_gestions')->where('status', '!=', 1)->count())->toBe(0)
        ->and(DB::table('register_type_members')->whereNotNull('name_ar')->count())->toBe(0);
});
