<?php

require __DIR__ . '/bootstrap.php';

$failed = 0;
$files = glob(__DIR__ . '/*Test.php');
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    try {
        $test = require $file;
        if (is_callable($test)) {
            $test();
        }
        echo 'ok ' . $name . PHP_EOL;
    } catch (Throwable $exception) {
        $failed++;
        echo 'FAIL ' . $name . ': ' . $exception->getMessage() . PHP_EOL;
        echo $exception->getFile() . ':' . $exception->getLine() . PHP_EOL;
    }
}

if ($failed > 0) {
    echo $failed . " failed\n";
    exit(1);
}

echo "all tests passed\n";
exit(0);
