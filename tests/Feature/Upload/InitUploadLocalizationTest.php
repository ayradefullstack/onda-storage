<?php

declare(strict_types=1);

use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Models\CollegeOeuvreFile;
use App\Models\Oeuvre;
use App\Models\RegisterTypeCollege;
use App\Models\UploadSession;
use App\Models\User;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

/**
 * Every user-facing 422 of POST /uploads, through the real route, under the
 * application's normal locale selection (the `locale` cookie on this
 * non-prefixed route). The expected sentences are typed literally per
 * locale — never read from lang/ — so an English fallback in ar or fr, or a
 * typo in a lang file, fails here.
 */
beforeEach(function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
});

function iulAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create();
    $user->assignRole('author');

    return $user;
}

function iulOeuvre(User $author, string $codeCollege, ?OeuvreStatus $status = null): Oeuvre
{
    $college = RegisterTypeCollege::where('code_college', $codeCollege)->firstOrFail();

    return Oeuvre::factory()->create(array_filter([
        'author_id' => $author->id,
        'register_type_id' => $college->register_type_id,
        'type_gestion_id' => $college->type_gestion_id,
        'register_type_college_id' => $college->id,
        'code_college_snapshot' => $college->code_college,
        'status' => $status,
    ]));
}

function iulSlot(string $codeCollege, string $key): CollegeOeuvreFile
{
    return RegisterTypeCollege::where('code_college', $codeCollege)->firstOrFail()
        ->collegeOeuvreFiles()->where('document_key', $key)->firstOrFail();
}

function iulInit(string $locale, Oeuvre $oeuvre, ?int $slotId, string $filename, int $size): TestResponse
{
    return test()->withCredentials()->withUnencryptedCookie('locale', $locale)->postJson('/uploads', [
        'oeuvre_id' => $oeuvre->id,
        'college_oeuvre_file_id' => $slotId,
        'filename' => $filename,
        'size_bytes' => $size,
        'mime' => 'application/octet-stream',
    ]);
}

it('localizes the slot-required error', function (string $locale, string $expected) {
    $author = iulAuthor();
    $oeuvre = iulOeuvre($author, 'MUSIQUE');
    $this->actingAs($author);

    iulInit($locale, $oeuvre, null, 'paroles.pdf', 100)
        ->assertUnprocessable()
        ->assertJsonPath('errors.college_oeuvre_file_id.0', $expected);
})->with([
    'en' => ['en', 'Choose which required document this file is for.'],
    'fr' => ['fr', 'Choisissez le document requis auquel ce fichier correspond.'],
    'ar' => ['ar', 'اختاروا الوثيقة المطلوبة التي يخصّها هذا الملف.'],
]);

it('localizes the slot-of-another-college error', function (string $locale, string $expected) {
    $author = iulAuthor();
    $oeuvre = iulOeuvre($author, 'MUSIQUE');
    $foreign = iulSlot('DRAMATIQUE', 'oeuvre_dramatique');
    $this->actingAs($author);

    iulInit($locale, $oeuvre, $foreign->id, 'x.pdf', 100)
        ->assertUnprocessable()
        ->assertJsonPath('errors.college_oeuvre_file_id.0', $expected);
})->with([
    'en' => ['en', "This document is not a requirement of this work's collège."],
    'fr' => ['fr', 'Ce document n’est pas une pièce requise pour le collège de cette oeuvre.'],
    'ar' => ['ar', 'هذه الوثيقة ليست من الوثائق المطلوبة لكلية هذا المصنف.'],
]);

it('localizes the extension error for MUSIQUE / paroles and lists PDF, MP4 and MOV', function (string $locale, string $expected) {
    $author = iulAuthor();
    $oeuvre = iulOeuvre($author, 'MUSIQUE');
    $this->actingAs($author);

    iulInit($locale, $oeuvre, iulSlot('MUSIQUE', 'paroles')->id, 'paroles.mp3', 100)
        ->assertUnprocessable()
        ->assertJsonPath('errors.filename.0', $expected);

    expect(UploadSession::count())->toBe(0);
})->with([
    'en' => ['en', 'The file type ".mp3" is not accepted for this document. Accepted formats: PDF, MP4, MOV.'],
    'fr' => ['fr', 'Le type de fichier « .mp3 » n’est pas accepté pour ce document. Formats acceptés : PDF, MP4, MOV.'],
    'ar' => ['ar', 'نوع الملف «.mp3» غير مقبول لهذه الوثيقة. الصيغ المقبولة: PDF, MP4, MOV.'],
]);

it('localizes the size-limit error with the substituted limit', function (string $locale, string $expected) {
    $author = iulAuthor();
    $oeuvre = iulOeuvre($author, 'ARTS_GRAPHIQUES');
    $slot = iulSlot('ARTS_GRAPHIQUES', 'oeuvres_plastiques_graphiques');
    expect($slot->max_size_kb)->toBe(102400);
    $this->actingAs($author);

    iulInit($locale, $oeuvre, $slot->id, 'plan.'.$slot->extensions[0], 102400 * 1024 + 1)
        ->assertUnprocessable()
        ->assertJsonPath('errors.size_bytes.0', $expected);
})->with([
    'en' => ['en', 'This file exceeds the 100 MB limit for this document.'],
    'fr' => ['fr', 'Ce fichier dépasse la limite de 100 Mo pour ce document.'],
    'ar' => ['ar', 'يتجاوز هذا الملف الحد الأقصى المسموح به لهذه الوثيقة وهو 100 ميغابايت.'],
]);

it('localizes the submitted-oeuvre refusal', function (string $locale, string $expected) {
    $author = iulAuthor();
    $oeuvre = iulOeuvre($author, 'MUSIQUE', OeuvreStatus::SUBMITTED);
    $this->actingAs($author);

    iulInit($locale, $oeuvre, iulSlot('MUSIQUE', 'paroles')->id, 'paroles.pdf', 100)
        ->assertUnprocessable()
        ->assertJsonPath('errors.oeuvre_id.0', $expected);
})->with([
    'en' => ['en', 'This work has been submitted and can no longer receive files.'],
    'fr' => ['fr', 'Cette oeuvre a été soumise et ne peut plus recevoir de fichiers.'],
    'ar' => ['ar', 'تم إيداع هذا المصنف ولم يعد بالإمكان إضافة ملفات إليه.'],
]);

it('still admits pdf, mp4 and mov into MUSIQUE / paroles in every locale', function (string $locale, string $filename) {
    $author = iulAuthor();
    $oeuvre = iulOeuvre($author, 'MUSIQUE');
    $this->actingAs($author);

    iulInit($locale, $oeuvre, iulSlot('MUSIQUE', 'paroles')->id, $filename, 100)->assertCreated();
})->with(function () {
    foreach (['ar', 'fr', 'en'] as $locale) {
        foreach (['paroles.pdf', 'paroles.mp4', 'paroles.mov'] as $file) {
            yield "$locale $file" => [$locale, $file];
        }
    }
});
