<?php

declare(strict_types=1);

use App\Enums\FileAccessLog\ActionEnum;
use App\Enums\MediaFile\StatusEnum as MediaFileStatus;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Models\FileAccessLog;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the works index hides drafts by default, even one belonging to the viewed author', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = User::factory()->withRole('author')->create();

    Oeuvre::factory()->create(['author_id' => $author->id, 'title' => 'Still drafting', 'status' => OeuvreStatus::DRAFT]);
    $submitted = Oeuvre::factory()->create(['author_id' => $author->id, 'title' => 'Sent in', 'status' => OeuvreStatus::SUBMITTED]);

    $response = $this->actingAs($admin)->get(route('admin.oeuvres.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('admin/oeuvres/Index')
        ->has('oeuvres.data', 1)
        ->where('oeuvres.data.0.uuid', $submitted->uuid)
    );
});

test('a work with a quarantined file is flagged as having a blocking file', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = User::factory()->withRole('author')->create();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id, 'status' => OeuvreStatus::SUBMITTED]);
    MediaFile::factory()->create(['oeuvre_id' => $oeuvre->id, 'status' => MediaFileStatus::READY]);
    MediaFile::factory()->create(['oeuvre_id' => $oeuvre->id, 'status' => MediaFileStatus::QUARANTINED]);

    $response = $this->actingAs($admin)->get(route('admin.oeuvres.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('oeuvres.data.0.has_blocking_file', true)
        ->where('oeuvres.data.0.all_ready', false)
    );
});

test('a work whose files are all ready is flagged accordingly', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = User::factory()->withRole('author')->create();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id, 'status' => OeuvreStatus::SUBMITTED]);
    MediaFile::factory()->count(2)->create(['oeuvre_id' => $oeuvre->id, 'status' => MediaFileStatus::READY]);

    $response = $this->actingAs($admin)->get(route('admin.oeuvres.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('oeuvres.data.0.all_ready', true)
        ->where('oeuvres.data.0.has_blocking_file', false)
    );
});

test('the works show page never exposes a vault path, wrapped DEK, or nonce', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = User::factory()->withRole('author')->create();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id, 'status' => OeuvreStatus::SUBMITTED]);
    MediaFile::factory()->create(['oeuvre_id' => $oeuvre->id]);

    $response = $this->actingAs($admin)->get(route('admin.oeuvres.show', $oeuvre));
    $json = json_encode($response->viewData('page')['props']);

    expect($json)
        ->not->toContain('dek_wrapped')
        ->not->toContain('mac_path')
        ->not->toContain('/vault/');
});

test('the works index with zero oeuvres returns 200 and the same props shape as with rows', function () {
    $admin = User::factory()->withRole('admin')->create();

    $empty = $this->actingAs($admin)->get(route('admin.oeuvres.index'));
    $empty->assertOk();
    $emptyProps = $empty->viewData('page')['props'];

    Oeuvre::factory()->create(['status' => OeuvreStatus::SUBMITTED, 'submitted_at' => now()]);
    $rows = $this->actingAs($admin)->get(route('admin.oeuvres.index'));
    $rowProps = $rows->viewData('page')['props'];

    expect($emptyProps['oeuvres']['data'])->toBe([])
        ->and($emptyProps['oeuvres']['total'])->toBe(0)
        ->and($emptyProps['filters'])->toBe($rowProps['filters'])
        ->and($emptyProps['statuses'])->toBe($rowProps['statuses'])
        ->and(array_keys($emptyProps['oeuvres']))->toBe(array_keys($rowProps['oeuvres']))
        ->and(array_keys($emptyProps))->toBe(array_keys($rowProps));
});

test('drafts are hidden by default and shown under the Draft status filter', function () {
    $admin = User::factory()->withRole('admin')->create();
    $draft = Oeuvre::factory()->create(['status' => OeuvreStatus::DRAFT]);
    Oeuvre::factory()->create(['status' => OeuvreStatus::SUBMITTED, 'submitted_at' => now()]);

    $this->actingAs($admin)->get(route('admin.oeuvres.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('oeuvres.data', 1)
            ->where('oeuvres.total', 1)
            ->where('statuses', fn ($statuses) => collect($statuses)->contains('draft')));

    $this->actingAs($admin)->get(route('admin.oeuvres.index', ['status' => 'draft']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('oeuvres.data', 1)
            ->where('oeuvres.data.0.uuid', $draft->uuid)
            ->where('oeuvres.data.0.submitted_at', null)
            ->where('filters.status', 'draft'));
});

test('ordering by a null submitted_at does not error and sorts drafts last', function () {
    $admin = User::factory()->withRole('admin')->create();
    Oeuvre::factory()->create(['status' => OeuvreStatus::DRAFT]);
    $older = Oeuvre::factory()->create(['status' => OeuvreStatus::SUBMITTED, 'submitted_at' => now()->subDays(2)]);
    $newer = Oeuvre::factory()->create(['status' => OeuvreStatus::UNDER_REVIEW, 'submitted_at' => now()->subDay()]);
    Oeuvre::factory()->create(['status' => OeuvreStatus::REGISTERED, 'submitted_at' => null]);

    $this->actingAs($admin)->get(route('admin.oeuvres.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('oeuvres.data.0.uuid', $newer->uuid)
            ->where('oeuvres.data.1.uuid', $older->uuid)
            ->where('oeuvres.data.2.submitted_at', null));
});

test('an admin cannot start a review, approve or reject a draft directly', function () {
    $admin = User::factory()->withRole('admin')->create();
    $draft = Oeuvre::factory()->create(['status' => OeuvreStatus::DRAFT]);

    $this->actingAs($admin)->post(route('admin.oeuvres.review', $draft))->assertForbidden();
    $this->actingAs($admin)->post(route('admin.oeuvres.approve', $draft))->assertForbidden();
    $this->actingAs($admin)->post(route('admin.oeuvres.reject', $draft), ['reason' => 'not good enough'])->assertForbidden();

    expect($draft->fresh()->status)->toBe(OeuvreStatus::DRAFT);
});

test('viewing a draft renders it read-only and writes an audit row naming the admin', function () {
    $admin = User::factory()->withRole('admin')->create();
    $draft = Oeuvre::factory()->create(['status' => OeuvreStatus::DRAFT]);
    $file = MediaFile::factory()->create(['oeuvre_id' => $draft->id]);

    $this->actingAs($admin)->get(route('admin.oeuvres.show', $draft))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('oeuvre.is_draft', true));

    $log = FileAccessLog::where('media_file_id', $file->id)->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->action)->toBe(ActionEnum::INSPECT);
});

test('viewing a submitted oeuvre is not flagged as a draft and writes no inspect row', function () {
    $admin = User::factory()->withRole('admin')->create();
    $oeuvre = Oeuvre::factory()->create(['status' => OeuvreStatus::SUBMITTED, 'submitted_at' => now()]);
    MediaFile::factory()->create(['oeuvre_id' => $oeuvre->id]);

    $this->actingAs($admin)->get(route('admin.oeuvres.show', $oeuvre))
        ->assertInertia(fn (Assert $page) => $page->where('oeuvre.is_draft', false));

    expect(FileAccessLog::where('action', ActionEnum::INSPECT)->count())->toBe(0);
});
