/**
 * The oeuvre classification cascade as pure functions, so the reveal,
 * filter and reset rules are testable without mounting the page.
 *
 *   Auteur:            type → gestion → collège → qualité
 *   every other type:  type → collège → qualité  (no gestion at all)
 *
 * The tree arrives from the server already filtered (status, no
 * REFERENTIEL_HORS_ADHESION, members available for registration only);
 * these functions only walk it.
 */

export interface MemberOption {
    id: number;
    name: string;
    available_in_registration: boolean;
}

export interface CollegeOption {
    id: number;
    name: string;
    code_college: string;
    type_gestion_id: number | null;
    is_disabled: boolean;
    members: MemberOption[];
}

export interface GestionOption {
    id: number;
    name: string;
}

export interface TypeOption {
    id: number;
    name: string;
    is_auteur: boolean;
    is_disabled: boolean;
    gestions: GestionOption[];
    colleges: CollegeOption[];
}

export interface ClassificationTree {
    types: TypeOption[];
}

export interface ClassificationSelection {
    register_type_id: number | null;
    type_gestion_id: number | null;
    register_type_college_id: number | null;
    register_type_member_id: number | null;
}

export const EMPTY_SELECTION: ClassificationSelection = {
    register_type_id: null,
    type_gestion_id: null,
    register_type_college_id: null,
    register_type_member_id: null,
};

export function findType(
    tree: ClassificationTree,
    typeId: number | null,
): TypeOption | undefined {
    return tree.types.find((type) => type.id === typeId);
}

export function findGestion(
    type: TypeOption | undefined,
    gestionId: number | null,
): GestionOption | undefined {
    return type?.gestions.find((gestion) => gestion.id === gestionId);
}

/** Only Auteur chooses a gestion; for every other type the select is absent. */
export function showsGestion(type: TypeOption | undefined): boolean {
    return type?.is_auteur === true;
}

/**
 * The colleges offered at level 3: for Auteur, those of the chosen gestion
 * (none until one is chosen); for any other type, all of the type's colleges.
 * Disabled colleges stay in the list — the page renders them unselectable.
 */
export function collegesFor(
    type: TypeOption | undefined,
    gestionId: number | null,
): CollegeOption[] {
    if (type === undefined) {
        return [];
    }

    if (!type.is_auteur) {
        return type.colleges;
    }

    return gestionId === null
        ? []
        : type.colleges.filter(
              (college) => college.type_gestion_id === gestionId,
          );
}

export function findCollege(
    type: TypeOption | undefined,
    gestionId: number | null,
    collegeId: number | null,
): CollegeOption | undefined {
    return collegesFor(type, gestionId).find(
        (college) => college.id === collegeId,
    );
}

export function membersFor(college: CollegeOption | undefined): MemberOption[] {
    return (college?.members ?? []).filter(
        (member) => member.available_in_registration,
    );
}

export function findMember(
    college: CollegeOption | undefined,
    memberId: number | null,
): MemberOption | undefined {
    return membersFor(college).find((member) => member.id === memberId);
}

// --- resets: changing a level clears every level below it ----------------
// A stale lower selection surviving a change above it forms a branch that is
// incoherent yet passes a required-fields check. Re-picking the current value
// changes nothing, as a native <select> fires no change for it.

export function selectType(
    selection: ClassificationSelection,
    typeId: number | null,
): ClassificationSelection {
    if (typeId === selection.register_type_id) {
        return selection;
    }

    return { ...EMPTY_SELECTION, register_type_id: typeId };
}

export function selectGestion(
    selection: ClassificationSelection,
    gestionId: number | null,
): ClassificationSelection {
    if (gestionId === selection.type_gestion_id) {
        return selection;
    }

    return {
        ...selection,
        type_gestion_id: gestionId,
        register_type_college_id: null,
        register_type_member_id: null,
    };
}

export function selectCollege(
    selection: ClassificationSelection,
    collegeId: number | null,
): ClassificationSelection {
    if (collegeId === selection.register_type_college_id) {
        return selection;
    }

    return {
        ...selection,
        register_type_college_id: collegeId,
        register_type_member_id: null,
    };
}

export function selectMember(
    selection: ClassificationSelection,
    memberId: number | null,
): ClassificationSelection {
    return { ...selection, register_type_member_id: memberId };
}

/** Every level answered, each within its parent, and nothing disabled. */
export function isSelectionComplete(
    tree: ClassificationTree,
    selection: ClassificationSelection,
): boolean {
    const type = findType(tree, selection.register_type_id);

    if (type === undefined || type.is_disabled) {
        return false;
    }

    if (
        type.is_auteur &&
        findGestion(type, selection.type_gestion_id) === undefined
    ) {
        return false;
    }

    const college = findCollege(
        type,
        type.is_auteur ? selection.type_gestion_id : null,
        selection.register_type_college_id,
    );

    return (
        college !== undefined &&
        !college.is_disabled &&
        findMember(college, selection.register_type_member_id) !== undefined
    );
}

/**
 * The request body. The gestion key is omitted entirely for every type but
 * Auteur — the server rejects a gestion posted for those types.
 */
export function toPayload(
    tree: ClassificationTree,
    selection: ClassificationSelection,
): Record<string, number | null> {
    const type = findType(tree, selection.register_type_id);
    const payload: Record<string, number | null> = {
        register_type_id: selection.register_type_id,
        register_type_college_id: selection.register_type_college_id,
        register_type_member_id: selection.register_type_member_id,
    };

    if (showsGestion(type)) {
        payload.type_gestion_id = selection.type_gestion_id;
    }

    return payload;
}
