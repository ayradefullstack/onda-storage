<?php

declare(strict_types=1);

use App\Models\MediaFile;
use App\Models\StorageQuota;
use App\Models\User;
use App\Models\Wilaya;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('the authors index lists every author and excludes admins', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author1 = User::factory()->withRole('author')->create();
    $author2 = User::factory()->withRole('author')->create();

    $response = $this->actingAs($admin)->get(route('admin.authors.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('admin/authors/Index')
        ->has('authors.data', 2)
    );

    $emails = collect($response->viewData('page')['props']['authors']['data'])->pluck('email');

    expect($emails)->toContain($author1->email, $author2->email)
        ->not->toContain($admin->email);
});

test('works count, files count and storage used are correct against seeded data', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = User::factory()->withRole('author')->create();

    $workOne = Work::factory()->create(['author_id' => $author->id, 'status' => 'submitted']);
    $workTwo = Work::factory()->create(['author_id' => $author->id, 'status' => 'draft']);
    MediaFile::factory()->count(2)->create(['work_id' => $workOne->id, 'size_bytes' => 1000]);
    MediaFile::factory()->count(1)->create(['work_id' => $workTwo->id, 'size_bytes' => 500]);

    StorageQuota::factory()->create([
        'user_id' => $author->id,
        'used_bytes' => 2500,
        'limit_bytes' => 10_000,
    ]);

    $response = $this->actingAs($admin)->get(route('admin.authors.index'));

    $row = collect($response->viewData('page')['props']['authors']['data'])
        ->firstWhere('email', $author->email);

    expect($row)
        ->works_count->toBe(2)
        ->files_count->toBe(3)
        ->quota_used_bytes->toBe(2500)
        ->quota_limit_bytes->toBe(10_000);
});

test('the index issues a small, fixed number of queries regardless of author count', function () {
    $admin = User::factory()->withRole('admin')->create();

    foreach (range(1, 5) as $i) {
        $author = User::factory()->withRole('author')->create();
        $work = Work::factory()->create(['author_id' => $author->id]);
        MediaFile::factory()->create(['work_id' => $work->id]);
    }

    DB::enableQueryLog();
    $this->actingAs($admin)->get(route('admin.authors.index'))->assertOk();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // One count query for pagination, one page-data query, plus a fixed
    // handful for auth/session/role resolution and the wilaya filter list
    // — never one that grows with the number of authors.
    expect($queryCount)->toBeLessThan(15);
});

test('search matches by name or email', function () {
    $admin = User::factory()->withRole('admin')->create();
    $match = User::factory()->withRole('author')->create(['name' => 'Karim Benali', 'email' => 'karim@example.test']);
    User::factory()->withRole('author')->create(['name' => 'Someone Else', 'email' => 'else@example.test']);

    $response = $this->actingAs($admin)->get(route('admin.authors.index', ['search' => 'karim']));

    $response->assertInertia(fn (Assert $page) => $page->has('authors.data', 1));
    expect($response->viewData('page')['props']['authors']['data'][0]['email'])->toBe($match->email);
});

test('filtering by wilaya only returns authors in that wilaya', function () {
    $admin = User::factory()->withRole('admin')->create();
    $alger = Wilaya::create(['code' => '16', 'name_ar' => 'الجزائر', 'name_fr' => 'Alger']);
    $oran = Wilaya::create(['code' => '31', 'name_ar' => 'وهران', 'name_fr' => 'Oran']);

    $inAlger = User::factory()->withRole('author')->create(['wilaya_id' => $alger->id]);
    User::factory()->withRole('author')->create(['wilaya_id' => $oran->id]);

    $response = $this->actingAs($admin)->get(route('admin.authors.index', ['wilaya' => $alger->uuid]));

    $response->assertInertia(fn (Assert $page) => $page->has('authors.data', 1));
    expect($response->viewData('page')['props']['authors']['data'][0]['email'])->toBe($inAlger->email);
});
