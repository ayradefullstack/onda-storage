<?php

declare(strict_types=1);

use App\Infrastructure\Render\PptxOutlineRenderer;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

const PPTX_NS = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';

function pptxSlideXml(array $paragraphs, string $prolog = ''): string
{
    $body = '';

    foreach ($paragraphs as $paragraph) {
        $runs = implode('', array_map(fn (string $t) => "<a:r><a:t>{$t}</a:t></a:r>", (array) $paragraph));
        $body .= "<a:p>{$runs}</a:p>";
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$prolog
        .'<p:sld '.PPTX_NS.'><p:cSld><p:spTree><p:sp><p:txBody>'.$body.'</p:txBody></p:sp></p:spTree></p:cSld></p:sld>';
}

function pptxNotesXml(array $paragraphs): string
{
    $body = implode('', array_map(fn (string $t) => "<a:p><a:r><a:t>{$t}</a:t></a:r></a:p>", $paragraphs));

    return '<?xml version="1.0" encoding="UTF-8"?><p:notes '.PPTX_NS.'><p:cSld><p:spTree>'
        .'<p:sp><p:nvSpPr><p:cNvPr id="1" name="n"/><p:cNvSpPr/><p:nvPr><p:ph type="sldNum"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:fld><a:t>7</a:t></a:fld></a:p></p:txBody></p:sp>'
        .'<p:sp><p:nvSpPr><p:cNvPr id="2" name="b"/><p:cNvSpPr/><p:nvPr><p:ph type="body"/></p:nvPr></p:nvSpPr><p:txBody>'.$body.'</p:txBody></p:sp>'
        .'</p:spTree></p:cSld></p:notes>';
}

/**
 * @param  array<int, string>  $slides  slide number => xml
 * @param  array<int, string>  $notes  slide number => notes xml (notesSlide{N}.xml)
 */
function pptxFile(array $slides, array $notes = []): string
{
    $path = tempnam(sys_get_temp_dir(), 'pptx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');

    foreach ($slides as $n => $xml) {
        $zip->addFromString("ppt/slides/slide{$n}.xml", $xml);
    }

    foreach ($notes as $n => $xml) {
        $zip->addFromString("ppt/notesSlides/notesSlide{$n}.xml", $xml);
        $zip->addFromString(
            "ppt/slides/_rels/slide{$n}.xml.rels",
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide" Target="../notesSlides/notesSlide'.$n.'.xml"/></Relationships>',
        );
    }

    $zip->close();

    return $path;
}

function pptxRender(string $path): array
{
    $workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pptx-ws-'.uniqid();
    mkdir($workspace, 0700, true);

    $result = app(PptxOutlineRenderer::class)->render(new MediaFile, $path, $workspace);
    $outline = $result->status === 'ready'
        ? json_decode((string) file_get_contents($result->assets[0]->path), true, flags: JSON_THROW_ON_ERROR)
        : null;

    foreach (glob($workspace.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($workspace);
    @unlink($path);

    return [$result, $outline];
}

test('slides come out in numeric order with their texts and notes', function () {
    $path = pptxFile(
        [
            10 => pptxSlideXml([['Ten']]),
            2 => pptxSlideXml(['Deuxième', ['Ligne ', 'coupée']]),
            1 => pptxSlideXml([['عنوان']]),
        ],
        [2 => pptxNotesXml(['Note du présentateur'])],
    );

    [$result, $outline] = pptxRender($path);

    expect($result->status)->toBe('ready')
        ->and($result->pageCount)->toBe(3)
        ->and($result->assets[0]->kind)->toBe('outline')
        ->and($outline['slides'][0])->toBe(['index' => 1, 'texts' => ['عنوان'], 'notes' => []])
        ->and($outline['slides'][1])->toBe(['index' => 2, 'texts' => ['Deuxième', 'Ligne coupée'], 'notes' => ['Note du présentateur']])
        ->and($outline['slides'][2]['texts'])->toBe(['Ten']);
});

test('the notes slide-number placeholder is not a note', function () {
    [, $outline] = pptxRender(pptxFile([1 => pptxSlideXml(['A'])], [1 => pptxNotesXml(['real note'])]));

    expect($outline['slides'][0]['notes'])->toBe(['real note']);
});

test('an XXE payload in a slide is not expanded', function () {
    $secret = tempnam(sys_get_temp_dir(), 'secret');
    file_put_contents($secret, 'TOP-SECRET-MARKER');
    $uri = 'file:///'.ltrim(str_replace('\\', '/', $secret), '/');

    $evil = pptxSlideXml(
        [['before &xxe; after']],
        '<!DOCTYPE x [<!ENTITY xxe SYSTEM "'.$uri.'">]>',
    );

    [$result, $outline] = pptxRender(pptxFile([1 => $evil, 2 => pptxSlideXml(['clean'])]));
    @unlink($secret);

    expect($result->status)->toBe('ready')
        ->and(json_encode($outline))->not->toContain('TOP-SECRET-MARKER')
        // the malicious part is skipped, the next slide is untouched
        ->and($outline['slides'][0]['texts'])->toBe([])
        ->and($outline['slides'][1]['texts'])->toBe(['clean']);
});

test('an entity-expansion (billion laughs) payload is not expanded', function () {
    $evil = pptxSlideXml(
        [['&lol2;']],
        '<!DOCTYPE l [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">]>',
    );

    [, $outline] = pptxRender(pptxFile([1 => $evil]));

    expect(json_encode($outline))->not->toContain('lollol');
});

test('the slide cap is enforced and noticed', function () {
    config(['vault.consult.pptx_max_slides' => 2]);

    [$result, $outline] = pptxRender(pptxFile([
        1 => pptxSlideXml(['a']),
        2 => pptxSlideXml(['b']),
        3 => pptxSlideXml(['c']),
    ]));

    expect($outline['slides'])->toHaveCount(2)
        ->and($result->reason)->toBe('pages_truncated');
});

test('the total text cap is enforced and noticed', function () {
    config(['vault.consult.pptx_max_text_bytes' => 10]);

    [$result, $outline] = pptxRender(pptxFile([
        1 => pptxSlideXml(['abcdef']),
        2 => pptxSlideXml(['ghijkl']),
        3 => pptxSlideXml(['mnopqr']),
    ]));

    expect($outline['slides'])->toHaveCount(1)
        ->and($result->reason)->toBe('text_truncated');
});

test('a zip with no slides fails with a reason, it does not throw', function () {
    [$result] = pptxRender(pptxFile([]));

    expect($result->status)->toBe('failed');
});

test('a pptx deposit becomes an encrypted outline asset through the pipeline, with no office tool', function () {
    config(['vault.soffice_binary' => 'C:/definitely/not/soffice.exe']);

    $author = consultAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id]);
    $path = pptxFile([1 => pptxSlideXml(['Plan']), 2 => pptxSlideXml(['Fin'])]);
    $file = consultFile($author, $oeuvre, (string) file_get_contents($path), 'deck.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation');
    @unlink($path);

    $consultation = consultGenerate($file);
    $asset = consultAssetRow($file, 'outline', 0);

    expect($consultation->status)->toBe('ready')
        ->and($consultation->page_count)->toBe(2)
        ->and($asset)->not->toBeNull();

    $plain = consultPlain($file, $asset);

    expect(json_decode($plain, true)['slides'][1]['texts'])->toBe(['Fin']);

    // encrypted at rest: the stored bytes are not the JSON
    expect(Storage::disk('variants')->get($asset->path))->not->toContain('Plan');

    consultCleanup($file);
});

test('ppt and odp are an unsupported card with no decryption', function (string $name) {
    $author = consultAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id]);
    $file = consultFile($author, $oeuvre, 'binary deck', $name, 'application/vnd.ms-powerpoint');

    $consultation = consultGenerate($file);

    expect($consultation->status)->toBe('unsupported')
        ->and($consultation->reason)->toBe('format_unavailable');

    consultCleanup($file);
})->with(['deck.ppt', 'deck.odp']);
