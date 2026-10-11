<?php

declare(strict_types=1);

use App\Actions\Consultation\DeleteConsultationAssets;
use App\Actions\Consultation\GenerateDerivative;
use App\Infrastructure\Render\ConsultationRenderer;
use App\Infrastructure\Render\OfficeConverter;
use App\Infrastructure\Render\PreviewFamily;
use App\Infrastructure\Render\RenderResult;
use App\Infrastructure\Render\TextRenderer;
use App\Infrastructure\Render\ToolRunner;
use App\Jobs\GenerateConsultationDerivative;
use App\Models\ConsultationAsset;
use App\Models\MediaConsultation;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Hyperlink;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->author = consultAuthor();
    $this->oeuvre = Oeuvre::factory()->create(['author_id' => $this->author->id]);
    $this->files = [];
    $this->tmp = [];
});

afterEach(function () {
    consultCleanup(...$this->files);

    foreach ($this->tmp as $path) {
        @unlink($path);
    }
});

function consultDeposit(object $test, string $bytes, string $name, string $mime): MediaFile
{
    $file = consultFile($test->author, $test->oeuvre, $bytes, $name, $mime);
    $test->files[] = $file;

    return $file;
}

test('a csv with Arabic content keeps UTF-8 and parses the delimiter', function () {
    $file = consultDeposit($this, consultFixture('consult/arabic.csv'), 'noms.csv', 'text/csv');

    $consultation = consultGenerate($file);

    expect($consultation->status)->toBe('ready')
        ->and($consultation->family)->toBe('csv');

    $json = json_decode(consultPlain($file, consultAssetRow($file, 'sheet', 0)), true);

    expect($json['sheets'][0]['rows'][0])->toBe(['الاسم', 'العنوان', 'السنة'])
        ->and($json['sheets'][0]['rows'][2])->toBe(['fatima', 'Livre; deux', '2023']);
});

test('an xlsx keeps cached formula values, drops hyperlinks and obeys the caps', function () {
    $sheet = new Spreadsheet;
    $ws = $sheet->getActiveSheet();
    $ws->setTitle('Données');
    $ws->setCellValue('A1', 10);
    $ws->setCellValue('A2', 32);
    $ws->setCellValue('A3', '=SUM(A1:A2)');
    $ws->setCellValue('B1', 'lien');
    $ws->getCell('B1')->setHyperlink(new Hyperlink('https://evil.example/', 'x'));
    for ($r = 4; $r <= 10; $r++) {
        $ws->setCellValue("A{$r}", $r);
        $ws->setCellValue("C{$r}", 'x');
    }

    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    (new Xlsx($sheet))->save($path);
    $this->tmp[] = $path;

    $file = consultDeposit($this, (string) file_get_contents($path), 'tableau.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    config(['vault.consult.sheet_max_rows' => 5, 'vault.consult.sheet_max_cols' => 2]);

    expect(consultGenerate($file)->status)->toBe('ready');

    $raw = consultPlain($file, consultAssetRow($file, 'sheet', 0));
    $json = json_decode($raw, true);
    $rows = $json['sheets'][0]['rows'];

    expect($json['sheets'][0]['name'])->toBe('Données')
        ->and($rows)->toHaveCount(5)
        ->and($rows[0])->toHaveCount(2)
        ->and($rows[2][0])->toBe('42') // =SUM shows its cached value
        ->and($rows[0][1])->toBe('lien')
        ->and($json['sheets'][0]['truncated'])->toBeTrue()
        ->and($raw)->not->toContain('evil.example');
});

test('a png becomes one stripped, valid WebP', function () {
    $file = consultDeposit($this, consultFixture('formats/sample.png'), 'image.png', 'image/png');

    expect(consultGenerate($file)->status)->toBe('ready');

    $webp = consultPlain($file, consultAssetRow($file, 'image', 0));

    expect(substr($webp, 0, 4))->toBe('RIFF')
        ->and(substr($webp, 8, 4))->toBe('WEBP');
});

test('svg and xml are text source, never markup', function (string $fixture, string $name) {
    $bytes = consultFixture($fixture);
    $file = consultDeposit($this, $bytes, $name, 'application/octet-stream');

    $consultation = consultGenerate($file);

    expect($consultation->status)->toBe('ready')
        ->and($consultation->family)->toBe('text');

    $asset = consultAssetRow($file, 'text', 0);

    // The stored bytes are the source text, served later as text/plain.
    expect(consultPlain($file, $asset))->toBe($bytes)
        ->and(consultAssetRow($file, 'image', 0))->toBeNull();
})->with([
    'svg' => ['formats/sample.svg', 'logo.svg'],
    'xml' => ['formats/sample.xml', 'metadata.xml'],
]);

test('derivatives are encrypted at rest, not stored as plaintext', function () {
    $secret = 'TOP-SECRET-DEPOSIT-CONTENT-'.bin2hex(random_bytes(8));
    $file = consultDeposit($this, $secret, 'notes.txt', 'text/plain');

    expect(consultGenerate($file)->status)->toBe('ready');

    $asset = consultAssetRow($file, 'text', 0);
    $onDisk = (string) file_get_contents(Storage::disk('variants')->path($asset->path));

    expect($onDisk)->not->toContain($secret)
        ->and(consultPlain($file, $asset))->toBe($secret)
        ->and($asset->nonce)->toHaveLength(16);
});

test('regenerating with force stores a new random nonce and removes the old assets afterwards', function () {
    $file = consultDeposit($this, 'plain text body', 'notes.txt', 'text/plain');

    consultGenerate($file);
    $first = consultAssetRow($file, 'text', 0);
    $firstNonce = $first->nonce;
    $firstPath = $first->path;
    $firstCipher = file_get_contents(Storage::disk('variants')->path($firstPath));

    consultGenerate($file, force: true);
    $second = consultAssetRow($file->refresh(), 'text', 0);

    expect($second->nonce)->not->toBe($firstNonce)
        ->and($second->path)->not->toBe($firstPath)
        ->and(file_get_contents(Storage::disk('variants')->path($second->path)))->not->toBe($firstCipher)
        ->and(Storage::disk('variants')->exists($firstPath))->toBeFalse()
        ->and(ConsultationAsset::where('media_file_id', $file->id)->count())->toBe(1);
});

test('generation without force is idempotent for a ready consultation', function () {
    $file = consultDeposit($this, 'plain text body', 'notes.txt', 'text/plain');

    consultGenerate($file);
    $nonce = consultAssetRow($file, 'text', 0)->nonce;

    consultGenerate($file);

    expect(consultAssetRow($file, 'text', 0)->nonce)->toBe($nonce);
});

test('an archive or unknown format gets a metadata card and no asset', function () {
    $file = consultDeposit($this, "Rar!\x1a\x07\x00 not really", 'bundle.rar', 'application/vnd.rar');

    $consultation = consultGenerate($file);

    expect($consultation->status)->toBe('unsupported')
        ->and($consultation->reason)->toBe('unsupported_format')
        ->and(ConsultationAsset::where('media_file_id', $file->id)->count())->toBe(0);
});

test('a docx with LibreOffice missing is unsupported with that reason, and the deposit is untouched', function () {
    config(['vault.soffice_binary' => 'C:/definitely/not/soffice.exe']);

    $file = consultDeposit($this, consultFixture('formats/sample.docx'), 'lettre.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    $consultation = consultGenerate($file);

    expect($consultation->status)->toBe('unsupported')
        ->and($consultation->reason)->toBe('tool_missing:soffice')
        ->and($file->fresh()->status)->toBe('ready');
});

test('a renderer exception becomes failed, never an exception out of the job, and never fails the deposit', function () {
    $file = consultDeposit($this, 'plain text body', 'notes.txt', 'text/plain');

    app()->bind(TextRenderer::class, fn () => new class implements ConsultationRenderer
    {
        public function supports(PreviewFamily $family): bool
        {
            return true;
        }

        public function render(MediaFile $mediaFile, string $sourcePath, string $workspace): RenderResult
        {
            throw new RuntimeException('boom');
        }
    });

    $job = new GenerateConsultationDerivative($file->uuid);
    $job->handle(app(GenerateDerivative::class)); // returns: the chain continues

    $consultation = MediaConsultation::where('media_file_id', $file->id)->first();

    expect($consultation->status)->toBe('failed')
        ->and($consultation->reason)->toBe('render_failed')
        ->and($file->fresh()->status)->toBe('ready');

    // Even the worker-killed path (failed()) only touches the consultation.
    $job->failed(new RuntimeException('killed'));

    expect($file->fresh()->status)->toBe('ready')
        ->and(MediaConsultation::where('media_file_id', $file->id)->value('reason'))->toBe('render_timeout');
});

test('a failed regeneration keeps the previous derivatives working', function () {
    $file = consultDeposit($this, 'plain text body', 'notes.txt', 'text/plain');
    consultGenerate($file);
    $before = consultAssetRow($file, 'text', 0);

    app()->bind(TextRenderer::class, fn () => new class implements ConsultationRenderer
    {
        public function supports(PreviewFamily $family): bool
        {
            return true;
        }

        public function render(MediaFile $mediaFile, string $sourcePath, string $workspace): RenderResult
        {
            throw new RuntimeException('boom');
        }
    });

    consultGenerate($file, force: true);

    expect(consultPlain($file, consultAssetRow($file->refresh(), 'text', 0)))->toBe('plain text body')
        ->and(consultAssetRow($file, 'text', 0)->uuid)->toBe($before->uuid);
});

test('a pdf becomes page images when a rasterizer exists', function () {
    if (consultBinary('pdftoppm_binary') === null && ! extension_loaded('imagick')) {
        $this->markTestSkipped('No PDF rasterizer on this machine (pdftoppm / imagick).');
    }

    $file = consultDeposit($this, consultFixture('formats/sample.pdf'), 'acte.pdf', 'application/pdf');

    $consultation = consultGenerate($file);

    expect($consultation->status)->toBe('ready')
        ->and($consultation->page_count)->toBeGreaterThan(0);

    expect(substr(consultPlain($file, consultAssetRow($file, 'page', 0)), 8, 4))->toBe('WEBP');
});

test('pdf without a rasterizer is unsupported with the exact tool named', function () {
    config(['vault.pdftoppm_binary' => 'C:/definitely/not/pdftoppm.exe']);

    if (extension_loaded('imagick')) {
        $this->markTestSkipped('Imagick is loaded: the PDF path does not degrade here.');
    }

    $file = consultDeposit($this, consultFixture('formats/sample.pdf'), 'acte.pdf', 'application/pdf');

    $consultation = consultGenerate($file);

    expect($consultation->status)->toBe('unsupported')
        ->and($consultation->reason)->toBe('tool_missing:pdftoppm');
});

test('a docx converts through LibreOffice to page images', function () {
    if (consultBinary('soffice_binary') === null || (consultBinary('pdftoppm_binary') === null && ! extension_loaded('imagick'))) {
        $this->markTestSkipped('LibreOffice and a PDF rasterizer are both required.');
    }

    $file = consultDeposit($this, consultFixture('formats/sample.docx'), 'lettre.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    $consultation = consultGenerate($file);

    expect($consultation->status)->toBe('ready')
        ->and(consultAssetRow($file, 'page', 0))->not->toBeNull();
});

test('a video keeps the existing poster instead of generating a second one', function () {
    if (consultBinary('ffmpeg_binary') === null) {
        $this->markTestSkipped('ffmpeg is not available.');
    }

    $file = consultDeposit($this, consultFixture('sample.mp4'), 'clip.mp4', 'video/mp4');
    $variant = consultVariant($file, 'poster', "\xFF\xD8\xFF\xE0 fake-jpeg");

    $consultation = consultGenerate($file);

    expect($consultation->status)->toBe('ready');

    $video = consultAssetRow($file, 'video', 0);
    $poster = consultAssetRow($file, 'poster', 0);

    expect(substr(consultPlain($file, $video), 4, 4))->toBe('ftyp')
        ->and($poster->is_shared_variant)->toBeTrue()
        ->and($poster->path)->toBe($variant->path)
        ->and($poster->nonce)->toBe($variant->nonce);

    // Removing the consultation's rows must not delete the variant's bytes.
    app(DeleteConsultationAssets::class)->handle($file->consultationAssets()->get());

    expect(Storage::disk('variants')->exists($variant->path))->toBeTrue();
});

test('an audio file becomes an mp3 and reuses the waveform', function () {
    $ffmpeg = consultBinary('ffmpeg_binary');

    if ($ffmpeg === null) {
        $this->markTestSkipped('ffmpeg is not available.');
    }

    $wav = tempnam(sys_get_temp_dir(), 'wav').'.wav';
    $this->tmp[] = $wav;
    $run = (new ToolRunner)->run([$ffmpeg, '-nostdin', '-y', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', $wav], 60);

    if (! $run['ok']) {
        $this->markTestSkipped('ffmpeg could not synthesise a test tone: '.$run['output']);
    }

    $file = consultDeposit($this, (string) file_get_contents($wav), 'son.wav', 'audio/wav');
    consultVariant($file, 'waveform', "\x89PNG fake");

    expect(consultGenerate($file)->status)->toBe('ready');

    $mp3 = consultPlain($file, consultAssetRow($file, 'audio', 0));

    expect(str_starts_with($mp3, 'ID3') || ord($mp3[0]) === 0xFF)->toBeTrue()
        ->and(consultAssetRow($file, 'waveform', 0)->is_shared_variant)->toBeTrue();
});

test('ToolRunner kills a hung process tree at the timeout', function () {
    $script = tempnam(sys_get_temp_dir(), 'fake').(PHP_OS_FAMILY === 'Windows' ? '.cmd' : '.sh');
    $this->tmp[] = $script;

    file_put_contents($script, PHP_OS_FAMILY === 'Windows'
        ? "@echo off\r\nping -n 60 127.0.0.1 >nul\r\n"
        : "#!/bin/sh\nsleep 60\n");
    chmod($script, 0755);

    $start = microtime(true);
    $run = (new ToolRunner)->run([$script], 1);

    expect($run['timedOut'])->toBeTrue()
        ->and(microtime(true) - $start)->toBeLessThan(10);
});

test('a LibreOffice conversion that hangs is aborted with convert_timeout', function () {
    $script = tempnam(sys_get_temp_dir(), 'fakesoffice').(PHP_OS_FAMILY === 'Windows' ? '.cmd' : '.sh');
    $this->tmp[] = $script;

    file_put_contents($script, PHP_OS_FAMILY === 'Windows'
        ? "@echo off\r\nping -n 60 127.0.0.1 >nul\r\n"
        : "#!/bin/sh\nsleep 60\n");
    chmod($script, 0755);

    config(['vault.soffice_binary' => $script, 'vault.consult.convert_timeout' => 1]);

    $workspace = Storage::disk('work')->path('consult-test-'.uniqid());
    mkdir($workspace, 0700, true);
    $input = $workspace.DIRECTORY_SEPARATOR.'input.docx';
    file_put_contents($input, 'x');

    try {
        expect(fn () => app(OfficeConverter::class)->toPdf($input, $workspace))
            ->toThrow(RuntimeException::class, 'convert_timeout');
    } finally {
        @unlink($input);
        @rmdir($workspace.DIRECTORY_SEPARATOR.'office-out');
        @rmdir($workspace);
    }
});
