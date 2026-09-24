<?php

declare(strict_types=1);

use App\Domain\Deposit\MediaFileStatus;
use App\Domain\Deposit\OeuvreStatus;
use App\Models\CollegeOeuvreFile;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\ReferenceDataChange;
use App\Models\RegisterTypeCollege;
use App\Models\RegisterTypeMember;
use App\Models\User;
use App\Support\FileFormats;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * What an admin may and may not change, and what is recorded when they do.
 *
 * The never-editable fields are the point: `code_college` and
 * `document_key` are join keys frozen into already-filed deposits, so
 * these assert the server refuses them on a DIRECT request, not merely
 * that the form omits the input.
 */
beforeEach(function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
});

function editAdmin(): User
{
    return User::factory()->withRole('admin')->create();
}

function musiqueCollege(): RegisterTypeCollege
{
    return RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail();
}

// --- never editable ------------------------------------------------------

test('code_college cannot be changed, even by a direct request', function () {
    $college = musiqueCollege();

    $this->actingAs(editAdmin())
        ->patch(route('admin.referentiel.colleges.update', $college), [
            'code_college' => 'HACKED',
            'name_ar' => 'اسم جديد',
        ])
        ->assertRedirect();

    $college->refresh();

    // The legitimate part of the request applied; the forbidden part did not.
    expect($college->code_college)->toBe('MUSIQUE')
        ->and($college->name_ar)->toBe('اسم جديد');
});

test('a college\'s register_type_id and name cannot be changed', function () {
    $college = musiqueCollege();
    $originalType = $college->register_type_id;
    $originalName = $college->name;

    $this->actingAs(editAdmin())
        ->patch(route('admin.referentiel.colleges.update', $college), [
            'register_type_id' => $originalType + 1,
            'name' => 'Renamed college',
            'type_gestion' => 99,
            'code_dv' => 'X',
        ])
        ->assertRedirect();

    $college->refresh();

    expect($college->register_type_id)->toBe($originalType)
        ->and($college->name)->toBe($originalName)
        ->and($college->type_gestion)->not->toBe(99)
        ->and(ReferenceDataChange::count())->toBe(0);
});

test('document_key cannot be changed, even by a direct request', function () {
    $document = CollegeOeuvreFile::where('document_key', 'justificatif_exploitation')->firstOrFail();
    $originalCollege = $document->register_type_college_id;

    $this->actingAs(editAdmin())
        ->patch(route('admin.referentiel.documents.update', $document), [
            'document_key' => 'hacked_key',
            'register_type_college_id' => $originalCollege + 1,
            'title' => 'Renamed',
            'title_en' => 'Proof of exploitation',
        ])
        ->assertRedirect();

    $document->refresh();

    expect($document->document_key)->toBe('justificatif_exploitation')
        ->and($document->register_type_college_id)->toBe($originalCollege)
        ->and($document->title)->not->toBe('Renamed')
        ->and($document->title_en)->toBe('Proof of exploitation');
});

test('a member\'s code_qlt, name and college cannot be changed', function () {
    $member = RegisterTypeMember::whereNotNull('code_qlt')->firstOrFail();
    $original = $member->only(['code_qlt', 'name', 'register_type_college_id']);

    $this->actingAs(editAdmin())
        ->patch(route('admin.referentiel.membres.update', $member), [
            'code_qlt' => 'HACKED',
            'name' => 'Renamed',
            'register_type_college_id' => $member->register_type_college_id + 1,
            'is_disabled' => true,
        ])
        ->assertRedirect();

    $member->refresh();

    expect($member->only(['code_qlt', 'name', 'register_type_college_id']))->toBe($original)
        ->and($member->is_disabled)->toBeTrue();
});

// --- editable, safe ------------------------------------------------------

test('editing name_ar succeeds and touches nothing else', function () {
    $college = musiqueCollege();
    $untouched = $college->only([
        'code_college', 'name', 'name_en', 'register_type_id',
        'type_gestion', 'type_gestion_id', 'status', 'is_disabled', 'adhesion',
    ]);

    $this->actingAs(editAdmin())
        ->patch(route('admin.referentiel.colleges.update', $college), ['name_ar' => 'مصنفات موسيقية'])
        ->assertRedirect();

    $college->refresh();

    expect($college->name_ar)->toBe('مصنفات موسيقية')
        ->and($college->only(array_keys($untouched)))->toBe($untouched);
});

test('clearing a translation falls the display back to the French name', function () {
    $college = musiqueCollege();
    $college->forceFill(['name_ar' => 'سابق'])->save();

    $this->actingAs(editAdmin())
        ->patch(route('admin.referentiel.colleges.update', $college), ['name_ar' => null]);

    app()->setLocale('ar');

    expect($college->fresh()->name_ar)->toBeNull()
        ->and(trim($college->fresh()->name_global))->toBe(trim($college->name));
});

// --- extensions ----------------------------------------------------------

test('saving valid extensions on a needs_review row clears the flag', function () {
    $document = CollegeOeuvreFile::where('needs_review', true)->firstOrFail();

    $this->actingAs(editAdmin())
        ->patch(route('admin.referentiel.documents.update', $document), [
            'extensions' => ['pdf', 'jpg'],
        ])
        ->assertRedirect();

    $document->refresh();

    expect($document->extensions)->toBe(['pdf', 'jpg'])
        // Confirming or correcting the guess IS the review the flag asked
        // for, so it clears without a second checkbox.
        ->and($document->needs_review)->toBeFalse();
});

test('invalid extensions are rejected', function (array $extensions) {
    $document = CollegeOeuvreFile::where('document_key', 'justificatif_exploitation')->firstOrFail();
    $original = $document->extensions;

    $this->actingAs(editAdmin())
        ->patch(route('admin.referentiel.documents.update', $document), ['extensions' => $extensions])
        ->assertInvalid('extensions.0');

    expect($document->fresh()->extensions)->toBe($original);
})->with([
    'uppercase' => [['PDF']],
    'with a dot' => [['.pdf']],
    'a MIME string' => [['application/pdf']],
    // An internal space, not a trailing one: TrimStrings already strips
    // the latter before validation ever sees it.
    'with a space' => [['p df']],
    'a comma-separated string' => [['pdf,jpg']],
]);

test('an empty extensions list is rejected', function () {
    $document = CollegeOeuvreFile::where('document_key', 'justificatif_exploitation')->firstOrFail();

    $this->actingAs(editAdmin())
        ->patch(route('admin.referentiel.documents.update', $document), ['extensions' => []])
        ->assertInvalid('extensions');
});

test('a duplicated extension is rejected', function () {
    $document = CollegeOeuvreFile::where('document_key', 'justificatif_exploitation')->firstOrFail();

    $this->actingAs(editAdmin())
        ->patch(route('admin.referentiel.documents.update', $document), ['extensions' => ['pdf', 'pdf']])
        ->assertInvalid('extensions');
});

// --- is_required impact --------------------------------------------------

test('turning on is_required reports the number of affected drafts before saving', function () {
    $admin = editAdmin();
    $college = musiqueCollege();
    $document = $college->collegeOeuvreFiles()->where('is_required', false)->first()
        ?? $college->collegeOeuvreFiles()->firstOrFail();

    $author = User::factory()->withRole('author')->create();

    // Two drafts with nothing in this slot, one with a ready file in it,
    // and one already submitted — only the first two are affected.
    $unsatisfied = Oeuvre::factory()->count(2)->create([
        'author_id' => $author->id,
        'status' => OeuvreStatus::DRAFT,
        'register_type_college_id' => $college->id,
    ]);

    $satisfied = Oeuvre::factory()->create([
        'author_id' => $author->id,
        'status' => OeuvreStatus::DRAFT,
        'register_type_college_id' => $college->id,
    ]);
    MediaFile::factory()->create([
        'oeuvre_id' => $satisfied->id,
        'uploaded_by' => $author->id,
        'college_oeuvre_file_id' => $document->id,
        'status' => MediaFileStatus::READY,
    ]);

    Oeuvre::factory()->create([
        'author_id' => $author->id,
        'status' => OeuvreStatus::SUBMITTED,
        'register_type_college_id' => $college->id,
    ]);

    $this->actingAs($admin)
        ->getJson(route('admin.referentiel.documents.impact', $document))
        ->assertOk()
        ->assertJsonPath('affected_drafts', $unsatisfied->count());
});

// --- audit ---------------------------------------------------------------

test('every change writes an audit record naming the admin and the field', function () {
    $admin = editAdmin();
    $college = musiqueCollege();

    $this->actingAs($admin)
        ->patch(route('admin.referentiel.colleges.update', $college), [
            'name_ar' => 'اسم جديد',
            'is_disabled' => true,
        ]);

    $changes = ReferenceDataChange::query()->orderBy('id')->get();

    expect($changes)->toHaveCount(2)
        ->and($changes->pluck('field')->all())->toBe(['name_ar', 'is_disabled'])
        ->and($changes->pluck('actor_id')->unique()->all())->toBe([$admin->id])
        ->and($changes->first()->subject_uuid)->toBe($college->uuid)
        ->and($changes->first()->subject_type)->toBe(RegisterTypeCollege::class)
        // JSON-encoded so `false` stays distinguishable from null and "".
        ->and($changes->last()->old_value)->toBe('false')
        ->and($changes->last()->new_value)->toBe('true');
});

test('an unchanged field writes no audit record', function () {
    $college = musiqueCollege();

    $this->actingAs(editAdmin())
        ->patch(route('admin.referentiel.colleges.update', $college), [
            'status' => $college->status,
            'is_disabled' => $college->is_disabled,
            'adhesion' => $college->adhesion,
        ]);

    expect(ReferenceDataChange::count())->toBe(0);
});

test('the audit trail is hash-chained, so an altered old row stops matching', function () {
    $admin = editAdmin();
    $college = musiqueCollege();

    $this->actingAs($admin)->patch(route('admin.referentiel.colleges.update', $college), ['name_ar' => 'أول']);
    $this->actingAs($admin)->patch(route('admin.referentiel.colleges.update', $college), ['name_ar' => 'ثان']);

    $rows = ReferenceDataChange::query()->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->prev_hash)->toBeNull()
        // Each row chains off the one before it.
        ->and($rows[1]->prev_hash)->toBe($rows[0]->row_hash);
});

test('the audit trail records extensions as a list, not a string', function () {
    $document = CollegeOeuvreFile::where('document_key', 'justificatif_exploitation')->firstOrFail();

    $this->actingAs(editAdmin())
        ->patch(route('admin.referentiel.documents.update', $document), ['extensions' => ['pdf']]);

    $change = ReferenceDataChange::where('field', 'extensions')->firstOrFail();

    expect(json_decode((string) $change->new_value, true))->toBe(['pdf']);
});

// --- the edit form tells the truth about what is locked ------------------

test('the colleges page sends the codes it renders read-only', function () {
    $this->actingAs(editAdmin())
        ->get(route('admin.referentiel.colleges', ['search' => 'MUSIQUE']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.data.0.code_college', 'MUSIQUE')
            ->has('rows.data.0.oeuvres_count')
            ->has('rows.data.0.in_flight_oeuvres_count')
        );
});

// --- the format registry governs what may be saved -----------------------

test('an extension outside the registry is rejected, even by a direct request', function (string $extension) {
    $document = CollegeOeuvreFile::where('document_key', 'justificatif_exploitation')->firstOrFail();
    $original = $document->extensions;

    $this->actingAs(editAdmin())
        ->patch(route('admin.referentiel.documents.update', $document), [
            'extensions' => ['pdf', $extension],
        ])
        ->assertInvalid('extensions.1');

    expect($document->fresh()->extensions)->toBe($original);
})->with([
    // Executables and scripts are absent from the registry, and absence is
    // refusal — there is no blocklist to keep in step.
    'exe' => ['exe'],
    'php' => ['php'],
    'js' => ['js'],
    'sh' => ['sh'],
    'bat' => ['bat'],
    'jar' => ['jar'],
    // And anything simply invented.
    'unknown' => ['foo'],
]);

test('a valid selection stores a lowercase, dot-free list and derives its mime types', function () {
    $document = CollegeOeuvreFile::where('document_key', 'justificatif_exploitation')->firstOrFail();

    $this->actingAs(editAdmin())
        ->patch(route('admin.referentiel.documents.update', $document), [
            'extensions' => ['mp3', 'wav', 'flac'],
        ])
        ->assertRedirect();

    $document->refresh();

    expect($document->extensions)->toBe(['mp3', 'wav', 'flac'])
        ->and($document->mime_types)->toBe(FileFormats::mimeTypesFor(['mp3', 'wav', 'flac']))
        // Measured on this machine — the old hardcoded map said audio/wav.
        ->and($document->mime_types)->toContain('audio/x-wav');
});

test('the documents page sends the registry and each row\'s derived mime types', function () {
    $this->actingAs(editAdmin())
        ->get(route('admin.referentiel.documents', ['search' => 'justificatif_exploitation']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('formats')
            ->where('rows.data.0.mime_types', ['application/pdf', 'image/jpeg', 'image/png'])
        );
});
