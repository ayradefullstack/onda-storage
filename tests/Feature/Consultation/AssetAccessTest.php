<?php

declare(strict_types=1);

use App\Actions\Consultation\IssueAssetUrl;
use App\Actions\Consultation\StoreConsultationAsset;
use App\Infrastructure\Render\RenderedAsset;
use App\Models\MediaConsultation;
use App\Models\Oeuvre;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

require_once __DIR__.'/helpers.php';

const CONSULT_BODY = 'The quick brown fox jumps over the lazy dog. 0123456789';

beforeEach(function () {
    $this->admin = consultAdmin();
    $this->author = consultAuthor();
    $this->oeuvre = Oeuvre::factory()->create(['author_id' => $this->author->id]);
    $this->file = consultFile($this->author, $this->oeuvre, CONSULT_BODY, 'notes.txt', 'text/plain');
    consultGenerate($this->file);
    $this->asset = consultAssetRow($this->file, 'text', 0);
    $this->cleanup = [$this->file];
});

afterEach(function () {
    consultCleanup(...$this->cleanup);
});

function consultUrl(object $test, ?User $for = null, $asset = null, $oeuvre = null, $file = null): string
{
    return app(IssueAssetUrl::class)->handle(
        $oeuvre ?? $test->oeuvre,
        $file ?? $test->file,
        $asset ?? $test->asset,
        $for ?? $test->admin,
    );
}

test('the issuing admin streams the derivative with exactly the specified headers', function () {
    $response = $this->actingAs($this->admin)->get(consultUrl($this));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertHeader('Content-Disposition', 'inline')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'")
        ->assertHeader('Referrer-Policy', 'no-referrer');

    $cacheControl = (string) $response->headers->get('Cache-Control');
    expect($cacheControl)->toContain('private')->toContain('no-store')
        ->and($response->streamedContent())->toBe(CONSULT_BODY);
});

test('a Range request returns 206 with exactly the requested bytes', function () {
    $response = $this->actingAs($this->admin)
        ->withHeaders(['Range' => 'bytes=4-14'])
        ->get(consultUrl($this));

    $response->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 4-14/'.strlen(CONSULT_BODY))
        ->assertHeader('Content-Length', '11');

    expect($response->streamedContent())->toBe(substr(CONSULT_BODY, 4, 11));
});

test('a Range start off the cipher block boundary still decrypts correctly', function () {
    $response = $this->actingAs($this->admin)
        ->withHeaders(['Range' => 'bytes=21-40'])
        ->get(consultUrl($this));

    $response->assertStatus(206);
    expect($response->streamedContent())->toBe(substr(CONSULT_BODY, 21, 20));
});

test('a Range read of a large derivative stays under a fixed memory ceiling', function () {
    // 48 MiB of derivative on disk; a 1 MiB window from the middle must not
    // pull the file into memory (Storage::get would need >= 48 MiB at once).
    $size = 48 * 1024 * 1024;
    $workspace = Storage::disk('work')->path('consult-large-'.uniqid());
    mkdir($workspace, 0700, true);
    $plain = $workspace.DIRECTORY_SEPARATOR.'big.bin';

    $handle = fopen($plain, 'wb');
    $block = str_repeat('0123456789abcdef', 65536 / 16); // 64 KiB, deterministic
    for ($i = 0; $i < $size / 65536; $i++) {
        fwrite($handle, $block);
    }
    fclose($handle);

    $big = app(StoreConsultationAsset::class)->handle($this->file, new RenderedAsset('video', 0, $plain), $workspace);
    @rmdir($workspace);

    $url = consultUrl($this, asset: $big);
    $start = 20 * 1024 * 1024 + 7;
    $length = 1024 * 1024;

    gc_collect_cycles();
    $before = memory_get_peak_usage(true);

    $response = $this->actingAs($this->admin)
        ->withHeaders(['Range' => "bytes={$start}-".($start + $length - 1)])
        ->get($url);
    $response->assertStatus(206);
    $body = $response->streamedContent();

    $growth = memory_get_peak_usage(true) - $before;

    // The slice is 1 MiB; the ceiling is generous (8 MiB) but far below the
    // 48 MiB a whole-file read would need.
    expect(strlen($body))->toBe($length)
        ->and($growth)->toBeLessThan(8 * 1024 * 1024);

    // The window really is the right bytes of the repeating pattern.
    $expected = '';
    for ($i = 0; $i < $length; $i++) {
        $expected .= '0123456789abcdef'[($start + $i) % 16];
    }
    expect($body)->toBe($expected);
});

test('a URL copied into another admin session is refused', function () {
    $other = consultAdmin();

    $this->actingAs($other)->get(consultUrl($this))->assertForbidden();
});

test('an expired URL is refused', function () {
    $url = URL::temporarySignedRoute('admin.oeuvres.files.consult.asset', now()->subMinute(), [
        'oeuvre' => $this->oeuvre->uuid,
        'mediaFile' => $this->file->uuid,
        'asset' => $this->asset->uuid,
        'admin' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)->get($url)->assertForbidden();
});

test('an unsigned or tampered URL is refused', function () {
    $url = consultUrl($this);

    $this->actingAs($this->admin)->get(preg_replace('/signature=[^&]+/', 'signature=nope', $url))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.oeuvres.files.consult.asset', [
        'oeuvre' => $this->oeuvre->uuid,
        'mediaFile' => $this->file->uuid,
        'asset' => $this->asset->uuid,
        'admin' => $this->admin->id,
    ]))->assertForbidden();
});

test('a URL is refused when the consultation is not ready', function () {
    MediaConsultation::where('media_file_id', $this->file->id)->update(['status' => 'failed']);

    $this->actingAs($this->admin)->get(consultUrl($this))->assertForbidden();
});

test('an author, even the owner, cannot use a consultation URL', function () {
    $url = consultUrl($this, $this->author);

    $this->actingAs($this->author)->get($url)->assertForbidden();
});

test('an asset of another oeuvre is a 404 even under a valid signature for the same admin', function () {
    $otherOeuvre = Oeuvre::factory()->create(['author_id' => $this->author->id]);
    $otherFile = consultFile($this->author, $otherOeuvre, 'other body', 'other.txt', 'text/plain');
    $this->cleanup[] = $otherFile;
    consultGenerate($otherFile);
    $otherAsset = consultAssetRow($otherFile, 'text', 0);

    // File B under oeuvre A.
    $this->actingAs($this->admin)
        ->get(consultUrl($this, asset: $otherAsset, file: $otherFile))
        ->assertNotFound();

    // Asset B under file A.
    $this->actingAs($this->admin)
        ->get(consultUrl($this, asset: $otherAsset))
        ->assertNotFound();

    // Both correctly paired: fine.
    $this->actingAs($this->admin)
        ->get(consultUrl($this, asset: $otherAsset, oeuvre: $otherOeuvre, file: $otherFile))
        ->assertOk();
});
