<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\Contracts\HasPresentation;
use BackedEnum;

/**
 * Enum fields arrive as their KEY ('active', 'rtl') — the same string the
 * page received in its props — and are validated, and handed to the model,
 * as the stored int. A known key is swapped for its value before
 * validation; an unknown key is left untouched, so `Rule::enum()` rejects
 * it with a 422. A raw int is still accepted.
 *
 * Hooks validationData() rather than prepareForValidation(), so a request
 * that already prepares its input keeps doing so unchanged.
 */
trait AcceptsEnumKeys
{
    /**
     * @return array<string, class-string<BackedEnum&HasPresentation>> field => enum class
     */
    abstract protected function enumFields(): array;

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $data = parent::validationData();

        foreach ($this->enumFields() as $field => $enum) {
            $input = $data[$field] ?? null;

            if (is_string($input) && ($case = $enum::fromKey($input)) !== null) {
                $data[$field] = $case->value;
            }
        }

        return $data;
    }
}
