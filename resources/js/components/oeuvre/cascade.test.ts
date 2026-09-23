import { describe, expect, it } from 'vitest';
import type {
    ClassificationSelection,
    ClassificationTree,
    CollegeOption,
} from './cascade';
import {
    EMPTY_SELECTION,
    collegesFor,
    findType,
    isSelectionComplete,
    membersFor,
    selectCollege,
    selectGestion,
    selectMember,
    selectType,
    showsGestion,
    toPayload,
} from './cascade';

function college(
    id: number,
    code: string,
    gestionId: number | null,
    options: { disabled?: boolean; members?: number[] } = {},
): CollegeOption {
    return {
        id,
        name: code.toLowerCase(),
        code_college: code,
        type_gestion_id: gestionId,
        is_disabled: options.disabled ?? false,
        members: (options.members ?? [id * 10]).map((memberId) => ({
            id: memberId,
            name: `member ${memberId}`,
            available_in_registration: true,
        })),
    };
}

/**
 * The shape the server sends for the seeded data: ids as seeded, the tree
 * already without REFERENTIEL_HORS_ADHESION (college 7).
 */
const tree: ClassificationTree = {
    types: [
        {
            id: 1,
            name: 'Auteur',
            is_auteur: true,
            is_disabled: false,
            gestions: [
                { id: 1, name: 'Gestion collective' },
                { id: 2, name: 'Gestion individuelle' },
                { id: 3, name: 'Simple protection' },
            ],
            colleges: [
                college(1, 'MUSIQUE', 1, { members: [10, 11] }),
                college(2, 'DRAMATIQUE', 1),
                college(3, 'LITTERAIRE_EMISSION', 1),
                college(4, 'LITTERAIRE_EDITION', 2),
                college(5, 'POESIE', 2),
                college(6, 'ARTS_GRAPHIQUES', 2),
                college(8, 'OEUVRE_FILM', 1, { disabled: true, members: [] }),
                college(16, 'SIMPLE_LITTERAIRE_EDITION', 3),
                college(17, 'SIMPLE_POESIE', 3),
                college(18, 'SIMPLE_ARTS_GRAPHIQUES', 3),
                college(19, 'LOGICIEL', 3),
                college(20, 'RECHERCHE_SCIENTIFIQUE', 3),
                college(21, 'SITE_WEB', 3),
                college(22, 'THESES_MEMOIRES', 3),
            ],
        },
        {
            id: 2,
            name: 'Editeur',
            is_auteur: false,
            is_disabled: false,
            gestions: [],
            colleges: [college(9, 'EDITEUR_MUSICAL', null)],
        },
        {
            id: 3,
            name: 'Artiste-interprète',
            is_auteur: false,
            is_disabled: false,
            gestions: [],
            colleges: [
                college(10, 'PRESTATION_LYRIQUE', null),
                college(11, 'PRESTATION_DRAMATIQUE_CHOREGRAPHIQUE', null),
                college(12, 'PRESTATION_LITTERAIRE_EMISSION', null),
                college(13, 'PRESTATION_AUDIOVISUELLE', null),
            ],
        },
        {
            id: 4,
            name: 'Producteur',
            is_auteur: false,
            is_disabled: false,
            gestions: [],
            colleges: [
                college(14, 'PRODUCTION_PHONOGRAMME', null),
                college(15, 'PRODUCTION_VIDEOGRAMME', null),
            ],
        },
    ],
};

const codes = (colleges: CollegeOption[]) =>
    colleges.map((c) => c.code_college);

function musiqueBranch(): ClassificationSelection {
    return {
        register_type_id: 1,
        type_gestion_id: 1,
        register_type_college_id: 1,
        register_type_member_id: 11,
    };
}

describe('the gestion level', () => {
    it('exists for Auteur', () => {
        expect(showsGestion(findType(tree, 1))).toBe(true);
    });

    it('does not exist for types 2, 3 and 4', () => {
        expect(showsGestion(findType(tree, 2))).toBe(false);
        expect(showsGestion(findType(tree, 3))).toBe(false);
        expect(showsGestion(findType(tree, 4))).toBe(false);
        expect(showsGestion(undefined)).toBe(false);
    });
});

describe('college lists', () => {
    it('Auteur + gestion 1 yields MUSIQUE, DRAMATIQUE, LITTERAIRE_EMISSION and OEUVRE_FILM (disabled)', () => {
        const colleges = collegesFor(findType(tree, 1), 1);

        expect(codes(colleges)).toEqual([
            'MUSIQUE',
            'DRAMATIQUE',
            'LITTERAIRE_EMISSION',
            'OEUVRE_FILM',
        ]);
        expect(codes(colleges)).not.toContain('REFERENTIEL_HORS_ADHESION');
        expect(
            colleges.find((c) => c.code_college === 'OEUVRE_FILM')?.is_disabled,
        ).toBe(true);
    });

    it('Auteur + gestion 3 yields exactly the seven simple-protection colleges', () => {
        expect(codes(collegesFor(findType(tree, 1), 3))).toEqual([
            'SIMPLE_LITTERAIRE_EDITION',
            'SIMPLE_POESIE',
            'SIMPLE_ARTS_GRAPHIQUES',
            'LOGICIEL',
            'RECHERCHE_SCIENTIFIQUE',
            'SITE_WEB',
            'THESES_MEMOIRES',
        ]);
    });

    it('Auteur without a gestion yields nothing yet', () => {
        expect(collegesFor(findType(tree, 1), null)).toEqual([]);
    });

    it('type 3 yields exactly the four prestation colleges', () => {
        expect(codes(collegesFor(findType(tree, 3), null))).toEqual([
            'PRESTATION_LYRIQUE',
            'PRESTATION_DRAMATIQUE_CHOREGRAPHIQUE',
            'PRESTATION_LITTERAIRE_EMISSION',
            'PRESTATION_AUDIOVISUELLE',
        ]);
    });

    it('drops a member not available for registration', () => {
        const withHidden: CollegeOption = {
            ...college(99, 'X', null),
            members: [
                { id: 1, name: 'visible', available_in_registration: true },
                { id: 2, name: 'hidden', available_in_registration: false },
            ],
        };

        expect(membersFor(withHidden).map((m) => m.name)).toEqual(['visible']);
    });
});

describe('resets: a change clears every level below it', () => {
    it('changing the type clears gestion, collège and qualité', () => {
        expect(selectType(musiqueBranch(), 3)).toEqual({
            register_type_id: 3,
            type_gestion_id: null,
            register_type_college_id: null,
            register_type_member_id: null,
        });
    });

    it('changing the type from another type to Auteur carries nothing over', () => {
        const prestation: ClassificationSelection = {
            register_type_id: 3,
            type_gestion_id: null,
            register_type_college_id: 13,
            register_type_member_id: 130,
        };

        expect(selectType(prestation, 1)).toEqual({
            ...EMPTY_SELECTION,
            register_type_id: 1,
        });
    });

    it('changing the gestion clears collège and qualité, keeping the type', () => {
        expect(selectGestion(musiqueBranch(), 3)).toEqual({
            register_type_id: 1,
            type_gestion_id: 3,
            register_type_college_id: null,
            register_type_member_id: null,
        });
    });

    it('changing the collège clears the qualité only', () => {
        expect(selectCollege(musiqueBranch(), 2)).toEqual({
            register_type_id: 1,
            type_gestion_id: 1,
            register_type_college_id: 2,
            register_type_member_id: null,
        });
    });

    it('re-picking the current value clears nothing', () => {
        const branch = musiqueBranch();

        expect(selectType(branch, 1)).toBe(branch);
        expect(selectGestion(branch, 1)).toBe(branch);
        expect(selectCollege(branch, 1)).toBe(branch);
    });

    it('choosing the qualité leaves the levels above untouched', () => {
        expect(selectMember(musiqueBranch(), 10)).toEqual({
            ...musiqueBranch(),
            register_type_member_id: 10,
        });
    });
});

describe('completeness and payload', () => {
    it('accepts a coherent Auteur branch', () => {
        expect(isSelectionComplete(tree, musiqueBranch())).toBe(true);
    });

    it('rejects a stale collège that no longer matches the gestion', () => {
        expect(
            isSelectionComplete(tree, {
                ...musiqueBranch(),
                type_gestion_id: 3,
            }),
        ).toBe(false);
    });

    it('rejects a disabled collège and a member from another collège', () => {
        expect(
            isSelectionComplete(tree, {
                ...musiqueBranch(),
                register_type_college_id: 8,
                register_type_member_id: null,
            }),
        ).toBe(false);
        expect(
            isSelectionComplete(tree, {
                ...musiqueBranch(),
                register_type_member_id: 20,
            }),
        ).toBe(false);
    });

    it('accepts a type 3 branch with no gestion', () => {
        expect(
            isSelectionComplete(tree, {
                register_type_id: 3,
                type_gestion_id: null,
                register_type_college_id: 10,
                register_type_member_id: 100,
            }),
        ).toBe(true);
    });

    it('sends the gestion for Auteur and omits the key for other types', () => {
        expect(toPayload(tree, musiqueBranch())).toHaveProperty(
            'type_gestion_id',
            1,
        );
        expect(
            toPayload(tree, {
                register_type_id: 3,
                type_gestion_id: 2,
                register_type_college_id: 10,
                register_type_member_id: 100,
            }),
        ).not.toHaveProperty('type_gestion_id');
    });
});
