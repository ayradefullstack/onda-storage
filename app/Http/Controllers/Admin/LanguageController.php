<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Localization\LanguageService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLanguageRequest;
use App\Http\Requests\Admin\UpdateLanguageRequest;
use App\Models\Language;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Language administration. There is deliberately no destroy route: a
 * language is retired by deactivating it, and the default can't be
 * deactivated. Routes are keyed by `code` (unique, immutable).
 */
final class LanguageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/languages/Index', [
            'languages' => Language::query()->ordered()->get()->map(fn (Language $l): array => [
                'code' => $l->code,
                'name' => $l->name,
                'native_name' => $l->native_name,
                'direction' => $l->direction,
                'is_default' => $l->is_default,
                'is_active' => $l->is_active,
                'sort_order' => $l->sort_order,
                // The UI strings live in resources/js/locales/{code}.json;
                // without that file the language renders in the default's.
                'has_bundle' => is_file(resource_path("js/locales/{$l->code}.json")),
            ]),
        ]);
    }

    public function store(StoreLanguageRequest $request): RedirectResponse
    {
        Language::create($request->validated());

        return back();
    }

    public function update(UpdateLanguageRequest $request, Language $language, LanguageService $languages): RedirectResponse
    {
        $data = $request->validated();
        $active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null;
        unset($data['is_active']);

        // One transaction: a refused deactivation must not leave the
        // accompanying name/direction edit half-applied.
        DB::transaction(function () use ($language, $data, $active, $languages): void {
            $language->fill($data)->save();

            if ($active !== null && $active !== $language->is_active) {
                $languages->setActive($language, $active);
            }
        });

        return back();
    }

    public function makeDefault(Language $language, LanguageService $languages): RedirectResponse
    {
        $languages->setDefault($language);

        return back();
    }
}
