<?php

namespace PhpPdo\Tests;

use PhpPdo\PDO;
use PhpPdo\PgsqlLibrary;
use PhpPdo\PDOException;

/**
 * Сценарии из ext/pdo_pgsql для драйвера FFI.
 * Нужны PDO_PGSQL_DSN, PDO_PGSQL_USER и PDO_PGSQL_PASSWORD.
 */
class PgsqlTest extends PdoTestCase
{
    public function testPhp74PgsqlConstants()
    {
        $this->assertSame(1000, PDO::PGSQL_ATTR_DISABLE_PREPARES);
        $this->assertSame(0, PDO::PGSQL_TRANSACTION_IDLE);
        $this->assertSame(1, PDO::PGSQL_TRANSACTION_ACTIVE);
        $this->assertSame(2, PDO::PGSQL_TRANSACTION_INTRANS);
        $this->assertSame(3, PDO::PGSQL_TRANSACTION_INERROR);
        $this->assertSame(4, PDO::PGSQL_TRANSACTION_UNKNOWN);
        if (PgsqlLibrary::available()) {
            $this->assertContains('pgsql', PDO::getAvailableDrivers());
        } else {
            $this->assertNotContains('pgsql', PDO::getAvailableDrivers());
        }
    }

    public function testExecQuoteAndTypes()
    {
        $pdo = $this->pgsql();
        $this->assertSame("'a''b'", $pdo->quote("a'b"));
        $pdo->exec('DROP TABLE IF EXISTS pdo_pgsql_probe');
        $this->assertSame(0, $pdo->exec('CREATE TABLE pdo_pgsql_probe (id SERIAL PRIMARY KEY, name VARCHAR(16), flag BOOLEAN)'));
        $this->assertSame(1, $pdo->exec("INSERT INTO pdo_pgsql_probe (name, flag) VALUES ('php', TRUE)"));
        $this->assertSame('1', $pdo->lastInsertId());
        $this->assertSame(1, $pdo->exec("UPDATE pdo_pgsql_probe SET name = 'pdo' WHERE id = 1"));
        $row = $pdo->query('SELECT id, name, flag, 7::int2 AS small FROM pdo_pgsql_probe')->fetch(PDO::FETCH_NUM);
        $this->assertSame(1, $row[0]);
        $this->assertSame('pdo', $row[1]);
        $this->assertTrue($row[2]);
        $this->assertSame(7, $row[3]);
        $this->assertSame(1, $pdo->exec('DELETE FROM pdo_pgsql_probe'));
        $pdo->exec('DROP TABLE pdo_pgsql_probe');
    }

    public function testNativeAndEmulatedPlaceholders()
    {
        $pdo = $this->pgsql();
        $pdo->exec('DROP TABLE IF EXISTS pdo_pgsql_bind');
        $pdo->exec('CREATE TABLE pdo_pgsql_bind (id INT, name VARCHAR(16))');
        $insert = $pdo->prepare('INSERT INTO pdo_pgsql_bind (id, name) VALUES (?, ?)');
        $this->assertTrue($insert->execute(array(1, 'php')));
        $select = $pdo->prepare('SELECT name FROM pdo_pgsql_bind WHERE id = ?');
        $select->execute(array(1));
        $this->assertSame('php', $select->fetchColumn());

        $emulated = $this->pgsql(array(PDO::ATTR_EMULATE_PREPARES => true));
        $named = $emulated->prepare('SELECT name FROM pdo_pgsql_bind WHERE id = :id AND name = :name');
        $named->execute(array('id' => 1, 'name' => 'php'));
        $this->assertSame('php', $named->fetchColumn());
        $pdo->exec('DROP TABLE pdo_pgsql_bind');
    }

    public function testCommitAndRollback()
    {
        $pdo = $this->pgsql();
        $pdo->exec('DROP TABLE IF EXISTS pdo_pgsql_tx');
        $pdo->exec('CREATE TABLE pdo_pgsql_tx (id INT)');
        $this->assertFalse($pdo->inTransaction());
        $this->assertTrue($pdo->beginTransaction());
        $this->assertTrue($pdo->inTransaction());
        $pdo->exec('INSERT INTO pdo_pgsql_tx (id) VALUES (1)');
        $this->assertTrue($pdo->rollBack());
        $this->assertFalse($pdo->query('SELECT id FROM pdo_pgsql_tx')->fetch());
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO pdo_pgsql_tx (id) VALUES (2)');
        $this->assertTrue($pdo->commit());
        $this->assertSame(2, $pdo->query('SELECT id FROM pdo_pgsql_tx')->fetchColumn());
        $pdo->exec('DROP TABLE pdo_pgsql_tx');
    }

    public function testServerAttributesAndErrors()
    {
        $pdo = $this->pgsql();
        $this->assertSame('pgsql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $this->assertNotSame('', $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
        $this->assertNotSame('', $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION));
        $this->assertStringContainsString('PID:', $pdo->getAttribute(PDO::ATTR_SERVER_INFO));
        $this->assertSame('Connection OK; waiting to send.', $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS));
        $this->assertFalse($pdo->getAttribute(PDO::PGSQL_ATTR_DISABLE_PREPARES));
        $this->assertTrue($pdo->setAttribute(PDO::PGSQL_ATTR_DISABLE_PREPARES, true));
        $this->assertTrue($pdo->getAttribute(PDO::PGSQL_ATTR_DISABLE_PREPARES));

        $silent = $this->pgsql(array(PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT));
        $this->assertFalse($silent->query('SELECT * FROM pdo_pgsql_missing'));
        $info = $silent->errorInfo();
        $this->assertSame('42P01', $info[0]);
        $this->assertIsInt($info[1]);
        $this->assertStringContainsString('pdo_pgsql_missing', $info[2]);
        $this->assertNotFalse($silent->query('SELECT 1'));
        $this->assertSame(array('00000', null, null), $silent->errorInfo());
    }

    public function testMultiStatementNextRowset()
    {
        $pdo = $this->pgsql();
        $statement = $pdo->query('SELECT 1 AS a; SELECT 2 AS b');
        $this->assertSame(1, $statement->fetchColumn());
        $this->assertTrue($statement->nextRowset());
        $this->assertSame(2, $statement->fetchColumn());
        $this->assertFalse($statement->nextRowset());
    }

    public function testConnectionFailureKeepsErrorInfo()
    {
        if (!PgsqlLibrary::available()) {
            $this->markTestSkipped('libpq is not available');
        }
        try {
            new PDO('pgsql:host=127.0.0.1;port=1;dbname=none', 'nobody', 'wrong');
            $this->fail('connection must fail');
        } catch (PDOException $exception) {
            $this->assertIsArray($exception->errorInfo);
            $this->assertNotSame('', $exception->errorInfo[0]);
        }
    }

    /**
     * @param array<int,mixed> $options
     * @return PDO
     */
    private function pgsql(array $options = array())
    {
        $dsn = getenv('PDO_PGSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            $this->markTestSkipped('PDO_PGSQL_DSN is empty');
        }
        $defaults = array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        );
        return new PDO(
            $dsn,
            getenv('PDO_PGSQL_USER') ?: '',
            getenv('PDO_PGSQL_PASSWORD') ?: '',
            $options + $defaults
        );
    }
}
