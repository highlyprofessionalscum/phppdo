<?php

namespace PhpPdo\Tests;

use PhpPdo\PDO;
use PHPUnit\Framework\TestCase;

abstract class PdoTestCase extends TestCase
{
    /**
     * Соединение в духе PDOTest::factory(): строки вместо чисел, имена в нижнем регистре.
     *
     * @param array<int,mixed> $options
     * @param string $class
     * @return PDO
     */
    protected function pdo(array $options = [], $class = PDO::class)
    {
        $dsn = getenv('PDO_ODBC_DSN');
        if (!is_string($dsn) || $dsn === '') {
            $this->markTestSkipped('PDO_ODBC_DSN is empty');
        }

        $defaults = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_STRINGIFY_FETCHES => true,
            PDO::ATTR_CASE => PDO::CASE_LOWER,
        ];

        return new $class(
            $dsn,
            getenv('PDO_ODBC_USER') ?: '',
            getenv('PDO_ODBC_PASSWORD') ?: '',
            $options + $defaults
        );
    }

    /**
     * @param PDO $pdo
     */
    protected function table(PDO $pdo, $name, $columns)
    {
        $pdo->exec('DROP TABLE IF EXISTS ' . $name);
        $pdo->exec('CREATE TABLE ' . $name . ' (' . $columns . ')');
    }
}
