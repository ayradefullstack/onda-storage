<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

/**
 * How a deposited file is shown to a reviewer. Decided in exactly one place:
 * `PreviewFamilyResolver`.
 */
enum PreviewFamily: string
{
    case Pdf = 'pdf';
    case Document = 'document';
    case Presentation = 'presentation';
    case Spreadsheet = 'spreadsheet';
    case Csv = 'csv';
    case Image = 'image';
    case Text = 'text';
    case Video = 'video';
    case Audio = 'audio';
    case Archive = 'archive';
    case Other = 'other';
}
