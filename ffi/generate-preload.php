<?php

/**
 * Строит ffi/generated/*.h с #define FFI_LIB для php.ini (ffi.preload).
 *
 *   php ffi/generate-preload.php
 *
 * stdout: пути к сгенерированным заголовкам через PATH_SEPARATOR (для ffi.preload).
 */

require_once dirname(__DIR__) . '/src/FfiPreload.php';
require_once dirname(__DIR__) . '/src/MysqlClient.php';
require_once dirname(__DIR__) . '/src/PgsqlClient.php';

use PhpPdo\FfiPreload;
use PhpPdo\MysqlLibrary;
use PhpPdo\PgsqlLibrary;

if (!extension_loaded('ffi')) {
    fwrite(STDERR, "ext-ffi is not loaded\n");
    exit(1);
}

$outDir = __DIR__ . DIRECTORY_SEPARATOR . 'generated';
$written = array();

$jobs = array(
    array(MysqlLibrary::class, 'ffi/mysql.h', $outDir . DIRECTORY_SEPARATOR . 'mysql.h', 'mysql'),
    array(PgsqlLibrary::class, 'ffi/pgsql.h', $outDir . DIRECTORY_SEPARATOR . 'pgsql.h', 'pgsql'),
);

foreach ($jobs as $job) {
    $class = $job[0];
    /** @var string|null $library */
    $library = call_user_func(array($class, 'probeLibrary'));
    if ($library === null) {
        fwrite(STDERR, 'Skip ' . $job[3] . ': client library was not found' . PHP_EOL);
        continue;
    }
    FfiPreload::writePreloadHeader($job[1], $job[2], $library);
    $written[] = $job[2];
    fwrite(STDERR, 'Wrote ' . $job[3] . ' preload header for ' . $library . PHP_EOL);
}

if ($written === array()) {
    fwrite(STDERR, "No preload headers were generated\n");
    exit(1);
}

fwrite(STDOUT, implode(PATH_SEPARATOR, $written) . PHP_EOL);
exit(0);
