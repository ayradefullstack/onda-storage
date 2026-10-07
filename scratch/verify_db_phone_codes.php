<?php

use App\Models\Country;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$total = Country::count();
$withPhone = Country::whereNotNull('phone_code')->where('phone_code', '!=', '')->count();
$withoutPhone = Country::whereNull('phone_code')->orWhere('phone_code', '')->count();

echo "Total countries in database: {$total}\n";
echo "Countries with valid phone_code: {$withPhone}\n";
echo "Countries without phone_code: {$withoutPhone}\n";

$samples = Country::take(10)->get(['id', 'alpha2', 'name', 'phone_code']);
foreach ($samples as $s) {
    echo " - [{$s->alpha2}] {$s->name}: {$s->phone_code}\n";
}
