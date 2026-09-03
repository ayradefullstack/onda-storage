<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\VaultServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    VaultServiceProvider::class,
];
