<?php

declare(strict_types=1);

use App\Domain\Localization\LanguageService;
use App\Models\Language;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function languagePayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'es',
        'name' => 'Spanish',
        'native_name' => 'Español',
        'direction' => 'ltr',
    ], $overrides);
}

// Model-level update, so the saved event flushes the language cache (a bare
// query-builder `->update()` bypasses model events by design).
function patchLanguage(string $code, array $attributes): void
{
    Language::where('code', $code)->firstOrFail()->update($attributes);
}

beforeEach(function () {
    $this->admin = User::factory()->withRole('admin')->create();
});

test('the migration seeds ar (default), fr and en', function () {
    expect(Language::query()->pluck('code')->all())->toEqualCanonicalizing(['ar', 'fr', 'en'])
        ->and(Language::query()->where('is_default', true)->pluck('code')->all())->toBe(['ar'])
        ->and(Language::query()->where('code', 'ar')->value('direction'))->toBe('rtl');
});

// --- authorization ---------------------------------------------------------

test('guests and authors cannot administer languages', function () {
    $this->post(route('admin.languages.store'), languagePayload())->assertRedirect(route('login'));

    $author = User::factory()->withRole('author')->create();

    $this->actingAs($author)->get(route('admin.languages.index'))->assertForbidden();
    $this->actingAs($author)->post(route('admin.languages.store'), languagePayload())->assertForbidden();
    $this->actingAs($author)->patch(route('admin.languages.update', 'fr'), ['name' => 'x'])->assertForbidden();
    $this->actingAs($author)->post(route('admin.languages.make-default', 'fr'))->assertForbidden();

    expect(Language::query()->where('code', 'es')->exists())->toBeFalse()
        ->and(Language::query()->where('is_default', true)->value('code'))->toBe('ar');
});

test('an admin sees every language including inactive ones', function () {
    patchLanguage('en', ['is_active' => false]);

    $this->actingAs($this->admin)->get(route('admin.languages.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/languages/Index')
            ->has('languages', 3)
            ->where('languages.0.code', 'ar'));
});

// --- create / edit ---------------------------------------------------------

test('an admin can add a language and it becomes selectable', function () {
    $this->actingAs($this->admin)->post(route('admin.languages.store'), languagePayload(['code' => ' ES ']))
        ->assertRedirect();

    $language = Language::where('code', 'es')->firstOrFail();

    expect($language->is_default)->toBeFalse()
        ->and($language->is_active)->toBeTrue()
        ->and(app(LanguageService::class)->isActive('es'))->toBeTrue();

    $this->get('/locale/es?redirect=/login')->assertRedirect('/login')->assertCookie('locale', 'es', encrypted: false);
});

test('creating a language cannot set is_default', function () {
    $this->actingAs($this->admin)->post(route('admin.languages.store'), languagePayload(['is_default' => true]));

    expect(Language::where('code', 'es')->value('is_default'))->toBeFalse()
        ->and(Language::where('is_default', true)->count())->toBe(1);
});

test('create validates code shape, uniqueness and direction', function (array $override, string $field) {
    $this->actingAs($this->admin)->post(route('admin.languages.store'), languagePayload($override))
        ->assertSessionHasErrors($field);
})->with([
    'duplicate code' => [['code' => 'fr'], 'code'],
    'path traversal code' => [['code' => '../etc'], 'code'],
    'too long code' => [['code' => 'abcdefghijklm'], 'code'],
    'bad direction' => [['direction' => 'up'], 'direction'],
    'missing name' => [['name' => ''], 'name'],
]);

test('an edit changes display fields but never the code', function () {
    $this->actingAs($this->admin)->patch(route('admin.languages.update', 'fr'), [
        'code' => 'zz',
        'name' => 'French (FR)',
        'direction' => 'ltr',
        'sort_order' => 9,
    ])->assertRedirect();

    $fr = Language::where('name', 'French (FR)')->firstOrFail();

    expect($fr->code)->toBe('fr')
        ->and($fr->sort_order)->toBe(9);
});

// --- default / activation rules -------------------------------------------

test('setting a new default leaves exactly one default', function () {
    $this->actingAs($this->admin)->post(route('admin.languages.make-default', 'fr'))->assertRedirect();

    expect(Language::where('is_default', true)->pluck('code')->all())->toBe(['fr'])
        ->and(app(LanguageService::class)->defaultCode())->toBe('fr');
});

test('an inactive language cannot become the default', function () {
    patchLanguage('en', ['is_active' => false]);

    $this->actingAs($this->admin)->post(route('admin.languages.make-default', 'en'))
        ->assertSessionHasErrors(['language' => 'admin.languages.error.defaultInactive']);

    expect(Language::where('code', 'ar')->value('is_default'))->toBeTrue();
});

test('the default language cannot be deactivated', function () {
    $this->actingAs($this->admin)->patch(route('admin.languages.update', 'ar'), ['name' => 'Arabic 2', 'is_active' => false])
        ->assertSessionHasErrors(['language' => 'admin.languages.error.deactivateDefault']);

    // The refused request must not leave its name edit half-applied.
    expect(Language::where('code', 'ar')->first())
        ->is_active->toBeTrue()
        ->name->toBe('Arabic');
});

test('the default language cannot be deleted', function () {
    expect(fn () => Language::where('code', 'ar')->firstOrFail()->delete())->toThrow(LogicException::class);

    expect(Language::where('code', 'ar')->exists())->toBeTrue();
});

test('there is no route that deletes a language', function () {
    $this->actingAs($this->admin)->delete('/admin/languages/fr')->assertStatus(405);
});

// --- locale resolution -----------------------------------------------------

test('an inactive language is rejected by the switcher and the locale route', function () {
    patchLanguage('en', ['is_active' => false]);

    $this->get('/locale/en')->assertNotFound();
    $this->get('/en')->assertNotFound();
});

test('a deactivated language stored in the cookie falls back to the default', function () {
    patchLanguage('fr', ['is_active' => false]);

    $this->withUnencryptedCookie('locale', 'fr')->get('/login')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'ar')->where('direction', 'rtl'));
});

test('an unknown cookie value cannot inject a locale', function () {
    $this->withUnencryptedCookie('locale', '../../etc')->get('/login')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'ar'));
});

test('a new default language is used when there is no cookie', function () {
    Language::where('code', 'fr')->first()->update(['is_active' => true]);
    app(LanguageService::class)->setDefault(Language::where('code', 'fr')->first());

    $this->get('/')->assertRedirect('/fr');
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('locale', 'fr')->where('direction', 'ltr'));
});

test('direction comes from the language row, not the code', function () {
    Language::create(languagePayload(['code' => 'ur', 'direction' => 'rtl']));

    $this->withUnencryptedCookie('locale', 'ur')->get('/login')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'ur')->where('direction', 'rtl'));

    patchLanguage('ar', ['direction' => 'ltr']);

    $this->withUnencryptedCookie('locale', 'ar')->get('/login')
        ->assertInertia(fn (Assert $page) => $page->where('direction', 'ltr'));
});

test('shared props expose only active languages', function () {
    patchLanguage('en', ['is_active' => false]);

    $this->get('/login')->assertInertia(fn (Assert $page) => $page
        ->has('languages', 2)
        ->where('languages.0.code', 'ar')
        ->where('defaultLocale', 'ar'));
});

test('the active-language cache is flushed when a language changes', function () {
    $service = app(LanguageService::class);

    expect($service->active())->toHaveCount(3);

    Language::create(languagePayload());
    expect($service->active())->toHaveCount(4);

    Language::where('code', 'es')->first()->update(['is_active' => false]);
    expect($service->active())->toHaveCount(3);
});
