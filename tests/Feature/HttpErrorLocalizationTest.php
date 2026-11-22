<?php

declare(strict_types=1);

use App\Actions\Oeuvre\RemovalConflict;
use App\Actions\Review\FileDecisionRefused;
use App\Domain\Deposit\Exceptions\IllegalTransition;
use App\Domain\Deposit\Exceptions\ReasonRequired;
use App\Domain\Deposit\Exceptions\RemovalRefused;
use App\Domain\Deposit\Exceptions\ReviewConflict;
use App\Domain\Deposit\Value\SubmissionReason;
use App\Enums\MediaFile\ReviewStatusEnum;
use App\Enums\MediaFile\StatusEnum as MediaFileStatus;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Enums\UploadSession\StatusEnum as UploadSessionStatus;
use App\Models\CollegeOeuvreFile;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\UploadSession;
use App\Models\User;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

/**
 * User-facing HTTP error text of the upload runtime, the review flow, the
 * author's removal/submission flow and the reference-data forms, in the
 * three locales, through the real routes under the `locale` cookie. The
 * expected sentences are typed literally (never read from lang/), so a
 * missing key, an English fallback in ar/fr or a typo fails here.
 */
beforeEach(function () {
    config(['vault.chunk_size' => 32, 'vault.mac_segment_size' => 16]);
    Notification::fake();
});

function helAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('author');

    return $user;
}

function helAdmin(string $name = 'Officer'): User
{
    Role::findOrCreate('admin');
    $user = User::factory()->create(['name' => $name, 'email_verified_at' => now()]);
    $user->assignRole('admin');

    return $user;
}

function helJson(string $locale): object
{
    return test()->withCredentials()->withUnencryptedCookie('locale', $locale);
}

function helSession(User $author, int $size = 64): UploadSession
{
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id]);
    $uuid = test()->actingAs($author)->postJson('/uploads', [
        'oeuvre_id' => $oeuvre->id,
        'filename' => 'movie.mp4',
        'size_bytes' => $size,
        'mime' => 'video/mp4',
    ])->json('uuid');

    return UploadSession::where('uuid', $uuid)->firstOrFail();
}

function helChunk(string $locale, string $uuid, int $index, string $bytes, ?string $crc, string $contentType = 'application/octet-stream'): TestResponse
{
    $server = ['CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/json'];

    if ($crc !== null) {
        $server['HTTP_X_CHUNK_CRC32'] = $crc;
    }

    // call() takes raw cookies (json() adds the default ones itself).
    return test()->call('POST', "/uploads/{$uuid}/chunk/{$index}", [], ['locale' => $locale], [], $server, $bytes);
}

function helOeuvre(?User $holder, OeuvreStatus $status = OeuvreStatus::UNDER_REVIEW, ?User $author = null): Oeuvre
{
    return Oeuvre::factory()->create([
        'author_id' => ($author ?? helAuthor())->id,
        'status' => $status,
        'submitted_at' => $status === OeuvreStatus::DRAFT ? null : now(),
        'reviewed_by' => $holder?->id,
        'reviewed_at' => $holder === null ? null : now(),
        'registered_at' => $status === OeuvreStatus::REGISTERED ? now() : null,
    ]);
}

/** @param  array<string, mixed>  $attributes */
function helFile(Oeuvre $oeuvre, MediaFileStatus $status = MediaFileStatus::READY, array $attributes = []): MediaFile
{
    return MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $oeuvre->author_id,
        'status' => $status,
        ...$attributes,
    ]);
}

// --- upload runtime ------------------------------------------------------

it('localizes a CRC32 mismatch', function (string $locale, string $expected) {
    $uuid = helSession(helAuthor())->uuid;

    helChunk($locale, $uuid, 0, random_bytes(32), 'deadbeef')
        ->assertStatus(422)
        ->assertJsonPath('message', $expected)
        ->assertJsonPath('index', 0);
})->with([
    'en' => ['en', 'CRC32 mismatch for chunk 0; retry this chunk.'],
    'fr' => ['fr', 'Le CRC32 du morceau 0 ne correspond pas ; renvoyez ce morceau.'],
    'ar' => ['ar', 'قيمة CRC32 للجزء 0 غير مطابقة؛ أعيدوا إرسال هذا الجزء.'],
]);

it('localizes a missing CRC32 header', function (string $locale, string $expected) {
    $uuid = helSession(helAuthor())->uuid;

    helChunk($locale, $uuid, 0, random_bytes(32), null)
        ->assertStatus(422)
        ->assertJsonPath('message', $expected);
})->with([
    'en' => ['en', 'Missing X-Chunk-CRC32 header for chunk 0.'],
    'fr' => ['fr', 'L’en-tête X-Chunk-CRC32 est absent pour le morceau 0.'],
    'ar' => ['ar', 'ترويسة X-Chunk-CRC32 مفقودة للجزء 0.'],
]);

it('localizes an out-of-range chunk index', function (string $locale, string $expected) {
    $uuid = helSession(helAuthor())->uuid;
    $bytes = random_bytes(32);

    helChunk($locale, $uuid, 5, $bytes, hash('crc32b', $bytes))
        ->assertStatus(422)
        ->assertJsonPath('message', $expected);
})->with([
    'en' => ['en', 'Chunk index 5 is out of range for this upload (0 to 1).'],
    'fr' => ['fr', 'L’indice de morceau 5 est hors limites pour ce téléversement (de 0 à 1).'],
    'ar' => ['ar', 'رقم الجزء 5 خارج النطاق المسموح به لهذا الرفع (من 0 إلى 1).'],
]);

it('localizes the chunk-length error with Arabic plural forms', function (string $locale, int $total, string $expected) {
    // 32-byte chunks: a final chunk of $total - 32 bytes is expected; send one byte too few or many.
    $uuid = helSession(helAuthor(), $total)->uuid;
    $finalExpected = $total - 32;
    $bytes = random_bytes($finalExpected + 1);

    helChunk($locale, $uuid, 1, $bytes, hash('crc32b', $bytes))
        ->assertStatus(422)
        ->assertJsonPath('message', $expected);
})->with([
    'en 1 byte' => ['en', 33, 'Chunk 1 must be exactly 1 byte (received 2).'],
    'en 5 bytes' => ['en', 37, 'Chunk 1 must be exactly 5 bytes (received 6).'],
    'fr 1 octet' => ['fr', 33, 'Le morceau 1 doit faire exactement 1 octet (reçu : 2).'],
    'fr 32 octets' => ['fr', 64, 'Le morceau 1 doit faire exactement 32 octets (reçu : 33).'],
    'ar 1' => ['ar', 33, 'الجزء 1: يجب أن يكون حجمه بايتًا واحدًا بالضبط (المستلم: 2).'],
    'ar 2' => ['ar', 34, 'الجزء 1: يجب أن يكون حجمه بايتين بالضبط (المستلم: 3).'],
    'ar 5' => ['ar', 37, 'الجزء 1: يجب أن يكون حجمه 5 بايتات بالضبط (المستلم: 6).'],
    'ar 32' => ['ar', 64, 'الجزء 1: يجب أن يكون حجمه 32 بايت بالضبط (المستلم: 33).'],
]);

it('localizes an expired session', function (string $locale, string $expected) {
    $session = helSession(helAuthor());
    $session->forceFill(['expires_at' => now()->subMinute()])->save();
    $bytes = random_bytes(32);

    helChunk($locale, $session->uuid, 0, $bytes, hash('crc32b', $bytes))
        ->assertStatus(410)
        ->assertJsonPath('message', $expected);
})->with([
    'en' => ['en', 'This upload session has expired.'],
    'fr' => ['fr', 'Cette session de téléversement a expiré.'],
    'ar' => ['ar', 'انتهت صلاحية جلسة الرفع هذه.'],
]);

it('localizes a session that no longer accepts chunks', function (string $locale, string $expected) {
    $session = helSession(helAuthor());
    $session->forceFill(['status' => UploadSessionStatus::ABORTED])->save();
    $bytes = random_bytes(32);

    helChunk($locale, $session->uuid, 0, $bytes, hash('crc32b', $bytes))
        ->assertStatus(409)
        ->assertJsonPath('message', $expected);
})->with([
    'en' => ['en', 'This upload session is not accepting chunks (status: Cancelled).'],
    'fr' => ['fr', 'Cette session de téléversement n’accepte plus de morceaux (statut : Annulé).'],
    'ar' => ['ar', 'جلسة الرفع هذه لم تعد تقبل أجزاء جديدة (الحالة: أُلغي).'],
]);

it('localizes a multipart chunk body', function (string $locale, string $expected) {
    $uuid = helSession(helAuthor())->uuid;

    helChunk($locale, $uuid, 0, random_bytes(32), null, 'multipart/form-data; boundary=----XYZ')
        ->assertStatus(415)
        ->assertJsonPath('message', $expected);
})->with([
    'en' => ['en', 'Chunk bodies must be sent as raw application/octet-stream, not multipart/form-data.'],
    'fr' => ['fr', 'Le corps d’un morceau doit être envoyé en application/octet-stream brut, et non en multipart/form-data.'],
    'ar' => ['ar', 'يجب إرسال محتوى الجزء بصيغة application/octet-stream الخام، وليس multipart/form-data.'],
]);

it('localizes a complete call with missing chunks', function (string $locale, string $expected) {
    $uuid = helSession(helAuthor())->uuid;

    helJson($locale)->postJson("/uploads/{$uuid}/complete")
        ->assertStatus(409)
        ->assertJsonPath('message', $expected)
        ->assertJsonPath('missing', [0, 1]);
})->with([
    'en' => ['en', 'This upload is missing chunks and cannot be completed.'],
    'fr' => ['fr', 'Il manque des morceaux à ce téléversement : il ne peut pas être finalisé.'],
    'ar' => ['ar', 'تنقص هذا الرفع بعض الأجزاء، ولا يمكن إكماله.'],
]);

it('localizes the late-upload refusals of the replacement guard', function (string $locale, bool $replacement, string $error, string $expected) {
    $author = helAuthor();
    $session = helSession($author);
    $oeuvre = Oeuvre::findOrFail($session->oeuvre_id);

    if ($replacement) {
        $removed = helFile($oeuvre, MediaFileStatus::READY, ['review_status' => ReviewStatusEnum::REJECTED]);
        $session->forceFill(['replaces_media_file_id' => $removed->id])->save();
        $oeuvre->forceFill(['status' => OeuvreStatus::REGISTERED])->save();
    } else {
        $oeuvre->forceFill(['status' => OeuvreStatus::SUBMITTED])->save();
    }

    $bytes = random_bytes(32);

    helChunk($locale, $session->uuid, 0, $bytes, hash('crc32b', $bytes))
        ->assertStatus(409)
        ->assertJsonPath('error', $error)
        ->assertJsonPath('message', $expected);
})->with([
    'en submitted' => ['en', false, 'oeuvre_submitted', 'This work has been submitted; the file was not added.'],
    'fr submitted' => ['fr', false, 'oeuvre_submitted', 'Cette oeuvre a été soumise ; le fichier n’a pas été ajouté.'],
    'ar submitted' => ['ar', false, 'oeuvre_submitted', 'تم إيداع هذا المصنف؛ لم تتم إضافة الملف.'],
    'en decided' => ['en', true, 'oeuvre_decided', 'This work has been decided; the file was not added.'],
    'fr decided' => ['fr', true, 'oeuvre_decided', 'Cette oeuvre a fait l’objet d’une décision ; le fichier n’a pas été ajouté.'],
    'ar decided' => ['ar', true, 'oeuvre_decided', 'تم البتّ في هذا المصنف؛ لم تتم إضافة الملف.'],
]);

// --- review: per-file decisions -----------------------------------------

function helDecide(string $locale, User $admin, Oeuvre $oeuvre, MediaFile $file, string $decision, ?string $reason = null): TestResponse
{
    return test()->actingAs($admin)->withCredentials()->withUnencryptedCookie('locale', $locale)->postJson(
        route('admin.oeuvres.files.decide', ['oeuvre' => $oeuvre, 'mediaFile' => $file]),
        array_filter(['decision' => $decision, 'reason' => $reason], fn ($v) => $v !== null),
    );
}

it('localizes the accept-needs-ready refusal', function (string $locale, string $expected) {
    $admin = helAdmin();
    $oeuvre = helOeuvre($admin);

    helDecide($locale, $admin, $oeuvre, helFile($oeuvre, MediaFileStatus::FAILED), 'accepted')
        ->assertUnprocessable()
        ->assertJsonPath('errors.decision.0', $expected);
})->with([
    'en' => ['en', 'Only a ready file can be accepted; this file is "Failed".'],
    'fr' => ['fr', 'Seul un fichier prêt peut être accepté ; ce fichier est « Échec ».'],
    'ar' => ['ar', 'لا يمكن قبول إلا الملف الجاهز؛ وحالة هذا الملف «فشل».'],
]);

it('localizes the decide-needs-finished refusal', function (string $locale, string $expected) {
    $admin = helAdmin();
    $oeuvre = helOeuvre($admin);

    helDecide($locale, $admin, $oeuvre, helFile($oeuvre, MediaFileStatus::PROCESSING), 'rejected', 'Le document est illisible, merci de le déposer à nouveau.')
        ->assertUnprocessable()
        ->assertJsonPath('errors.decision.0', $expected);
})->with([
    'en' => ['en', 'This file is still "Preparing"; it can be decided once the pipeline has finished with it.'],
    'fr' => ['fr', 'Ce fichier est encore « Préparation » ; il pourra faire l’objet d’une décision une fois son traitement terminé.'],
    'ar' => ['ar', 'حالة هذا الملف ما زالت «جارٍ التحضير»؛ يمكن البتّ فيه بعد انتهاء معالجته.'],
]);

it('localizes the not-under-review refusal of a file decision', function (string $locale, string $expected) {
    $admin = helAdmin();
    $oeuvre = helOeuvre(null, OeuvreStatus::SUBMITTED);

    helDecide($locale, $admin, $oeuvre, helFile($oeuvre), 'accepted')
        ->assertUnprocessable()
        ->assertJsonPath('errors.decision.0', $expected);
})->with([
    'en' => ['en', 'This work is not under review; open it for review before deciding on its files.'],
    'fr' => ['fr', 'Cette oeuvre n’est pas en cours d’examen ; ouvrez son examen avant de statuer sur ses fichiers.'],
    'ar' => ['ar', 'هذا المصنف ليس قيد المراجعة؛ افتحوا مراجعته قبل البتّ في ملفاته.'],
]);

it('localizes the held-by conflict of a file decision', function (string $locale, string $expected) {
    $amina = helAdmin('Amina');
    $karim = helAdmin('Karim');
    $oeuvre = helOeuvre($amina);

    helDecide($locale, $karim, $oeuvre, helFile($oeuvre), 'accepted')
        ->assertStatus(409)
        ->assertJsonPath('message', $expected)
        ->assertJsonPath('conflict.kind', 'held');
})->with([
    'en' => ['en', 'Amina is already reviewing this work. Take it over explicitly to decide on it.'],
    'fr' => ['fr', 'Amina examine déjà cette oeuvre. Reprenez-la explicitement pour statuer.'],
    'ar' => ['ar', 'Amina يراجع هذا المصنف بالفعل. استلموه صراحةً لتتمكنوا من البتّ فيه.'],
]);

it('localizes the already-decided conflict of a file decision', function (string $locale, string $expected) {
    $amina = helAdmin('Amina');
    $karim = helAdmin('Karim');
    $oeuvre = helOeuvre($amina, OeuvreStatus::REGISTERED);

    helDecide($locale, $karim, $oeuvre, helFile($oeuvre), 'accepted')
        ->assertStatus(409)
        ->assertJsonPath('message', $expected)
        ->assertJsonPath('conflict.kind', 'decided');
})->with([
    'en' => ['en', 'Amina has already decided on this work.'],
    'fr' => ['fr', 'Amina a déjà statué sur cette oeuvre.'],
    'ar' => ['ar', 'سبق لـ Amina أن بتّ في هذا المصنف.'],
]);

// --- review: registration refusals --------------------------------------

it('localizes the approval refusal and its blocker for a work with no file', function (string $locale, string $message, string $blocker) {
    $admin = helAdmin();
    $oeuvre = helOeuvre($admin);

    helJson($locale)->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))
        ->assertUnprocessable()
        ->assertJsonPath('message', $message)
        ->assertJsonPath('errors.approval.0', $blocker)
        ->assertJsonPath('problems.0.message', $blocker);
})->with([
    'en' => ['en', 'This work cannot be registered yet.', 'This work has no files yet.'],
    'fr' => ['fr', 'Cette oeuvre ne peut pas encore être enregistrée.', 'Cette oeuvre ne contient encore aucun fichier.'],
    'ar' => ['ar', 'لا يمكن تسجيل هذا المصنف بعد.', 'لا يحتوي هذا المصنف على أي ملف بعد.'],
]);

it('localizes the files-not-accepted refusal', function (string $locale, string $message, string $line, string $problem) {
    $admin = helAdmin();
    $oeuvre = helOeuvre($admin);
    helFile($oeuvre, MediaFileStatus::READY, ['original_name' => 'score.pdf']);

    helJson($locale)->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))
        ->assertUnprocessable()
        ->assertJsonPath('message', $message)
        ->assertJsonPath('errors.approval.0', $line)
        ->assertJsonPath('problems.0.message', $problem);
})->with([
    'en' => ['en', 'Every file must be accepted before this work can be registered.', 'score.pdf (To review)', 'score.pdf has not been accepted.'],
    'fr' => ['fr', 'Tous les fichiers doivent être acceptés avant l’enregistrement de cette oeuvre.', 'score.pdf (À examiner)', '« score.pdf » n’a pas été accepté.'],
    'ar' => ['ar', 'يجب قبول جميع الملفات قبل تسجيل هذا المصنف.', 'score.pdf (قيد الانتظار)', 'لم يتم قبول الملف «score.pdf».'],
]);

// --- author: removal, deletion, submission ------------------------------

it('localizes the removal conflict on a decided work', function (string $locale, string $expected) {
    $author = helAuthor();
    $oeuvre = helOeuvre(helAdmin(), OeuvreStatus::REGISTERED, $author);
    $file = helFile($oeuvre, MediaFileStatus::READY, ['review_status' => ReviewStatusEnum::REJECTED]);

    helJson($locale)->actingAs($author)->deleteJson(route('oeuvres.files.destroy', $file))
        ->assertStatus(409)
        ->assertJsonPath('error', 'oeuvre_decided')
        ->assertJsonPath('message', $expected);
})->with([
    'en' => ['en', 'This work has been decided; its files can no longer be changed.'],
    'fr' => ['fr', 'Cette oeuvre a fait l’objet d’une décision ; ses fichiers ne peuvent plus être modifiés.'],
    'ar' => ['ar', 'تم البتّ في هذا المصنف؛ ولم يعد بالإمكان تعديل ملفاته.'],
]);

it('localizes the removal conflict on a replacement accepted since the page loaded', function (string $locale, string $expected) {
    $author = helAuthor();
    $oeuvre = helOeuvre(helAdmin(), OeuvreStatus::UNDER_REVIEW, $author);
    $removed = helFile($oeuvre, MediaFileStatus::READY, ['review_status' => ReviewStatusEnum::REJECTED]);
    $replacement = helFile($oeuvre, MediaFileStatus::READY, [
        'review_status' => ReviewStatusEnum::ACCEPTED,
        'replaces_media_file_id' => $removed->id,
    ]);

    helJson($locale)->actingAs($author)->deleteJson(route('oeuvres.files.destroy', $replacement))
        ->assertStatus(409)
        ->assertJsonPath('error', 'file_not_removable')
        ->assertJsonPath('message', $expected);
})->with([
    'en' => ['en', 'This file has just been accepted by the ONDA; it can no longer be removed.'],
    'fr' => ['fr', 'Ce fichier vient d’être accepté par l’ONDA ; il ne peut plus être retiré.'],
    'ar' => ['ar', 'قبل الديوان هذا الملف للتو؛ ولا يمكن حذفه بعد الآن.'],
]);

it('localizes the removal refusal of a file still in the pipeline', function (string $locale, string $expected) {
    $author = helAuthor();
    $oeuvre = helOeuvre(null, OeuvreStatus::DRAFT, $author);
    $file = helFile($oeuvre, MediaFileStatus::PROCESSING, ['original_name' => 'score.pdf']);

    helJson($locale)->actingAs($author)->deleteJson(route('oeuvres.files.destroy', $file))
        ->assertUnprocessable()
        ->assertJsonPath('errors.media_file.0', $expected);
})->with([
    'en' => ['en', '"score.pdf" is still being processed (Preparing). Wait for it to finish, then remove it.'],
    'fr' => ['fr', '« score.pdf » est encore en cours de traitement (Préparation). Attendez la fin du traitement, puis retirez-le.'],
    'ar' => ['ar', 'الملف «score.pdf» قيد المعالجة (جارٍ التحضير). انتظروا اكتمال المعالجة ثم احذفوه.'],
]);

it('localizes the submission blocker of an empty draft', function (string $locale, string $expected) {
    $author = helAuthor();
    $oeuvre = helOeuvre(null, OeuvreStatus::DRAFT, $author);

    helJson($locale)->actingAs($author)->postJson(route('author.oeuvres.submit', $oeuvre))
        ->assertUnprocessable()
        ->assertJsonPath('errors.submission.0', $expected);
})->with([
    'en' => ['en', 'This work has no files yet.'],
    'fr' => ['fr', 'Cette oeuvre ne contient encore aucun fichier.'],
    'ar' => ['ar', 'لا يحتوي هذا المصنف على أي ملف بعد.'],
]);

// --- stream endpoints -----------------------------------------------------

it('localizes the disabled-downloads refusal', function (string $locale, string $expected) {
    config(['vault.download.owner_enabled' => false]);
    $author = helAuthor();
    $oeuvre = helOeuvre(null, OeuvreStatus::DRAFT, $author);
    $file = helFile($oeuvre);

    helJson($locale)->actingAs($author)->getJson(route('media.link', $file))
        ->assertForbidden()
        ->assertJsonPath('message', $expected);
})->with([
    'en' => ['en', 'Downloading originals is disabled.'],
    'fr' => ['fr', 'Le téléchargement des originaux est désactivé.'],
    'ar' => ['ar', 'تنزيل النسخ الأصلية معطَّل.'],
]);

// --- reference-data forms ---------------------------------------------------

it('localizes the extension validation messages of a required document', function (string $locale, array $extensions, string $field, string $expected) {
    test()->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
    $document = CollegeOeuvreFile::where('document_key', 'justificatif_exploitation')->firstOrFail();

    $response = helJson($locale)->actingAs(helAdmin())
        ->patchJson(route('admin.referentiel.documents.update', $document), ['extensions' => $extensions])
        ->assertUnprocessable();

    // The field name carries a dot ("extensions.0"): read the bag directly.
    expect($response->json('errors')[$field][0])->toBe($expected);
})->with([
    'en shape' => ['en', ['PDF'], 'extensions.0', 'Each extension must be lowercase letters or digits only — no dot, no MIME type, no spaces (e.g. "pdf", not ".PDF" or "application/pdf").'],
    'fr shape' => ['fr', ['PDF'], 'extensions.0', 'Chaque extension doit contenir uniquement des lettres minuscules ou des chiffres — sans point, sans type MIME, sans espace (par exemple « pdf », et non « .PDF » ou « application/pdf »).'],
    'ar shape' => ['ar', ['PDF'], 'extensions.0', 'يجب أن يتكوّن كل امتداد من أحرف لاتينية صغيرة أو أرقام فقط، دون نقطة ودون نوع MIME ودون مسافات (مثلًا «pdf» وليس «.PDF» أو «application/pdf»).'],
    'en registry' => ['en', ['exe'], 'extensions.0', 'That format is not one this application can accept. Executable and script formats are deliberately absent from the registry.'],
    'fr registry' => ['fr', ['exe'], 'extensions.0', 'Ce format ne peut pas être accepté par l’application. Les formats exécutables et de script sont volontairement absents du registre.'],
    'ar registry' => ['ar', ['exe'], 'extensions.0', 'لا يمكن للتطبيق قبول هذه الصيغة. الصيغ التنفيذية وصيغ البرمجة النصية غائبة عن السجلّ عمدًا.'],
    'en duplicate' => ['en', ['pdf', 'pdf'], 'extensions', 'The same extension is listed more than once.'],
    'fr duplicate' => ['fr', ['pdf', 'pdf'], 'extensions', 'La même extension est indiquée plusieurs fois.'],
    'ar duplicate' => ['ar', ['pdf', 'pdf'], 'extensions', 'تكرّر الامتداد نفسه أكثر من مرة.'],
]);

// --- domain exceptions: branches the routes cannot reach ----------------------

it('localizes the domain refusals the routes cannot provoke', function (string $locale, array $expected) {
    app()->setLocale($locale);

    expect(IllegalTransition::between(OeuvreStatus::DRAFT, OeuvreStatus::REGISTERED)->getMessage())->toBe($expected['between'])
        ->and(IllegalTransition::terminal(OeuvreStatus::REGISTERED, OeuvreStatus::DRAFT)->getMessage())->toBe($expected['terminal'])
        ->and(IllegalTransition::actor(OeuvreStatus::SUBMITTED, OeuvreStatus::UNDER_REVIEW)->getMessage())->toBe($expected['actor'])
        ->and((new ReasonRequired)->getMessage())->toBe($expected['reason'])
        ->and(RemovalRefused::stillUploading('a.pdf')->getMessage())->toBe($expected['uploading'])
        ->and(RemovalConflict::noLongerRejected()->getMessage())->toBe($expected['no_longer'])
        ->and(FileDecisionRefused::removed()->getMessage())->toBe($expected['removed'])
        ->and(FileDecisionRefused::foreignFile()->getMessage())->toBe($expected['foreign'])
        ->and(ReviewConflict::statusMoved(OeuvreStatus::REGISTERED)->getMessage())->toBe($expected['moved']);
})->with([
    'en' => ['en', [
        'between' => 'This work cannot move from "Draft" to "Registered".',
        'terminal' => 'This work is registered. A registered deposit is final and cannot move to "Draft".',
        'actor' => 'You are not allowed to move this work from "Pending" to "Under review".',
        'reason' => 'A rejection must carry a reason for the author.',
        'uploading' => '"a.pdf" is still uploading. Cancel the upload instead of removing it.',
        'no_longer' => 'This file is no longer rejected; it can no longer be removed.',
        'removed' => 'This file was removed by the author; it can no longer be decided.',
        'foreign' => 'This file does not belong to this work.',
        'moved' => 'This work is now "Registered". Reload before deciding on it.',
    ]],
    'fr' => ['fr', [
        'between' => 'Cette oeuvre ne peut pas passer de « Brouillon » à « Enregistrée ».',
        'terminal' => 'Cette oeuvre est enregistrée. Un dépôt enregistré est définitif et ne peut pas passer à « Brouillon ».',
        'actor' => 'Vous n’êtes pas autorisé à faire passer cette oeuvre de « En attente » à « En examen ».',
        'reason' => 'Un rejet doit comporter un motif pour l’auteur.',
        'uploading' => '« a.pdf » est encore en cours de téléversement. Annulez le téléversement au lieu de le retirer.',
        'no_longer' => 'Ce fichier n’est plus rejeté ; il ne peut plus être retiré.',
        'removed' => 'Ce fichier a été retiré par l’auteur ; il ne peut plus faire l’objet d’une décision.',
        'foreign' => 'Ce fichier n’appartient pas à cette oeuvre.',
        'moved' => 'Cette oeuvre est maintenant « Enregistrée ». Rechargez la page avant de statuer.',
    ]],
    'ar' => ['ar', [
        'between' => 'لا يمكن نقل هذا المصنف من حالة «مسودة» إلى حالة «مسجَّل».',
        'terminal' => 'هذا المصنف مسجَّل. الإيداع المسجَّل نهائي ولا يمكن نقله إلى حالة «مسودة».',
        'actor' => 'غير مسموح لكم بنقل هذا المصنف من حالة «قيد الانتظار» إلى حالة «قيد المراجعة».',
        'reason' => 'يجب أن يتضمن الرفض سببًا يُبلَّغ به المؤلف.',
        'uploading' => 'الملف «a.pdf» قيد الرفع. ألغوا الرفع بدلًا من حذف الملف.',
        'no_longer' => 'لم يعد هذا الملف مرفوضًا؛ ولا يمكن حذفه بعد الآن.',
        'removed' => 'حذف المؤلف هذا الملف؛ ولم يعد بالإمكان البتّ فيه.',
        'foreign' => 'هذا الملف لا يخص هذا المصنف.',
        'moved' => 'أصبحت حالة هذا المصنف «مسجَّل». أعيدوا تحميل الصفحة قبل البتّ فيه.',
    ]],
]);

it('localizes every submission reason', function (string $locale, array $expected) {
    app()->setLocale($locale);
    $name = 'score.pdf';

    $messages = [
        SubmissionReason::NO_FILES => new SubmissionReason(SubmissionReason::NO_FILES),
        SubmissionReason::FILE_IN_PIPELINE => new SubmissionReason(SubmissionReason::FILE_IN_PIPELINE, ['name' => $name, 'status' => 'processing']),
        SubmissionReason::FILE_FAILED => new SubmissionReason(SubmissionReason::FILE_FAILED, ['name' => $name]),
        SubmissionReason::FILE_QUARANTINED => new SubmissionReason(SubmissionReason::FILE_QUARANTINED, ['name' => $name]),
        SubmissionReason::FILE_REJECTED => new SubmissionReason(SubmissionReason::FILE_REJECTED, ['name' => $name]),
        SubmissionReason::SLOT_EMPTY => new SubmissionReason(SubmissionReason::SLOT_EMPTY, ['name' => 'Paroles']),
        SubmissionReason::SLOT_EMPTY_CONDITIONAL => new SubmissionReason(SubmissionReason::SLOT_EMPTY_CONDITIONAL, ['name' => 'Paroles']),
    ];

    foreach ($expected as $code => $text) {
        expect($messages[$code]->message())->toBe($text);
    }
})->with([
    'en' => ['en', [
        SubmissionReason::NO_FILES => 'This work has no files yet.',
        SubmissionReason::FILE_IN_PIPELINE => '"score.pdf" is still being processed (Preparing). Wait for it to finish.',
        SubmissionReason::FILE_FAILED => '"score.pdf" failed to process. Remove it or upload it again.',
        SubmissionReason::FILE_QUARANTINED => '"score.pdf" was quarantined by the malware scan. Remove it.',
        SubmissionReason::FILE_REJECTED => '"score.pdf" was rejected by the ONDA. Remove it and upload a compliant file.',
        SubmissionReason::SLOT_EMPTY => 'The required document "Paroles" has no file yet.',
        SubmissionReason::SLOT_EMPTY_CONDITIONAL => 'The document "Paroles" is empty; it may not apply to this work.',
    ]],
    'fr' => ['fr', [
        SubmissionReason::NO_FILES => 'Cette oeuvre ne contient encore aucun fichier.',
        SubmissionReason::FILE_IN_PIPELINE => '« score.pdf » est encore en cours de traitement (Préparation). Attendez la fin du traitement.',
        SubmissionReason::FILE_FAILED => 'Le traitement de « score.pdf » a échoué. Retirez-le ou téléversez-le de nouveau.',
        SubmissionReason::FILE_QUARANTINED => '« score.pdf » a été mis en quarantaine par l’analyse antivirus. Retirez-le.',
        SubmissionReason::FILE_REJECTED => '« score.pdf » a été rejeté par l’ONDA. Retirez-le et téléversez un fichier conforme.',
        SubmissionReason::SLOT_EMPTY => 'Le document requis « Paroles » n’a pas encore de fichier.',
        SubmissionReason::SLOT_EMPTY_CONDITIONAL => 'Le document « Paroles » est vide ; il ne s’applique peut-être pas à cette oeuvre.',
    ]],
    'ar' => ['ar', [
        SubmissionReason::NO_FILES => 'لا يحتوي هذا المصنف على أي ملف بعد.',
        SubmissionReason::FILE_IN_PIPELINE => 'الملف «score.pdf» قيد المعالجة (جارٍ التحضير). انتظروا اكتمال المعالجة.',
        SubmissionReason::FILE_FAILED => 'فشلت معالجة الملف «score.pdf». احذفوه أو أعيدوا رفعه.',
        SubmissionReason::FILE_QUARANTINED => 'تم عزل الملف «score.pdf» بواسطة فحص البرمجيات الخبيثة. احذفوه.',
        SubmissionReason::FILE_REJECTED => 'رفض الديوان الوطني لحقوق المؤلف والحقوق المجاورة الملف «score.pdf». احذفوه وارفعوا ملفًا مطابقًا.',
        SubmissionReason::SLOT_EMPTY => 'الوثيقة المطلوبة «Paroles» لا تحتوي على ملف بعد.',
        SubmissionReason::SLOT_EMPTY_CONDITIONAL => 'الوثيقة «Paroles» فارغة؛ وقد لا تنطبق على هذا المصنف.',
    ]],
]);

// --- parity -----------------------------------------------------------------

it('keeps the same keys and placeholders in en, fr and ar for every runtime lang file', function (string $file) {
    $load = fn (string $locale): array => require lang_path("{$locale}/{$file}.php");
    $placeholders = fn (string $text): array => collect(preg_match_all('/:[a-z_]+/', $text, $m) ? $m[0] : [])->unique()->sort()->values()->all();

    $en = $load('en');

    foreach (['fr', 'ar'] as $locale) {
        $other = $load($locale);

        expect(array_keys($other))->toBe(array_keys($en), "[{$file}] key set differs in {$locale}");

        foreach ($en as $key => $text) {
            expect($placeholders($other[$key]))->toBe($placeholders($text), "[{$file}.{$key}] placeholders differ in {$locale}");
            expect($other[$key])->not->toBe('', "[{$file}.{$key}] is empty in {$locale}");
        }
    }
})->with(['upload_runtime', 'deposit', 'review', 'errors', 'toast', 'referentiel']);
