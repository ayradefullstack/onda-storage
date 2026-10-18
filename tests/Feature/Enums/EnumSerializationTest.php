<?php

declare(strict_types=1);

use App\Domain\Access\AccessLogger;
use App\Domain\Deposit\Value\RowHash;
use App\Enums\FileAccessLog\ActionEnum;
use App\Enums\MediaFile\ReviewStatusEnum;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Enums\Referentiel\StatusEnum as ReferentielStatus;
use App\Models\FileAccessLog;
use App\Models\Language;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\ReferenceDataChange;
use App\Models\RegisterType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// --- the database holds the int, everything that leaves the backend holds the key ---

test('an enum column is stored as its int and serialised as its key', function () {
    $oeuvre = Oeuvre::factory()->submitted()->create();

    expect(DB::table('oeuvres')->where('id', $oeuvre->id)->value('status'))->toBe(OeuvreStatus::SUBMITTED->value)
        ->and($oeuvre->fresh()->status)->toBe(OeuvreStatus::SUBMITTED)
        ->and($oeuvre->fresh()->toArray()['status'])->toBe('submitted')
        ->and(json_decode($oeuvre->fresh()->toJson(), true)['status'])->toBe('submitted');

    expect(Language::where('code', 'ar')->first()->toArray()['direction'])->toBe('rtl')
        ->and(RegisterType::factory()->create()->fresh()->toArray()['status'])->toBe('active');
});

test('Inertia props carry the key, not the int', function () {
    $author = User::factory()->withRole('author')->create();
    Oeuvre::factory()->submitted()->create(['author_id' => $author->id]);

    $this->actingAs($author)->get(route('oeuvres.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('oeuvres.data.0.status', 'submitted')
            ->where('statuses', ['draft', 'submitted', 'under_review', 'registered', 'rejected'])
            ->where('counts.submitted', 1));
});

// --- URL filters take the key; anything else is no filter at all ----------------

test('the admin list filters on the status key and ignores an unknown key or a raw int', function () {
    $admin = User::factory()->withRole('admin')->create();
    $submitted = Oeuvre::factory()->submitted()->create();
    Oeuvre::factory()->registered()->create();

    $this->actingAs($admin)->get(route('admin.oeuvres.index', ['status' => 'submitted']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('oeuvres.data', 1)->where('oeuvres.data.0.uuid', $submitted->uuid));

    foreach (['archived', '2', 'SUBMITTED'] as $unknown) {
        $this->actingAs($admin)->get(route('admin.oeuvres.index', ['status' => $unknown]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('oeuvres.data', 2));
    }
});

test('the variant route resolves {kind} by key, and an unknown or unserved key is a 404', function () {
    $admin = User::factory()->withRole('admin')->create();
    $file = MediaFile::factory()->create();

    foreach (['bogus', 'thumbnail', '1'] as $kind) {
        $this->actingAs($admin)->get(route('admin.media.variant', ['mediaFile' => $file, 'kind' => $kind]))->assertNotFound();
    }
});

test('a référentiel form posts the key; an unknown key is a 422, never a 500', function () {
    $admin = User::factory()->withRole('admin')->create();
    $type = RegisterType::factory()->create();

    $this->actingAs($admin)->patch(route('admin.referentiel.types.update', $type), ['status' => 'inactive'])->assertSessionHasNoErrors();
    expect($type->fresh()->status)->toBe(ReferentielStatus::INACTIVE);

    $this->actingAs($admin)->patch(route('admin.referentiel.types.update', $type), ['status' => 'archived'])->assertSessionHasErrors('status');
    expect($type->fresh()->status)->toBe(ReferentielStatus::INACTIVE);
});

// --- hash chains: the payload representation did not change ----------------------

test('the access ledger hashes the action as its key, exactly the string it held before', function () {
    $this->freezeTime();
    $file = MediaFile::factory()->create();
    $user = User::factory()->create();

    AccessLogger::record($file, $user->id, ActionEnum::STREAM, '127.0.0.1', 'agent');

    $row = FileAccessLog::where('media_file_id', $file->id)->sole();

    expect(DB::table('file_access_logs')->where('id', $row->id)->value('action'))->toBe(ActionEnum::STREAM->value)
        ->and($row->action)->toBe(ActionEnum::STREAM)
        ->and(RowHash::verify($row->prev_hash, [
            'action' => 'stream',
            'media_file_uuid' => $file->uuid,
            'user_id' => $user->id,
            'ip' => '127.0.0.1',
            'range_header' => null,
            'logged_at' => now()->toISOString(),
        ], $row->row_hash))->toBeTrue()
        // The int would be a different chain: it must not verify.
        ->and(RowHash::verify($row->prev_hash, [
            'action' => ActionEnum::STREAM->value,
            'media_file_uuid' => $file->uuid,
            'user_id' => $user->id,
            'ip' => '127.0.0.1',
            'range_header' => null,
            'logged_at' => now()->toISOString(),
        ], $row->row_hash))->toBeFalse();
});

test('the reference-data trail records and hashes an enum change as its permanent int', function () {
    $actor = User::factory()->withRole('admin')->create();
    $type = RegisterType::factory()->create();

    ReferenceDataChange::record($actor, $type, ['status' => ReferentielStatus::ACTIVE], ['status' => ReferentielStatus::INACTIVE], ['status']);

    $row = ReferenceDataChange::where('subject_id', $type->id)->sole();

    expect($row->old_value)->toBe('1')
        ->and($row->new_value)->toBe('2')
        ->and(RowHash::verify($row->prev_hash, [
            'actor_id' => $actor->id,
            'subject_type' => $type->getMorphClass(),
            'subject_id' => $type->id,
            'subject_uuid' => $type->uuid,
            'field' => 'status',
            'old_value' => '1',
            'new_value' => '2',
        ], $row->row_hash))->toBeTrue();
});

// --- media_files.review_status: a second enum on the same row ------------------

test('review_status is stored as its int, cast to its enum and serialised as its key', function () {
    $file = MediaFile::factory()->create();

    // The column default is PENDING.
    expect(DB::table('media_files')->where('id', $file->id)->value('review_status'))->toBe(ReviewStatusEnum::PENDING->value)
        ->and($file->fresh()->review_status)->toBe(ReviewStatusEnum::PENDING)
        ->and($file->fresh()->toArray()['review_status'])->toBe('pending');

    $file->forceFill(['review_status' => ReviewStatusEnum::REJECTED])->save();

    expect(DB::table('media_files')->where('id', $file->id)->value('review_status'))->toBe(3)
        ->and(json_decode($file->fresh()->toJson(), true)['review_status'])->toBe('rejected')
        // The pipeline status is a different column and did not move.
        ->and($file->fresh()->status)->toBe($file->status);
});
