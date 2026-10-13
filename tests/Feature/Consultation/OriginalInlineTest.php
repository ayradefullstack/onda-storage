<?php

declare(strict_types=1);

use App\Actions\Consultation\ConsultationDescriptor;
use App\Actions\Consultation\GenerateDerivative;
use App\Actions\Consultation\IssueOriginalUrl;
use App\Domain\Access\SignedMediaUrl;
use App\Domain\Deposit\Value\RowHash;
use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Value\UploadIntent;
use App\Infrastructure\Render\DeliveryMode;
use App\Infrastructure\Render\PreviewFamily;
use App\Infrastructure\Render\PreviewFamilyResolver;
use App\Jobs\GenerateConsultationDerivative;
use App\Models\FileAccessLog;
use App\Models\MediaConsultation;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

require_once __DIR__.'/helpers.php';

/** 100 distinguishable bytes: byte i is chr(33 + i % 90). */
function originalBody(int $length = 100): string
{
    $out = '';

    for ($i = 0; $i < $length; $i++) {
        $out .= chr(33 + $i % 90);
    }

    return $out;
}

beforeEach(function () {
    $this->admin = consultAdmin();
    $this->author = consultAuthor();
    $this->oeuvre = Oeuvre::factory()->create(['author_id' => $this->author->id]);
    $this->files = [];
    $this->body = originalBody();
    $this->file = consultFile($this->author, $this->oeuvre, $this->body, 'acte.pdf', 'application/pdf');
    $this->files[] = $this->file;
    consultGenerate($this->file);
});

afterEach(function () {
    consultCleanup(...$this->files);
});

function originalUrl(object $test, $admin = null, $oeuvre = null, $file = null): string
{
    return app(IssueOriginalUrl::class)->handle($oeuvre ?? $test->oeuvre, $file ?? $test->file, $admin ?? $test->admin);
}

// --- the delivery map ---------------------------------------------------------

test('the default delivery map matches the approved table', function (PreviewFamily $family, string $ext, DeliveryMode $mode) {
    expect(app(PreviewFamilyResolver::class)->deliveryMode($family, $ext))->toBe($mode);
})->with([
    'pdf' => [PreviewFamily::Pdf, 'pdf', DeliveryMode::OriginalInline],
    'docx' => [PreviewFamily::Document, 'docx', DeliveryMode::OriginalInline],
    'doc' => [PreviewFamily::Document, 'doc', DeliveryMode::Unsupported],
    'odt' => [PreviewFamily::Document, 'odt', DeliveryMode::Unsupported],
    'rtf' => [PreviewFamily::Document, 'rtf', DeliveryMode::Unsupported],
    'video' => [PreviewFamily::Video, 'mp4', DeliveryMode::OriginalInline],
    'audio' => [PreviewFamily::Audio, 'mp3', DeliveryMode::OriginalInline],
    'image' => [PreviewFamily::Image, 'png', DeliveryMode::Derivative],
    'spreadsheet' => [PreviewFamily::Spreadsheet, 'xlsx', DeliveryMode::Derivative],
    'csv' => [PreviewFamily::Csv, 'csv', DeliveryMode::Derivative],
    'text' => [PreviewFamily::Text, 'txt', DeliveryMode::Derivative],
    'pptx' => [PreviewFamily::Presentation, 'pptx', DeliveryMode::Derivative],
    'ppt' => [PreviewFamily::Presentation, 'ppt', DeliveryMode::Unsupported],
    'odp' => [PreviewFamily::Presentation, 'odp', DeliveryMode::Unsupported],
    'archive' => [PreviewFamily::Archive, 'zip', DeliveryMode::Unsupported],
    'other' => [PreviewFamily::Other, 'exe', DeliveryMode::Unsupported],
]);

test('an unknown or missing delivery entry fails closed to unsupported, never to an original', function () {
    config(['vault.consult.delivery' => ['pdf' => 'download_please']]);

    $resolver = app(PreviewFamilyResolver::class);

    expect($resolver->deliveryMode(PreviewFamily::Pdf, 'pdf'))->toBe(DeliveryMode::Unsupported)
        ->and($resolver->deliveryMode(PreviewFamily::Video, 'mp4'))->toBe(DeliveryMode::Unsupported);
});

test('a family:ext key overrides the bare family key', function () {
    config(['vault.consult.delivery' => ['document' => 'unsupported', 'document:odt' => 'derivative']]);

    $resolver = app(PreviewFamilyResolver::class);

    expect($resolver->deliveryMode(PreviewFamily::Document, 'odt'))->toBe(DeliveryMode::Derivative)
        ->and($resolver->deliveryMode(PreviewFamily::Document, 'doc'))->toBe(DeliveryMode::Unsupported);
});

test('inline MIME is the registry-verified stored type of the four families, nothing else', function () {
    $resolver = app(PreviewFamilyResolver::class);
    $make = function (string $ext, string $mime): MediaFile {
        $file = new MediaFile;
        $file->extension = $ext;
        $file->mime = $mime;

        return $file;
    };

    expect($resolver->inlineMime($make('pdf', 'application/pdf')))->toBe('application/pdf')
        ->and($resolver->inlineMime($make('mp4', 'video/mp4')))->toBe('video/mp4')
        ->and($resolver->inlineMime($make('mp3', 'audio/mpeg')))->toBe('audio/mpeg')
        ->and($resolver->inlineMime($make('docx', 'application/zip')))->toBe(PreviewFamilyResolver::DOCX_MIME)
        // not one of the four families
        ->and($resolver->inlineMime($make('png', 'image/png')))->toBeNull()
        ->and($resolver->inlineMime($make('svg', 'image/svg+xml')))->toBeNull()
        // a MIME the registry does not accept for that extension
        ->and($resolver->inlineMime($make('pdf', 'text/html')))->toBeNull()
        ->and($resolver->inlineMime($make('mp4', 'video/x-msvideo')))->toBeNull()
        ->and($resolver->inlineMime($make('nope', 'video/mp4')))->toBeNull();
});

// --- the pipeline -------------------------------------------------------------

test('an original_inline family is ready with no assets, and nothing is decrypted', function () {
    // The deposit goes through the REAL vault; only generation is watched.
    $file = consultFile($this->author, $this->oeuvre, 'plain video bytes', 'clip.mp4', 'video/mp4');
    $this->files[] = $file;

    $vault = Mockery::mock(VaultContract::class);
    $vault->shouldNotReceive('decryptToTemp');
    app()->instance(VaultContract::class, $vault);

    $consultation = app(GenerateDerivative::class)->handle($file);

    expect($consultation->status)->toBe('ready')
        ->and($consultation->family)->toBe('video')
        ->and($consultation->reason)->toBeNull()
        ->and($file->consultationAssets()->count())->toBe(0);
});

test('vault:generate-previews --all --force turns tool_missing rows of these families into ready', function () {
    Queue::fake();

    $consultation = MediaConsultation::forFile($this->file);
    $consultation->family = 'pdf';
    $consultation->status = MediaConsultation::UNSUPPORTED;
    $consultation->reason = 'tool_missing:pdftoppm';
    $consultation->save();

    $this->artisan('vault:generate-previews', ['--all' => true, '--force' => true])->assertSuccessful();

    Queue::assertPushed(GenerateConsultationDerivative::class, fn ($job) => $job->mediaFileUuid === $this->file->uuid);

    // Run what was queued.
    (new GenerateConsultationDerivative($this->file->uuid, true))->handle(app(GenerateDerivative::class));

    expect(MediaConsultation::where('media_file_id', $this->file->id)->first())
        ->status->toBe('ready')
        ->reason->toBeNull();
});

test('the descriptor carries the mode and a signed original url, and no asset urls', function () {
    $descriptor = app(ConsultationDescriptor::class)->handle($this->oeuvre, $this->file, $this->admin);

    expect($descriptor['mode'])->toBe('original_inline')
        ->and($descriptor['status'])->toBe('ready')
        ->and($descriptor['assets'])->toBe([])
        ->and($descriptor['original']['mime'])->toBe('application/pdf')
        ->and($descriptor['original']['url'])->toContain('signature=')
        ->and(json_encode($descriptor))->not->toContain($this->file->path);
});

test('a docx above docx_max_bytes gets a too-large card and no original url', function () {
    $file = consultFile($this->author, $this->oeuvre, 'PK fake docx', 'lettre.docx', PreviewFamilyResolver::DOCX_MIME);
    $this->files[] = $file;
    consultGenerate($file);
    config(['vault.consult.docx_max_bytes' => 5]);

    $descriptor = app(ConsultationDescriptor::class)->handle($this->oeuvre, $file, $this->admin);

    expect($descriptor['status'])->toBe('unsupported')
        ->and($descriptor['reason'])->toBe('docx_too_large')
        ->and($descriptor['original'])->toBeNull();

    $this->actingAs($this->admin)->get(originalUrl($this, file: $file))->assertNotFound();
});

test('descriptor issuance for an original_inline file is one consult_original row per window, and range_header stays empty', function () {
    $json = fn () => $this->actingAs($this->admin)->getJson(route('admin.oeuvres.files.review.assets', [$this->oeuvre, $this->file]));

    $json()->assertOk();
    $json()->assertOk();

    $rows = FileAccessLog::where('media_file_id', $this->file->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->action)->toBe('consult_original')
        ->and($rows[0]->user_id)->toBe($this->admin->id)
        ->and($rows[0]->range_header)->toBeNull();
});

test('a derivative file is still logged as admin_consult', function () {
    $text = consultFile($this->author, $this->oeuvre, 'notes', 'notes.txt', 'text/plain');
    $this->files[] = $text;
    consultGenerate($text);

    $this->actingAs($this->admin)->getJson(route('admin.oeuvres.files.review.assets', [$this->oeuvre, $text]))->assertOk();

    expect(FileAccessLog::where('media_file_id', $text->id)->pluck('action')->all())->toBe(['admin_consult']);
});

test('the hash chain stays valid across consult_original rows', function () {
    Carbon::setTestNow('2026-10-13 10:00:00.123');

    $text = consultFile($this->author, $this->oeuvre, 'notes', 'notes.txt', 'text/plain');
    $this->files[] = $text;
    consultGenerate($text);

    $get = fn ($file) => $this->actingAs($this->admin)->getJson(route('admin.oeuvres.files.review.assets', [$this->oeuvre, $file]))->assertOk();
    $get($this->file); // consult_original
    $get($text);       // admin_consult
    $other = consultAdmin();
    $this->actingAs($other)->getJson(route('admin.oeuvres.files.review.assets', [$this->oeuvre, $this->file]))->assertOk(); // consult_original

    $rows = FileAccessLog::orderBy('id')->get();

    expect($rows->pluck('action')->all())->toBe(['consult_original', 'admin_consult', 'consult_original']);

    $previous = null;

    foreach ($rows as $row) {
        $payload = [
            'action' => $row->action,
            'media_file_uuid' => MediaFile::find($row->media_file_id)->uuid,
            'user_id' => $row->user_id,
            'ip' => $row->ip,
            'range_header' => $row->range_header,
            'logged_at' => now()->toISOString(),
        ];

        expect($row->prev_hash)->toBe($previous)
            ->and(RowHash::verify($previous, $payload, $row->row_hash))->toBeTrue();

        $previous = $row->row_hash;
    }

    // Changing the action of a stored row breaks its own hash.
    $first = $rows[0];
    $forged = ['action' => 'admin_consult', 'media_file_uuid' => $this->file->uuid, 'user_id' => $first->user_id, 'ip' => $first->ip, 'range_header' => null, 'logged_at' => now()->toISOString()];

    expect(RowHash::verify(null, $forged, $first->row_hash))->toBeFalse();

    Carbon::setTestNow();
});

// --- the endpoint -------------------------------------------------------------

test('the issuing admin gets the original inline, with exactly the specified headers', function () {
    $response = $this->actingAs($this->admin)->get(originalUrl($this));

    $response->assertStatus(206)
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="consultation.pdf"')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'")
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Range', 'bytes 0-99/100');

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('private')->toContain('no-store')
        ->and((string) $response->headers->get('Content-Disposition'))->not->toContain('attachment')
        ->and($response->streamedContent())->toBe($this->body);
});

test('a Range read returns 206 with exactly the requested bytes', function () {
    $response = $this->actingAs($this->admin)->withHeaders(['Range' => 'bytes=21-40'])->get(originalUrl($this));

    $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 21-40/100');

    expect($response->streamedContent())->toBe(substr($this->body, 21, 20));
});

test('a request without Range is a 206 of the first capped slice, never a 200 with the whole file', function () {
    config(['vault.consult.range_max_bytes' => 30]);

    $response = $this->actingAs($this->admin)->get(originalUrl($this));

    $response->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 0-29/100')
        ->assertHeader('Content-Length', '30');

    expect($response->streamedContent())->toBe(substr($this->body, 0, 30));
});

test('an open-ended range is capped and the player asks for the next slice itself', function () {
    config(['vault.consult.range_max_bytes' => 30]);

    $first = $this->actingAs($this->admin)->withHeaders(['Range' => 'bytes=0-'])->get(originalUrl($this));
    $first->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-29/100');

    $next = $this->actingAs($this->admin)->withHeaders(['Range' => 'bytes=30-'])->get(originalUrl($this));
    $next->assertStatus(206)->assertHeader('Content-Range', 'bytes 30-59/100');

    expect($first->streamedContent().$next->streamedContent())->toBe(substr($this->body, 0, 60));
});

test('a bytes=0-1 probe returns exactly two bytes', function () {
    $response = $this->actingAs($this->admin)->withHeaders(['Range' => 'bytes=0-1'])->get(originalUrl($this));

    $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-1/100');

    expect($response->streamedContent())->toBe(substr($this->body, 0, 2));
});

test('a suffix range returns the last bytes', function () {
    $response = $this->actingAs($this->admin)->withHeaders(['Range' => 'bytes=-10'])->get(originalUrl($this));

    $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 90-99/100');

    expect($response->streamedContent())->toBe(substr($this->body, 90));
});

test('a range end past EOF is clamped', function () {
    $response = $this->actingAs($this->admin)->withHeaders(['Range' => 'bytes=95-5000'])->get(originalUrl($this));

    $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 95-99/100');
});

test('a start beyond EOF is 416 with Content-Range bytes */total', function () {
    $response = $this->actingAs($this->admin)->withHeaders(['Range' => 'bytes=100-'])->get(originalUrl($this));

    $response->assertStatus(416)->assertHeader('Content-Range', 'bytes */100');

    expect($response->getContent())->toBe('');
});

test('several ranges: only the first is served', function () {
    $response = $this->actingAs($this->admin)->withHeaders(['Range' => 'bytes=0-4,10-14'])->get(originalUrl($this));

    $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-4/100');

    expect($response->streamedContent())->toBe(substr($this->body, 0, 5));
});

test('a malformed Range is ignored and answers the first slice', function () {
    $response = $this->actingAs($this->admin)->withHeaders(['Range' => 'bytes=9-3'])->get(originalUrl($this));

    $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-99/100');
});

test('HEAD returns the headers of the GET and no body', function () {
    $response = $this->actingAs($this->admin)->call('HEAD', originalUrl($this));

    $response->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 0-99/100')
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Accept-Ranges', 'bytes');

    expect($response->getContent())->toBe('');
});

test('a Range read of a large file stays under the memory ceiling and under the cap', function () {
    $vault = app(VaultContract::class);
    $chunk = 8 * 1024 * 1024;
    $chunks = 6; // 48 MiB, written chunk by chunk: never one string

    $session = $vault->beginUpload(new UploadIntent($this->oeuvre->id, $this->author->id, 'big.mp4', $chunk * $chunks));
    $block = str_repeat('0123456789abcdef', $chunk / 16);

    for ($i = 0; $i < $chunks; $i++) {
        $vault->writeChunk($session, $i, $block);
    }

    $object = $vault->finalize($session->fresh());
    unset($block);

    $big = new MediaFile;
    $big->uuid = (string) Str::uuid7();
    $big->oeuvre_id = $this->oeuvre->id;
    $big->uploaded_by = $this->author->id;
    $big->original_name = 'big.mp4';
    $big->extension = 'mp4';
    $big->mime = 'video/mp4';
    $big->size_bytes = $object->sizeBytes;
    $big->disk = $object->disk;
    $big->path = $object->path;
    $big->sha256_plain = str_repeat('0', 64);
    $big->dek_wrapped = $object->dekWrapped;
    $big->nonce = $object->nonce;
    $big->mac_path = $object->macPath;
    $big->status = 'ready';
    $big->ref_count = 1;
    $big->save();
    $this->files[] = $big;
    consultGenerate($big);

    $url = originalUrl($this, file: $big);
    $start = 20 * 1024 * 1024 + 7;

    gc_collect_cycles();
    $before = memory_get_peak_usage(true);

    $response = $this->actingAs($this->admin)->withHeaders(['Range' => "bytes={$start}-"])->get($url);
    $response->assertStatus(206);
    $body = $response->streamedContent();

    $growth = memory_get_peak_usage(true) - $before;
    $cap = 8 * 1024 * 1024;

    // The 8 MiB slice is captured by the test itself; the server must add
    // only a few MiB on top, far below the 48 MiB a whole-file read needs.
    expect(strlen($body))->toBe($cap)
        ->and($response->headers->get('Content-Range'))->toBe('bytes '.$start.'-'.($start + $cap - 1).'/'.($chunk * $chunks))
        ->and($growth)->toBeLessThan(24 * 1024 * 1024)
        ->and($body[0])->toBe('0123456789abcdef'[$start % 16]);
});

// --- who may call it ----------------------------------------------------------

test('another admin signature is denied', function () {
    $other = consultAdmin();

    $this->actingAs($other)->get(originalUrl($this, $this->admin))->assertForbidden();
});

test('an expired url is denied', function () {
    $url = URL::temporarySignedRoute('admin.oeuvres.files.consult.original', now()->subMinute(), [
        'oeuvre' => $this->oeuvre->uuid,
        'mediaFile' => $this->file->uuid,
        'admin' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)->get($url)->assertForbidden();
});

test('an unsigned or tampered url is denied', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.oeuvres.files.consult.original', [$this->oeuvre, $this->file]).'?admin='.$this->admin->id)
        ->assertForbidden();

    $this->actingAs($this->admin)->get(originalUrl($this).'&x=1')->assertForbidden();
});

test('a file of another oeuvre is a 404 even under a valid signature', function () {
    $otherOeuvre = Oeuvre::factory()->create(['author_id' => $this->author->id]);

    $this->actingAs($this->admin)->get(originalUrl($this, oeuvre: $otherOeuvre))->assertNotFound();
});

test('a consultation that is not ready is denied', function () {
    MediaConsultation::where('media_file_id', $this->file->id)->update(['status' => MediaConsultation::PENDING]);

    $this->actingAs($this->admin)->get(originalUrl($this))->assertForbidden();
});

test('a family configured derivative or unsupported is a 404', function (string $mode) {
    config(['vault.consult.delivery.pdf' => $mode]);

    $this->actingAs($this->admin)->get(originalUrl($this))->assertNotFound();
})->with(['derivative', 'unsupported']);

test('a non-admin, even the owner, is denied', function () {
    // An ADMIN-route URL signed with the author's id: the admin group refuses.
    $url = URL::temporarySignedRoute('admin.oeuvres.files.consult.original', now()->addMinutes(5), [
        'oeuvre' => $this->oeuvre->uuid,
        'mediaFile' => $this->file->uuid,
        'admin' => $this->author->id,
    ]);

    $this->actingAs($this->author)->get($url)->assertForbidden();
});

test('a guest is sent away', function () {
    $this->get(originalUrl($this))->assertRedirect();
});

test('the endpoint refuses a file whose stored MIME is not one of the four families', function () {
    $file = consultFile($this->author, $this->oeuvre, 'x', 'photo.png', 'image/png');
    $this->files[] = $file;
    config(['vault.consult.delivery.image' => 'original_inline']);
    // The job refuses to mark it ready (unsupported), so the gate denies it.
    expect(consultGenerate($file)->status)->toBe('unsupported');

    $this->actingAs($this->admin)->get(originalUrl($this, file: $file))->assertForbidden();
});

test('media.link and media.stream stay owner-only for an admin', function () {
    $this->actingAs($this->admin)->getJson(route('media.link', $this->file))->assertForbidden();
    $this->actingAs($this->admin)->get(SignedMediaUrl::forStreaming($this->file, $this->admin))->assertForbidden();
});
