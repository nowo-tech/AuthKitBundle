<?php

declare(strict_types=1);

use Doctrine\Deprecations\Deprecation;
use Symfony\Component\Filesystem\Filesystem;

require __DIR__ . '/../vendor/autoload.php';

// Integration kernels keep their cache (rate limiter pools, SQLite database) under this directory: start every
// run from a clean state so repeated local runs do not hit the reset/magic-login throttles of the previous one.
(new Filesystem())->remove(sys_get_temp_dir() . '/nowo-auth-kit-tests');

// doctrine/orm 3.8 deprecates building a schema without the DBAL schema editor API (doctrine/orm#12780). Only the test
// harness builds schemas (SchemaTool in about 40 test kernels and fixtures; src/ never does), and the editor API
// needs doctrine/dbal >= 4.5, which the supported dependency range does not guarantee, so this one deprecation is
// ignored here. Remove once the test kernels opt in (`use_schema_editor_api`) on every supported DBAL.
if (class_exists(Deprecation::class)) {
    Deprecation::ignoreDeprecations('https://github.com/doctrine/orm/pull/12780');
}
