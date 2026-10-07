<?php

namespace Database\Seeders;

use App\Models\Commune;
use App\Models\Wilaya;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CommuneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $jsonPath = public_path('assets/seeders/algeria_cities.json');

        if (! File::exists($jsonPath)) {
            $this->command->error("JSON file not found at: {$jsonPath}");

            return;
        }

        $jsonContent = File::get($jsonPath);
        $cities = json_decode($jsonContent, true);

        if (! is_array($cities)) {
            $this->command->error("Invalid JSON content in: {$jsonPath}");

            return;
        }

        $wilayas = Wilaya::all()->keyBy('code');

        $batch = [];
        $now = now();

        foreach ($cities as $city) {
            $wilayaCode = sprintf('%02d', (int) ($city['wilaya_code'] ?? 0));
            $wilaya = $wilayas->get($wilayaCode);

            if (! $wilaya) {
                continue;
            }

            $nameFr = trim($city['commune_name_fr'] ?? '');
            $nameAr = trim($city['commune_name'] ?? '');
            $postCode = isset($city['code_commune']) ? (string) $city['code_commune'] : null;

            if (empty($nameFr) && empty($nameAr)) {
                continue;
            }

            $batch[] = [
                'uuid' => (string) Str::uuid7(),
                'wilaya_id' => $wilaya->id,
                'post_code' => $postCode,
                'name_fr' => $nameFr ?: $nameAr,
                'name_ar' => $nameAr ?: $nameFr,
                'is_active' => true,
                'is_visible' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 500) {
                Commune::insert($batch);
                $batch = [];
            }
        }

        if (! empty($batch)) {
            Commune::insert($batch);
        }
    }
}
