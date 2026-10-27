<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;

require __DIR__ . '/../vendor/autoload.php';

// Integration kernels keep their cache (rate limiter pools, SQLite database) under this directory: start every
// run from a clean state so repeated local runs do not hit the reset/magic-login throttles of the previous one.
(new Filesystem())->remove(sys_get_temp_dir() . '/nowo-auth-kit-tests');
