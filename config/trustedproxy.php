<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| Read by Laravel's TrustProxies middleware (`config('trustedproxy.proxies')`).
| By default NO proxy is trusted: `$request->ip()` is the directly connected
| peer and every X-Forwarded-* header from a client is ignored, so a client
| cannot choose the IP the login limiter counts.
|
| Only when the app is really behind a reverse proxy / load balancer, list
| ITS address(es) in AUTH_TRUSTED_PROXIES (comma separated IPs or CIDR
| ranges). Without that, every visitor would share the proxy's IP.
|
| Deliberately refused, whatever the .env says: `*`, `**`, `REMOTE_ADDR`
| (all mean "trust whoever is calling"), anything that is not an IP or CIDR,
| and ranges wider than IPv4 /8 or IPv6 /32. Refused entries are simply not
| trusted; the count is exposed as `trustedproxy.rejected`.
|
*/

$proxies = [];
$rejected = 0;

foreach (explode(',', (string) env('AUTH_TRUSTED_PROXIES', '')) as $entry) {
    $entry = trim($entry);

    if ($entry === '') {
        continue;
    }

    [$ip, $mask] = array_pad(explode('/', $entry, 2), 2, null);

    $version = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 4
        : (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 6 : null);

    $max = $version === 4 ? 32 : 128;
    $min = $version === 4 ? 8 : 32;

    $maskOk = $mask === null
        || (ctype_digit($mask) && (int) $mask >= $min && (int) $mask <= $max);

    if ($version === null || ! $maskOk) {
        $rejected++;

        continue;
    }

    $proxies[] = $entry;
}

return [

    // An empty array (not null) also stops the framework's built-in
    // "trust everything on Forge/Vapor/Cloud hostnames" shortcut.
    'proxies' => $proxies,

    'rejected' => $rejected,

];
