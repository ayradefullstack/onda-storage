import { computed, nextTick, reactive, ref } from 'vue';
import { ARABIC_NAME, hasArabicLetters } from '@/lib/authInput';

/**
 * Presentation-side state for the Login / Register forms.
 *
 * It never submits anything and never decides what is valid on the server:
 * the Inertia `<Form>` still posts the same fields to the same Fortify
 * endpoints. This only (1) gives an earlier, localised message for rules the
 * backend enforces anyway, (2) shows the server's own errors on the right
 * field, (3) moves focus to the first invalid field and (4) feeds the error
 * summary. Rules here must stay a subset of the backend's — see rules below.
 */

export type RuleResult = {
    key: string;
    params?: Record<string, unknown>;
} | null;
export type Rule = (value: string, get: (name: string) => string) => RuleResult;

export type AuthFieldDef = {
    name: string;
    /** DOM id of the control, so focus and summary links can reach it. */
    id: string;
    label: () => string;
    rule?: Rule;
    /** Show the green "valid" state after a clean blur (Register only). */
    showValid?: boolean;
};

type Translate = (key: string, params?: Record<string, unknown>) => string;

// ---------------------------------------------------------------- rules ----
// Each mirrors a backend rule in App\Actions\Fortify\CreateNewUser or Fortify's
// login request; none is stricter than the server.

export const requiredText: Rule = (value) =>
    value.trim() === '' ? { key: 'authUi.err.required' } : null;

export const requiredSelect: Rule = (value) =>
    value.trim() === '' ? { key: 'authUi.err.requiredSelect' } : null;

export const emailRule: Rule = (value) => {
    const v = value.trim();

    if (v === '') {
        return { key: 'authUi.err.required' };
    }

    if (hasArabicLetters(v)) {
        return { key: 'authUi.err.emailArabic' };
    }

    return /^[^\s@]+@[^\s@]+$/.test(v)
        ? null
        : { key: 'authUi.err.emailFormat' };
};

export const arabicNameRule: Rule = (value) => {
    const v = value.trim();

    if (v === '') {
        return { key: 'authUi.err.required' };
    }

    return ARABIC_NAME.test(v) ? null : { key: 'authUi.err.arabicOnly' };
};

export function newPasswordRule(minLength: number | null): Rule {
    return (value) => {
        if (value === '') {
            return { key: 'authUi.err.required' };
        }

        return minLength !== null && value.length < minLength
            ? { key: 'authUi.err.pwMin', params: { n: minLength } }
            : null;
    };
}

export function confirmRule(passwordName: string): Rule {
    return (value, get) => {
        if (value === '') {
            return { key: 'authUi.err.required' };
        }

        return value === get(passwordName)
            ? null
            : { key: 'authUi.err.pwMatch' };
    };
}

// ------------------------------------------------------------ composable ---

export function useAuthForm(defs: AuthFieldDef[], translate: Translate) {
    const values = reactive<Record<string, string>>({});
    const clientErrors = reactive<Record<string, string>>({});
    const valid = reactive<Record<string, boolean>>({});
    const dismissed = reactive<Record<string, boolean>>({});
    const serverErrors = ref<Record<string, string>>({});
    const submitted = ref(false);
    const busy = ref(false);

    const byName = new Map(defs.map((def) => [def.name, def]));

    function get(name: string): string {
        return values[name] ?? '';
    }

    function evaluate(name: string): RuleResult {
        return byName.get(name)?.rule?.(get(name), get) ?? null;
    }

    /** Reads uncontrolled inputs (autofill, restored values) into `values`. */
    function syncFromDom(): void {
        for (const def of defs) {
            const el = document.getElementById(def.id);

            if (el instanceof HTMLInputElement) {
                values[def.name] = el.value;
            }
        }
    }

    function setValue(name: string, value: string): void {
        values[name] = value;
        dismissed[name] = true;

        if (clientErrors[name] || valid[name]) {
            validate(name);
        }
    }

    function validate(name: string): boolean {
        const def = byName.get(name);
        const result = evaluate(name);

        if (result) {
            clientErrors[name] = translate(result.key, {
                f: def?.label() ?? name,
                ...result.params,
            });
            valid[name] = false;

            return false;
        }

        delete clientErrors[name];
        valid[name] = Boolean(def?.showValid) && get(name) !== '';

        return true;
    }

    /** Re-checks a field only if the user already saw feedback on it. */
    function revalidate(name: string): void {
        if (clientErrors[name] || valid[name]) {
            validate(name);
        }
    }

    function onBlur(name: string): void {
        // Don't scold a field the user merely tabbed through.
        if (get(name) !== '' || clientErrors[name] || valid[name]) {
            validate(name);
        }
    }

    function error(name: string): string {
        return (
            clientErrors[name] ||
            (dismissed[name] ? '' : (serverErrors.value[name] ?? ''))
        );
    }

    function focusFirstInvalid(): void {
        void nextTick(() => {
            const first = defs.find((def) => error(def.name) !== '');

            document.getElementById(first?.id ?? '')?.focus();
        });
    }

    /** Client-side pass over every field; false = stop, show the summary. */
    function validateAll(): boolean {
        syncFromDom();

        let ok = true;

        for (const def of defs) {
            if (def.rule && !validate(def.name)) {
                ok = false;
            }
        }

        if (!ok) {
            submitted.value = true;
            focusFirstInvalid();
        }

        return ok;
    }

    /** Inertia `onBefore`: false cancels the visit. Also blocks a double submit. */
    function begin(): boolean {
        if (busy.value || !validateAll()) {
            return false;
        }

        busy.value = true;
        submitted.value = false;
        serverErrors.value = {};

        for (const key of Object.keys(dismissed)) {
            delete dismissed[key];
        }

        return true;
    }

    function finish(): void {
        busy.value = false;
    }

    /** Inertia `onError`: the server's validation messages, field by field. */
    function setServerErrors(errors: Record<string, string>): void {
        serverErrors.value = errors;

        for (const key of Object.keys(dismissed)) {
            delete dismissed[key];
        }

        submitted.value = true;
        focusFirstInvalid();
    }

    /** True when every named field passes its rule (silent, no messages). */
    function isComplete(names: string[]): boolean {
        return names.every((name) => evaluate(name) === null);
    }

    const summary = computed(() =>
        submitted.value
            ? defs
                  .filter((def) => error(def.name) !== '')
                  .map((def) => ({
                      id: def.id,
                      name: def.name,
                      label: def.label(),
                      message: error(def.name),
                  }))
            : [],
    );

    /** The first visible error after a failed submit (Login's page alert). */
    const firstError = computed(
        () =>
            (submitted.value
                ? defs.map((def) => error(def.name)).find((m) => m !== '')
                : '') ?? '',
    );

    function bind(name: string) {
        return {
            error: error(name),
            valid: valid[name] === true && error(name) === '',
            onBlur: () => onBlur(name),
            onInput: (value: string) => setValue(name, value),
        };
    }

    return {
        values,
        busy,
        summary,
        firstError,
        error,
        bind,
        begin,
        finish,
        setValue,
        revalidate,
        syncFromDom,
        setServerErrors,
        isComplete,
    };
}
