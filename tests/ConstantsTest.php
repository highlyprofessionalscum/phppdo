<?php

use PhpPdo\Flags;
use PhpPdo\PDO;

return function () {
    assert_same(65536, PDO::FETCH_GROUP, 'FETCH_GROUP PHP 7.4');
    assert_same(196608, PDO::FETCH_UNIQUE, 'FETCH_UNIQUE PHP 7.4');
    assert_same(1, PDO::CASE_UPPER);
    assert_same(2, PDO::CASE_LOWER);
    assert_same(20, PDO::ATTR_EMULATE_PREPARES);
    assert_same(19, PDO::ATTR_DEFAULT_FETCH_MODE);
    assert_same('00000', PDO::ERR_NONE);
    assert_true(in_array('odbc', PDO::getAvailableDrivers(), true), 'odbc driver is advertised');
    assert_same(PDO::PARAM_STR, Flags::paramBase(PDO::PARAM_INPUT_OUTPUT | PDO::PARAM_STR));
    assert_same(PDO::PARAM_INT, Flags::paramBase(PDO::PARAM_STR_NATL | PDO::PARAM_INT));

    if (!class_exists('PDO', false) || (new ReflectionClass('PDO'))->getName() === PDO::class) {
        return;
    }

    $native = new ReflectionClass('PDO');
    $ours = new ReflectionClass(PDO::class);
    foreach ($ours->getReflectionConstants() as $constant) {
        $name = $constant->getName();
        if (!$native->hasConstant($name)) {
            continue;
        }
        assert_same($native->getConstant($name), $constant->getValue(), 'PDO::' . $name);
    }
};
