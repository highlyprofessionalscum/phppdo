<?php

use PhpPdo\PDO;
use PhpPdo\PDOException;

return function () {
    $dsn = getenv('PDO_ODBC_DSN');
    if (!is_string($dsn) || $dsn === '') {
        echo "skip IntegrationTest (PDO_ODBC_DSN is empty)\n";
        return;
    }

    $pdo = new PDO($dsn, getenv('PDO_ODBC_USER') ?: '', getenv('PDO_ODBC_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);

    assert_same('odbc', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    assert_same(false, $pdo->inTransaction());
    assert_same("'O''Brien'", $pdo->quote("O'Brien"));

    $pdo->exec('DROP TABLE IF EXISTS phppdo_t');
    $pdo->exec('CREATE TABLE phppdo_t (id INTEGER, name VARCHAR(50))');

    $insert = $pdo->prepare('INSERT INTO phppdo_t (id, name) VALUES (:id, :name)');
    $insert->execute(['id' => 1, 'name' => "O'Brien"]);

    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO phppdo_t (id, name) VALUES (2, 'rolled')");
    assert_same(true, $pdo->inTransaction());
    $pdo->rollBack();
    assert_same(false, $pdo->inTransaction());

    $pdo->beginTransaction();
    $second = $pdo->prepare('INSERT INTO phppdo_t (id, name) VALUES (?, ?)');
    $second->execute([3, 'kept']);
    $pdo->commit();

    $select = $pdo->query('SELECT id, name FROM phppdo_t ORDER BY id', PDO::FETCH_ASSOC);
    $rows = $select->fetchAll();
    assert_same(2, count($rows));
    assert_same("O'Brien", $rows[0]['name']);
    assert_same('kept', $rows[1]['name']);

    $scroll = $pdo->prepare('SELECT id, name FROM phppdo_t ORDER BY id', [
        PDO::ATTR_CURSOR => PDO::CURSOR_SCROLL,
    ]);
    $scroll->execute();
    $last = $scroll->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_LAST);
    assert_same('kept', $last['name']);
    $first = $scroll->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_FIRST);
    assert_same("O'Brien", $first['name']);

    $failed = false;
    try {
        $pdo->query('SELECT * FROM phppdo_missing_zz');
    } catch (PDOException $exception) {
        $failed = true;
        assert_true($exception->getCode() !== '00000');
        assert_true(is_array($exception->errorInfo));
    }
    assert_true($failed, 'missing table must raise PDOException');

    $thrown = false;
    try {
        $pdo->lastInsertId();
    } catch (PDOException $exception) {
        $thrown = $exception->getCode() === 'IM001';
    }
    assert_true($thrown, 'lastInsertId is IM001');

    $pdo->exec('DROP TABLE IF EXISTS phppdo_t');
};
