<?php

declare(strict_types=1);

$testsDir = getenv('WP_TESTS_DIR');
if (! $testsDir) {
    $testsDir = '/tmp/wordpress-tests-lib';
}

if (file_exists($testsDir . '/includes/functions.php')) {
    require_once $testsDir . '/includes/functions.php';
}

if (file_exists($testsDir . '/includes/bootstrap.php')) {
    require_once $testsDir . '/includes/bootstrap.php';
}
