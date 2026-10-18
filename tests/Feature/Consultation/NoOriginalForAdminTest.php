<?php

declare(strict_types=1);

use App\Actions\Consultation\IssueOriginalUrl;
use App\Domain\Access\SignedMediaUrl;
use App\Enums\MediaVariant\KindEnum;
use App\Models\Oeuvre;
use Symfony\Component\HttpFoundation\StreamedResponse;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->admin = consultAdmin();
    $this->author = consultAuthor();
    $this->oeuvre = Oeuvre::factory()->create(['author_id' => $this->author->id]);
    $this->files = [];
});

afterEach(function () {
    consultCleanup(...$this->files);
});

/**
 * DELIBERATELY CHANGED (browser-native preview). For DERIVATIVE families every
 * body an admin can obtain still differs from the original's SHA-256. Binary
 * families only: for a plain-text file the "derivative" IS the text (that is
 * what viewing text means), so it is covered by the closed-endpoint
 * assertions below instead.
 *
 * The mp4 case moved out of this list: video is now `original_inline`, and
 * its original may reach an admin through exactly ONE route — see the
 * `original_inline` tests below.
 */
test('no admin-obtainable response body equals the original', function (string $name, string $mime, string $fixture) {
    $bytes = consultFixture($fixture);
    $original = hash('sha256', $bytes);
    $file = consultFile($this->author, $this->oeuvre, $bytes, $name, $mime);
    $this->files[] = $file;
    consultVariant($file, KindEnum::POSTER, "\xFF\xD8\xFF\xE0 poster-bytes");
    consultGenerate($file);

    $bodies = [];

    $page = $this->actingAs($this->admin)->get(route('admin.oeuvres.files.review', [$this->oeuvre, $file]));
    $bodies[] = $page->getContent();

    $show = $this->actingAs($this->admin)->get(route('admin.oeuvres.show', $this->oeuvre));
    $bodies[] = $show->getContent();

    $json = $this->actingAs($this->admin)->getJson(route('admin.oeuvres.files.review.assets', [$this->oeuvre, $file]));
    $bodies[] = $json->getContent();

    foreach ($json->json('consultation.assets') as $asset) {
        $response = $this->actingAs($this->admin)->get($asset['url']);
        $response->assertOk();
        $bodies[] = $response->streamedContent();
    }

    $bodies[] = $this->actingAs($this->admin)->get(route('admin.media.variant', [$file, 'poster']))->getContent();

    expect($bodies)->not->toBeEmpty();

    foreach ($bodies as $body) {
        expect(hash('sha256', (string) $body))->not->toBe($original);
    }
})->with([
    'png' => ['image.png', 'image/png', 'formats/sample.png'],
    'csv' => ['noms.csv', 'text/csv', 'consult/arabic.csv'],
]);

/**
 * For `original_inline` families a body MAY equal the original, but ONLY when
 * served by the consult-original endpoint, inline and never as an attachment.
 * Every other admin-obtainable body still differs.
 */
test('for original_inline families the original is served only by the consult original endpoint, inline', function (string $name, string $mime, string $fixture) {
    $bytes = consultFixture($fixture);
    $original = hash('sha256', $bytes);
    $file = consultFile($this->author, $this->oeuvre, $bytes, $name, $mime);
    $this->files[] = $file;
    consultVariant($file, KindEnum::POSTER, "\xFF\xD8\xFF\xE0 poster-bytes");
    consultGenerate($file);

    $others = [];
    $others[] = $this->actingAs($this->admin)->get(route('admin.oeuvres.files.review', [$this->oeuvre, $file]))->getContent();
    $others[] = $this->actingAs($this->admin)->get(route('admin.oeuvres.show', $this->oeuvre))->getContent();
    $others[] = $this->actingAs($this->admin)->getJson(route('admin.oeuvres.files.review.assets', [$this->oeuvre, $file]))->getContent();
    $others[] = $this->actingAs($this->admin)->get(route('admin.media.variant', [$file, 'poster']))->getContent();

    foreach ($others as $body) {
        expect(hash('sha256', (string) $body))->not->toBe($original);
    }

    // The one route: the whole (small) file in one capped slice.
    config(['vault.consult.range_max_bytes' => strlen($bytes) + 1]);
    $response = $this->actingAs($this->admin)->get(app(IssueOriginalUrl::class)->handle($this->oeuvre, $file, $this->admin));

    $response->assertStatus(206);
    expect(hash('sha256', $response->streamedContent()))->toBe($original)
        ->and((string) $response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and((string) $response->headers->get('Content-Disposition'))->not->toContain('attachment');
})->with([
    'mp4' => ['clip.mp4', 'video/mp4', 'sample.mp4'],
    'pdf' => ['acte.pdf', 'application/pdf', 'formats/sample.pdf'],
]);

test('no other route returns original bytes to an admin', function () {
    $bytes = consultFixture('formats/sample.pdf');
    $original = hash('sha256', $bytes);
    $file = consultFile($this->author, $this->oeuvre, $bytes, 'acte.pdf', 'application/pdf');
    $this->files[] = $file;
    consultGenerate($file);

    $routes = [
        $this->actingAs($this->admin)->getJson(route('media.link', $file)),
        $this->actingAs($this->admin)->get(SignedMediaUrl::forStreaming($file, $this->admin)),
        $this->actingAs($this->admin)->get(route('media.stream', $file)),
        $this->actingAs($this->admin)->get(route('admin.media.variant', [$file, 'poster'])),
        $this->actingAs($this->admin)->get(route('admin.oeuvres.files.review', [$this->oeuvre, $file])),
        $this->actingAs($this->admin)->get(route('admin.oeuvres.show', $this->oeuvre)),
    ];

    foreach ($routes as $response) {
        expect(hash('sha256', $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent()))->not->toBe($original);
    }

    // A consult ASSET url cannot be pointed at the original either: there is
    // no asset row for an original_inline file.
    expect($file->consultationAssets()->count())->toBe(0);
});

test('an admin cannot reach the original by any route, signed or not', function () {
    $file = consultFile($this->author, $this->oeuvre, 'original plaintext body', 'notes.txt', 'text/plain');
    $this->files[] = $file;
    consultGenerate($file);

    $this->actingAs($this->admin)->getJson(route('media.link', $file))->assertForbidden();
    $this->actingAs($this->admin)->get(SignedMediaUrl::forStreaming($file, $this->admin))->assertForbidden();
    $this->actingAs($this->admin)->get(route('media.stream', $file))->assertForbidden();
});

test('the owner still streams and links the original', function () {
    $file = consultFile($this->author, $this->oeuvre, 'original plaintext body', 'notes.txt', 'text/plain');
    $this->files[] = $file;

    $this->actingAs($this->author)->getJson(route('media.link', $file))->assertOk();

    $response = $this->actingAs($this->author)->get(SignedMediaUrl::forStreaming($file, $this->author));

    expect($response->streamedContent())->toBe('original plaintext body');
});

test('VAULT_OWNER_DOWNLOAD_ENABLED=false closes link issuance and the stream for the owner', function () {
    $file = consultFile($this->author, $this->oeuvre, 'original plaintext body', 'notes.txt', 'text/plain');
    $this->files[] = $file;
    $url = SignedMediaUrl::forStreaming($file, $this->author);

    config(['vault.download.owner_enabled' => false]);

    $this->actingAs($this->author)->getJson(route('media.link', $file))->assertForbidden();
    $this->actingAs($this->author)->get($url)->assertForbidden();
});

test('the admin file-review path does not depend on the owner download flag', function () {
    $file = consultFile($this->author, $this->oeuvre, 'plain', 'notes.txt', 'text/plain');
    $this->files[] = $file;
    consultGenerate($file);

    config(['vault.download.owner_enabled' => false]);

    $this->actingAs($this->admin)->get(route('admin.oeuvres.files.review', [$this->oeuvre, $file]))->assertOk();
});
