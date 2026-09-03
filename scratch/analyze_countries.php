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

echo 'Total countries in JSON: '.count($data)."\n";
if (! empty($data)) {
    echo "Columns in sample record:\n";
    foreach (array_keys($data[0]) as $k) {
        echo ' - '.$k.' (sample: '.var_export($data[0][$k], true).")\n";
    }
}
