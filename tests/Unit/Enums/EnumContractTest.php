<?php

declare(strict_types=1);

use App\Enums\Contracts\HasPresentation;
use App\Enums\FileAccessLog\ActionEnum;
use App\Enums\Language\DirectionEnum;
use App\Enums\MediaConsultation\StatusEnum as ConsultationStatus;
use App\Enums\MediaFile\StatusEnum as MediaFileStatus;
use App\Enums\MediaVariant\KindEnum;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Enums\Referentiel\StatusEnum as ReferentielStatus;
use App\Enums\TypeGestion\TypeGestionEnum;
use App\Enums\UploadSession\StatusEnum as UploadSessionStatus;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The stored ints are PERMANENT: rows, migration column comments and (for
 * reference_data_changes) the hash chain depend on them. This snapshot is
 * the contract — a renumbered or renamed case fails here first. A new case
 * takes the next free number and is ADDED to this list; nothing is removed.
 */
dataset('enum_snapshots', [
    'oeuvre.status' => [OeuvreStatus::class, 'oeuvre.status', [1 => 'draft', 2 => 'submitted', 3 => 'under_review', 4 => 'registered', 5 => 'rejected']],
    'media_file.status' => [MediaFileStatus::class, 'media_file.status', [1 => 'uploading', 2 => 'assembling', 3 => 'scanning', 4 => 'processing', 5 => 'ready', 6 => 'failed', 7 => 'quarantined']],
    'upload_session.status' => [UploadSessionStatus::class, 'upload_session.status', [1 => 'pending', 2 => 'uploading', 3 => 'aborted', 4 => 'completed']],
    'media_variant.kind' => [KindEnum::class, 'media_variant.kind', [1 => 'poster', 2 => 'preview', 3 => 'waveform', 4 => 'thumbnail']],
    'file_access_log.action' => [ActionEnum::class, 'file_access_log.action', [
        1 => 'stream', 2 => 'download', 3 => 'preview', 4 => 'delete', 5 => 'purge', 6 => 'deposit', 7 => 'inspect',
        8 => 'admin_consult', 9 => 'bytes_released', 10 => 'bytes_kept', 11 => 'bytes_missing', 12 => 'consult_original',
    ]],
    'media_consultation.status' => [ConsultationStatus::class, 'media_consultation.status', [1 => 'pending', 2 => 'ready', 3 => 'failed', 4 => 'unsupported']],
    'language.direction' => [DirectionEnum::class, 'language.direction', [1 => 'ltr', 2 => 'rtl']],
    'type_gestion.type_gestion' => [TypeGestionEnum::class, 'type_gestion.type_gestion', [1 => 'collective', 2 => 'individual', 3 => 'simple']],
    'referentiel.status' => [ReferentielStatus::class, 'referentiel.status', [1 => 'active', 2 => 'inactive']],
]);

test('values and keys match the permanent snapshot', function (string $enum, string $labelPath, array $snapshot) {
    $actual = [];

    foreach ($enum::cases() as $case) {
        $actual[$case->value] = $case->key();
    }

    expect($actual)->toBe($snapshot)
        ->and($enum::values())->toBe(array_keys($snapshot))
        ->and($enum::keys())->toBe(array_values($snapshot))
        // Unique by construction of the snapshot, asserted anyway.
        ->and(array_unique($enum::values()))->toHaveCount(count($snapshot))
        ->and(min($enum::values()))->toBe(1);
})->with('enum_snapshots');

test('every case has a label in ar, fr and en', function (string $enum, string $labelPath) {
    foreach (['ar', 'fr', 'en'] as $locale) {
        foreach ($enum::cases() as $case) {
            $key = "enums.{$labelPath}.{$case->key()}";

            expect(trans()->has($key, $locale, false))->toBeTrue("Missing [{$locale}] {$key}");
        }
    }

    app()->setLocale('fr');
    foreach ($enum::cases() as $case) {
        expect($case->label())->not->toBe("enums.{$labelPath}.{$case->key()}");
    }
})->with('enum_snapshots');

test('fromKey round-trips every case and returns null for anything else', function (string $enum) {
    foreach ($enum::cases() as $case) {
        expect($enum::fromKey($case->key()))->toBe($case);
    }

    expect($enum::fromKey(null))->toBeNull()
        ->and($enum::fromKey(''))->toBeNull()
        ->and($enum::fromKey('archived'))->toBeNull()
        // A translated label or a stringified int is never a key.
        ->and($enum::fromKey('1'))->toBeNull()
        ->and($enum::fromKey(strtoupper($enum::cases()[0]->key())))->toBeNull();
})->with('enum_snapshots');

test('dbComment names the class and lists every case', function (string $enum, string $labelPath, array $snapshot) {
    $pairs = [];
    foreach ($snapshot as $value => $key) {
        $pairs[] = "{$value}={$key}";
    }

    expect($enum::dbComment())->toBe($enum.': '.implode(', ', $pairs));
})->with('enum_snapshots');

test('options, colours, icons and JSON follow the shared contract', function (string $enum) {
    $tones = ['success', 'warning', 'destructive', 'info', 'neutral', 'primary'];

    foreach ($enum::cases() as $case) {
        expect($case)->toBeInstanceOf(HasPresentation::class)
            ->and($tones)->toContain($case->color())
            ->and($case->icon())->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/')
            // An enum leaves the backend as its key.
            ->and(json_encode(['x' => $case]))->toBe(json_encode(['x' => $case->key()]));
    }

    expect(array_column($enum::options(), 'value'))->toBe($enum::values())
        ->and(array_column($enum::options(), 'key'))->toBe($enum::keys());
})->with('enum_snapshots');
