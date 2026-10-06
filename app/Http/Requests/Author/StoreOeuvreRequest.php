<?php

declare(strict_types=1);

namespace App\Http\Requests\Author;

use App\Models\RegisterType;
use App\Models\RegisterTypeCollege;
use App\Models\RegisterTypeMember;
use App\Models\TypeGestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Classifying a new oeuvre: declarant type → type de gestion (only for a type
 * that has an active gestion) → collège → qualité.
 *
 * Required fields are not enough: every id can exist and still form a branch
 * no correct form produces — a college of another type, a member of another
 * college, a gestion the college doesn't belong to. after() rejects each of
 * those explicitly, because a crafted request will send them.
 *
 * Error messages are frontend translation keys
 * (`oeuvres.classification.errors.*`), shown in the viewer's locale.
 *
 * No author or title input is read: the controller files the oeuvre for the
 * authenticated user, and the title is collected later in the flow.
 */
final class StoreOeuvreRequest extends FormRequest
{
    private const STATUS_ACTIVE = 1;

    private const ERRORS = 'oeuvres.classification.errors.';

    public function authorize(): bool
    {
        // The route's role:author middleware is the gate; the oeuvre is
        // always created for the authenticated user.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'register_type_id' => [
                'bail', 'required', 'integer',
                Rule::exists('register_types', 'id')->where('status', self::STATUS_ACTIVE)->whereNull('deleted_at'),
            ],
            // Required for a type that has a gestion level, rejected outright
            // for one that does not — the predicate is RegisterType::
            // hasActiveGestions(), the same one the page is given.
            'type_gestion_id' => [
                'bail',
                Rule::prohibitedIf(fn (): bool => ! $this->typeHasGestions()),
                Rule::requiredIf(fn (): bool => $this->typeHasGestions()),
                'nullable', 'integer',
                Rule::exists('type_gestions', 'id')->where('status', self::STATUS_ACTIVE)->whereNull('deleted_at'),
            ],
            'register_type_college_id' => [
                'bail', 'required', 'integer',
                Rule::exists('register_type_colleges', 'id')->where('status', self::STATUS_ACTIVE)->whereNull('deleted_at'),
            ],
            'register_type_member_id' => [
                'bail', 'required', 'integer',
                Rule::exists('register_type_members', 'id')->where('status', self::STATUS_ACTIVE)->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'register_type_id.required' => self::ERRORS.'typeRequired',
            'register_type_id.integer' => self::ERRORS.'typeInvalid',
            'register_type_id.exists' => self::ERRORS.'typeInvalid',
            'type_gestion_id.prohibited' => self::ERRORS.'gestionNotAllowed',
            'type_gestion_id.required' => self::ERRORS.'gestionRequired',
            'type_gestion_id.integer' => self::ERRORS.'gestionInvalid',
            'type_gestion_id.exists' => self::ERRORS.'gestionInvalid',
            'register_type_college_id.required' => self::ERRORS.'collegeRequired',
            'register_type_college_id.integer' => self::ERRORS.'collegeInvalid',
            'register_type_college_id.exists' => self::ERRORS.'collegeInvalid',
            'register_type_member_id.required' => self::ERRORS.'memberRequired',
            'register_type_member_id.integer' => self::ERRORS.'memberInvalid',
            'register_type_member_id.exists' => self::ERRORS.'memberInvalid',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->rejectIncoherentBranch($validator)];
    }

    /**
     * The validated classification, as stored on the oeuvre.
     *
     * @return array{register_type_id: int, type_gestion_id: int|null, register_type_college_id: int, register_type_member_id: int, code_college_snapshot: string|null}
     */
    public function classification(): array
    {
        $college = RegisterTypeCollege::query()->findOrFail($this->integer('register_type_college_id'));

        return [
            'register_type_id' => $this->integer('register_type_id'),
            'type_gestion_id' => $this->typeHasGestions() ? $this->integer('type_gestion_id') : null,
            'register_type_college_id' => $college->id,
            'register_type_member_id' => $this->integer('register_type_member_id'),
            'code_college_snapshot' => $college->code_college,
        ];
    }

    private function rejectIncoherentBranch(Validator $validator): void
    {
        // A missing or unknown id is already reported by rules(); the
        // relationships between levels only mean something once all exist.
        if ($validator->errors()->hasAny(['register_type_id', 'type_gestion_id', 'register_type_college_id', 'register_type_member_id'])) {
            return;
        }

        $typeId = $this->integer('register_type_id');
        $college = RegisterTypeCollege::query()->find($this->integer('register_type_college_id'));
        $member = RegisterTypeMember::query()->find($this->integer('register_type_member_id'));

        if ($college === null || $member === null) {
            return;
        }

        if ($college->register_type_id !== $typeId) {
            $validator->errors()->add('register_type_college_id', self::ERRORS.'collegeWrongType');
        }

        // Checked before is_disabled: REFERENTIEL_HORS_ADHESION is also
        // disabled, but "not depositable at all" is the accurate reason.
        if ($college->code_college === RegisterTypeCollege::CODE_REFERENTIEL_HORS_ADHESION) {
            $validator->errors()->add('register_type_college_id', self::ERRORS.'collegeNotDepositable');
        } elseif ($college->is_disabled) {
            $validator->errors()->add('register_type_college_id', self::ERRORS.'collegeUnavailable');
        }

        if ($this->typeHasGestions()) {
            $gestionId = $this->integer('type_gestion_id');
            $gestion = TypeGestion::query()->find($gestionId);

            if ($gestion === null || $gestion->register_type_id !== $typeId) {
                $validator->errors()->add('type_gestion_id', self::ERRORS.'gestionWrongType');
            }

            if ($college->type_gestion_id !== $gestionId) {
                $validator->errors()->add('register_type_college_id', self::ERRORS.'collegeWrongGestion');
            }
        } elseif ($college->type_gestion_id !== null) {
            // A type with no gestion level offers only its gestion-less
            // colleges; one still tied to a (now retired) gestion is
            // unreachable, exactly as the tree hides it.
            $validator->errors()->add('register_type_college_id', self::ERRORS.'collegeWrongGestion');
        }

        if ($member->register_type_college_id !== $college->id) {
            $validator->errors()->add('register_type_member_id', self::ERRORS.'memberWrongCollege');
        }

        if (! $member->available_in_registration || $member->is_disabled) {
            $validator->errors()->add('register_type_member_id', self::ERRORS.'memberUnavailable');
        }
    }

    private function typeHasGestions(): bool
    {
        $typeId = $this->input('register_type_id');

        if (! is_numeric($typeId)) {
            return false;
        }

        $type = RegisterType::query()->find((int) $typeId);

        return $type !== null && $type->hasActiveGestions();
    }
}
