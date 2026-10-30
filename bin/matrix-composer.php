<?php

// Writes composer.l<laravel>.json: composer.json pinned to one Laravel major, resolved for the
// lowest PHP that major supports, installed into vendor-l<laravel>/ (see bin/test-matrix.sh).
[$_, $laravel, $php] = $argv + [null, null, null];
$testbench = ['10' => '^8.0', '11' => '^9.0', '12' => '^10.0'][$laravel] ?? null;
if ($testbench === null || !preg_match('/^\d+\.\d+$/', (string) $php)) {
    fwrite(STDERR, "usage: matrix-composer.php <10|11|12> <php x.y>\n");
    exit(1);
}
$c = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true, 512, JSON_THROW_ON_ERROR);
$c['require-dev']['orchestra/testbench'] = $testbench;
foreach (['console', 'contracts', 'http', 'routing', 'support'] as $p) {
    $c['require']['illuminate/' . $p] = '^' . $laravel . '.0';
}
$c['config']['platform']['php'] = $php . '.0';
$c['config']['vendor-dir'] = 'vendor-l' . $laravel;
if ($laravel === '10' || $laravel === '11') {
    // Laravel 10 and 11 are past their security support and every release has open advisories;
    // these legs only prove the package still runs there. Apps on them should upgrade.
    $c['config']['policy']['advisories']['block'] = false;
}
file_put_contents(__DIR__ . "/../composer.l{$laravel}.json", json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
