<?php

use PhpPdo\PDO;
use PhpPdo\PDOException;

return function () {
    $cases = [
        '' => 'invalid data source name',
        'odbc:' => 'invalid data source name',
        'sqlsrv:host=localhost' => 'could not find driver',
    ];
    foreach ($cases as $dsn => $needle) {
        $caught = null;
        try {
            new PDO($dsn);
        } catch (PDOException $exception) {
            $caught = $exception;
        }
        assert_true($caught instanceof PDOException, 'DSN must fail: ' . $dsn);
        assert_true(strpos($caught->getMessage(), $needle) !== false, $caught->getMessage());
    }

    if (!extension_loaded('odbc')) {
        return;
    }

    $caught = null;
    try {
        new PDO('odbc:DSN=phppdo_missing_dsn_zz', '', '');
    } catch (PDOException $exception) {
        $caught = $exception;
    }
    assert_true($caught instanceof PDOException, 'unknown ODBC DSN must fail');
    assert_true($caught->getCode() !== '00000', 'SQLSTATE was ' . $caught->getCode());
};
