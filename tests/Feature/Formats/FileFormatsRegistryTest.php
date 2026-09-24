<?php

declare(strict_types=1);

use App\Models\CollegeOeuvreFile;
use App\Models\User;
use App\Support\FileFormats;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;

/**
 * The registry is the single source of truth for what this application
 * accepts. These pin the properties every other part of the system assumes
 * about it.
 */
test('every entry is complete and well formed', function () {
    foreach (FileFormats::all() as $extension => $format) {
        expect($extension)->toBe(strtolower($extension), "[$extension] must be lowercase")
            ->and($extension)->not->toContain('.', "[$extension] must not carry a dot")
            ->and($format['category'])->toBeIn(FileFormats::CATEGORIES, "[$extension] has an unknown category")
            ->and($format['label'])->not->toBe('', "[$extension] has no label")
            ->and($format['mimes'])->not->toBeEmpty("[$extension] has no MIME types")
            ->and($format['stored_mime'])->not->toBe('', "[$extension] has no stored_mime")
            ->and($format['inline_safe'])->toBeBool();

        foreach ($format['mimes'] as $mime) {
            expect($mime)->toMatch('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', "[$extension] has a malformed MIME: $mime");
        }
    }
});

test('extensions are unique', function () {
    $extensions = FileFormats::extensions();

    expect($extensions)->toBe(array_unique($extensions));
});

/**
 * `application/octet-stream` is what libmagic returns when it identifies
 * NOTHING. Listing it for any format would make that format accept a
 * renamed executable, and the content check decorative.
 */
test('no format accepts application/octet-stream as a detected type', function () {
    foreach (FileFormats::all() as $extension => $format) {
        expect($format['mimes'])->not->toContain(
            'application/octet-stream',
            "[$extension] lists octet-stream, which would accept any unidentifiable file",
        );
    }
});

test('no executable or script format is in the registry', function (string $forbidden) {
    expect(FileFormats::isSupported($forbidden))->toBeFalse();
})->with(['exe', 'msi', 'bat', 'cmd', 'sh', 'ps1', 'js', 'php', 'jar', 'apk', 'dll', 'com', 'scr', 'vbs']);

/**
 * The step-2 stored-XSS fix: an author-supplied SVG served as
 * `image/svg+xml` would execute against the session of the admin reviewing
 * it. This must survive the move into the registry.
 */
test('browser-executable formats are stored as octet-stream and never inline', function (string $extension) {
    expect(FileFormats::storedMimeFor($extension))->toBe('application/octet-stream')
        ->and(FileFormats::isInlineSafe($extension))->toBeFalse();
})->with(['svg', 'xml', 'musicxml']);

test('lookups are case- and dot-insensitive', function () {
    expect(FileFormats::isSupported('PDF'))->toBeTrue()
        ->and(FileFormats::isSupported('.pdf'))->toBeTrue()
        ->and(FileFormats::isSupported('  .MOV '))->toBeTrue()
        ->and(FileFormats::storedMimeFor('.MOV'))->toBe('video/quicktime');
});

test('mimeTypesFor returns a sorted, de-duplicated union', function () {
    // jpg and jpeg both carry image/jpeg — it must appear once.
    $mimes = FileFormats::mimeTypesFor(['jpeg', 'jpg', 'pdf']);

    expect($mimes)->toBe(['application/pdf', 'image/jpeg'])
        ->and($mimes)->toBe(array_values(array_unique($mimes)));
});

test('mimeTypesFor ignores an extension the registry does not know', function () {
    expect(FileFormats::mimeTypesFor(['pdf', 'exe', 'nonsense']))->toBe(['application/pdf']);
});

/**
 * The OpenXML formats are ZIP containers. libmagic 545 (this machine)
 * returns the full type; older builds — including production's, which is
 * unverified — return the plain container type. Both must be accepted or a
 * legitimate file is rejected after deploy.
 */
test('OpenXML and ODF formats accept the plain zip container type too', function (string $extension) {
    expect(FileFormats::mimesFor($extension))->toContain('application/zip');
})->with(['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'epub']);

test('grouped() covers every format exactly once, in category order', function () {
    $grouped = FileFormats::grouped();

    $categories = array_column($grouped, 'category');
    expect($categories)->toBe(array_values(array_intersect(FileFormats::CATEGORIES, $categories)));

    $flattened = [];
    foreach ($grouped as $group) {
        foreach ($group['formats'] as $format) {
            $flattened[] = $format['extension'];
        }
    }

    sort($flattened);
    $all = FileFormats::extensions();
    sort($all);

    expect($flattened)->toBe($all);
});

/**
 * There must be exactly ONE list. A second copy in TypeScript drifts the
 * first time a format is added, and drift means the admin can select
 * something the pipeline will reject.
 */
test('the client receives the server list rather than holding its own', function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);

    $admin = User::factory()->withRole('admin')->create();

    $response = $this->actingAs($admin)->get(route('admin.referentiel.documents'));

    $sent = collect($response->viewData('page')['props']['formats'])
        ->flatMap(fn (array $group) => array_column($group['formats'], 'extension'))
        ->sort()
        ->values()
        ->all();

    $registry = FileFormats::extensions();
    sort($registry);

    expect($sent)->toBe($registry);

    // And the TypeScript side defines no competing list of its own.
    $clientSource = file_get_contents(resource_path('js/components/admin/FormatMultiSelect.vue'));
    expect($clientSource)->not->toContain("'docx'")
        ->and($clientSource)->not->toContain("'pdf'");
});

// --- derived mime_types on the seeded rows ------------------------------

test('every seeded row validates against the registry', function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);

    $unknown = [];

    foreach (CollegeOeuvreFile::withTrashed()->get() as $row) {
        foreach ($row->extensions as $extension) {
            if (! FileFormats::isSupported($extension)) {
                $unknown[] = "{$row->document_key}: {$extension}";
            }
        }
    }

    expect($unknown)->toBe([]);
});

test('for all 69 rows, stored mime_types equals the registry derivation', function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);

    $rows = CollegeOeuvreFile::withTrashed()->get();

    expect($rows)->toHaveCount(69);

    $drifted = [];

    foreach ($rows as $row) {
        $derived = FileFormats::mimeTypesFor($row->extensions);

        if ($row->mime_types !== $derived) {
            $drifted[] = $row->document_key;
        }
    }

    // This fails the moment the registry changes without
    // `referentiel:sync-mime-types` being run.
    expect($drifted)->toBe([]);
});

test('the seeder derives mime_types rather than leaving them empty', function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);

    $row = CollegeOeuvreFile::where('document_key', 'justificatif_exploitation')->firstOrFail();

    expect($row->extensions)->toBe(['pdf', 'jpg', 'jpeg', 'png'])
        ->and($row->mime_types)->toBe(['application/pdf', 'image/jpeg', 'image/png']);
});

test('the saving hook recomputes mime_types on every write', function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);

    $row = CollegeOeuvreFile::where('document_key', 'justificatif_exploitation')->firstOrFail();

    // Written directly on the model, bypassing the controller entirely —
    // the hook must still fire.
    $row->extensions = ['mp3', 'wav'];
    $row->save();

    expect($row->fresh()->mime_types)->toBe(FileFormats::mimeTypesFor(['mp3', 'wav']))
        ->and($row->fresh()->mime_types)->toContain('audio/mpeg')
        ->and($row->fresh()->mime_types)->toContain('audio/x-wav');
});

test('the sync command reports and repairs drift', function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);

    $row = CollegeOeuvreFile::where('document_key', 'justificatif_exploitation')->firstOrFail();

    // Simulate registry drift: a row correct when written, now missing an
    // alias. saveQuietly bypasses the hook, which is the only way to get
    // into this state.
    $row->forceFill(['mime_types' => ['application/pdf']])->saveQuietly();

    $this->artisan('referentiel:sync-mime-types --dry-run')->assertSuccessful();

    // --dry-run changed nothing.
    expect($row->fresh()->mime_types)->toBe(['application/pdf']);

    $this->artisan('referentiel:sync-mime-types')->assertSuccessful();

    expect($row->fresh()->mime_types)->toBe(FileFormats::mimeTypesFor($row->extensions));
});
