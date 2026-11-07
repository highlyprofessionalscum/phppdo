<?php

namespace PhpPdo\Tests;

use PhpPdo\PDO;
use PhpPdo\PDOException;
use PhpPdo\PDORow;
use PhpPdo\PDOStatement;

/**
 * Сценарии из c:\OSPanel\home\php-src\ext\pdo\tests, которые применимы к PHP 7.4 и ODBC/SQLite.
 * Тесты только для PHP 8 (ValueError, именованные аргументы, GC, sensitive parameter) не переносились.
 */
class LocalPdoTest extends PdoTestCase
{
    public function testBug35671NamedParameters()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test35671', 'field1 VARCHAR(32), field2 VARCHAR(32), field3 VARCHAR(32)');
        $insert = $pdo->prepare('INSERT INTO test35671 (field1, field2, field3) VALUES (:value1, :value2, :value3)');
        $insert->execute([':value1' => 15, ':value2' => 20, ':value3' => 25]);
        $this->assertSame(
            [['field1' => '15', 'field2' => '20', 'field3' => '25']],
            $pdo->query('SELECT * FROM test35671')->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function testBug36428FetchClassPropsLate()
    {
        if (!class_exists('SimpleXMLElement', false) && !class_exists('SimpleXMLElement')) {
            $this->markTestSkipped('simplexml is not loaded');
        }
        $pdo = $this->pdo();
        $this->table($pdo, 'test36428', 'a VARCHAR(10)');
        $pdo->exec("INSERT INTO test36428 (a) VALUES ('xyz')");
        $rows = $pdo->query('SELECT a FROM test36428')->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, 'SimpleXMLElement', ['<root/>']);
        $this->assertCount(1, $rows);
        $this->assertInstanceOf('SimpleXMLElement', $rows[0]);
        $this->assertSame('xyz', (string) $rows[0]->a);
    }

    public function testBug38253DefaultFetchModeWithoutPayload()
    {
        $pdo = $this->pdo([PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING]);
        $this->table($pdo, 'test38253', 'id integer primary key, n varchar(255)');
        $pdo->exec("INSERT INTO test38253 (id, n) VALUES (1, 'hi')");

        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_CLASS);
        $stmt = $pdo->prepare('SELECT * FROM test38253');
        $stmt->execute();
        list($rows, $warnings) = $this->warningsOf(function () use ($stmt) {
            return $stmt->fetchAll();
        });
        $this->assertSame([], $rows);
        $this->assertContainsMessage($warnings, 'No fetch class specified');

        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_FUNC);
        $stmt = $pdo->prepare('SELECT * FROM test38253');
        $stmt->execute();
        list($rows, $warnings) = $this->warningsOf(function () use ($stmt) {
            return $stmt->fetchAll();
        });
        $this->assertSame([], $rows);
        $this->assertContainsMessage($warnings, 'No fetch function specified');

        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_INTO);
        $stmt = $pdo->prepare('SELECT * FROM test38253');
        $stmt->execute();
        list($row, $warnings) = $this->warningsOf(function () use ($stmt) {
            return $stmt->fetch();
        });
        $this->assertFalse($row);
        $this->assertContainsMessage($warnings, 'No fetch-into object specified');
    }

    public function testBug38394ExtraParameterDoesNotBlockTheNextExecute()
    {
        $pdo = $this->pdo([PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING]);
        $this->table($pdo, 'test38394', 'a INT, b INT, c INT');
        $statement = $pdo->prepare('INSERT INTO test38394 (a, b, c) VALUES (:a, :b, :c)');
        $this->assertTrue($statement->execute(['a' => 1, 'b' => 2, 'c' => 3]));
        list($failed, $warnings) = $this->warningsOf(function () use ($statement) {
            return $statement->execute(['a' => 5, 'b' => 6, 'c' => 7, 'd' => 8]);
        });
        $this->assertFalse($failed);
        $this->assertNotEmpty($warnings);
        $this->assertTrue($statement->execute(['a' => 9, 'b' => 10, 'c' => 11]));
        $rows = $pdo->query('SELECT * FROM test38394')->fetchAll(PDO::FETCH_NUM);
        $this->assertSame([['1', '2', '3'], ['9', '10', '11']], $rows);
    }

    public function testBug42917KeyPairSetFetchMode()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test42917', 'a varchar(100), b varchar(100), c varchar(100)');
        for ($i = 0; $i < 5; $i++) {
            $pdo->exec("INSERT INTO test42917 (a, b, c) VALUES('test" . $i . "', '" . $i . "', '" . $i . "')");
        }
        $statement = $pdo->query('SELECT a, b FROM test42917');
        $statement->setFetchMode(PDO::FETCH_KEY_PAIR);
        $this->assertSame(
            ['test0' => '0', 'test1' => '1', 'test2' => '2', 'test3' => '3', 'test4' => '4'],
            $statement->fetchAll()
        );
    }

    public function testBug43139GroupUsesDefaultFetchMode()
    {
        $pdo = $this->pdo([
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING,
        ]);
        $grouped = $pdo->query('SELECT 0 AS abc, 1 AS xyz, 2 AS def')->fetchAll(PDO::FETCH_GROUP);
        $this->assertSame([['xyz' => '1', 'def' => '2']], $grouped[0]);
    }

    public function testBug43663CallOnPdoSubclass()
    {
        $pdo = $this->pdo([], LocalCallPdo::class);
        ob_start();
        $pdo->foo();
        $pdo->bar();
        $this->assertSame("Called foo in PhpPdo\\Tests\\LocalCallPdo\nCalled bar in PhpPdo\\Tests\\LocalCallPdo\n", ob_get_clean());
    }

    public function testBug44159BadAttributeValuesDoNotCrash()
    {
        $pdo = $this->pdo([PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING]);
        list($classResult, $warnings) = $this->warningsOf(function () use ($pdo) {
            return $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, null);
        });
        $this->assertFalse($classResult);
        $this->assertNotEmpty($warnings);
        $this->assertTrue($pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, null));
        $this->assertFalse($pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES));
    }

    public function testBug44173QueryFetchModeArguments()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test44173', 'x int');
        $pdo->exec('INSERT INTO test44173 VALUES (1)');

        try {
            $pdo->query('SELECT * FROM test44173', PDO::FETCH_LAZY, 0, []);
            $this->fail('expected ArgumentCountError');
        } catch (\ArgumentCountError $error) {
            $this->assertSame('PDO::query() expects exactly 2 arguments for the fetch mode provided, 4 given', $error->getMessage());
        }
        try {
            $pdo->query('SELECT * FROM test44173', 'abc');
            $this->fail('expected TypeError');
        } catch (\TypeError $error) {
            $this->assertSame('PDO::query(): Argument #2 ($fetchMode) must be of type ?int, string given', $error->getMessage());
        }
        try {
            $pdo->query('SELECT * FROM test44173', PDO::FETCH_CLASS, 0, 0, 0);
            $this->fail('expected ArgumentCountError');
        } catch (\ArgumentCountError $error) {
            $this->assertSame('PDO::query() expects at most 4 arguments for the fetch mode provided, 5 given', $error->getMessage());
        }
        try {
            $pdo->query('SELECT * FROM test44173', PDO::FETCH_INTO);
            $this->fail('expected ArgumentCountError');
        } catch (\ArgumentCountError $error) {
            $this->assertSame('PDO::query() expects exactly 3 arguments for the fetch mode provided, 2 given', $error->getMessage());
        }
        try {
            $pdo->query('SELECT * FROM test44173', PDO::FETCH_COLUMN);
            $this->fail('expected ArgumentCountError');
        } catch (\ArgumentCountError $error) {
            $this->assertSame('PDO::query() expects exactly 3 arguments for the fetch mode provided, 2 given', $error->getMessage());
        }
        try {
            $pdo->query('SELECT * FROM test44173', PDO::FETCH_CLASS);
            $this->fail('expected ArgumentCountError');
        } catch (\ArgumentCountError $error) {
            $this->assertSame('PDO::query() expects at least 3 arguments for the fetch mode provided, 2 given', $error->getMessage());
        }
    }

    public function testBug46292ClassTypeWithoutClassName()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test_46292', 'name VARCHAR(80) NOT NULL, group_name VARCHAR(20), val VARCHAR(1)');
        $pdo->exec("INSERT INTO test_46292 VALUES ('PhpPdo\\Tests\\LocalClassTypeRow', 'alpha', '1')");
        $pdo->exec("INSERT INTO test_46292 VALUES ('PhpPdo\\Tests\\LocalClassTypeRow2', 'beta', '2')");
        $pdo->exec("INSERT INTO test_46292 VALUES ('PhpPdo\\Tests\\LocalClassTypeRow', 'alpha', '0')");
        $pdo->exec("INSERT INTO test_46292 VALUES ('PhpPdo\\Tests\\MissingClassType', 'beta', '0')");
        $pdo->exec("INSERT INTO test_46292 VALUES ('PhpPdo\\Tests\\LocalClassTypeRow', 'alpha', '1')");
        $pdo->exec("INSERT INTO test_46292 VALUES ('PhpPdo\\Tests\\LocalClassTypeRow2', 'beta', '2')");

        $statement = $pdo->prepare('SELECT * FROM test_46292');
        $this->assertTrue($statement->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_CLASSTYPE | PDO::FETCH_GROUP));
        $statement->execute();
        $first = $statement->fetch();
        $second = $statement->fetch();
        $this->assertInstanceOf(LocalClassTypeRow::class, $first);
        $this->assertSame('1', $first->val);
        $this->assertSame('alpha', $first->group_name);
        $this->assertInstanceOf(LocalClassTypeRow2::class, $second);
        $this->assertSame('2', $second->val);

        $grouped = $statement->fetchAll();
        $this->assertInstanceOf(LocalClassTypeRow::class, $grouped['alpha'][0]);
        $this->assertSame('0', $grouped['alpha'][0]->val);
        $this->assertFalse((new \ReflectionProperty(LocalClassTypeRow::class, 'group_name'))->isInitialized($grouped['alpha'][0]));
        $this->assertInstanceOf('stdClass', $grouped['beta'][0]);
        $this->assertSame('0', $grouped['beta'][0]->val);
        $this->assertInstanceOf(LocalClassTypeRow2::class, $grouped['beta'][1]);
    }

    public function testBug50458FetchFuncClosure()
    {
        $pdo = $this->pdo();
        $statement = $pdo->query("SELECT 'php' AS name, 'sql' AS other");
        $rows = $statement->fetchAll(PDO::FETCH_FUNC, function ($name, $other) {
            return strtoupper($name) . ':' . $other;
        });
        $this->assertSame(['PHP:sql'], $rows);
    }

    public function testBug52098StatementCall()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test52098', 'x int');
        $pdo->exec('INSERT INTO test52098 VALUES (1)');
        $derived = $pdo->prepare('SELECT * FROM test52098', [PDO::ATTR_STATEMENT_CLASS => [LocalCallStatement::class]]);
        $derived->execute();
        ob_start();
        $derived->foo();
        $rows = $derived->fetchAll();
        $this->assertSame('1', $rows[0]['x']);
        $text = ob_get_clean();
        $this->assertStringContainsString('Calling object method', $text);
    }

    public function testBug60665EmptyOnLazyNull()
    {
        $pdo = $this->pdo();
        $statement = $pdo->query('SELECT NULL AS null_value, 0 AS zero, 1 AS one');
        $row = $statement->fetch(PDO::FETCH_LAZY);
        $this->assertTrue(empty($row->null_value));
        $this->assertTrue(empty($row->zero));
        $this->assertTrue(!empty($row->one));
        $this->assertTrue(empty($row->missing));
        $this->assertTrue(!isset($row->null_value));
        $this->assertTrue(isset($row->zero));
        $this->assertTrue(isset($row->one));
        $this->assertTrue(!isset($row->missing));
    }

    public function testBug61292MethodOnPdoSubclass()
    {
        $pdo = $this->pdo([], LocalFooPdo::class);
        ob_start();
        $pdo->foo();
        $this->assertSame("array(0) {\n}\n", ob_get_clean());
    }

    public function testBug64172ErrorInfoClearedAfterSuccess()
    {
        $pdo = $this->pdo([PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING]);
        $this->table($pdo, 'test64172', 'x int');
        $pdo->exec('INSERT INTO test64172 VALUES (1)');
        list($failed, ) = $this->warningsOf(function () use ($pdo) {
            return $pdo->query('SELECT * FROM bad_table');
        });
        $this->assertFalse($failed);
        $info = $pdo->errorInfo();
        $this->assertIsString($info[0]);
        $this->assertNotSame('00000', $info[0]);
        $this->assertTrue($info[1] === null || is_int($info[1]));
        $this->assertIsString($info[2]);
        $pdo->query('SELECT * FROM test64172')->fetchAll();
        $this->assertSame(['00000', null, null], $pdo->errorInfo());
    }

    public function testBug65946BoundIntIsNotStringifiedForever()
    {
        $pdo = $this->pdo([PDO::ATTR_EMULATE_PREPARES => true]);
        $this->table($pdo, 'test65946', 'id int');
        $pdo->exec('INSERT INTO test65946 VALUES(1)');
        $statement = $pdo->prepare('SELECT * FROM test65946 LIMIT :limit');
        $statement->bindValue('limit', 1, PDO::PARAM_INT);
        $this->assertTrue($statement->execute());
        $this->assertTrue($statement->execute());
        $this->assertSame([['id' => '1']], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testBug69356DebugDumpDoesNotTruncate()
    {
        $pdo = $this->pdo([PDO::ATTR_EMULATE_PREPARES => true]);
        $query = "SELECT '\n    Dumps the information contained by a prepared statement directly on the output.' AS sample";
        $statement = $pdo->query($query);
        ob_start();
        $result = $statement->debugDumpParams();
        $dump = ob_get_clean();
        $this->assertNull($result);
        $this->assertStringContainsString('SQL: [' . strlen($query) . '] ' . $query, $dump);
        $this->assertStringContainsString('Params:  0', $dump);
    }

    public function testBug72368ExecuteWithExtraParametersThrows()
    {
        $pdo = $this->pdo([PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true]);
        try {
            $pdo->prepare('SELECT 1')->execute([':bar' => 1]);
            $this->fail('expected PDOException');
        } catch (PDOException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public function testBug73234NullParameterType()
    {
        $pdo = $this->pdo([PDO::ATTR_EMULATE_PREPARES => true]);
        $this->table($pdo, 'test73234', 'id INT');
        $statement = $pdo->prepare('INSERT INTO test73234 VALUES(:value)');
        $statement->bindValue(':value', 0, PDO::PARAM_NULL);
        $statement->execute();
        $statement->bindValue(':value', null, PDO::PARAM_NULL);
        $statement->execute();
        $this->assertSame([['id' => null], ['id' => null]], $pdo->query('SELECT * FROM test73234')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testBug79106FetchBothNumericNames()
    {
        $statement = $this->pdo()->query('SELECT 0 AS "2007", 0 AS "2008", 0 AS "2020"');
        if ($statement === false) {
            $this->markTestSkipped('driver rejected quoted numeric identifiers');
        }
        $row = $statement->fetch(PDO::FETCH_BOTH);
        $this->assertSame('0', $row[2007]);
        $this->assertSame('0', $row[0]);
        $this->assertSame('0', $row[2008]);
        $this->assertSame('0', $row[1]);
        $this->assertSame('0', $row[2020]);
        $this->assertSame('0', $row[2]);
    }

    public function testGh8626ErrorInfoResetAfterSuccessfulExecute()
    {
        $pdo = $this->pdo([PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        $this->table($pdo, 'test8626', 'x int NOT NULL');
        $statement = $pdo->prepare('INSERT INTO test8626 VALUES(?)');
        $this->assertFalse($statement->execute([null]));
        $this->assertNotSame('00000', $statement->errorCode());
        $this->assertNotSame('00000', $statement->errorInfo()[0]);
        $statement->closeCursor();
        $this->assertTrue($statement->execute([1]));
        $this->assertSame('00000', $statement->errorCode());
        $this->assertSame(['00000', null, null], $statement->errorInfo());
    }

    public function testPecl5217SerializeIsRejected()
    {
        $pdo = $this->pdo();
        try {
            serialize($pdo);
            $this->fail('expected Exception');
        } catch (\Exception $exception) {
            $this->assertSame("Serialization of 'PDO' is not allowed", $exception->getMessage());
        }
    }

    public function testPecl5772MixedCaseFunctionName()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test5772', 'id int NOT NULL, PRIMARY KEY (id)');
        $pdo->exec('INSERT INTO test5772 (id) VALUES (1)');
        $rows = $pdo->query('SELECT * FROM test5772')->fetchAll(PDO::FETCH_FUNC, __NAMESPACE__ . '\\localHeLLO');
        $this->assertSame(['1'], $rows);
    }

    public function testPecl5809ExecuteDoesNotRewriteInputArray()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test5809', 'id int NOT NULL, PRIMARY KEY (id)');
        $pdo->exec('INSERT INTO test5809 (id) VALUES (1)');
        $values = [1];
        $pdo->prepare('SELECT * FROM test5809 WHERE id = ?')->execute($values);
        $this->assertSame([1], $values);
    }

    public function testPdo016FetchBound()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test016', 'idx int NOT NULL PRIMARY KEY, txt VARCHAR(20)');
        $pdo->exec("INSERT INTO test016 VALUES(0, 'String0')");
        $pdo->exec("INSERT INTO test016 VALUES(1, 'String1')");
        $pdo->exec("INSERT INTO test016 VALUES(2, 'String2')");
        $count = $pdo->prepare('SELECT COUNT(idx) FROM test016');
        $count->execute();
        $this->assertSame('3', $count->fetchColumn());

        $statement = $pdo->prepare('SELECT idx, txt FROM test016 ORDER BY idx');
        $statement->execute();
        $this->assertEquals(['0' => 'String0', '1' => 'String1', '2' => 'String2'], $statement->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_UNIQUE));

        $statement->bindColumn('idx', $idx);
        $statement->bindColumn('txt', $txt);
        $statement->execute();
        $pairs = [];
        while ($statement->fetch(PDO::FETCH_BOUND)) {
            $pairs[$idx] = $txt;
        }
        $this->assertEquals(['0' => 'String0', '1' => 'String1', '2' => 'String2'], $pairs);

        $statement->bindColumn('idx', $col1);
        $statement->bindColumn('txt', $col1);
        $statement->execute();
        $conflict = [];
        while ($statement->fetch(PDO::FETCH_BOUND)) {
            $conflict[] = $col1;
        }
        $this->assertSame(['String0', 'String1', 'String2'], $conflict);
    }

    public function testPdo023ExtendingPdo()
    {
        $pdo = $this->pdo([], LocalExtendingPdo::class);
        $this->assertSame(1, $pdo->test1);
        $pdo->testMethod();
        $this->assertSame(22, $pdo->test2);
        $this->table($pdo, 'test023', 'id INT NOT NULL PRIMARY KEY, val VARCHAR(10)');
        $pdo->exec("INSERT INTO test023 VALUES(0, 'A')");
        $pdo->exec("INSERT INTO test023 VALUES(1, 'B')");
        $statement = $pdo->query('SELECT val, id FROM test023');
        $this->assertInstanceOf(LocalExtendingStatement::class, $statement);
        $this->assertSame(22, $statement->test2);
        $this->assertSame(['A' => '0', 'B' => '1'], $statement->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_UNIQUE));
    }

    public function testPdo035PdoRowOffsets()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test_pdo_35_pdo_row', 'id int, name varchar(10)');
        $pdo->exec("INSERT INTO test_pdo_35_pdo_row VALUES (23, '0')");
        $statement = $pdo->prepare('SELECT id, name FROM test_pdo_35_pdo_row');
        $statement->execute();
        $result = $statement->fetch(PDO::FETCH_LAZY);
        $this->assertInstanceOf(PDORow::class, $result);
        $this->assertFalse(get_parent_class($result));
        $this->assertSame('23', $result[0]);
        $this->assertSame('23', $result['0']);
        $this->assertSame('23', $result['id']);
        $this->assertSame('0', $result['name']);
        $this->assertSame('0', $result[1]);
        $this->assertTrue(isset($result['name']));
        $this->assertTrue(empty($result['name']));
        $this->assertTrue(isset($result->name));
        $this->assertTrue(empty($result->name));
        $this->assertSame('23', $result->id);

        try {
            $result[0] = 1;
            $this->fail('expected Error');
        } catch (\Error $error) {
            $this->assertSame('Cannot write to PDORow offset', $error->getMessage());
        }
        try {
            unset($result[0]);
            $this->fail('expected Error');
        } catch (\Error $error) {
            $this->assertSame('Cannot unset PDORow offset', $error->getMessage());
        }
        try {
            $result->foo = 1;
            $this->fail('expected Error');
        } catch (\Error $error) {
            $this->assertSame('Cannot write to PDORow property', $error->getMessage());
        }
        list($value, $warnings) = $this->warningsOf(function () use ($result) {
            $reference = &$result[0];
            return $reference;
        });
        $this->assertSame('23', $value);
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Indirect modification', $warnings[0]);
    }

    public function testPdo037UndefinedMethodOnStatementSubclass()
    {
        $this->expectException(\Error::class);
        $statement = new LocalBareStatement();
        $statement->foo();
    }

    public function testDriversAndEmptyQuote()
    {
        $this->assertContains('odbc', PDO::getAvailableDrivers());
        $pdo = $this->pdo();
        $this->assertSame("''", $pdo->quote(''));
    }

    public function testFetchFuncCallables()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'pdo_fetch_function_001', 'id int NOT NULL PRIMARY KEY, pl_name VARCHAR(10)');
        $pdo->exec("INSERT INTO pdo_fetch_function_001 VALUES (1, 'php')");
        $pdo->exec("INSERT INTO pdo_fetch_function_001 VALUES (2, 'sql')");
        $upper = $pdo->query('SELECT pl_name FROM pdo_fetch_function_001')->fetchAll(PDO::FETCH_FUNC, 'strtoupper');
        $this->assertSame(['PHP', 'SQL'], $upper);
        $factory = $pdo->query('SELECT * FROM pdo_fetch_function_001')->fetchAll(PDO::FETCH_FUNC, [LocalFetchBar::class, 'factory']);
        $this->assertSame(['PhpPdo\\Tests\\LocalFetchBar::factory(1, php)', 'PhpPdo\\Tests\\LocalFetchBar::factory(2, sql)'], $factory);
    }

    public function testStatementClassAttribute()
    {
        $pdo = $this->pdo();
        $this->assertSame([PDOStatement::class, []], $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS));
        $this->table($pdo, 'pdo_attr_statement_class_basic', 'id INT, label CHAR(1), PRIMARY KEY(id)');
        $pdo->exec("INSERT INTO pdo_attr_statement_class_basic (id, label) VALUES (1, 'a')");
        $pdo->exec("INSERT INTO pdo_attr_statement_class_basic (id, label) VALUES (2, 'b')");

        $this->assertTrue($pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LocalPublicDestructStatement::class]));
        ob_start();
        $statement = $pdo->query('SELECT id, label FROM pdo_attr_statement_class_basic ORDER BY id ASC');
        unset($statement);
        $this->assertStringContainsString('LocalPublicDestructStatement::__destruct', ob_get_clean());

        $this->assertTrue($pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LocalPrivateStatement::class, ['param1']]));
        ob_start();
        $statement = $pdo->query('SELECT id, label FROM pdo_attr_statement_class_basic ORDER BY id ASC');
        $text = ob_get_clean();
        $this->assertStringContainsString('param1', $text);
        $this->assertInstanceOf(LocalPrivateStatement::class, $statement);

        try {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, 'not an array');
            $this->fail('expected PDOException');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('ATTR_STATEMENT_CLASS', $exception->getMessage());
        }
        try {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['missing_statement_class']);
            $this->fail('expected PDOException');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('valid class', $exception->getMessage());
        }
        try {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LocalNotStatement::class]);
            $this->fail('expected PDOException');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('derived from PDOStatement', $exception->getMessage());
        }
        try {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LocalPublicStatement::class]);
            $this->fail('expected PDOException');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('public constructor', $exception->getMessage());
        }
    }

    public function testDebugDumpEmulatedPrepares()
    {
        $pdo = $this->pdo([PDO::ATTR_EMULATE_PREPARES => true]);
        ob_start();
        $plain = $pdo->query('SELECT 1');
        $plain->debugDumpParams();
        $plainDump = ob_get_clean();
        $this->assertStringContainsString('SQL: [8] SELECT 1', $plainDump);
        $this->assertStringNotContainsString('Sent SQL:', $plainDump);

        $statement = $pdo->prepare('SELECT :bool, :int, :string, :null');
        $statement->bindValue(':bool', true, PDO::PARAM_BOOL);
        $statement->bindValue(':int', 123, PDO::PARAM_INT);
        $statement->bindValue(':string', 'foo', PDO::PARAM_STR);
        $statement->bindValue(':null', null, PDO::PARAM_NULL);
        $statement->execute();
        ob_start();
        $statement->debugDumpParams();
        $dump = ob_get_clean();
        $this->assertStringContainsString('Sent SQL: [26] SELECT 1, 123, \'foo\', NULL', $dump);
        $this->assertStringContainsString('paramno=-1', $dump);
        $this->assertStringContainsString('param_type=5', $dump);
        $this->assertStringContainsString('param_type=0', $dump);
    }

    public function testOracleNullsRejectsUnknownMode()
    {
        $pdo = $this->pdo([PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING]);
        $this->assertTrue($pdo->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_TO_STRING));
        list($rejected, $warnings) = $this->warningsOf(function () use ($pdo) {
            return $pdo->setAttribute(PDO::ATTR_ORACLE_NULLS, 99);
        });
        $this->assertFalse($rejected);
        $this->assertNotEmpty($warnings);
        $this->assertSame(PDO::NULL_TO_STRING, $pdo->getAttribute(PDO::ATTR_ORACLE_NULLS));
    }

    public function testLobStreamInsert()
    {
        $pdo = $this->pdo([PDO::ATTR_EMULATE_PREPARES => true]);
        $this->table($pdo, 'test34630', 'data varchar(32)');
        $stream = fopen('php://memory', 'rb+');
        fwrite($stream, 'lob-text');
        rewind($stream);
        $statement = $pdo->prepare('INSERT INTO test34630 (data) VALUES (?)');
        $statement->bindParam(1, $stream, PDO::PARAM_LOB);
        $this->assertTrue($statement->execute());
        $this->assertSame('lob-text', $pdo->query('SELECT data FROM test34630')->fetchColumn());
        fclose($stream);
    }

    /**
     * @param callable $callback
     * @return array{0:mixed,1:array<int,string>}
     */
    private function warningsOf($callback)
    {
        $warnings = [];
        set_error_handler(function ($severity, $message) use (&$warnings) {
            unset($severity);
            $warnings[] = $message;
            return true;
        });
        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }
        return [$result, $warnings];
    }

    /**
     * @param array<int,string> $warnings
     */
    private function assertContainsMessage(array $warnings, $fragment)
    {
        foreach ($warnings as $warning) {
            if (strpos($warning, $fragment) !== false) {
                $this->assertTrue(true);
                return;
            }
        }
        $this->fail('missing warning: ' . $fragment);
    }
}

class LocalCallPdo extends PDO
{
    public function __call($name, array $args)
    {
        unset($args);
        echo "Called $name in " . __CLASS__ . "\n";
    }

    public function foo()
    {
        echo 'Called foo in ' . __CLASS__ . "\n";
    }
}

class LocalFooPdo extends PDO
{
    public $bar = [];

    public function foo()
    {
        var_dump($this->bar);
    }
}

class LocalCallStatement extends PDOStatement
{
    public function __call($name, $arguments)
    {
        echo "Calling object method '$name'" . implode(', ', $arguments) . "\n";
    }
}

class LocalClassTypeRow
{
    public $val;

    /** @var string */
    public string $group_name;

    public function __construct()
    {
    }
}

class LocalClassTypeRow2 extends LocalClassTypeRow
{
}

class LocalExtendingStatement extends PDOStatement
{
    public $test1 = 1;
    public $test2;

    protected function __construct()
    {
        $this->test2 = 22;
    }
}

class LocalExtendingPdo extends PDO
{
    public $test1 = 1;
    public $test2;

    public function testMethod()
    {
        $this->test2 = 22;
    }

    public function query($sql)
    {
        $statement = $this->prepare($sql, [PDO::ATTR_STATEMENT_CLASS => [LocalExtendingStatement::class]]);
        $statement->execute();
        return $statement;
    }
}

class LocalBareStatement extends PDOStatement
{
}

class LocalPublicDestructStatement extends PDOStatement
{
    public function __destruct()
    {
        echo __METHOD__, "\n";
    }
}

class LocalPrivateStatement extends PDOStatement
{
    private function __construct($message)
    {
        echo $message, "\n";
    }
}

class LocalPublicStatement extends PDOStatement
{
    public function __construct()
    {
    }
}

class LocalNotStatement
{
}

class LocalFetchBar
{
    public static function factory($x, $y)
    {
        return __METHOD__ . "($x, $y)";
    }
}

function localHello($row)
{
    return $row;
}
