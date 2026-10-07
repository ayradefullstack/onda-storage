<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RegisterType;
use App\Models\TypeGestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TypeGestion>
 */
class TypeGestionFactory extends Factory
{
    protected $model = TypeGestion::class;

    public function definition(): array
    {
        return [
            'register_type_id' => RegisterType::factory(),
            'type_gestion' => TypeGestion::COLLECTIVE,
            'name' => 'Gestion collective',
        ];
    }
}
