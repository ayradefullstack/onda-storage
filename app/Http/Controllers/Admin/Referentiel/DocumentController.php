<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Referentiel;

use App\Actions\Referentiel\CreateDocument;
use App\Domain\Deposit\OeuvreStatus;
use App\Http\Requests\Admin\Referentiel\StoreDocumentRequest;
use App\Http\Requests\Admin\Referentiel\UpdateDocumentRequest;
use App\Models\CollegeOeuvreFile;
use App\Models\Oeuvre;
use App\Models\RegisterTypeCollege;
use App\Models\User;
use App\Support\FileFormats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * College document requirements — the rows that decide what an author must
 * attach before they can submit.
 */
final class DocumentController extends ReferentielController
{
    /**
     * `document_key` and `register_type_college_id` are absent by design:
     * the key is frozen into `media_files.document_key_snapshot`, and
     * moving a document between colleges would invalidate every oeuvre
     * already filed under either.
     *
     * @var list<string>
     */
    public const EDITABLE = [
        'title_ar', 'title_en', 'extensions', 'is_required',
        'display_order', 'max_size_kb', 'allows_multiple', 'needs_review',
    ];

    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));
        // The college filter travels as a uuid — no sequential id in a URL.
        $college = $this->idForUuid(RegisterTypeCollege::class, $request->query('college')) ?? 0;
        $needsReview = $request->boolean('needs_review');

        $documents = CollegeOeuvreFile::query()
            ->with('registerTypeCollege:id,name,name_ar,name_en,code_college')
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('document_key', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%")
                ->orWhere('title_ar', 'like', "%{$search}%")
                ->orWhere('title_en', 'like', "%{$search}%")))
            ->when($college > 0, fn ($query) => $query->where('register_type_college_id', $college))
            ->when($needsReview, fn ($query) => $query->needsReview())
            ->orderBy('register_type_college_id')
            ->orderBy('display_order')
            ->paginate($this->perPage($request, self::PER_PAGE))
            ->withQueryString();

        return $this->page('admin/referentiel/Documents', [
            'rows' => $documents->through(fn (CollegeOeuvreFile $document): array => [
                'uuid' => $document->uuid,
                'document_key' => $document->document_key,
                'title' => $document->title,
                'title_ar' => $document->title_ar,
                'title_en' => $document->title_en,
                'college' => $document->registerTypeCollege?->name_global,
                'code_college' => $document->registerTypeCollege?->code_college,
                'extensions' => $document->extensions,
                // Derived, read-only. Sent so the admin sees exactly what
                // the pipeline will accept for this slot.
                'mime_types' => $document->mime_types,
                'is_required' => $document->is_required,
                'display_order' => $document->display_order,
                'max_size_kb' => $document->max_size_kb,
                'allows_multiple' => $document->allows_multiple,
                'needs_review' => $document->needs_review,
                // The expression itself is not shown — it is ONDA's raw
                // show_when/hide_when and means nothing to an officer. That
                // one EXISTS does mean something: this requirement is
                // advisory at the submission gate, never a blocker.
                'has_conditions' => $document->conditions !== null,
            ]),
            'filters' => [
                'search' => $search,
                'college' => $college > 0 ? $request->query('college') : null,
                'needs_review' => $needsReview,
            ],
            // Every college, not only those that already have documents: a
            // new college has none, and this is where it gets its first —
            // without which it could never be enabled.
            'colleges' => RegisterTypeCollege::query()
                ->orderBy('name')
                ->get(['id', 'uuid', 'name', 'name_ar', 'name_en'])
                ->map(fn (RegisterTypeCollege $c): array => ['uuid' => $c->uuid, 'name' => trim($c->name_global)]),
            // The format registry, grouped by category, for the
            // multi-select. Sent from the server so there is exactly ONE
            // list — a second copy in TypeScript would drift the first time
            // a format is added.
            'formats' => FileFormats::grouped(),
        ]);
    }

    /**
     * How many draft oeuvres would become un-submittable if this document
     * were made required right now.
     *
     * Asked before the save, from the edit dialog, so the officer sees the
     * number and decides — rather than discovering it from support calls.
     * A draft counts as affected when it is filed under this document's
     * college and holds no `ready` file in this slot.
     *
     * Drafts only: a `submitted` or `under_review` oeuvre has already
     * passed the gate, and a `registered` one is untouchable.
     */
    public function impact(CollegeOeuvreFile $document): JsonResponse
    {
        $affected = Oeuvre::query()
            ->where('register_type_college_id', $document->register_type_college_id)
            ->where('status', OeuvreStatus::DRAFT)
            ->whereDoesntHave('mediaFiles', fn ($query) => $query
                ->where('college_oeuvre_file_id', $document->id)
                ->where('status', 'ready'))
            ->count();

        return response()->json(['affected_drafts' => $affected]);
    }

    public function store(StoreDocumentRequest $request, CreateDocument $create): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var RegisterTypeCollege $college */
        $college = $request->college();

        $create->handle($actor, $college, $request->validated());

        return $this->created();
    }

    public function update(UpdateDocumentRequest $request, CollegeOeuvreFile $document): RedirectResponse
    {
        $validated = $request->validated();

        // Saving the extensions IS the review this flag was asking for —
        // an officer has now either confirmed or corrected the guess the
        // seeder made. Clearing it here rather than asking the officer to
        // untick a second box keeps the flag meaning one thing.
        if ($document->needs_review && array_key_exists('extensions', $validated)) {
            $validated['needs_review'] = false;
        }

        $this->applyAndRecord($request, $document, $validated, self::EDITABLE);

        return $this->saved();
    }
}
