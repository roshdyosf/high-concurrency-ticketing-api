<?php

$proxies = (string) env('TRUSTED_PROXIES', '');

return [
    // '*' trusts the calling IP (testing only); otherwise a comma-separated list of IPs/CIDRs
    'proxies' => $proxies === '*'
        ? '*'
        : array_values(array_filter(array_map('trim', explode(',', $proxies)))),
];
