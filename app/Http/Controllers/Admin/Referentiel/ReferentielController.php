<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Referentiel;

use App\Http\Controllers\Concerns\ResolvesPerPage;
use App\Http\Controllers\Controller;
use App\Models\CollegeOeuvreFile;
use App\Models\ReferenceDataChange;
use App\Models\RegisterType;
use App\Models\RegisterTypeCollege;
use App\Models\RegisterTypeMember;
use App\Models\TypeGestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shared behaviour for the five reference-data tabs.
 *
 * Each tab is its own route and its own controller, not a client-side tab
 * over one payload: a deep link must work, a refresh must stay put, and
 * each table needs its own pagination, search and filters. What they share
 * is the tab bar (with its counts) and the way a save is recorded.
 *
 * Nothing here deletes. A college with deposits filed under it must never
 * disappear, and soft-deleting one would leave those oeuvres pointing at a
 * trashed parent — so retiring is `status` / `is_disabled`, and there is
 * deliberately no destroy route on any of the five.
 */
abstract class ReferentielController extends Controller
{
    use ResolvesPerPage;

    protected const PER_PAGE = 25;

    /**
     * The tab bar. Five counts, five queries, computed once per request —
     * an officer should see at a glance that colleges holds 22 and
     * documents 69.
     *
     * Counted over every row including retired ones: this is the admin's
     * inventory of the reference data, not the author-facing catalogue.
     *
     * @return array<int, array{key: string, route: string, count: int}>
     */
    protected static function tabs(): array
    {
        return [
            ['key' => 'types', 'route' => 'admin.referentiel.types', 'count' => RegisterType::query()->count()],
            ['key' => 'gestions', 'route' => 'admin.referentiel.gestions', 'count' => TypeGestion::query()->count()],
            ['key' => 'colleges', 'route' => 'admin.referentiel.colleges', 'count' => RegisterTypeCollege::query()->count()],
            ['key' => 'membres', 'route' => 'admin.referentiel.membres', 'count' => RegisterTypeMember::query()->count()],
            ['key' => 'documents', 'route' => 'admin.referentiel.documents', 'count' => CollegeOeuvreFile::query()->count()],
        ];
    }

    /**
     * @param  array<string, mixed>  $props
     */
    protected function page(string $component, array $props): Response
    {
        return Inertia::render($component, [
            'tabs' => self::tabs(),
            ...$props,
        ]);
    }

    /**
     * Apply a validated change and record it, both or neither.
     *
     * `$fields` is the allow-list: only these columns are written, and only
     * these are audited. It is what keeps a crafted request from reaching
     * `code_college` — the request classes validate shape, this decides
     * which columns exist at all as far as the save is concerned.
     *
     * The before-snapshot is taken from the model's own attributes rather
     * than from `getOriginal()` after the fill, so a cast column (the
     * `extensions` list, the boolean flags) is compared as its PHP value
     * and not as its raw JSON.
     *
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $fields
     */
    protected function applyAndRecord(Request $request, Model $subject, array $validated, array $fields): void
    {
        /** @var User $actor */
        $actor = $request->user();

        DB::transaction(function () use ($subject, $validated, $fields, $actor): void {
            $before = [];
            foreach ($fields as $field) {
                $before[$field] = $subject->getAttribute($field);
            }

            foreach ($fields as $field) {
                if (array_key_exists($field, $validated)) {
                    $subject->setAttribute($field, $validated[$field]);
                }
            }

            $subject->save();

            $after = [];
            foreach ($fields as $field) {
                $after[$field] = $subject->getAttribute($field);
            }

            ReferenceDataChange::record($actor, $subject, $before, $after, $fields);
        });
    }

    protected function saved(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reference data updated.')]);

        return back();
    }

    protected function created(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reference data created.')]);

        return back();
    }

    /**
     * Resolves a filter or parent value, which travels as a uuid — no
     * sequential id ever appears in a URL or a page prop — to the id the
     * queries use. Anything that is not a live row's uuid is `null`, so a
     * malformed filter simply filters nothing.
     *
     * @param  class-string<Model>  $model
     */
    protected function idForUuid(string $model, mixed $uuid): ?int
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $id = $model::query()->where('uuid', $uuid)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
