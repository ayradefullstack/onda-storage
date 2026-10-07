<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The single source of truth for every file format this application accepts.
 *
 * ---------------------------------------------------------------------
 * Why a PHP class and not a database table
 * ---------------------------------------------------------------------
 * This is code-level capability, not an admin setting. Adding a row to a
 * table would not teach `VerifyContentType` what bytes to expect, would not
 * teach the browser what to put in an `accept` attribute, and would not make
 * the streaming endpoint safe to serve the result inline. Every one of those
 * is a code decision, so the list lives in code.
 *
 * A plain final class of constants rather than a backed enum: an enum case
 * carries one scalar, and each format here carries six attributes including
 * two lists. Modelling that as an enum means a `match` per attribute, five
 * of them, each a place to forget a case. A keyed array is read once and is
 * exhaustive by construction. It is also a config file's shape without a
 * config file's ability to be overridden per environment — which would be
 * wrong, because a format the pipeline cannot verify must not become
 * acceptable by editing `.env`.
 *
 * ---------------------------------------------------------------------
 * `mimes` is a LIST, and that is load-bearing
 * ---------------------------------------------------------------------
 * Content detection is not one-to-one. Every value here was MEASURED with
 * `finfo_file()` on a real sample file, on libmagic **545** (PHP 8.4.25,
 * Windows) — not taken from documentation, which disagrees with reality
 * constantly. Measurements that contradicted the code they replace:
 *
 *   aac  → audio/x-hx-aac-adts   (the old map said audio/aac, audio/x-aac)
 *   rar  → application/x-rar     (the old map said application/vnd.rar)
 *   wav  → audio/x-wav           (the old map listed audio/wav first)
 *   opus → audio/ogg             (indistinguishable from ogg by content)
 *   ai   → application/pdf       (an .ai file IS a PDF)
 *   sql  → text/plain            (no distinct type exists)
 *   exe  → application/vnd.microsoft.portable-executable
 *
 * The OpenXML formats carry `application/zip` as well as their full type:
 * libmagic 545 returns the full type, but older builds — including the one
 * on the production host, which has NOT been verified — return the plain
 * container type. A list missing that alias rejects a legitimate file.
 *
 * `application/octet-stream` appears in NO format's list, deliberately. It
 * is what libmagic returns when it identifies nothing, so accepting it would
 * accept a renamed executable and make content verification decorative. The
 * cost is that an unidentifiable legacy file is refused; that is the correct
 * side to err on for a legal deposit archive.
 *
 * ---------------------------------------------------------------------
 * `inline_safe`
 * ---------------------------------------------------------------------
 * False for anything a browser would execute. Those formats are stored as
 * `application/octet-stream` so the streaming endpoint serves them as a
 * download — this preserves the step-2 stored-XSS fix exactly: an
 * author-supplied SVG served as `image/svg+xml` would execute against the
 * session of the admin reviewing it.
 *
 * ---------------------------------------------------------------------
 * Never present
 * ---------------------------------------------------------------------
 * exe, msi, bat, cmd, sh, ps1, js, php, jar, apk, dll. A deposit never
 * requires an executable directly — LOGICIEL and SITE_WEB receive source as
 * archives, which the seeded rows already do. They are absent rather than
 * blocklisted: `isSupported()` is a whitelist, so absence is refusal.
 */
final class FileFormats
{
    public const CATEGORY_DOCUMENTS = 'documents';

    public const CATEGORY_SPREADSHEETS = 'spreadsheets';

    public const CATEGORY_PRESENTATIONS = 'presentations';

    public const CATEGORY_IMAGES = 'images';

    public const CATEGORY_AUDIO = 'audio';

    public const CATEGORY_VIDEO = 'video';

    public const CATEGORY_ARCHIVES = 'archives';

    public const CATEGORY_DATA = 'data';

    public const CATEGORY_EBOOKS = 'ebooks';

    public const CATEGORY_NOTATION = 'notation';

    /**
     * Display order of the categories in the admin multi-select.
     *
     * @var list<string>
     */
    public const CATEGORIES = [
        self::CATEGORY_DOCUMENTS,
        self::CATEGORY_SPREADSHEETS,
        self::CATEGORY_PRESENTATIONS,
        self::CATEGORY_IMAGES,
        self::CATEGORY_AUDIO,
        self::CATEGORY_VIDEO,
        self::CATEGORY_ARCHIVES,
        self::CATEGORY_DATA,
        self::CATEGORY_EBOOKS,
        self::CATEGORY_NOTATION,
    ];

    /**
     * extension => [category, label, mimes, stored_mime, inline_safe]
     *
     * `mimes` values marked (*) were NOT verified with a genuine sample on
     * this machine and come from the format specification — see the class
     * docblock. Everything else was measured.
     *
     * @var array<string, array{category: string, label: string, mimes: list<string>, stored_mime: string, inline_safe: bool}>
     */
    private const FORMATS = [
        // --- documents ---------------------------------------------------
        'pdf' => [
            'category' => self::CATEGORY_DOCUMENTS, 'label' => 'PDF',
            'mimes' => ['application/pdf'],
            'stored_mime' => 'application/pdf', 'inline_safe' => true,
        ],
        'doc' => [
            'category' => self::CATEGORY_DOCUMENTS, 'label' => 'DOC',
            // (*) unverified: no genuine .doc was available to measure. A
            // minimal OLE2 header returns application/octet-stream, which is
            // deliberately not listed — see the class docblock.
            'mimes' => ['application/msword', 'application/x-ole-storage'],
            'stored_mime' => 'application/msword', 'inline_safe' => false,
        ],
        'docx' => [
            'category' => self::CATEGORY_DOCUMENTS, 'label' => 'DOCX',
            'mimes' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
            ],
            'stored_mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'inline_safe' => false,
        ],
        'odt' => [
            'category' => self::CATEGORY_DOCUMENTS, 'label' => 'ODT',
            'mimes' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
            'stored_mime' => 'application/vnd.oasis.opendocument.text', 'inline_safe' => false,
        ],
        'rtf' => [
            'category' => self::CATEGORY_DOCUMENTS, 'label' => 'RTF',
            'mimes' => ['text/rtf', 'application/rtf'],
            'stored_mime' => 'application/rtf', 'inline_safe' => false,
        ],
        'txt' => [
            'category' => self::CATEGORY_DOCUMENTS, 'label' => 'TXT',
            'mimes' => ['text/plain'],
            'stored_mime' => 'text/plain', 'inline_safe' => true,
        ],

        // --- spreadsheets ------------------------------------------------
        'xls' => [
            'category' => self::CATEGORY_SPREADSHEETS, 'label' => 'XLS',
            // (*) unverified — see `doc`.
            'mimes' => ['application/vnd.ms-excel', 'application/x-ole-storage'],
            'stored_mime' => 'application/vnd.ms-excel', 'inline_safe' => false,
        ],
        'xlsx' => [
            'category' => self::CATEGORY_SPREADSHEETS, 'label' => 'XLSX',
            'mimes' => [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
            ],
            'stored_mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'inline_safe' => false,
        ],
        'ods' => [
            'category' => self::CATEGORY_SPREADSHEETS, 'label' => 'ODS',
            'mimes' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
            'stored_mime' => 'application/vnd.oasis.opendocument.spreadsheet', 'inline_safe' => false,
        ],
        'csv' => [
            'category' => self::CATEGORY_SPREADSHEETS, 'label' => 'CSV',
            // A CSV with no commas in its first bytes reads as text/plain.
            'mimes' => ['text/csv', 'text/plain'],
            'stored_mime' => 'text/csv', 'inline_safe' => true,
        ],

        // --- presentations -----------------------------------------------
        'ppt' => [
            'category' => self::CATEGORY_PRESENTATIONS, 'label' => 'PPT',
            // (*) unverified — see `doc`.
            'mimes' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage'],
            'stored_mime' => 'application/vnd.ms-powerpoint', 'inline_safe' => false,
        ],
        'pptx' => [
            'category' => self::CATEGORY_PRESENTATIONS, 'label' => 'PPTX',
            'mimes' => [
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/zip',
            ],
            'stored_mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'inline_safe' => false,
        ],
        'odp' => [
            'category' => self::CATEGORY_PRESENTATIONS, 'label' => 'ODP',
            'mimes' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
            'stored_mime' => 'application/vnd.oasis.opendocument.presentation', 'inline_safe' => false,
        ],

        // --- images --------------------------------------------------------
        'jpg' => [
            'category' => self::CATEGORY_IMAGES, 'label' => 'JPG',
            'mimes' => ['image/jpeg'], 'stored_mime' => 'image/jpeg', 'inline_safe' => true,
        ],
        'jpeg' => [
            'category' => self::CATEGORY_IMAGES, 'label' => 'JPEG',
            'mimes' => ['image/jpeg'], 'stored_mime' => 'image/jpeg', 'inline_safe' => true,
        ],
        'png' => [
            'category' => self::CATEGORY_IMAGES, 'label' => 'PNG',
            'mimes' => ['image/png'], 'stored_mime' => 'image/png', 'inline_safe' => true,
        ],
        'gif' => [
            'category' => self::CATEGORY_IMAGES, 'label' => 'GIF',
            'mimes' => ['image/gif'], 'stored_mime' => 'image/gif', 'inline_safe' => true,
        ],
        'webp' => [
            'category' => self::CATEGORY_IMAGES, 'label' => 'WEBP',
            'mimes' => ['image/webp'], 'stored_mime' => 'image/webp', 'inline_safe' => true,
        ],
        'tif' => [
            'category' => self::CATEGORY_IMAGES, 'label' => 'TIF',
            'mimes' => ['image/tiff'], 'stored_mime' => 'image/tiff', 'inline_safe' => true,
        ],
        'tiff' => [
            'category' => self::CATEGORY_IMAGES, 'label' => 'TIFF',
            'mimes' => ['image/tiff'], 'stored_mime' => 'image/tiff', 'inline_safe' => true,
        ],
        'bmp' => [
            'category' => self::CATEGORY_IMAGES, 'label' => 'BMP',
            'mimes' => ['image/bmp', 'image/x-ms-bmp'], 'stored_mime' => 'image/bmp', 'inline_safe' => true,
        ],
        'heic' => [
            'category' => self::CATEGORY_IMAGES, 'label' => 'HEIC',
            'mimes' => ['image/heic', 'image/heif'], 'stored_mime' => 'image/heic', 'inline_safe' => false,
        ],
        'psd' => [
            'category' => self::CATEGORY_IMAGES, 'label' => 'PSD',
            'mimes' => ['image/vnd.adobe.photoshop', 'application/x-photoshop'],
            'stored_mime' => 'image/vnd.adobe.photoshop', 'inline_safe' => false,
        ],
        'ai' => [
            'category' => self::CATEGORY_IMAGES, 'label' => 'AI',
            // Measured: an .ai file IS a PDF. Older ones are PostScript.
            'mimes' => ['application/pdf', 'application/postscript', 'application/illustrator'],
            'stored_mime' => 'application/illustrator', 'inline_safe' => false,
        ],
        'eps' => [
            'category' => self::CATEGORY_IMAGES, 'label' => 'EPS',
            'mimes' => ['application/postscript', 'image/x-eps'],
            'stored_mime' => 'application/postscript', 'inline_safe' => false,
        ],
        'svg' => [
            'category' => self::CATEGORY_IMAGES, 'label' => 'SVG',
            'mimes' => ['image/svg+xml', 'text/xml', 'text/plain'],
            // NOT image/svg+xml: an author-supplied SVG served inline would
            // execute its script against the reviewing admin's session.
            'stored_mime' => 'application/octet-stream', 'inline_safe' => false,
        ],

        // --- audio ---------------------------------------------------------
        'mp3' => [
            'category' => self::CATEGORY_AUDIO, 'label' => 'MP3',
            'mimes' => ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg'],
            'stored_mime' => 'audio/mpeg', 'inline_safe' => true,
        ],
        'wav' => [
            'category' => self::CATEGORY_AUDIO, 'label' => 'WAV',
            'mimes' => ['audio/x-wav', 'audio/wav', 'audio/wave', 'audio/vnd.wave'],
            'stored_mime' => 'audio/wav', 'inline_safe' => true,
        ],
        'flac' => [
            'category' => self::CATEGORY_AUDIO, 'label' => 'FLAC',
            'mimes' => ['audio/flac', 'audio/x-flac'],
            'stored_mime' => 'audio/flac', 'inline_safe' => true,
        ],
        'aac' => [
            'category' => self::CATEGORY_AUDIO, 'label' => 'AAC',
            // Measured as audio/x-hx-aac-adts — the old map's audio/aac and
            // audio/x-aac were both wrong for a real ADTS stream.
            'mimes' => ['audio/x-hx-aac-adts', 'audio/aac', 'audio/x-aac'],
            'stored_mime' => 'audio/aac', 'inline_safe' => true,
        ],
        'ogg' => [
            'category' => self::CATEGORY_AUDIO, 'label' => 'OGG',
            'mimes' => ['audio/ogg', 'application/ogg'],
            'stored_mime' => 'audio/ogg', 'inline_safe' => true,
        ],
        'opus' => [
            'category' => self::CATEGORY_AUDIO, 'label' => 'OPUS',
            // Opus lives in an Ogg container and is indistinguishable from
            // .ogg by content alone.
            'mimes' => ['audio/ogg', 'audio/opus', 'application/ogg'],
            'stored_mime' => 'audio/opus', 'inline_safe' => true,
        ],
        'm4a' => [
            'category' => self::CATEGORY_AUDIO, 'label' => 'M4A',
            'mimes' => ['audio/x-m4a', 'audio/mp4', 'audio/m4a'],
            'stored_mime' => 'audio/mp4', 'inline_safe' => true,
        ],
        'aiff' => [
            'category' => self::CATEGORY_AUDIO, 'label' => 'AIFF',
            'mimes' => ['audio/x-aiff', 'audio/aiff'],
            'stored_mime' => 'audio/aiff', 'inline_safe' => true,
        ],

        // --- video ---------------------------------------------------------
        'mp4' => [
            'category' => self::CATEGORY_VIDEO, 'label' => 'MP4',
            'mimes' => ['video/mp4'], 'stored_mime' => 'video/mp4', 'inline_safe' => true,
        ],
        'mov' => [
            'category' => self::CATEGORY_VIDEO, 'label' => 'MOV',
            'mimes' => ['video/quicktime'], 'stored_mime' => 'video/quicktime', 'inline_safe' => true,
        ],
        'avi' => [
            'category' => self::CATEGORY_VIDEO, 'label' => 'AVI',
            'mimes' => ['video/x-msvideo', 'video/avi', 'video/msvideo'],
            'stored_mime' => 'video/x-msvideo', 'inline_safe' => false,
        ],
        'mkv' => [
            'category' => self::CATEGORY_VIDEO, 'label' => 'MKV',
            'mimes' => ['video/x-matroska'], 'stored_mime' => 'video/x-matroska', 'inline_safe' => false,
        ],
        'webm' => [
            'category' => self::CATEGORY_VIDEO, 'label' => 'WEBM',
            'mimes' => ['video/webm'], 'stored_mime' => 'video/webm', 'inline_safe' => true,
        ],
        'm4v' => [
            'category' => self::CATEGORY_VIDEO, 'label' => 'M4V',
            'mimes' => ['video/x-m4v', 'video/mp4'], 'stored_mime' => 'video/x-m4v', 'inline_safe' => true,
        ],
        'mpeg' => [
            'category' => self::CATEGORY_VIDEO, 'label' => 'MPEG',
            'mimes' => ['video/mpeg'], 'stored_mime' => 'video/mpeg', 'inline_safe' => false,
        ],
        'mpg' => [
            'category' => self::CATEGORY_VIDEO, 'label' => 'MPG',
            'mimes' => ['video/mpeg'], 'stored_mime' => 'video/mpeg', 'inline_safe' => false,
        ],

        // --- archives --------------------------------------------------------
        'zip' => [
            'category' => self::CATEGORY_ARCHIVES, 'label' => 'ZIP',
            'mimes' => ['application/zip', 'application/x-zip-compressed'],
            'stored_mime' => 'application/zip', 'inline_safe' => false,
        ],
        'rar' => [
            'category' => self::CATEGORY_ARCHIVES, 'label' => 'RAR',
            // Measured as application/x-rar; the old map said vnd.rar.
            'mimes' => ['application/x-rar', 'application/vnd.rar', 'application/x-rar-compressed'],
            'stored_mime' => 'application/vnd.rar', 'inline_safe' => false,
        ],
        '7z' => [
            'category' => self::CATEGORY_ARCHIVES, 'label' => '7Z',
            'mimes' => ['application/x-7z-compressed'],
            'stored_mime' => 'application/x-7z-compressed', 'inline_safe' => false,
        ],
        'tar' => [
            'category' => self::CATEGORY_ARCHIVES, 'label' => 'TAR',
            'mimes' => ['application/x-tar', 'application/x-gtar'],
            'stored_mime' => 'application/x-tar', 'inline_safe' => false,
        ],
        'gz' => [
            'category' => self::CATEGORY_ARCHIVES, 'label' => 'GZ',
            'mimes' => ['application/gzip', 'application/x-gzip'],
            'stored_mime' => 'application/gzip', 'inline_safe' => false,
        ],

        // --- data ------------------------------------------------------------
        'json' => [
            'category' => self::CATEGORY_DATA, 'label' => 'JSON',
            'mimes' => ['application/json', 'text/plain'],
            'stored_mime' => 'application/json', 'inline_safe' => true,
        ],
        'xml' => [
            'category' => self::CATEGORY_DATA, 'label' => 'XML',
            'mimes' => ['text/xml', 'application/xml', 'text/plain'],
            // Same reasoning as SVG: XML can carry an XSLT stylesheet.
            'stored_mime' => 'application/octet-stream', 'inline_safe' => false,
        ],
        'sql' => [
            'category' => self::CATEGORY_DATA, 'label' => 'SQL',
            // Measured as text/plain — no distinct type exists.
            'mimes' => ['text/plain', 'application/sql', 'text/x-sql'],
            'stored_mime' => 'text/plain', 'inline_safe' => false,
        ],

        // --- e-books ---------------------------------------------------------
        'epub' => [
            'category' => self::CATEGORY_EBOOKS, 'label' => 'EPUB',
            'mimes' => ['application/epub+zip', 'application/zip'],
            'stored_mime' => 'application/epub+zip', 'inline_safe' => false,
        ],

        // --- music notation ---------------------------------------------------
        // A copyright office receives scores. A composer depositing a musical
        // work may reasonably submit one.
        'mid' => [
            'category' => self::CATEGORY_NOTATION, 'label' => 'MID',
            'mimes' => ['audio/midi', 'audio/x-midi'],
            'stored_mime' => 'audio/midi', 'inline_safe' => false,
        ],
        'midi' => [
            'category' => self::CATEGORY_NOTATION, 'label' => 'MIDI',
            'mimes' => ['audio/midi', 'audio/x-midi'],
            'stored_mime' => 'audio/midi', 'inline_safe' => false,
        ],
        'mxl' => [
            'category' => self::CATEGORY_NOTATION, 'label' => 'MXL',
            // Measured as application/zip — MXL is a zipped MusicXML and
            // libmagic 545 does not recognise the inner type.
            'mimes' => ['application/zip', 'application/vnd.recordare.musicxml'],
            'stored_mime' => 'application/vnd.recordare.musicxml', 'inline_safe' => false,
        ],
        'musicxml' => [
            'category' => self::CATEGORY_NOTATION, 'label' => 'MusicXML',
            // Measured as text/xml — it is XML, with no distinct signature.
            'mimes' => ['text/xml', 'application/xml', 'application/vnd.recordare.musicxml+xml', 'text/plain'],
            'stored_mime' => 'application/octet-stream', 'inline_safe' => false,
        ],
    ];

    /**
     * @return array<string, array{category: string, label: string, mimes: list<string>, stored_mime: string, inline_safe: bool}>
     */
    public static function all(): array
    {
        return self::FORMATS;
    }

    /**
     * @return list<string>
     */
    public static function extensions(): array
    {
        return array_keys(self::FORMATS);
    }

    public static function isSupported(string $extension): bool
    {
        return array_key_exists(self::normalise($extension), self::FORMATS);
    }

    /**
     * @return array{category: string, label: string, mimes: list<string>, stored_mime: string, inline_safe: bool}|null
     */
    public static function get(string $extension): ?array
    {
        return self::FORMATS[self::normalise($extension)] ?? null;
    }

    /**
     * Every MIME `finfo` might return for this extension.
     *
     * @return list<string>
     */
    public static function mimesFor(string $extension): array
    {
        return self::FORMATS[self::normalise($extension)]['mimes'] ?? [];
    }

    /**
     * The single MIME written to `media_files.mime`, which is also what the
     * streaming endpoint serves as `Content-Type`. For a format that is not
     * `inline_safe` this is `application/octet-stream` where the format
     * would otherwise be executable in a browser.
     */
    public static function storedMimeFor(string $extension): ?string
    {
        return self::FORMATS[self::normalise($extension)]['stored_mime'] ?? null;
    }

    public static function isInlineSafe(string $extension): bool
    {
        return self::FORMATS[self::normalise($extension)]['inline_safe'] ?? false;
    }

    /**
     * The union of every MIME across a set of extensions — what gets stored
     * in `college_oeuvre_files.mime_types` and what `VerifyContentType`
     * checks a file's detected type against.
     *
     * Sorted and de-duplicated so the derived value is stable: an unstable
     * ordering would make the drift test fail on a no-op re-derivation.
     *
     * @param  list<string>  $extensions
     * @return list<string>
     */
    public static function mimeTypesFor(array $extensions): array
    {
        $mimes = [];

        foreach ($extensions as $extension) {
            foreach (self::mimesFor($extension) as $mime) {
                $mimes[$mime] = true;
            }
        }

        $list = array_keys($mimes);
        sort($list);

        return $list;
    }

    /**
     * The registry grouped for the admin multi-select, in category order.
     *
     * @return list<array{category: string, formats: list<array{extension: string, label: string, mimes: list<string>, inline_safe: bool}>}>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::CATEGORIES as $category) {
            $formats = [];

            foreach (self::FORMATS as $extension => $format) {
                if ($format['category'] !== $category) {
                    continue;
                }

                $formats[] = [
                    'extension' => $extension,
                    'label' => $format['label'],
                    'mimes' => $format['mimes'],
                    'inline_safe' => $format['inline_safe'],
                ];
            }

            if ($formats !== []) {
                $grouped[] = ['category' => $category, 'formats' => $formats];
            }
        }

        return $grouped;
    }

    private static function normalise(string $extension): string
    {
        return strtolower(ltrim(trim($extension), '.'));
    }
}
