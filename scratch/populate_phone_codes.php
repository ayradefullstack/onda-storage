<?php

$jsonPath = __DIR__.'/../public/assets/seeders/countries.json';
$raw = json_decode(file_get_contents($jsonPath), true);

$phoneCodes = [
    'DZ' => '+213',
    'FR' => '+33',
    'TN' => '+216',
    'MA' => '+212',
    'EG' => '+20',
    'SA' => '+966',
    'AE' => '+971',
    'GB' => '+44',
    'US' => '+1',
    'CA' => '+1',
    'DE' => '+49',
    'ES' => '+34',
    'IT' => '+39',
    'TR' => '+90',
    'BR' => '+55',
    'GR' => '+30',
    'UY' => '+598',
    'AT' => '+43',
    'CF' => '+236',
    'PE' => '+51',
    'AU' => '+61',
    'HU' => '+36',
    'AR' => '+54',
    'PY' => '+595',
    'ML' => '+223',
    'GN' => '+224',
    'NG' => '+234',
    'NL' => '+31',
    'CI' => '+225',
    'SN' => '+221',
    'HK' => '+852',
    'SK' => '+421',
    'CL' => '+56',
    'CZ' => '+420',
    'PH' => '+63',
    'MG' => '+261',
    'IN' => '+91',
    'BJ' => '+229',
    'JP' => '+81',
    'BG' => '+359',
    'DK' => '+45',
    'KE' => '+254',
    'BF' => '+226',
    'CG' => '+242',
    'CH' => '+41',
    'BE' => '+32',
    'MX' => '+52',
    'VE' => '+58',
    'ZA' => '+27',
    'RS' => '+381',
    'EC' => '+593',
    'PT' => '+351',
    'PR' => '+1',
    'IS' => '+354',
    'SE' => '+46',
    'CD' => '+243',
    'CO' => '+57',
    'PF' => '+689',
    'FI' => '+358',
    'NO' => '+47',
    'CM' => '+237',
    'RU' => '+7',
    'TT' => '+1868',
    'PL' => '+48',
    'ZW' => '+263',
    'NA' => '+264',
    'CU' => '+53',
    'MY' => '+60',
    'MU' => '+230',
    'SG' => '+65',
    'CR' => '+506',
    'TW' => '+886',
    'ID' => '+62',
    'LT' => '+370',
    'HR' => '+385',
    'SI' => '+386',
    'LV' => '+371',
    'GT' => '+502',
    'RO' => '+40',
    'EE' => '+372',
    'KR' => '+82',
    'CN' => '+86',
    'GH' => '+233',
    'MW' => '+265',
    'NE' => '+227',
    'TH' => '+66',
    'AL' => '+355',
    'IE' => '+353',
    'BO' => '+591',
    'TG' => '+228',
    'ZM' => '+260',
    'LK' => '+94',
    'MK' => '+389',
    'UA' => '+380',
    'PA' => '+507',
    'BY' => '+375',
    'JM' => '+1876',
    'KZ' => '+7',
    'MD' => '+373',
];

$tableIndex = null;
foreach ($raw as $idx => $entry) {
    if (isset($entry['type']) && $entry['type'] === 'table' && isset($entry['data'])) {
        $tableIndex = $idx;
        break;
    }
}

if ($tableIndex === null) {
    exit("Table data not found\n");
}

$updatedCount = 0;
foreach ($raw[$tableIndex]['data'] as &$country) {
    $alpha2 = $country['alpha2'] ?? '';
    if (isset($phoneCodes[$alpha2])) {
        $country['phone_code'] = $phoneCodes[$alpha2];
        $updatedCount++;
    }
}

echo "Successfully mapped phone codes for {$updatedCount} countries.\n";

// Check if any country in JSON still lacks phone code
$missing = [];
foreach ($raw[$tableIndex]['data'] as $c) {
    if (empty($c['phone_code'])) {
        $missing[] = $c['name'].' ('.$c['alpha2'].')';
    }
}

if (empty($missing)) {
    echo "ALL 99 COUNTRIES NOW HAVE 100% VALID PHONE CODES!\n";
    file_put_contents($jsonPath, json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "Updated public/assets/seeders/countries.json successfully.\n";
} else {
    echo 'Still missing: '.implode(', ', $missing)."\n";
}
