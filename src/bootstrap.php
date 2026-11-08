<?php

require_once __DIR__ . '/PDOException.php';
require_once __DIR__ . '/PDORow.php';
require_once __DIR__ . '/Flags.php';
require_once __DIR__ . '/SqlParser.php';
require_once __DIR__ . '/SqlLiteral.php';
require_once __DIR__ . '/RowFormatter.php';
require_once __DIR__ . '/OdbcClient.php';
require_once __DIR__ . '/FfiPreload.php';
require_once __DIR__ . '/DsnParts.php';
require_once __DIR__ . '/MysqlClient.php';
require_once __DIR__ . '/PgsqlClient.php';
require_once __DIR__ . '/PDO.php';
require_once __DIR__ . '/PDOStatement.php';

if (!class_exists('PDO', false)) {
    class_alias(\PhpPdo\PDO::class, 'PDO');
    class_alias(\PhpPdo\PDOStatement::class, 'PDOStatement');
    class_alias(\PhpPdo\PDOException::class, 'PDOException');
    class_alias(\PhpPdo\PDORow::class, 'PDORow');
}
