<?php

declare(strict_types=1);

use App\Domain\Access\SignedMediaUrl;
use App\Models\Oeuvre;

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
 * Every body an admin can obtain for a file must differ from the original's
 * SHA-256. Binary families only: for a plain-text file the "derivative" IS
 * the text (that is what viewing text means), so it is covered by the
 * closed-endpoint assertions below instead.
 */
test('no admin-obtainable response body equals the original', function (string $name, string $mime, string $fixture) {
    $bytes = consultFixture($fixture);
    $original = hash('sha256', $bytes);
    $file = consultFile($this->author, $this->oeuvre, $bytes, $name, $mime);
    $this->files[] = $file;
    consultVariant($file, 'poster', "\xFF\xD8\xFF\xE0 poster-bytes");
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
    'mp4' => ['clip.mp4', 'video/mp4', 'sample.mp4'],
]);

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
