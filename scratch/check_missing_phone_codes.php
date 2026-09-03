<?php

$jsonPath = __DIR__.'/../public/assets/seeders/countries.json';
$raw = json_decode(file_get_contents($jsonPath), true);
$data = [];

foreach ($raw as $entry) {
    if (isset($entry['type']) && $entry['type'] === 'table' && isset($entry['data'])) {
        $data = $entry['data'];
        break;
    }
}

$missingPhone = [];
foreach ($data as $c) {
    if (empty($c['phone_code'])) {
        $missingPhone[] = [
            'id' => $c['id'],
            'alpha2' => $c['alpha2'],
            'name' => $c['name'],
        ];
    }
}

echo 'Total countries in JSON: '.count($data)."\n";
echo 'Countries missing phone_code: '.count($missingPhone)."\n\n";

foreach ($missingPhone as $item) {
    echo "ID: {$item['id']} | Code: {$item['alpha2']} | Name: {$item['name']}\n";
}
