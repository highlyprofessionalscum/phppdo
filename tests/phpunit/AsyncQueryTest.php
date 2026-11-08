<?php

namespace PhpPdo\Tests;

use PhpPdo\MysqlLibrary;
use PhpPdo\PDO;
use PhpPdo\PDOException;
use PhpPdo\PgsqlLibrary;

/**
 * Асинхронная отправка SQL (mysql_send_query / PQsendQuery).
 */
class AsyncQueryTest extends PdoTestCase
{
    public function testOdbcDriverRejectsAsync()
    {
        $dsn = getenv('PDO_ODBC_DSN');
        if (!is_string($dsn) || $dsn === '') {
            $this->markTestSkipped('PDO_ODBC_DSN is empty');
        }
        $pdo = $this->pdo([PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->expectException(PDOException::class);
        $pdo->sendQueryAsync('SELECT 1');
    }

    public function testMysqlAsyncSelectSameAsQuery()
    {
        $pdo = $this->mysql();
        $sync = $pdo->query('SELECT 21 AS n');
        $this->assertEquals(21, $sync->fetchColumn());

        $this->assertTrue($pdo->sendQueryAsync('SELECT 21 AS n'));
        $this->assertTrue($pdo->readQueryResult());
        $async = $pdo->receiveQueryResult();
        $this->assertInstanceOf(\PhpPdo\PDOStatement::class, $async);
        $this->assertEquals(21, $async->fetchColumn());
    }

    public function testMysqlAsyncMultiStatement()
    {
        $pdo = $this->mysql();
        $this->assertTrue($pdo->sendQueryAsync('SELECT 1 AS a; SELECT 2 AS b'));
        $stmt = $pdo->receiveQueryResult();
        $this->assertEquals(1, $stmt->fetchColumn());
        $this->assertTrue($stmt->nextRowset());
        $this->assertEquals(2, $stmt->fetchColumn());
        $this->assertFalse($stmt->nextRowset());
    }

    public function testMysqlAsyncExecStyle()
    {
        $pdo = $this->mysql();
        $pdo->exec('DROP TABLE IF EXISTS pdo_mysql_async');
        $this->assertTrue($pdo->sendQueryAsync(
            'CREATE TABLE pdo_mysql_async (id INT PRIMARY KEY, v VARCHAR(8))'
        ));
        $stmt = $pdo->receiveQueryResult();
        $this->assertInstanceOf(\PhpPdo\PDOStatement::class, $stmt);

        $this->assertTrue($pdo->sendQueryAsync("INSERT INTO pdo_mysql_async (id, v) VALUES (1, 'x')"));
        $pdo->receiveQueryResult();

        $this->assertTrue($pdo->sendQueryAsync('SELECT v FROM pdo_mysql_async WHERE id = 1'));
        $row = $pdo->receiveQueryResult()->fetchColumn();
        $this->assertEquals('x', $row);
        $pdo->exec('DROP TABLE pdo_mysql_async');
    }

    public function testMysqlAsyncInvalidSql()
    {
        $pdo = $this->mysql([PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        $this->assertTrue($pdo->sendQueryAsync('SELECT 1'));
        $pdo->receiveQueryResult();
        $this->assertTrue($pdo->sendQueryAsync('SELECT FROM oops'));
        $this->assertFalse($pdo->receiveQueryResult());
        $this->assertNotSame('00000', $pdo->errorInfo()[0]);
    }

    public function testMysqlConnectionSocket()
    {
        if (!MysqlLibrary::available()) {
            $this->markTestSkipped('libmysqlclient is not available');
        }
        $pdo = $this->mysql();
        $socket = $pdo->connectionSocket();
        $this->assertNotNull($socket);
        $this->assertGreaterThan(0, $socket);
    }

    public function testPgsqlAsyncNonBlockingLoop()
    {
        $pdo = $this->pgsql();
        $this->assertTrue($pdo->setNonBlocking(true));
        $this->assertTrue($pdo->sendQueryAsync('SELECT 22 AS n'));
        $this->assertTrue($pdo->flushConnection());

        $stmt = false;
        for ($attempt = 0; $attempt < 500; $attempt++) {
            $this->assertTrue($pdo->consumeInput());
            $stmt = $pdo->receiveQueryResult();
            if ($stmt !== false) {
                break;
            }
            if ($pdo->isQueryBusy()) {
                usleep(1000);
            }
        }

        $this->assertInstanceOf(\PhpPdo\PDOStatement::class, $stmt);
        $this->assertEquals(22, $stmt->fetchColumn());
        $this->assertTrue($pdo->setNonBlocking(false));
        $this->assertFalse($pdo->isQueryBusy());
    }

    public function testPgsqlAsyncMultiStatement()
    {
        $pdo = $this->pgsql();
        $this->assertTrue($pdo->sendQueryAsync('SELECT 3 AS a; SELECT 4 AS b'));
        $stmt = $pdo->receiveQueryResult();
        $this->assertEquals(3, $stmt->fetchColumn());
        $this->assertTrue($stmt->nextRowset());
        $this->assertEquals(4, $stmt->fetchColumn());
    }

    public function testPgsqlReceiveWithoutPendingQuery()
    {
        $pdo = $this->pgsql([PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        $this->assertFalse($pdo->receiveQueryResult());
        $this->assertNotSame('00000', $pdo->errorInfo()[0]);
    }

    public function testPgsqlSecondReceiveAfterDrain()
    {
        $pdo = $this->pgsql([PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        $this->assertTrue($pdo->sendQueryAsync('SELECT 1'));
        $this->assertNotFalse($pdo->receiveQueryResult());
        $this->assertFalse($pdo->receiveQueryResult());
    }

    public function testPgsqlConnectionSocket()
    {
        if (!PgsqlLibrary::available()) {
            $this->markTestSkipped('libpq is not available');
        }
        $pdo = $this->pgsql();
        $socket = $pdo->connectionSocket();
        $this->assertNotNull($socket);
        $this->assertGreaterThan(0, $socket);
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
