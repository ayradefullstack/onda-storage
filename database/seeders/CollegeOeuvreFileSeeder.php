<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CollegeOeuvreFile;
use App\Models\RegisterTypeCollege;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The required documents per college, from ONDA's
 * docs/register/COLLEGE_DOCUMENTS_MATRIX.md §5.2 — 69 rows across the 22
 * colleges, checked against ONDA's source (HandlesDroitsAuteurStep4,
 * HandlesDroitsVoisinsStep4, OeuvreFactory::getValidationDataByCollegeCode()).
 *
 * Idempotent and never deletes. Colleges are resolved by `code_college`,
 * never by ONDA's numeric ids; an unknown code aborts the whole run.
 *
 * ---------------------------------------------------------------------
 * THIS SEEDER AND THE ADMIN REFERENCE-DATA UI SHARE THIS TABLE.
 * ---------------------------------------------------------------------
 * `/admin/referentiel/documents` lets an officer edit these rows, so the
 * two own different columns:
 *
 *   SEEDER-OWNED (the UI renders these read-only)
 *     document_key, register_type_college_id, title, conditions
 *
 *   UI-OWNED (create-only here, so a re-run never reverts an officer)
 *     title_ar, title_en, extensions, is_required, max_size_kb,
 *     allows_multiple, display_order, needs_review
 *
 * `document_key` is frozen into `media_files.document_key_snapshot`, which
 * is why it is not editable anywhere. `needs_review` is UI-owned because
 * clearing it IS the officer's review: re-asserting it here would re-flag
 * rows an officer has already dealt with.
 * ---------------------------------------------------------------------
 *
 * Choices made against the matrix:
 * - `extensions` is ONDA's server rule, not its client `extension` hint.
 *   `mimetypes:application/pdf,image/jpeg,image/png` reads as pdf/jpg/jpeg/
 *   png; where File::types and mimetypes both apply, their intersection.
 * - Where ONDA enforces no type at all (`AUCUNE RÈGLE TROUVÉE`, colleges 7
 *   and 8's `N/A`, EDITEUR_MUSICAL's unrestricted `cd_commercialise`), the
 *   list is a default chosen from the document and the client hint, and
 *   the row is flagged `needs_review`.
 * - PRODUCTION_PHONOGRAMME's `contrat` and `jaquette` have no Arabic or
 *   English label in ONDA (the keys are absent; French shows through the
 *   locale fallback), so title_ar and title_en stay null. The same holds for
 *   the English `code_source` and `schema_bdd`.
 * - `conditions` keeps ONDA's show_when / hide_when arrays and server
 *   condition verbatim. Nothing reads it yet.
 * - REFERENTIEL_HORS_ADHESION and OEUVRE_FILM are unreachable in this
 *   application (see RegisterTypeCollege::CODES_HIDDEN_FROM_REGISTRATION);
 *   their single `numerique` row is seeded so the counts reconcile.
 */
class CollegeOeuvreFileSeeder extends Seeder
{
    private const DOCUMENT = ['pdf', 'jpg', 'jpeg', 'png'];

    private const IMAGE = ['png', 'jpg', 'jpeg'];

    private const AUDIO = ['mp3', 'wav', 'flac'];

    private const RECORDING = ['mp3', 'wav', 'flac', 'mp4'];

    private const ARCHIVE = ['zip', 'rar', '7z', 'tar', 'gz', 'pdf', 'txt', 'sql', 'json', 'xml'];

    private const DATABASE_SCHEMA = ['sql', 'pdf', 'png', 'jpg', 'jpeg', 'svg', 'xml', 'json', 'zip'];

    private const DIGITAL_WORK = ['zip', 'rar', '7z', 'tar', 'gz', 'pdf', 'txt', 'sql', 'json', 'xml', 'png', 'jpg', 'jpeg'];

    public function run(): void
    {
        $documentsByCode = $this->documentsByCode();

        $colleges = RegisterTypeCollege::withTrashed()
            ->whereIn('code_college', array_keys($documentsByCode))
            ->pluck('id', 'code_college');

        $missing = array_diff(array_keys($documentsByCode), $colleges->keys()->all());

        if ($missing !== []) {
            throw new RuntimeException('CollegeOeuvreFileSeeder: no register_type_colleges row for code_college '.implode(', ', $missing).'. Run MembershipTypeSeeder first.');
        }

        DB::transaction(function () use ($documentsByCode, $colleges): void {
            foreach ($documentsByCode as $code => $documents) {
                foreach ($documents as $index => $document) {
                    // withTrashed: the unique index covers retired rows too, so
                    // a retired document is updated in place, never duplicated
                    // — and stays retired.
                    $identity = [
                        'register_type_college_id' => $colleges[$code],
                        'document_key' => $document['document_key'],
                    ];

                    // Seeder-owned vs UI-owned — see the class docblock.
                    $seederOwned = [
                        'title' => $document['title'],
                        'conditions' => $document['conditions'],
                    ];

                    $existing = CollegeOeuvreFile::withTrashed()->where($identity)->first();

                    if ($existing instanceof CollegeOeuvreFile) {
                        $existing->update($seederOwned);

                        continue;
                    }

                    CollegeOeuvreFile::query()->create([
                        ...$identity,
                        ...$document,
                        'display_order' => $index + 1,
                    ]);
                }
            }
        });

        $this->command->info('College required documents seeded.');
    }

    /**
     * @param  list<string>  $extensions
     * @param  array<string, mixed>|null  $conditions
     * @return array{document_key: string, title: string, title_ar: string|null, title_en: string|null, extensions: list<string>, is_required: bool, max_size_kb: int|null, allows_multiple: bool, conditions: array<string, mixed>|null, needs_review: bool}
     */
    private function document(
        string $key,
        string $title,
        ?string $titleAr,
        ?string $titleEn,
        array $extensions,
        bool $required = true,
        ?int $maxSizeKb = null,
        ?array $conditions = null,
        bool $needsReview = false,
    ): array {
        return [
            'document_key' => $key,
            'title' => $title,
            'title_ar' => $titleAr,
            'title_en' => $titleEn,
            'extensions' => $extensions,
            'is_required' => $required,
            'max_size_kb' => $maxSizeKb,
            'allows_multiple' => true,
            'conditions' => $conditions,
            'needs_review' => $needsReview,
        ];
    }

    /**
     * code_college => documents in display order.
     *
     * @return array<string, list<array{document_key: string, title: string, title_ar: string|null, title_en: string|null, extensions: list<string>, is_required: bool, max_size_kb: int|null, allows_multiple: bool, conditions: array<string, mixed>|null, needs_review: bool}>>
     */
    private function documentsByCode(): array
    {
        return [
            'MUSIQUE' => [
                $this->document('paroles', 'Paroles', 'كلمات الأغاني', 'Lyrics', ['pdf'], conditions: ['show_when' => ['presence_parole', 'avec_paroles'], 'server' => 'required_if:presence_parole,avec_paroles', 'unconditional_for_qualite' => 'Auteur']),
                $this->document('enregistrement_oeuvre', 'Enregistrement de l\'œuvre', 'تسجيل العمل', 'Work recording', self::AUDIO, conditions: ['server' => 'required_without:cd_commercialise']),
                $this->document('justificatif_exploitation', 'Justificatif d\'exploitation (*)', 'إثبات الاستغلال (*)', 'Proof of exploitation (*)', self::DOCUMENT),
                $this->document('autorisation_sample', 'Autorisation du sample', 'ترخيص العينة الموسيقية', 'Sample Authorization', ['png', 'jpg', 'pdf'], conditions: ['show_when' => ['utilisation_sample', 1], 'server' => 'required_if:utilisation_sample,1']),
                $this->document('autorisation_compositeur', 'Autorisation du compositeur', 'ترخيص الملحن', 'Composer authorization', self::DOCUMENT, conditions: ['show_when' => ['type_oeuvre', [2, 3]], 'server' => 'required_unless:oeuvres.*.declaration.type_oeuvre,1', 'removed_for_qualite' => 'Compositeur']),
                $this->document('file_editeur', 'Justificatif / Contrat d\'édition', 'وثيقة الناشر', 'Publisher Document', ['png', 'jpg', 'pdf'], conditions: ['show_when' => ['has_editeur', 1], 'server' => 'required_if:has_editeur,1']),
            ],
            'DRAMATIQUE' => [
                $this->document('oeuvre_dramatique', 'Oeuvre dramatique ou dramatico-musicales', 'عمل درامي أو درامي موسيقي', 'Dramatic or dramatic-musical work', self::DOCUMENT, needsReview: true),
                $this->document('enregistrement_oeuvre', 'Enregistrement de l\'œuvre', 'تسجيل العمل', 'Work recording', self::RECORDING, needsReview: true),
                $this->document('dvd_commercialise', 'Preuve d’exploitation', 'إثبات الاستغلال', 'Proof of exploitation', self::DOCUMENT, needsReview: true),
                $this->document('autorisation_auteur', 'Autorisation de l’auteur de l’œuvre originale', 'ترخيص من المؤلف الأصلي للعمل', 'Original work author authorization', self::DOCUMENT, conditions: ['show_when' => ['type_oeuvre', 3], 'server' => 'required_if:oeuvres.*.declaration.type_oeuvre,3']),
            ],
            'LITTERAIRE_EMISSION' => [
                $this->document('oeuvre_litteraire', 'Oeuvre littéraire', 'عمل أدبي', 'Literary work', self::DOCUMENT, needsReview: true),
                $this->document('enregistrement_oeuvre', 'Enregistrement de l\'œuvre', 'تسجيل العمل', 'Work recording', self::RECORDING, needsReview: true),
                $this->document('dvd_commercialise', 'DVD commercialisé ou enregistré', 'قرص DVD مسوق أو مسجل', 'Commercialized or recorded DVD', ['mp4', 'pdf', 'jpg', 'jpeg', 'png'], needsReview: true),
                $this->document('autorisation_auteur', 'Autorisation de l’auteur de l’œuvre originale', 'ترخيص من المؤلف الأصلي للعمل', 'Original work author authorization', self::DOCUMENT, needsReview: true),
            ],
            'LITTERAIRE_EDITION' => [
                $this->document('livre_edite', 'Livre édité (format pdf)', 'كتاب منشور (بصيغة PDF)', 'Published book (pdf format)', self::DOCUMENT, conditions: ['show_when' => ['format_edition', 'papier'], 'server' => 'required ssi isFormatPapier(format_edition)']),
                $this->document('numerique', 'Oeuvre format numérique', 'عمل بصيغة رقمية', 'Digital format work', self::DOCUMENT, conditions: ['show_when' => ['format_edition', 'numerique'], 'server' => 'required ssi isFormatNumerique(format_edition)']),
                $this->document('autorisation_auteur', 'Autorisation de l’auteur de l’œuvre originale', 'ترخيص من المؤلف الأصلي للعمل', 'Original work author authorization', self::DOCUMENT, conditions: ['show_when' => ['origine', ['traduite', 'adaptee']], 'server' => 'required ssi isOrigineDerivee(origine)']),
                $this->document('isbn_file', 'ISBN/ISNN', 'ISBN/ISNN', 'ISBN/ISNN', self::DOCUMENT),
            ],
            'POESIE' => [
                $this->document('oeuvres_poetiques', 'oeuvres poétiques (format pdf)', 'أعمال شعرية (بصيغة PDF)', 'Poetic works (pdf format)', ['pdf'], needsReview: true),
                $this->document('autorisation_auteur', 'Autorisation de l’auteur de l’œuvre originale', 'ترخيص من المؤلف الأصلي للعمل', 'Original work author authorization', self::DOCUMENT, conditions: ['show_when' => ['origine', ['traduite']], 'server' => 'required ssi isOrigineDerivee(origine)']),
            ],
            'ARTS_GRAPHIQUES' => [
                $this->document('oeuvres_plastiques_graphiques', 'Oeuvres plastiques et graphiques (png)', 'أعمال تشكيلية وغرافيكية (PNG)', 'Plastic and graphic works (png)', self::IMAGE, maxSizeKb: 102400),
                $this->document('attestation_exposition', 'Attestation d’exposition', 'شهادة عرض', 'Exhibition certificate', self::DOCUMENT, conditions: ['hide_when' => ['genre_specifique', ['ARC', 'MOE']]], needsReview: true),
            ],
            'REFERENTIEL_HORS_ADHESION' => [
                $this->document('numerique', 'Oeuvre format numérique', 'عمل بصيغة رقمية', 'Digital format work', self::DOCUMENT, conditions: ['note' => 'college unreachable at step 2'], needsReview: true),
            ],
            'OEUVRE_FILM' => [
                $this->document('numerique', 'Oeuvre format numérique', 'عمل بصيغة رقمية', 'Digital format work', self::DOCUMENT, conditions: ['note' => 'college unreachable at step 2'], needsReview: true),
            ],
            'EDITEUR_MUSICAL' => [
                $this->document('paroles_oeuvre', 'Paroles de l\'œuvre (*)', 'كلمات العمل (*)', 'Work lyrics (*)', self::DOCUMENT, conditions: ['show_when' => ['presence_parole', 'avec_paroles'], 'server' => 'required_if:presence_parole,avec_paroles']),
                $this->document('cd_commercialise', 'Enregistrement de l\'œuvre (*)', 'تسجيل العمل (*)', 'Work recording (*)', self::RECORDING, needsReview: true),
                $this->document('contrat_edition', 'Contrat d\'édition (*)', 'عقد النشر (*)', 'Publishing contract (*)', self::DOCUMENT),
                $this->document('justificatif_exploitation', 'Justificatif d\'exploitation (*)', 'إثبات الاستغلال (*)', 'Proof of exploitation (*)', self::DOCUMENT),
            ],
            'PRESTATION_LYRIQUE' => [
                $this->document('declaration_enregistrement', 'Déclaration d\'enregistrement en studio', 'تصريح التسجيل في الاستوديو', 'Studio recording declaration', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_lyrique', 'declaration_enregistrement', 'in']]),
                $this->document('declaration_radiodiffusion', 'Déclaration de radiodiffusion', 'تصريح البث الإذاعي', 'Radio broadcast declaration', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_lyrique', 'declaration_radiodiffusion', 'in']]),
                $this->document('attestation_radiodiffusion', 'Enregistrement sonore de la prestation', 'التسجيل الصوتي للعرض', 'Sound recording of the performance', ['mp3'], conditions: ['show_when' => ['types_justificatifs_lyrique', 'attestation_radiodiffusion', 'in']]),
                $this->document('contrat_chanteur', 'Contrat (Chanteur)', 'عقد (مغنٍ)', 'Contract (Singer)', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_lyrique', 'contrat_chanteur', 'in']]),
            ],
            'PRESTATION_DRAMATIQUE_CHOREGRAPHIQUE' => [
                $this->document('declaration_enregistrement', 'Déclaration d\'enregistrement en studio / captation', 'تصريح التسجيل في الاستوديو / الالتقاط', 'Studio recording / capture declaration', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_dramatique_choreographique', 'declaration_enregistrement', 'in']]),
                $this->document('declaration_radiodiffusion', 'Déclaration de radiodiffusion', 'تصريح البث الإذاعي', 'Radio broadcast declaration', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_dramatique_choreographique', 'declaration_radiodiffusion', 'in']]),
                $this->document('attestation_diffusion_tv', 'Attestation de diffusion TV', 'شهادة البث التلفزيوني', 'TV broadcast certificate', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_dramatique_choreographique', 'attestation_diffusion_tv', 'in']]),
                $this->document('contrat_artiste', 'Contrat (comédien / danseur)', 'عقد (ممثل / راقص)', 'Contract (actor / dancer)', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_dramatique_choreographique', 'contrat_artiste', 'in']]),
                $this->document('autre', 'Autre (document)', 'أخرى (مستند)', 'Other (document)', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_dramatique_choreographique', 'autre', 'in'], 'note' => 'ticking it also makes justificatif_autre_precision required']),
            ],
            'PRESTATION_LITTERAIRE_EMISSION' => [
                $this->document('declaration_enregistrement', 'Déclaration d\'enregistrement en studio', 'تصريح التسجيل في الاستوديو', 'Studio recording declaration', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_litteraire_emission', 'declaration_enregistrement', 'in']]),
                $this->document('declaration_radiodiffusion', 'Déclaration de radiodiffusion', 'تصريح البث الإذاعي', 'Radio broadcast declaration', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_litteraire_emission', 'declaration_radiodiffusion', 'in'], 'note' => 'tickable only when declaration.media_diffusion = radiodiffusee']),
                $this->document('declaration_telediffusion', 'Déclaration de télédiffusion', 'تصريح البث التلفزيوني', 'TV broadcast declaration', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_litteraire_emission', 'declaration_telediffusion', 'in'], 'note' => 'tickable only when declaration.media_diffusion = telediffusee']),
                $this->document('attestation_diffusion', 'Attestation de diffusion (radio ou TV)', 'شهادة البث (إذاعة أو تلفزيون)', 'Broadcast certificate (radio or TV)', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_litteraire_emission', 'attestation_diffusion', 'in']]),
                $this->document('contrat_lecteur_recitant', 'Contrat (lecteur / récitant)', 'عقد (قارئ / راوٍ)', 'Contract (reader / reciter)', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_litteraire_emission', 'contrat_lecteur_recitant', 'in']]),
                $this->document('autre', 'Autre (document)', 'أخرى (مستند)', 'Other (document)', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_litteraire_emission', 'autre', 'in'], 'note' => 'ticking it also makes justificatif_autre_precision required']),
            ],
            'PRESTATION_AUDIOVISUELLE' => [
                $this->document('declaration_enregistrement', 'Déclaration d\'enregistrement / tournage', 'تصريح التسجيل / التصوير', 'Recording / shoot declaration', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_audiovisuelle', 'declaration_enregistrement', 'in']]),
                $this->document('contrat_travail_cession', 'Contrat de travail ou de cession', 'عقد عمل أو تنازل', 'Employment or assignment contract', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_audiovisuelle', 'contrat_travail_cession', 'in']]),
                $this->document('attestation_diffusion_tv', 'Attestation de diffusion TV', 'شهادة البث التلفزيوني', 'TV broadcast certificate', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_audiovisuelle', 'attestation_diffusion_tv', 'in']]),
                $this->document('fiche_technique_videogramme', 'Fiche technique du vidéogramme', 'البطاقة التقنية للفيديوغرام', 'Videogram technical sheet', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_audiovisuelle', 'fiche_technique_videogramme', 'in']]),
                $this->document('captures_ecran_prestation', 'Captures d\'écran ou lien de la prestation', 'لقطات شاشة أو رابط الأداء', 'Screenshots or performance link', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_audiovisuelle', 'captures_ecran_prestation', 'in']]),
                $this->document('autre', 'Autre (document)', 'أخرى (مستند)', 'Other (document)', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_audiovisuelle', 'autre', 'in']]),
            ],
            'PRODUCTION_PHONOGRAMME' => [
                $this->document('declaration_enregistrement', 'Déclaration d\'enregistrement en studio', 'تصريح التسجيل في الاستوديو', 'Studio recording declaration', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_phonogramme', 'declaration_enregistrement', 'in']]),
                $this->document('contrat', 'Contrat de production', null, null, self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_phonogramme', 'contrat', 'in']]),
                $this->document('jaquette', 'Jaquette du support', null, null, self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_phonogramme', 'jaquette', 'in']]),
                $this->document('autre', 'Autre (document)', 'أخرى (مستند)', 'Other (document)', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_phonogramme', 'autre', 'in']]),
            ],
            'PRODUCTION_VIDEOGRAMME' => [
                $this->document('contrat', 'Contrat', 'عقد', 'Contract', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_videogramme', 'contrat', 'in']]),
                $this->document('declaration_captation_video', 'Déclaration de captation vidéo / tournage', 'إقرار تصوير فيديو / تصوير', 'Video recording / filming declaration', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_videogramme', 'declaration_captation_video', 'in']]),
                $this->document('autre', 'Autre (document)', 'أخرى (مستند)', 'Other (document)', self::DOCUMENT, conditions: ['show_when' => ['types_justificatifs_videogramme', 'autre', 'in']]),
            ],
            'SIMPLE_LITTERAIRE_EDITION' => [
                $this->document('autorisation_auteur', 'Autorisation de l’auteur de l’œuvre originale', 'ترخيص من المؤلف الأصلي للعمل', 'Original work author authorization', self::DOCUMENT, conditions: ['show_when' => ['origine', ['traduite', 'adaptee']], 'server' => 'required ssi isOrigineDerivee(origine)']),
                $this->document('oeuvre_litteraire', 'Oeuvre littéraire', 'عمل أدبي', 'Literary work', self::DOCUMENT),
            ],
            'SIMPLE_POESIE' => [
                $this->document('oeuvres_poetiques', 'oeuvres poétiques (format pdf)', 'أعمال شعرية (بصيغة PDF)', 'Poetic works (pdf format)', ['pdf'], needsReview: true),
                $this->document('autorisation_auteur', 'Autorisation de l’auteur de l’œuvre originale', 'ترخيص من المؤلف الأصلي للعمل', 'Original work author authorization', self::DOCUMENT, conditions: ['show_when' => ['origine', ['traduite']], 'server' => 'required ssi isOrigineDerivee(origine)']),
            ],
            'SIMPLE_ARTS_GRAPHIQUES' => [
                $this->document('oeuvres_plastiques_graphiques', 'Oeuvres plastiques et graphiques (png)', 'أعمال تشكيلية وغرافيكية (PNG)', 'Plastic and graphic works (png)', self::IMAGE, maxSizeKb: 102400),
            ],
            'LOGICIEL' => [
                $this->document('code_source', 'Code source', 'شفرة المصدر', null, self::ARCHIVE, maxSizeKb: 20480),
                $this->document('schema_bdd', 'Schéma de la base de données', 'مخطط قاعدة البيانات', null, self::DATABASE_SCHEMA, required: false, maxSizeKb: 20480),
                $this->document('oeuvre_format_numerique', 'Œuvre format numérique', 'عمل بصيغة رقمية', 'Digital format work', self::DIGITAL_WORK, required: false, maxSizeKb: 20480),
            ],
            'RECHERCHE_SCIENTIFIQUE' => [
                $this->document('oeuvre_format_numerique', 'Œuvre format numérique', 'عمل بصيغة رقمية', 'Digital format work', self::DIGITAL_WORK, maxSizeKb: 20480),
            ],
            'SITE_WEB' => [
                $this->document('code_source', 'Code source', 'شفرة المصدر', null, self::ARCHIVE, required: false, maxSizeKb: 20480),
                $this->document('schema_bdd', 'Schéma de la base de données', 'مخطط قاعدة البيانات', null, self::DATABASE_SCHEMA, required: false, maxSizeKb: 20480),
                $this->document('oeuvre_format_numerique', 'Œuvre format numérique', 'عمل بصيغة رقمية', 'Digital format work', self::DIGITAL_WORK, maxSizeKb: 20480),
            ],
            'THESES_MEMOIRES' => [
                $this->document('oeuvre_format_numerique', 'Œuvre format numérique', 'عمل بصيغة رقمية', 'Digital format work', self::DIGITAL_WORK, maxSizeKb: 20480),
            ],
        ];
    }
}
