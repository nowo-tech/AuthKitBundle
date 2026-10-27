#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * REQ-MAKE-004 / REQ-I18N-002 — every translation catalogue must parse and hold exactly the same flattened
 * key set as its `en` reference, for the bundle domain (NowoAuthKitBundle). REQ-I18N-003: no other catalogue may be
 * shipped (constraint messages are translated in the bundle domain too).
 *
 * Usage: php .scripts/validate-translations.php [translations-dir]
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

$translationsDir = $argv[1] ?? dirname(__DIR__) . '/src/Resources/translations';
$domains         = ['NowoAuthKitBundle'];
$referenceLocale = 'en';

if (!is_dir($translationsDir)) {
    fwrite(STDERR, "Translations directory not found: {$translationsDir}\n");
    exit(1);
}

$failed = false;

// REQ-I18N-003: every shipped catalogue belongs to the bundle domain.
foreach (glob($translationsDir . '/*') ?: [] as $file) {
    if (!in_array(explode('.', basename($file))[0], $domains, true)) {
        $failed = true;
        fwrite(STDERR, "Catalogue outside the bundle domain (REQ-I18N-003): {$file}\n");
    }
}

foreach ($domains as $domain) {
    $referenceFile = sprintf('%s/%s.%s.yaml', $translationsDir, $domain, $referenceLocale);
    $referenceKeys = loadKeys($referenceFile);
    if ($referenceKeys === null) {
        $failed = true;

        continue;
    }

    $files = glob(sprintf('%s/%s.*.yaml', $translationsDir, $domain)) ?: [];
    foreach ($files as $file) {
        if (!preg_match('/\.([a-z]{2}(?:_[A-Z]{2})?)\.yaml$/', $file, $matches)) {
            continue;
        }

        $keys = loadKeys($file);
        if ($keys === null) {
            $failed = true;

            continue;
        }

        $missing = array_diff($referenceKeys, $keys);
        $extra   = array_diff($keys, $referenceKeys);
        if ($missing === [] && $extra === []) {
            echo sprintf("OK %s.%s (%d keys)\n", $domain, $matches[1], count($keys));

            continue;
        }

        $failed = true;
        fwrite(STDERR, "Key mismatch in {$file}:\n");
        if ($missing !== []) {
            fwrite(STDERR, '  Missing keys: ' . implode(', ', $missing) . "\n");
        }
        if ($extra !== []) {
            fwrite(STDERR, '  Extra keys: ' . implode(', ', $extra) . "\n");
        }
    }
}

exit($failed ? 1 : 0);

/**
 * @return list<string>|null null when the file is missing or invalid YAML
 */
function loadKeys(string $file): ?array
{
    if (!is_file($file)) {
        fwrite(STDERR, "Catalogue not found: {$file}\n");

        return null;
    }

    try {
        $data = Yaml::parseFile($file) ?? [];
    } catch (ParseException $e) {
        fwrite(STDERR, "Failed to parse {$file}: {$e->getMessage()}\n");

        return null;
    }

    $keys = array_keys(flattenKeys(is_array($data) ? $data : []));
    sort($keys);

    return $keys;
}

/**
 * @param array<mixed> $data
 *
 * @return array<string, true>
 */
function flattenKeys(array $data, string $prefix = ''): array
{
    $flat = [];
    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        if (is_array($value)) {
            $flat += flattenKeys($value, $path);
        } else {
            $flat[$path] = true;
        }
    }

    return $flat;
}
