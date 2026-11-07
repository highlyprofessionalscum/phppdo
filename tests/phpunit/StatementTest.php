<?php

namespace PhpPdo\Tests;

use PhpPdo\PDO;
use PhpPdo\PDOException;
use PhpPdo\PDORow;
use PhpPdo\PDOStatement;

class StatementAggregate extends PDOStatement implements \IteratorAggregate
{
    private function __construct()
    {
        $this->setFetchMode(PDO::FETCH_NUM);
        $this->constructed = true;
    }

    public function getIterator(): \Iterator
    {
        $this->executedByAggregate = true;
        $this->execute();
        return new \IteratorIterator($this, PDOStatement::class);
    }
}

/**
 * Выражение, транзакции, атрибуты и ошибки из ext/pdo/tests.
 */
class StatementTest extends PdoTestCase
{
    public function testTransactionsRollbackAndCommit()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test017', 'id INT NOT NULL PRIMARY KEY, val VARCHAR(10)');
        $pdo->exec("INSERT INTO test017 VALUES(1, 'A')");
        $pdo->exec("INSERT INTO test017 VALUES(2, 'B')");
        $pdo->exec("INSERT INTO test017 VALUES(3, 'C')");
        $delete = $pdo->prepare('DELETE FROM test017');

        $this->assertSame('3', $this->countRows($pdo, 'test017'));
        $pdo->beginTransaction();
        $delete->execute();
        $this->assertSame('0', $this->countRows($pdo, 'test017'));
        $pdo->rollBack();
        $this->assertSame('3', $this->countRows($pdo, 'test017'));

        $pdo->beginTransaction();
        $delete->execute();
        $pdo->commit();
        $this->assertSame('0', $this->countRows($pdo, 'test017'));
        $this->assertFalse($pdo->inTransaction());
    }

    public function testStatementClassIteratorAggregate()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test014', 'id int NOT NULL PRIMARY KEY, val VARCHAR(10), grp VARCHAR(10)');
        $pdo->exec("INSERT INTO test014 VALUES(1, 'A', 'Group1')");
        $pdo->exec("INSERT INTO test014 VALUES(2, 'B', 'Group2')");

        $stmt = $pdo->prepare('SELECT val, grp FROM test014 ORDER BY id', [
            PDO::ATTR_STATEMENT_CLASS => [StatementAggregate::class, []],
        ]);
        $this->assertInstanceOf(StatementAggregate::class, $stmt);
        $rows = [];
        foreach ($stmt as $row) {
            $rows[] = $row;
        }
        $this->assertTrue($stmt->constructed);
        $this->assertTrue($stmt->executedByAggregate);
        $this->assertSame([['A', 'Group1'], ['B', 'Group2']], $rows);

        $plain = $pdo->query('SELECT val, grp FROM test014 ORDER BY id', PDO::FETCH_NUM);
        $iterator = new \IteratorIterator($plain);
        $iterated = [];
        foreach ($iterator as $row) {
            $iterated[] = $row;
        }
        $this->assertSame([['A', 'Group1'], ['B', 'Group2']], $iterated);
        $iterator->next();
        $this->assertNull($iterator->current());
        $this->assertFalse($iterator->valid());
    }

    public function testIteratorCannotBeRewound()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test013', 'id int NOT NULL PRIMARY KEY, val VARCHAR(10)');
        $pdo->exec("INSERT INTO test013 VALUES(1, 'A')");
        $stmt = $pdo->query('SELECT val FROM test013');
        $seen = [];
        foreach ($stmt as $row) {
            $seen[] = $row['val'];
        }
        $this->assertSame(['A'], $seen);
        $this->expectException(PDOException::class);
        foreach ($stmt as $again) {
        }
    }

    public function testErrorCodeIsClearedByAttributeAndExecute()
    {
        $dsn = getenv('PDO_ODBC_DSN');
        if (!is_string($dsn) || $dsn === '') {
            $this->markTestSkipped('PDO_ODBC_DSN is empty');
        }
        $bare = new PDO($dsn, getenv('PDO_ODBC_USER') ?: '', getenv('PDO_ODBC_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
        ]);
        $this->assertNull($bare->errorCode());
        $this->assertSame('odbc', $bare->getAttribute(PDO::ATTR_DRIVER_NAME));
        $this->assertSame('00000', $bare->errorCode());

        $pdo = $this->pdo();
        $stmt = $pdo->prepare('SELECT 1');
        $this->assertSame('00000', $pdo->errorCode());
        $this->assertNull($stmt->errorCode());
        $stmt->execute();
        $this->assertSame('00000', $stmt->errorCode());
        $this->assertSame(['00000', null, null], $stmt->errorInfo());
    }

    public function testQueryFailureKeepsSqlstate()
    {
        $pdo = $this->pdo([PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        $result = $pdo->query('SELECT * FROM phppdo_missing_table_zz');
        $this->assertFalse($result);
        $this->assertNotSame('00000', $pdo->errorCode());
        $this->assertNotSame('', $pdo->errorCode());
        $this->assertSame($pdo->errorCode(), $pdo->errorInfo()[0]);
    }

    public function testFailedExecuteThrowsAndCloseCursorFetchIsFalse()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test39656', 'id INTEGER NOT NULL PRIMARY KEY, usr VARCHAR(256) NOT NULL');
        $pdo->exec("INSERT INTO test39656 (id, usr) VALUES (1, 'user')");
        $stmt = $pdo->prepare('SELECT * FROM test39656 WHERE id = ?');
        $stmt->bindValue(1, 1, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        $this->assertSame('user', $row['usr']);
        $this->assertSame('user', $row[1]);
        $stmt->closeCursor();
        $this->assertFalse($stmt->fetch());

        try {
            $pdo->prepare('SELECT * FROM phppdo_missing_table_zz')->execute();
            $this->fail('missing table must throw');
        } catch (PDOException $exception) {
            $this->assertNotSame('00000', $exception->getCode());
        }
    }

    public function testLastInsertIdAndSerializationAreRejected()
    {
        $pdo = $this->pdo();
        try {
            $pdo->lastInsertId();
            $this->fail('lastInsertId must be IM001');
        } catch (PDOException $exception) {
            $this->assertSame('IM001', $exception->getCode());
        }

        $this->expectException(\Exception::class);
        serialize($pdo);
    }

    public function testPdoRowCannotBeConstructed()
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('You may not create a PDORow manually');
        new PDORow();
    }

    public function testScrollCursor()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test_scroll', 'id int NOT NULL PRIMARY KEY, name VARCHAR(20)');
        $pdo->exec("INSERT INTO test_scroll VALUES(1, 'first')");
        $pdo->exec("INSERT INTO test_scroll VALUES(2, 'last')");
        $stmt = $pdo->prepare('SELECT name FROM test_scroll ORDER BY id', [
            PDO::ATTR_CURSOR => PDO::CURSOR_SCROLL,
        ]);
        $stmt->execute();
        $this->assertSame('last', $stmt->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_LAST)['name']);
        $this->assertSame('first', $stmt->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_FIRST)['name']);
    }

    public function testBooleanBoundAsIntAndColumnMeta()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test39398', 'test INT');
        $boolean = 1;
        $stmt = $pdo->prepare('INSERT INTO test39398 VALUES (:boolean)');
        $stmt->bindValue(':boolean', isset($boolean), PDO::PARAM_INT);
        $stmt->execute();
        $this->assertSame([['test' => '1']], $pdo->query('SELECT * FROM test39398')->fetchAll(PDO::FETCH_ASSOC));

        $meta = $pdo->query('SELECT test FROM test39398')->getColumnMeta(0);
        $this->assertSame('test', strtolower($meta['name']));
        $this->assertArrayHasKey('pdo_type', $meta);
    }

    public function testGetColumnMetaRejectsAnUnknownIndex()
    {
        $pdo = $this->pdo();
        $stmt = $pdo->query('SELECT 1 AS one');
        $this->assertFalse($stmt->getColumnMeta(3));
    }

    public function testBug40285PrepareOfQuotedColonFinishes()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test40285', 'field1 VARCHAR(32), field2 VARCHAR(32), field3 VARCHAR(32), field4 INT');
        $stmt = $pdo->prepare("INSERT INTO test40285 VALUES( ':id', 'name', 'section', 22)");
        $this->assertTrue($stmt->execute());
        $this->assertSame(':id', $pdo->query('SELECT field1 FROM test40285')->fetchColumn());
    }

    public function testBug71447CommentsRoundTripThroughSqlite()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test71447', 'id int');
        $pdo->exec('INSERT INTO test71447 VALUES(1)');
        $stmt = $pdo->prepare("SELECT -- That's all folks!\n '\"abc\":8000' FROM test71447");
        $stmt->execute();
        $this->assertSame('"abc":8000', $stmt->fetchColumn());

        $parsed = \PhpPdo\SqlParser::parse("SELECT -- What about question marks here?\n * FROM test71447 WHERE id = ?");
        $this->assertCount(1, $parsed['placeholders']);
        $this->assertSame('positional', $parsed['placeholders'][0]['kind']);
    }

    /**
     * @param PDO $pdo
     */
    private function countRows(PDO $pdo, $table)
    {
        return $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
