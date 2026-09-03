<?php

$jsonPath = __DIR__.'/../public/assets/seeders/countries.json';
$raw = json_decode(file_get_contents($jsonPath), true);

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
    $alpha2 = strtolower(trim($country['alpha2'] ?? ''));
    if (! empty($alpha2)) {
        // Flagcdn SVG URL format: https://flagcdn.com/{alpha2}.svg (or w40: https://flagcdn.com/w40/{alpha2}.png)
        $country['flag_url'] = "https://flagcdn.com/{$alpha2}.svg";
        $updatedCount++;
    }
}

file_put_contents($jsonPath, json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "Successfully updated flag_url for {$updatedCount} countries in countries.json.\n";
