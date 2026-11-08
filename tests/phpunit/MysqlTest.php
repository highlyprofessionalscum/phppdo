<?php

namespace PhpPdo\Tests;

use PhpPdo\MysqlLibrary;
use PhpPdo\PDO;
use PhpPdo\PDOException;

/**
 * Сценарии из c:\OSPanel\home\php-src\ext\pdo_mysql\tests для драйвера FFI.
 * Нужны PDO_MYSQL_DSN, PDO_MYSQL_USER и PDO_MYSQL_PASSWORD.
 */
class MysqlTest extends PdoTestCase
{
    public function testPhp74MysqlAttributeConstants()
    {
        $this->assertSame(1000, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $this->assertSame(1001, PDO::MYSQL_ATTR_LOCAL_INFILE);
        $this->assertSame(1002, PDO::MYSQL_ATTR_INIT_COMMAND);
        $this->assertSame(1003, PDO::MYSQL_ATTR_COMPRESS);
        $this->assertSame(1004, PDO::MYSQL_ATTR_DIRECT_QUERY);
        $this->assertSame(1005, PDO::MYSQL_ATTR_FOUND_ROWS);
        $this->assertSame(1006, PDO::MYSQL_ATTR_IGNORE_SPACE);
        $this->assertSame(1007, PDO::MYSQL_ATTR_SSL_KEY);
        $this->assertSame(1013, PDO::MYSQL_ATTR_MULTI_STATEMENTS);
        if (MysqlLibrary::available()) {
            $this->assertContains('mysql', PDO::getAvailableDrivers());
        } else {
            $this->assertNotContains('mysql', PDO::getAvailableDrivers());
        }
    }

    public function testExecAffectedRows()
    {
        $pdo = $this->mysql();
        $pdo->exec('DROP TABLE IF EXISTS test_mysql_exec');
        $this->assertSame(0, $pdo->exec('CREATE TABLE test_mysql_exec(id INT NOT NULL PRIMARY KEY, col1 CHAR(10)) ENGINE=InnoDB'));
        $this->assertSame(1, $pdo->exec("INSERT INTO test_mysql_exec(id, col1) VALUES (1, 'a')"));
        $this->assertSame(2, $pdo->exec("INSERT INTO test_mysql_exec(id, col1) VALUES (2, 'b'), (3, 'c')"));
        $this->assertSame(1, $pdo->exec('UPDATE test_mysql_exec SET id = 4 WHERE id = 3'));
        $this->assertSame(2, $pdo->exec("INSERT INTO test_mysql_exec(id, col1) VALUES (1, 'd') ON DUPLICATE KEY UPDATE id = 3"));
        $this->assertSame(0, $pdo->exec('UPDATE test_mysql_exec SET id = 5 WHERE id = 5'));
        $this->assertSame(1, $pdo->exec("INSERT INTO test_mysql_exec(id, col1) VALUES (5, 'e') ON DUPLICATE KEY UPDATE id = 6"));
        $this->assertSame(2, $pdo->exec("REPLACE INTO test_mysql_exec(id, col1) VALUES (5, 'f')"));
        $this->assertSame(1, $pdo->exec("REPLACE INTO test_mysql_exec(id, col1) VALUES (6, 'g')"));
        $this->assertSame(4, $pdo->exec('DELETE FROM test_mysql_exec WHERE id > 2'));
        $this->assertSame(0, $pdo->exec('DROP TABLE test_mysql_exec'));

        try {
            $pdo->exec('THIS IS NOT VALID SQL, I HOPE');
            $this->fail('invalid SQL must fail');
        } catch (PDOException $exception) {
            $info = $pdo->errorInfo();
            $this->assertNotSame('00000', $info[0]);
            $this->assertIsInt($info[1]);
            $this->assertNotSame('', $info[2]);
            $this->assertStringContainsString((string) $info[0], $exception->getMessage());
        }
    }

    public function testLastInsertId()
    {
        $pdo = $this->mysql();
        $this->assertSame('0', $pdo->lastInsertId());
        $this->assertSame('0', $pdo->lastInsertId('sequence_name'));
        $pdo->exec('DROP TABLE IF EXISTS test_pdo_mysql_last_insert_id');
        $pdo->exec('CREATE TABLE test_pdo_mysql_last_insert_id(id INT, col1 CHAR(10)) ENGINE=InnoDB');
        $this->assertSame('0', $pdo->lastInsertId());
        $pdo->exec("INSERT INTO test_pdo_mysql_last_insert_id(id, col1) VALUES (100, 'a')");
        $this->assertSame('0', $pdo->lastInsertId());
        $pdo->exec('ALTER TABLE test_pdo_mysql_last_insert_id MODIFY id INT AUTO_INCREMENT PRIMARY KEY');
        $pdo->exec("INSERT INTO test_pdo_mysql_last_insert_id(id, col1) VALUES (101, 'b')");
        $this->assertSame('101', $pdo->lastInsertId());
        $pdo->exec('DROP TABLE test_pdo_mysql_last_insert_id');
    }

    public function testQuoteMatchesPdoMysql()
    {
        $pdo = $this->mysql();
        $this->assertSame("'foo'", $pdo->quote('foo', PDO::PARAM_STR));
        $this->assertSame("'foo'", $pdo->quote('foo', PDO::PARAM_STR | PDO::PARAM_STR_CHAR));
        $this->assertSame("N'über'", $pdo->quote('über', PDO::PARAM_STR | PDO::PARAM_STR_NATL));
        $this->assertTrue($pdo->getAttribute(PDO::ATTR_DEFAULT_STR_PARAM) === PDO::PARAM_STR_CHAR);
        $this->assertTrue($pdo->setAttribute(PDO::ATTR_DEFAULT_STR_PARAM, PDO::PARAM_STR_NATL));
        $this->assertSame("'foo'", $pdo->quote('foo', PDO::PARAM_STR | PDO::PARAM_STR_CHAR));
        $this->assertSame("N'über'", $pdo->quote('über', PDO::PARAM_STR));
        $this->assertSame("'a\\'b\\\\c'", $pdo->quote("a'b\\c", PDO::PARAM_STR | PDO::PARAM_STR_CHAR));
    }

    public function testCommitAndRollback()
    {
        $pdo = $this->mysql();
        $pdo->exec('DROP TABLE IF EXISTS pdo_mysql_commit');
        $pdo->exec('CREATE TABLE pdo_mysql_commit (id INT, label CHAR(10)) ENGINE=InnoDB');
        $this->assertFalse($pdo->inTransaction());
        $this->assertTrue($pdo->beginTransaction());
        $this->assertTrue($pdo->inTransaction());
        $pdo->exec("INSERT INTO pdo_mysql_commit (id, label) VALUES (1, 'a')");
        $this->assertTrue($pdo->rollBack());
        $this->assertFalse($pdo->inTransaction());
        $this->assertFalse($pdo->query('SELECT id FROM pdo_mysql_commit')->fetch());

        $this->assertTrue($pdo->beginTransaction());
        $pdo->exec("INSERT INTO pdo_mysql_commit (id, label) VALUES (2, 'b')");
        $this->assertTrue($pdo->commit());
        $this->assertSame(2, $pdo->query('SELECT id FROM pdo_mysql_commit')->fetchColumn());
        $pdo->exec('DROP TABLE pdo_mysql_commit');
    }

    public function testFetchBothAndTypes()
    {
        $pdo = $this->mysql();
        $both = $pdo->query('SELECT 1')->fetch(PDO::FETCH_BOTH);
        $this->assertSame(1, $both[0]);
        $num = $pdo->query('SELECT 1')->fetch(PDO::FETCH_NUM);
        $assoc = $pdo->query('SELECT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($num[0], $both[0]);
        $this->assertSame($assoc[array_key_first($assoc)], $both[0]);

        $pdo->exec('DROP TABLE IF EXISTS pdo_mysql_types');
        $pdo->exec('CREATE TABLE pdo_mysql_types (i INT, s VARCHAR(8), n INT) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO pdo_mysql_types VALUES (7, 'php', NULL)");
        $row = $pdo->query('SELECT i, s, n FROM pdo_mysql_types')->fetch(PDO::FETCH_NUM);
        $this->assertSame(7, $row[0]);
        $this->assertSame('php', $row[1]);
        $this->assertNull($row[2]);
        $pdo->exec('DROP TABLE pdo_mysql_types');
    }

    public function testEmulatedAndNativePlaceholders()
    {
        $pdo = $this->mysql();
        $pdo->exec('DROP TABLE IF EXISTS pdo_mysql_prepare');
        $pdo->exec('CREATE TABLE pdo_mysql_prepare (id INT, name VARCHAR(16)) ENGINE=InnoDB');
        $insert = $pdo->prepare('INSERT INTO pdo_mysql_prepare (id, name) VALUES (:id, :name)');
        $this->assertTrue($insert->execute(['id' => 1, 'name' => 'php']));
        $this->assertTrue($insert->execute(['id' => 1, 'name' => 'again']));
        $select = $pdo->prepare('SELECT name FROM pdo_mysql_prepare WHERE id = :id AND name = :name');
        $select->execute(['id' => 1, 'name' => 'php']);
        $this->assertSame('php', $select->fetchColumn());

        $native = $this->mysql([PDO::ATTR_EMULATE_PREPARES => false]);
        $statement = $native->prepare('SELECT name FROM pdo_mysql_prepare WHERE id = ?');
        $this->assertNotFalse($statement);
        $statement->execute([1]);
        $this->assertSame('php', $statement->fetchColumn());
        $pdo->exec('DROP TABLE pdo_mysql_prepare');
    }

    public function testServerAttributesAndBufferedQuery()
    {
        $pdo = $this->mysql();
        $this->assertSame('mysql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $this->assertIsString($pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
        $this->assertNotSame('', $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
        $this->assertIsString($pdo->getAttribute(PDO::ATTR_CLIENT_VERSION));
        $this->assertIsString($pdo->getAttribute(PDO::ATTR_SERVER_INFO));
        $this->assertTrue($pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY));
        $this->assertTrue($pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false));
        $this->assertFalse($pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY));
        $this->assertTrue($pdo->getAttribute(PDO::MYSQL_ATTR_DIRECT_QUERY));
    }

    public function testMultiStatementNextRowset()
    {
        $pdo = $this->mysql();
        $statement = $pdo->query('SELECT 1 AS a; SELECT 2 AS b');
        $this->assertSame(1, $statement->fetchColumn());
        $this->assertTrue($statement->nextRowset());
        $this->assertSame(2, $statement->fetchColumn());
        $this->assertFalse($statement->nextRowset());
    }

    public function testAsyncQuery()
    {
        $pdo = $this->mysql();
        $this->assertTrue($pdo->sendQueryAsync('SELECT 12 AS n'));
        $stmt = $pdo->receiveQueryResult();
        $this->assertInstanceOf(\PhpPdo\PDOStatement::class, $stmt);
        $this->assertEquals(12, $stmt->fetchColumn());
        $socket = $pdo->connectionSocket();
        if ($socket !== null) {
            $this->assertGreaterThan(0, $socket);
        }
    }

    public function testErrorInfoClearsAfterSuccess()
    {
        $pdo = $this->mysql([PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        $this->assertFalse($pdo->query('SELECT * FROM pdo_mysql_missing_table'));
        $info = $pdo->errorInfo();
        $this->assertNotSame('00000', $info[0]);
        $this->assertIsInt($info[1]);
        $this->assertNotSame('', $info[2]);
        $this->assertNotFalse($pdo->query('SELECT 1'));
        $this->assertSame(['00000', null, null], $pdo->errorInfo());
    }

    public function testInitCommandFromConstructor()
    {
        $pdo = $this->mysql(array(
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET @phppdo_init = 42',
        ));
        $this->assertSame('42', $pdo->query('SELECT @phppdo_init')->fetchColumn());
    }

    public function testUnbufferedQuery()
    {
        $pdo = $this->mysql(array(
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ));
        $this->assertFalse($pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY));
        $pdo->exec('DROP TABLE IF EXISTS test_mysql_unbuffered');
        $pdo->exec('CREATE TABLE test_mysql_unbuffered(id INT PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO test_mysql_unbuffered(id) VALUES (1), (2), (3)');
        $statement = $pdo->query('SELECT id FROM test_mysql_unbuffered ORDER BY id');
        $this->assertSame(0, $statement->rowCount());
        $this->assertSame(1, $statement->fetchColumn());
        $this->assertSame(2, $statement->fetchColumn());
        $this->assertSame(3, $statement->fetchColumn());
        $this->assertFalse($statement->fetch());
        $pdo->exec('DROP TABLE test_mysql_unbuffered');
    }

    public function testUnbufferedNativePrepare()
    {
        $pdo = $this->mysql(array(
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
        $pdo->exec('DROP TABLE IF EXISTS test_mysql_unbuf_native');
        $pdo->exec('CREATE TABLE test_mysql_unbuf_native(id INT PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO test_mysql_unbuf_native(id) VALUES (1), (2), (3)');
        $statement = $pdo->prepare('SELECT id FROM test_mysql_unbuf_native ORDER BY id');
        $statement->execute();
        $this->assertSame(0, $statement->rowCount());
        $this->assertSame(1, $statement->fetchColumn());
        $this->assertSame(2, $statement->fetchColumn());
        $this->assertSame(3, $statement->fetchColumn());
        $pdo->exec('DROP TABLE test_mysql_unbuf_native');
    }

    public function testSslAttributesStoredInConstructor()
    {
        $pdo = $this->mysql(array(
            PDO::MYSQL_ATTR_SSL_CA => '/no/such/ca.pem',
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        ));
        $this->assertSame('/no/such/ca.pem', $pdo->getAttribute(PDO::MYSQL_ATTR_SSL_CA));
        $this->assertFalse($pdo->getAttribute(PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT));
    }

    /**
     * @param array<int,mixed> $options
     * @return PDO
     */
    private function mysql(array $options = [])
    {
        $dsn = getenv('PDO_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            $this->markTestSkipped('PDO_MYSQL_DSN is empty');
        }
        $defaults = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => true,
        ];
        return new PDO(
            $dsn,
            getenv('PDO_MYSQL_USER') ?: '',
            getenv('PDO_MYSQL_PASSWORD') ?: '',
            $options + $defaults
        );
    }
}
