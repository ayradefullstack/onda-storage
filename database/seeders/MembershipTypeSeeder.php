<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\RegisterRoleAuteur;
use App\Models\RegisterType;
use App\Models\RegisterTypeCollege;
use App\Models\RegisterTypeMember;
use App\Models\TypeGestion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The deposit classification reference data — declarant types, type de
 * gestion labels, colleges, qualités (members) and author roles — as
 * specified by docs/step-one-select.md, itself ONDA's RegisterTypesSeeder.
 *
 * Verified row for row against the ONDA dump (onda_db.sql): 4 types, 21
 * colleges, 100 members, 87 roles — plus OEUVRE_FILM, which the spec adds
 * and the dump predates, for 22 colleges.
 *
 * Idempotent and never deletes. Resolutions of the spec's defects:
 * - `$typeGestion` holds the integers 1/2/3 the `type_gestion` column
 *   stores. The spec's arrays of English labels passed straight into that
 *   tinyint; the labels now live in `type_gestions`.
 * - PHOTOGRAPHIE and SUSCEPTIBLE_GESTION_COLLECTIVE are kept in
 *   membersByCode() / rolesByCode(), inert: no college carries either code,
 *   so they seed nothing (exactly as in ONDA). Kept so this file can still be
 *   compared line for line with ONDA's seeder.
 * - CODE_REFERENTIEL_HORS_ADHESION and CODE_OEUVRE_FILM are constants on
 *   RegisterTypeCollege.
 *
 * Each Auteur college also gets `type_gestion_id`, the type_gestions row for
 * its `type_gestion`. Other types' colleges keep it null: type_gestions holds
 * Auteur's labels only.
 *
 * The data sits in private methods with declared return shapes rather than
 * in run()'s locals, so the spec's defensive branches (the isset checks,
 * PROTECTION_SAMPLE, the fallback member) read as the general guards they
 * are instead of conditions a static analyser can prove dead.
 */
class MembershipTypeSeeder extends Seeder
{
    public function run(): void
    {
        $start = microtime(true);
        $this->command->info('Register types are starting to be loaded...');

        $registerTypes = $this->registerTypes();
        $typeGestionLabels = $this->typeGestionLabels();
        $registerTypeColleges = $this->registerTypeColleges();
        $deferredRegisterTypeColleges = $this->deferredRegisterTypeColleges();
        $membersByCode = $this->membersByCode();
        $rolesByCode = $this->rolesByCode();

        // One transaction: the code_college reset below leaves every college
        // uncoded until the loops re-assign them, so a failure part-way must
        // roll back rather than leave the documents mapping with no codes.
        DB::transaction(function () use ($registerTypes, $typeGestionLabels, $registerTypeColleges, $deferredRegisterTypeColleges, $membersByCode, $rolesByCode): void {
            /** @var array<int, RegisterType> $createdRegisterTypes */
            $createdRegisterTypes = [];

            /** @var array<int, array<int, int>> $typeGestionIds register type key => type_gestion value => type_gestions.id */
            $typeGestionIds = [];

            // Reset semantic business key before re-assignment to avoid collisions
            // when legacy/partial seed runs left stale values.
            RegisterTypeCollege::query()->whereNotNull('code_college')->update(['code_college' => null]);

            foreach ($registerTypes as $typeId => $typeName) {
                $registerType = RegisterType::firstOrCreate(['name' => $typeName], ['slug' => Str::slug($typeName)]);
                $createdRegisterTypes[$typeId] = $registerType;

                if (isset($typeGestionLabels[$typeId])) {
                    foreach ($typeGestionLabels[$typeId] as $value => $labels) {
                        $typeGestionIds[$typeId][$value] = TypeGestion::firstOrCreate(
                            ['register_type_id' => $registerType->id, 'type_gestion' => $value],
                            $labels,
                        )->id;
                    }
                }

                if (! isset($registerTypeColleges[$typeId])) {
                    continue;
                }

                foreach ($registerTypeColleges[$typeId] as $code => $collegeData) {
                    $isDisabled = match ($code) {
                        'PROTECTION_SAMPLE',
                        RegisterTypeCollege::CODE_REFERENTIEL_HORS_ADHESION,
                        RegisterTypeCollege::CODE_OEUVRE_FILM => true,
                        default => false,
                    };

                    // Refonte workflow 2026-06 :
                    // register_type_id IN (1, 2) => Auteur + Editeur = Droits d'Auteur (DA, code_dv = NULL).
                    // register_type_id IN (3, 4) => Artiste-Interprete + Producteur = Droits Voisins (DV).
                    $codeDv = in_array($typeId, [1, 2], true) ? null : $code;

                    $registerTypeCollege = RegisterTypeCollege::updateOrCreate(
                        [
                            'register_type_id' => $registerType->id,
                            'name' => $collegeData[0],
                            'type_gestion' => $collegeData[1],
                        ],
                        [
                            'type_gestion_id' => $typeGestionIds[$typeId][$collegeData[1]] ?? null,
                            'code_college' => $code,
                            'code_dv' => $codeDv,
                            'is_disabled' => $isDisabled,
                        ]
                    );

                    if (isset($rolesByCode[$code])) {
                        foreach ($rolesByCode[$code] as $roleName) {
                            RegisterRoleAuteur::firstOrCreate([
                                'register_type_college_id' => $registerTypeCollege->id,
                                'name' => $roleName,
                            ]);
                        }
                    }

                    if (isset($membersByCode[$code])) {
                        foreach ($membersByCode[$code] as $memberDef) {
                            $this->syncMember($registerTypeCollege->id, $memberDef);
                        }
                    }
                }
            }

            foreach ($deferredRegisterTypeColleges as $typeId => $colleges) {
                $registerType = $createdRegisterTypes[$typeId] ?? null;

                if (! $registerType) {
                    continue;
                }

                foreach ($colleges as $code => $collegeData) {
                    $isDisabled = match ($code) {
                        'PROTECTION_SAMPLE' => true,
                        default => false,
                    };

                    $codeDv = in_array($typeId, [1, 2], true) ? null : $code;

                    $registerTypeCollege = RegisterTypeCollege::updateOrCreate(
                        [
                            'register_type_id' => $registerType->id,
                            'name' => $collegeData[0],
                            'type_gestion' => $collegeData[1],
                        ],
                        [
                            'type_gestion_id' => $typeGestionIds[$typeId][$collegeData[1]] ?? null,
                            'code_college' => $code,
                            'code_dv' => $codeDv,
                            'is_disabled' => $isDisabled,
                        ]
                    );

                    $memberDefs = $membersByCode[$code] ?? [];

                    // Ne remplacer par le libellé du collège que s'il n'y a aucune liste définie.
                    if ($memberDefs === []) {
                        $memberDefs = [$this->member($collegeData[0])];
                    }

                    foreach ($memberDefs as $memberDef) {
                        $this->syncMember($registerTypeCollege->id, $memberDef);
                    }

                    // Rôle « Auteur » par défaut pour les colleges en gestion simple.
                    RegisterRoleAuteur::firstOrCreate([
                        'register_type_college_id' => $registerTypeCollege->id,
                        'name' => 'Auteur',
                    ]);
                }
            }
        });

        $end = microtime(true);
        $this->command->info('Register types have been loaded in '.round($end - $start, 2).' seconds.');
    }

    /**
     * @return array<int, string>
     */
    private function registerTypes(): array
    {
        return [
            1 => 'Auteur',
            2 => 'Editeur',
            3 => 'Artiste-interprète',
            4 => 'Producteur',
        ];
    }

    /**
     * Keyed by register type. Seeded for Auteur (type 1) only — the only type
     * whose form shows the select. `name` is French like every other
     * reference `name`; `name_en` is the spec's label; `name_ar` is ONDA's
     * `enums.auteur.type_gestion.*` (STEP_TWO_ANALYSIS.md §4.3).
     *
     * @return array<int, array<int, array{name: string, name_en: string, name_ar: string}>>
     */
    private function typeGestionLabels(): array
    {
        return [
            1 => [
                TypeGestion::COLLECTIVE => ['name' => 'Gestion collective', 'name_en' => 'Collective management', 'name_ar' => 'إدارة جماعية'],
                TypeGestion::INDIVIDUAL => ['name' => 'Gestion individuelle', 'name_en' => 'Individual management', 'name_ar' => 'إدارة فردية'],
                TypeGestion::SIMPLE => ['name' => 'Simple protection', 'name_en' => 'Simple protection', 'name_ar' => 'حماية بسيطة'],
            ],
        ];
    }

    /**
     * The spec's `$typeGestion`, as the integers the column stores.
     *
     * @return array<int, int>
     */
    private function typeGestion(): array
    {
        return [
            1 => TypeGestion::COLLECTIVE,
            2 => TypeGestion::INDIVIDUAL,
            3 => TypeGestion::SIMPLE,
        ];
    }

    /**
     * The leading spaces in these names (" oeuvres musicales") are REAL DATA,
     * present verbatim in ONDA's live tables. Do not trim them: colleges are
     * matched by updateOrCreate on (register_type_id, name, type_gestion), so
     * a trimmed name matches nothing already seeded and creates a duplicate
     * college instead. Trim at display time only.
     *
     * @return array<int, array<string, array{0: string, 1: int}>>
     */
    private function registerTypeColleges(): array
    {
        $typeGestion = $this->typeGestion();

        return [
            1 => [
                'MUSIQUE' => [' oeuvres musicales', $typeGestion[1]],
                'DRAMATIQUE' => [' oeuvres dramatiques et dramatico-musicales', $typeGestion[1]],
                'LITTERAIRE_EMISSION' => [' oeuvres littéraires en emission', $typeGestion[1]],
                'LITTERAIRE_EDITION' => [' oeuvres littéraires en édition', $typeGestion[2]],
                'POESIE' => [' oeuvres poétiques', $typeGestion[2]],
                'ARTS_GRAPHIQUES' => [' oeuvres graphiques et plastiques', $typeGestion[2]],
                'REFERENTIEL_HORS_ADHESION' => [
                    'Référentiel qualités hors adhésion',
                    $typeGestion[1],
                ],
                'OEUVRE_FILM' => [
                    'Œuvres film',
                    $typeGestion[1],
                ],
            ],
            2 => [
                'EDITEUR_MUSICAL' => ["Les Editeurs d'oeuvres musicales", $typeGestion[1]],
            ],
            3 => [
                'PRESTATION_LYRIQUE' => [' Artiste de prestations lyriques', $typeGestion[1]],
                'PRESTATION_DRAMATIQUE_CHOREGRAPHIQUE' => ['  Artiste interprète de prestations dramatiques et chorégraphiques', $typeGestion[1]],
                'PRESTATION_LITTERAIRE_EMISSION' => ['  Artiste de prestations littéraires en émission', $typeGestion[1]],
                'PRESTATION_AUDIOVISUELLE' => ['  Artiste de prestations audiovisuelles', $typeGestion[1]],
            ],
            4 => [
                'PRODUCTION_PHONOGRAMME' => ['Producteurs de phonogrammes', $typeGestion[1]],
                'PRODUCTION_VIDEOGRAMME' => ['Producteurs de vidéogrammes', $typeGestion[1]],
            ],
        ];
    }

    /**
     * The seven simple-protection colleges, seeded in a second pass. Leading
     * spaces are real data — see registerTypeColleges().
     *
     * @return array<int, array<string, array{0: string, 1: int}>>
     */
    private function deferredRegisterTypeColleges(): array
    {
        $typeGestion = $this->typeGestion();

        return [
            1 => [
                'SIMPLE_LITTERAIRE_EDITION' => [' oeuvres littéraires', $typeGestion[3]],
                'SIMPLE_POESIE' => [' oeuvres poétiques', $typeGestion[3]],
                'SIMPLE_ARTS_GRAPHIQUES' => [' oeuvres graphiques et plastiques', $typeGestion[3]],
                'LOGICIEL' => ['Logiciel', $typeGestion[3]],
                'RECHERCHE_SCIENTIFIQUE' => ['Recherche scientifique', $typeGestion[3]],
                'SITE_WEB' => ['Site Web', $typeGestion[3]],
                'THESES_MEMOIRES' => ['Thèses et Mémoires', $typeGestion[3]],
            ],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function rolesByCode(): array
    {
        $rolesMusic = [
            'Compositeur',
            'Parolier',
            'Adaptateur',
            'Arrangeur',
        ];
        $rolesDrama = [
            'Auteur de l’œuvre originale',
            'Compositeur',
            'Chorégraphe',
            'Traducteur',
            'Dialoguiste',
            'Scénariste',
            'Metteur en scène',
            'Réalisateur',
        ];
        $rolesLiteraryBroadcast = [
            'Auteur',
            'Auteur de l’œuvre originale',
            'Traducteur',
            'Adaptateur',
        ];
        $rolesLiteraryPublishing = [
            'Auteur',
            'Co‑auteur',
            'Préfacier',
            'Traducteur',
        ];
        $rolesPoetry = [
            'Poète',
            'Co-auteur',
            'Traducteur',
            'Adaptateur',
        ];
        $rolesPhotography = [
            'Photographe',
            'Styliste',
            'Architecte',
            'Publicitaire',
            'Artiste plasticien',
        ];
        $rolesGraphicArts = [
            'Peintre',
            'Sculpteur',
            'Graveur',
            'Designer',
            'Artiste numérique',
            'Street‑art',
        ];
        $rolesSusceptibleCollective = [
            "Auteur d'oeuvres susceptibles de gestion collective",
        ];
        $rolesMusicPublisher = [
            'Editeur',
            'Sous-Editeur',
        ];
        $rolesLyricalPerformance = [
            "Chef d'orchestre",
            'Chanteur (se)',
            'Musicien (ne)',
            'Soliste',
            'Choriste',
            'Co-artiste',
        ];
        $rolesDramaticChoreographic = [
            'Comédien (ne)',
            'Danseur (se)',
            'Magicien',
            'Clown',
            'Co-artiste',
        ];
        $rolesLiteraryBroadcastPerformance = [
            'Liseur',
            'Diseur',
            'Conteur',
            'Narrateur',
            'Doubleur de voix',
            'Co-artiste',
        ];
        $rolesAudiovisualPerformance = [
            'Choriste',
            'Chanteur',
            "Chef d'orchestre",
            'Comédien',
            'Danseur',
            'Doubleur de voix',
            'Liseur',
            'Diseur',
            'Conteur',
            'Musicien',
            'Producteur',
            'Radio',
            'Télévision',
            'Narrateur',
            'Chanteuse',
            'Comédienne',
            'Productrice',
            'Danseuse',
            'Musicienne',
            'Magicien',
            'Auto-producteur',
            'Clown',
            'Exception',
            'Non définie',
            'Interprète',
            "L'Acteur",
            'Co-artiste',
        ];
        $rolesProduction = [
            'Principal',
            'Secondaire',
        ];

        return [
            'MUSIQUE' => $rolesMusic,
            'DRAMATIQUE' => $rolesDrama,
            'LITTERAIRE_EMISSION' => $rolesLiteraryBroadcast,
            'LITTERAIRE_EDITION' => $rolesLiteraryPublishing,
            'POESIE' => $rolesPoetry,
            'PHOTOGRAPHIE' => $rolesPhotography, // inert: no college has this code
            'ARTS_GRAPHIQUES' => $rolesGraphicArts,
            'SUSCEPTIBLE_GESTION_COLLECTIVE' => $rolesSusceptibleCollective, // inert: no college has this code
            'SIMPLE_LITTERAIRE_EDITION' => $rolesLiteraryPublishing,
            'SIMPLE_POESIE' => $rolesPoetry,
            'SIMPLE_ARTS_GRAPHIQUES' => $rolesGraphicArts,
            'EDITEUR_MUSICAL' => $rolesMusicPublisher,
            'PRESTATION_LYRIQUE' => $rolesLyricalPerformance,
            'PRESTATION_DRAMATIQUE_CHOREGRAPHIQUE' => $rolesDramaticChoreographic,
            'PRESTATION_LITTERAIRE_EMISSION' => $rolesLiteraryBroadcastPerformance,
            'PRESTATION_AUDIOVISUELLE' => $rolesAudiovisualPerformance,
            'PRODUCTION_PHONOGRAMME' => $rolesProduction,
            'PRODUCTION_VIDEOGRAMME' => $rolesProduction,
        ];
    }

    /**
     * @return array<string, list<array{name: string, code_qlt: ?string, available_in_registration: bool, legacy_names: list<string>}>>
     */
    private function membersByCode(): array
    {
        $membersCollegeEdition = [
            $this->member('Auteur', 'A'),
            $this->member('Traducteur', 'TR'),
            $this->member('Adaptateur', 'AD'),
            $this->member('Co-Auteur', 'CO'),
            $this->member('Auteur & éditeur', 'AE'),
            $this->member('Illustrateur', 'ILL'),
        ];
        $membersCollegePoesie = [
            $this->member('Auteur', 'A'),
            $this->member('Traducteur', 'TR'),
            $this->member('Adaptateur', 'AD'),
        ];
        $membersCollegeGraphiquePlastique = [
            $this->member('Auteur', 'A'),
            $this->member('Dessinateur', 'DE'),
            $this->member('Adaptateur', 'AD'),
            $this->member('Artiste plasticien', 'AP'),
        ];

        return [
            'MUSIQUE' => [
                $this->member('Auteur', 'A'),
                $this->member('Compositeur', 'C'),
                $this->member('Arrangeur', 'AR'),
                $this->member('Adaptateur', 'AD'),
                $this->member('Auteur-compositeur', 'CA'),
            ],
            'DRAMATIQUE' => [
                $this->member('Auteur', 'A'),
                $this->member("Auteur de l'oeuvre originale"),
                $this->member('Compositeur', 'C'),
                $this->member('Chorégraphe', 'CH'),
                $this->member('Traducteur', 'TR'),
                $this->member('Dialoguiste', 'DI'),
                $this->member('Scénariste', 'SC'),
                $this->member('Metteur en scène', 'MS'),
                $this->member('Réalisateur', 'R'),
            ],
            'LITTERAIRE_EMISSION' => [
                $this->member('Auteur', 'A'),
                $this->member("Auteur de l'oeuvre originale"),
                $this->member('Traducteur', 'TR'),
                $this->member('Adaptateur', 'AD'),
            ],
            'LITTERAIRE_EDITION' => $membersCollegeEdition,
            'POESIE' => $membersCollegePoesie,
            // Inert: no college has this code.
            'PHOTOGRAPHIE' => [
                $this->member('Photographe'),
            ],
            'ARTS_GRAPHIQUES' => $membersCollegeGraphiquePlastique,
            // Inert: no college has this code.
            'SUSCEPTIBLE_GESTION_COLLECTIVE' => [
                $this->member('Auteur d’œuvres littéraires (Inactive)'),
            ],
            'EDITEUR_MUSICAL' => [
                $this->member('Editeur d’œuvres musicales', 'E'),
                $this->member('Sous-Éditeur d’œuvres musicales', 'SE'),
                $this->member('Co-Éditeur d’œuvres musicales', 'CE'),
            ],
            'PRESTATION_LYRIQUE' => [
                $this->member("Chef d'orchestre", '03'),
                $this->member('Chanteur (se)', '02', true, ['Chanteur']),
                $this->member('Musicien (ne)', '08', true, ['Musicien']),
                $this->member('Soliste'),
                $this->member('Choriste', '01'),
            ],
            'PRESTATION_DRAMATIQUE_CHOREGRAPHIQUE' => [
                $this->member('Comédien (ne)', '04', true, ['Le comédien', 'Comédien']),
                $this->member('Danseur (se)', '05', true, ['Le danseur', 'Danseur']),
                $this->member('Magicien', '18'),
                $this->member('Clown', '20'),
            ],
            'PRESTATION_LITTERAIRE_EMISSION' => [
                $this->member('Le liseur', '07', true, ['Liseur', 'Le liseur']),
                $this->member('Le diseur'),
                $this->member('Le conteur'),
                $this->member('Le narrateur', '12', true, ['Narrateur', 'Le narrateur']),
                $this->member('Le doubleur de voix', '06', true, ['Doubleur de voix', 'Le doubleur de voix']),
            ],
            'PRESTATION_AUDIOVISUELLE' => [
                // Étape 2 — qualités d'adhésion (liste métier audiovisuelle).
                $this->member('Choriste', '01'),
                $this->member('Chanteur', '02'),
                $this->member("Chef d'orchestre", '03'),
                $this->member('Comédien', '04', true, ['Le comédien', 'Comédien']),
                $this->member('Danseur', '05', true, ['Le danseur', 'Danseur']),
                $this->member('Doubleur de voix', '06', true, ['Le doubleur de voix', 'Doubleur de voix']),
                $this->member('Liseur', '07', true, ['Le liseur', 'Liseur']),
                $this->member('Musicien', '08'),
                // $this->member('Producteur', '09'),
                $this->member('Radio', '10'),
                $this->member('Télévision', '11'),
                $this->member('Narrateur', '12', true, ['Le narrateur', 'Narrateur']),
                $this->member('Chanteuse', '13'),
                $this->member('Comédienne', '14'),
                // $this->member('Productrice', '15'),
                $this->member('Danseuse', '16'),
                $this->member('Musicienne', '17'),
                $this->member('Magicien', '18'),
                $this->member('Auto-producteur', '19', true, ['Autoproducteur', 'Auto-producteur']),
                $this->member('Clown', '20'),
                // $this->member('Exception', '21'),
                // $this->member('Non définie', 'XX'),
                // $this->member('Interprète', 'IN'),
                // Spécificité cinématographique / sous-natures fiche de participation.
                $this->member("L'Acteur", 'AC', true, ['Acteur', "L'Acteur"]),
                $this->member('Diseur', 'DI', true, ['Le diseur', 'Diseur']),
                $this->member('Conteur', 'CO', true, ['Le conteur', 'Conteur']),
            ],
            'PRODUCTION_PHONOGRAMME' => [
                $this->member('Producteur (trice)', '09', true, ['Producteur']),
                $this->member('Auto-producteur', '19'),
                $this->member('Auto-entrepreneur'),
            ],
            'PRODUCTION_VIDEOGRAMME' => [
                $this->member('Producteur (trice)', '09', true, ['Producteur']),
                $this->member('Auto-entrepreneur'),
            ],
            'LOGICIEL' => [
                $this->member('Auteur', 'A'),
                $this->member('Co-Auteur', 'CO'),
            ],
            'RECHERCHE_SCIENTIFIQUE' => [
                $this->member('Auteur', 'A'),
                $this->member('Co-Auteur', 'CO'),
            ],
            'SITE_WEB' => [
                $this->member('Auteur', 'A'),
                $this->member('Dessinateur', 'DE'),
            ],
            'THESES_MEMOIRES' => [
                $this->member('Auteur', 'A'),
            ],
            'SIMPLE_LITTERAIRE_EDITION' => $membersCollegeEdition,
            'SIMPLE_POESIE' => $membersCollegePoesie,
            'SIMPLE_ARTS_GRAPHIQUES' => $membersCollegeGraphiquePlastique,
            RegisterTypeCollege::CODE_REFERENTIEL_HORS_ADHESION => [
                $this->member('Impresario', 'IM', false),
                $this->member('Interprète', 'IN', false),
                $this->member('Non définie', 'XX', false),
                $this->member('Radio', '10', false),
                $this->member('Télévision', '11', false),
                $this->member('Exception', '21', false),
            ],
        ];
    }

    /**
     * @param  list<string>  $legacyNames
     * @return array{name: string, code_qlt: ?string, available_in_registration: bool, legacy_names: list<string>}
     */
    private function member(
        string $name,
        ?string $codeQlt = null,
        bool $availableInRegistration = true,
        array $legacyNames = [],
    ): array {
        return [
            'name' => $name,
            'code_qlt' => $codeQlt,
            'available_in_registration' => $availableInRegistration,
            'legacy_names' => $legacyNames,
        ];
    }

    /**
     * Update the college's existing member — matched by code_qlt, then a
     * legacy name, then the name — or create it. Never deletes.
     *
     * @param  array{name: string, code_qlt: ?string, available_in_registration: bool, legacy_names: list<string>}  $member
     */
    private function syncMember(int $collegeId, array $member): void
    {
        $name = $member['name'];
        $codeQlt = $member['code_qlt'];
        $available = $member['available_in_registration'];
        $legacyNames = $member['legacy_names'];

        $base = RegisterTypeMember::query()->where('register_type_college_id', $collegeId);

        $existing = null;
        if ($codeQlt !== null) {
            $existing = (clone $base)->where('code_qlt', $codeQlt)->first();
        }
        if ($existing === null && $legacyNames !== []) {
            $existing = (clone $base)->whereIn('name', $legacyNames)->first();
        }
        if ($existing === null) {
            $existing = (clone $base)->where('name', $name)->first();
        }

        $payload = [
            'name' => $name,
            'code_qlt' => $codeQlt,
            'available_in_registration' => $available,
        ];

        if ($existing instanceof RegisterTypeMember) {
            $existing->update($payload);

            return;
        }

        RegisterTypeMember::query()->create([
            'register_type_college_id' => $collegeId,
            ...$payload,
        ]);
    }
}
