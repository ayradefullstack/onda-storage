<?php

declare(strict_types=1);

use App\Actions\Consultation\ConsultationDescriptor;
use App\Actions\Consultation\IssueAssetUrl;
use App\Actions\Consultation\IssueOriginalUrl;
use App\Enums\FileAccessLog\ActionEnum;
use App\Enums\MediaConsultation\StatusEnum as ConsultationStatus;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Models\FileAccessLog;
use App\Models\MediaConsultation;
use App\Models\Oeuvre;
use Illuminate\Support\Facades\URL;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->admin = consultAdmin();
    $this->owner = consultAuthor();
    $this->other = consultAuthor();
    $this->oeuvre = Oeuvre::factory()->create(['author_id' => $this->owner->id]);
    $this->files = [];
});

afterEach(function () {
    consultCleanup(...$this->files);
});

function ownerDeposit(object $test, string $bytes, string $name, string $mime): object
{
    $file = consultFile($test->owner, $test->oeuvre, $bytes, $name, $mime);
    $test->files[] = $file;
    consultGenerate($file);

    return $file;
}

function previewRoute(object $test, object $file, string $name = 'oeuvres.files.preview'): string
{
    return route($name, [$test->oeuvre, $file]);
}

test('the owner opens the preview page, and its props carry no activity data', function () {
    $file = ownerDeposit($this, '%PDF-1.4 hello', 'acte.pdf', 'application/pdf');

    $this->actingAs($this->owner)->get(previewRoute($this, $file))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('author/oeuvres/FilePreview')
            ->has('consultation')
            ->where('consultation.mode', 'original_inline')
            ->missing('activity')
            ->missing('logs')
            ->missing('reviews')
            ->where('consultation.watermark', null));
});

test('the descriptor is issued to the owner as JSON and is never cached', function () {
    $file = ownerDeposit($this, 'plain text', 'notes.txt', 'text/plain');

    $response = $this->actingAs($this->owner)->getJson(previewRoute($this, $file, 'oeuvres.files.preview.assets'))->assertOk();

    expect($response->json('consultation.status'))->toBe('ready')
        ->and($response->json('consultation.assets.0.url'))->toContain('/author/oeuvres/')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

test('the owner is served the inline original of a pdf, mp4 and mp3, with the exact headers', function (string $name, string $mime, string $ext) {
    $body = 'original-bytes-0123456789';
    $file = ownerDeposit($this, $body, $name, $mime);
    $url = app(IssueOriginalUrl::class)->handle($this->oeuvre, $file, $this->owner);

    $response = $this->actingAs($this->owner)->get($url);

    // No Range header: the first capped slice, as for the admin.
    $response->assertStatus(206)
        ->assertHeader('Content-Type', $mime)
        ->assertHeader('Content-Disposition', 'inline; filename="consultation.'.$ext.'"')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");

    $cache = (string) $response->headers->get('Cache-Control');
    expect($cache)->toContain('private')->toContain('no-store')
        ->and((string) $response->headers->get('Content-Disposition'))->not->toContain('attachment')
        ->and($response->streamedContent())->toBe($body);
})->with([
    'pdf' => ['acte.pdf', 'application/pdf', 'pdf'],
    'mp4' => ['clip.mp4', 'video/mp4', 'mp4'],
    'mp3' => ['son.mp3', 'audio/mpeg', 'mp3'],
]);

test('the owner is served the inline original of a docx', function () {
    $file = ownerDeposit($this, consultFixture('formats/sample.docx'), 'lettre.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    $url = app(IssueOriginalUrl::class)->handle($this->oeuvre, $file, $this->owner);

    $this->actingAs($this->owner)->get($url)
        ->assertStatus(206)
        ->assertHeader('Content-Disposition', 'inline; filename="consultation.docx"');
});

test('a Range request on the owner original returns 206', function () {
    $file = ownerDeposit($this, str_repeat('abcdefghij', 10), 'acte.pdf', 'application/pdf');
    $url = app(IssueOriginalUrl::class)->handle($this->oeuvre, $file, $this->owner);

    $response = $this->actingAs($this->owner)->withHeaders(['Range' => 'bytes=10-19'])->get($url);

    $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 10-19/100');
    expect($response->streamedContent())->toBe('abcdefghij');
});

test('the owner is served derivative assets for a jpg, an xlsx and a pptx', function (string $name, string $mime, Closure $bytes) {
    $file = ownerDeposit($this, $bytes(), $name, $mime);
    $descriptor = app(ConsultationDescriptor::class)->handle($this->oeuvre, $file, $this->owner);

    expect($descriptor['status'])->toBe(ConsultationStatus::READY)
        ->and($descriptor['mode'])->toBe('derivative')
        ->and($descriptor['assets'])->not->toBeEmpty();

    foreach ($descriptor['assets'] as $asset) {
        $this->actingAs($this->owner)->get($asset['url'])->assertOk();
    }
})->with([
    'jpg' => ['photo.jpg', 'image/jpeg', function (): string {
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }],
    'xlsx' => ['budget.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', function (): string {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([['Nom', 'Montant'], ['Ali', 120]]);
        $path = tempnam(sys_get_temp_dir(), 'xl');
        (new Xlsx($sheet))->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }],
    'pptx' => ['deck.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', function (): string {
        $ns = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';
        $path = tempnam(sys_get_temp_dir(), 'pp');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('ppt/slides/slide1.xml', '<?xml version="1.0"?><p:sld '.$ns.'><p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>Plan</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }],
]);

test('the watermark is off for the owner by default and follows watermark_for_owner', function () {
    $file = ownerDeposit($this, 'plain text', 'notes.txt', 'text/plain');
    $descriptor = fn ($viewer) => app(ConsultationDescriptor::class)->handle($this->oeuvre, $file, $viewer)['watermark'];

    expect($descriptor($this->owner))->toBeNull()
        ->and($descriptor($this->admin))->toBe($this->admin->email);

    config(['vault.consult.watermark_for_owner' => true]);

    expect($descriptor($this->owner))->toBe($this->owner->email);
});

test('another author gets 404 on the preview page, the descriptor, every asset and the original', function () {
    $text = ownerDeposit($this, 'plain text', 'notes.txt', 'text/plain');
    $pdf = ownerDeposit($this, '%PDF-1.4 hello', 'acte.pdf', 'application/pdf');

    $this->actingAs($this->other)->get(previewRoute($this, $text))->assertNotFound();
    $this->actingAs($this->other)->getJson(previewRoute($this, $text, 'oeuvres.files.preview.assets'))->assertNotFound();

    // URLs the other author signs for themselves (the strongest case: valid
    // signature, matching `u`) still reach nothing.
    $asset = consultAssetRow($text, 'text', 0);
    $mine = fn (string $route, array $params) => URL::temporarySignedRoute($route, now()->addMinutes(5), [...$params, 'u' => $this->other->id]);

    $this->actingAs($this->other)->get($mine('oeuvres.files.consult.asset', ['oeuvre' => $this->oeuvre->uuid, 'mediaFile' => $text->uuid, 'asset' => $asset->uuid]))->assertNotFound();
    $this->actingAs($this->other)->get($mine('oeuvres.files.consult.original', ['oeuvre' => $this->oeuvre->uuid, 'mediaFile' => $pdf->uuid]))->assertNotFound();
});

test('a URL issued to the owner and replayed by another author is denied', function () {
    $text = ownerDeposit($this, 'plain text', 'notes.txt', 'text/plain');
    $pdf = ownerDeposit($this, '%PDF-1.4 hello', 'acte.pdf', 'application/pdf');
    $asset = consultAssetRow($text, 'text', 0);

    $assetUrl = app(IssueAssetUrl::class)->handle($this->oeuvre, $text, $asset, $this->owner);
    $originalUrl = app(IssueOriginalUrl::class)->handle($this->oeuvre, $pdf, $this->owner);

    $this->actingAs($this->other)->get($assetUrl)->assertForbidden();
    $this->actingAs($this->other)->get($originalUrl)->assertForbidden();
});

test('an expired owner URL is denied', function () {
    $file = ownerDeposit($this, '%PDF-1.4 hello', 'acte.pdf', 'application/pdf');
    $url = app(IssueOriginalUrl::class)->handle($this->oeuvre, $file, $this->owner);

    $this->travel(2)->hours();

    $this->actingAs($this->owner)->get($url)->assertForbidden();
});

test('an owner URL is denied while the consultation is not ready', function () {
    $file = ownerDeposit($this, '%PDF-1.4 hello', 'acte.pdf', 'application/pdf');
    $url = app(IssueOriginalUrl::class)->handle($this->oeuvre, $file, $this->owner);

    MediaConsultation::where('media_file_id', $file->id)->update(['status' => ConsultationStatus::PENDING]);

    $this->actingAs($this->owner)->get($url)->assertForbidden();
});

test('an owner URL for a derivative family has no original to serve: 404', function () {
    $file = ownerDeposit($this, 'plain text', 'notes.txt', 'text/plain');
    $url = URL::temporarySignedRoute('oeuvres.files.consult.original', now()->addMinutes(5), [
        'oeuvre' => $this->oeuvre->uuid,
        'mediaFile' => $file->uuid,
        'u' => $this->owner->id,
    ]);

    $this->actingAs($this->owner)->get($url)->assertNotFound();
});

test('an admin opening the author routes is refused by role:author with a 403', function () {
    $file = ownerDeposit($this, 'plain text', 'notes.txt', 'text/plain');

    $this->actingAs($this->admin)->get(previewRoute($this, $file))->assertForbidden();
    $this->actingAs($this->admin)->getJson(previewRoute($this, $file, 'oeuvres.files.preview.assets'))->assertForbidden();
});

test('the preview works in every oeuvre status', function (OeuvreStatus $status) {
    $file = ownerDeposit($this, 'plain text', 'notes.txt', 'text/plain');
    $this->oeuvre->forceFill(['status' => $status])->save();

    $this->actingAs($this->owner)->get(previewRoute($this, $file))->assertOk();
})->with(OeuvreStatus::cases());

test('an owner preview is audited like the owner stream: one stream row per window, nothing for the rest', function () {
    $file = ownerDeposit($this, 'plain text', 'notes.txt', 'text/plain');

    $this->actingAs($this->owner)->getJson(previewRoute($this, $file, 'oeuvres.files.preview.assets'))->assertOk();
    $this->actingAs($this->owner)->getJson(previewRoute($this, $file, 'oeuvres.files.preview.assets'))->assertOk();

    $rows = FileAccessLog::where('media_file_id', $file->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->action)->toBe(ActionEnum::STREAM)
        ->and($rows[0]->user_id)->toBe($this->owner->id);
});
