<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Referentiel\Concerns;

use App\Http\Requests\Admin\Referentiel\Rules\UniqueTrimmedName;
use Illuminate\Database\Eloquent\Model;

/**
 * `name` on an update request — editable on an admin-created row, REFUSED on
 * a system row.
 *
 * A system row's `name` is a seeder match key (colleges are matched on
 * (type, name, gestion), types on `name`): editing it would leave the row
 * unmatched, and the next run would create a duplicate. It is refused with
 * a message rather than silently ignored, so an officer sees the rule.
 *
 * Everything frozen after creation (`code_college`, `code_qlt`, `slug`,
 * `document_key`, every parent id) is simply absent from every rules()
 * array: anything not listed is never read, so a crafted request carrying
 * one changes nothing.
 */
trait ValidatesEditableName
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @param  array<string, int|null>  $scope  the parent columns the name is unique within
     * @return array<int, mixed>
     */
    protected function nameRules(Model $row, string $table, array $scope): array
    {
        if ($row->getAttribute('is_system') === true) {
            return ['prohibited'];
        }

        return ['sometimes', 'required', 'string', 'max:255', new UniqueTrimmedName($table, $scope, (int) $row->getKey())];
    }

    /**
     * @return array<string, string>
     */
    protected function systemNameMessages(): array
    {
        return ['name.prohibited' => 'admin.referentiel.errors.systemName'];
    }
}
