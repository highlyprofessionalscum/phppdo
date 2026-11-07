<?php

namespace PhpPdo\Tests;

use PhpPdo\PDO;
use PhpPdo\PDORow;

class FetchClass010A
{
    public $id;
    public $val;
    public function __construct()
    {
    }
}

class FetchClass010B
{
    public $id;
    public $val;
    public function __construct()
    {
    }
}

class FetchClass010C
{
    public $id;
    public $val;
    public function __construct()
    {
    }
}

class FetchModeClass
{
    public $val;
    public $grp;
    public $name;

    public function __construct($name = 'N/A')
    {
        $this->name = $name;
    }
}

class FetchIntoRow
{
    public $id;
    public $val;
    public $val2;
}

class FetchIntoProtected
{
    protected $id;
    public $val;
    public $val2;
}

class FetchSerializeRow implements \Serializable
{
    public $constructed = false;

    public function __construct()
    {
        $this->constructed = true;
    }

    public function serialize()
    {
        return 'unused';
    }

    public function unserialize($dat)
    {
        $this->constructed = false;
        $this->payload = $dat;
    }
}

/**
 * Общие тесты ext/pdo/tests pdo_001 … pdo_034: режимы выборки.
 */
class FetchTest extends PdoTestCase
{
    public function testFetchAssocNumBothAndObject()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test001', 'id int NOT NULL PRIMARY KEY, val VARCHAR(10)');
        $pdo->exec("INSERT INTO test001 VALUES(1, 'A')");
        $pdo->exec("INSERT INTO test001 VALUES(2, 'B')");
        $pdo->exec("INSERT INTO test001 VALUES(3, 'C')");

        $stmt = $pdo->query('SELECT * FROM test001 ORDER BY id');
        $this->assertSame([
            ['id' => '1', 'val' => 'A'],
            ['id' => '2', 'val' => 'B'],
            ['id' => '3', 'val' => 'C'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));

        $stmt = $pdo->query('SELECT * FROM test001 ORDER BY id');
        $this->assertSame([
            ['1', 'A'],
            ['2', 'B'],
            ['3', 'C'],
        ], $stmt->fetchAll(PDO::FETCH_NUM));

        $stmt = $pdo->query('SELECT * FROM test001 ORDER BY id');
        $both = $stmt->fetchAll(PDO::FETCH_BOTH);
        $this->assertSame('A', $both[0]['val']);
        $this->assertSame('A', $both[0][1]);
        $this->assertSame('1', $both[0]['id']);

        $stmt = $pdo->query('SELECT * FROM test001 ORDER BY id');
        $objects = $stmt->fetchAll(PDO::FETCH_OBJ);
        $this->assertInstanceOf('stdClass', $objects[0]);
        $this->assertSame('B', $objects[1]->val);
    }

    public function testFetchGroupAndUnique()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test006', 'id int NOT NULL PRIMARY KEY, val VARCHAR(10)');
        $pdo->exec("INSERT INTO test006 VALUES(1, 'A')");
        $pdo->exec("INSERT INTO test006 VALUES(2, 'A')");
        $pdo->exec("INSERT INTO test006 VALUES(3, 'C')");

        $stmt = $pdo->query('SELECT val, id FROM test006 ORDER BY id');
        $this->assertSame([
            'A' => [['1'], ['2']],
            'C' => [['3']],
        ], $stmt->fetchAll(PDO::FETCH_NUM | PDO::FETCH_GROUP));

        $stmt = $pdo->query('SELECT val, id FROM test006 ORDER BY id');
        $this->assertSame([
            'A' => [['id' => '1'], ['id' => '2']],
            'C' => [['id' => '3']],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_GROUP));

        $this->table($pdo, 'test007', 'id CHAR(1) NOT NULL PRIMARY KEY, val VARCHAR(10)');
        $pdo->exec("INSERT INTO test007 VALUES('A', 'A')");
        $pdo->exec("INSERT INTO test007 VALUES('B', 'A')");
        $pdo->exec("INSERT INTO test007 VALUES('C', 'C')");
        $stmt = $pdo->query('SELECT id, val FROM test007 ORDER BY id');
        $this->assertSame([
            'A' => ['A'],
            'B' => ['A'],
            'C' => ['C'],
        ], $stmt->fetchAll(PDO::FETCH_NUM | PDO::FETCH_UNIQUE));

        $this->table($pdo, 'test008', 'id CHAR(1) NOT NULL PRIMARY KEY, val VARCHAR(10)');
        $pdo->exec("INSERT INTO test008 VALUES('A', 'A')");
        $pdo->exec("INSERT INTO test008 VALUES('B', 'A')");
        $pdo->exec("INSERT INTO test008 VALUES('C', 'C')");
        $stmt = $pdo->query('SELECT val, id FROM test008 ORDER BY id');
        $this->assertSame([
            'A' => ['B'],
            'C' => ['C'],
        ], $stmt->fetchAll(PDO::FETCH_NUM | PDO::FETCH_UNIQUE));
    }

    public function testFetchClassTypeWithGroupAndUnique()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'classtypes010', 'id int NOT NULL PRIMARY KEY, name VARCHAR(32) NOT NULL UNIQUE');
        $this->table($pdo, 'test010', 'id int NOT NULL PRIMARY KEY, classtype int, val VARCHAR(10), grp VARCHAR(10)');
        $pdo->exec("INSERT INTO classtypes010 VALUES(0, 'stdClass')");
        $pdo->exec("INSERT INTO classtypes010 VALUES(1, '" . FetchClass010A::class . "')");
        $pdo->exec("INSERT INTO classtypes010 VALUES(2, '" . FetchClass010B::class . "')");
        $pdo->exec("INSERT INTO test010 VALUES(1, 0, 'A', 'Group1')");
        $pdo->exec("INSERT INTO test010 VALUES(2, 1, 'B', 'Group1')");
        $pdo->exec("INSERT INTO test010 VALUES(3, 2, 'C', 'Group2')");
        $pdo->exec("INSERT INTO test010 VALUES(4, 3, 'D', 'Group2')");

        $sql = 'SELECT classtypes010.name, test010.grp AS grp, test010.id AS id, test010.val AS val'
            . ' FROM test010 LEFT JOIN classtypes010 ON test010.classtype = classtypes010.id ORDER BY test010.id';
        $grouped = $pdo->query($sql)->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_CLASSTYPE | PDO::FETCH_GROUP, FetchClass010C::class);
        $this->assertInstanceOf('stdClass', $grouped['Group1'][0]);
        $this->assertInstanceOf(FetchClass010A::class, $grouped['Group1'][1]);
        $this->assertInstanceOf(FetchClass010B::class, $grouped['Group2'][0]);
        $this->assertInstanceOf(FetchClass010C::class, $grouped['Group2'][1]);
        $this->assertSame('B', $grouped['Group1'][1]->val);

        $unique = $pdo->query($sql)->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_CLASSTYPE | PDO::FETCH_UNIQUE, FetchClass010C::class);
        $this->assertInstanceOf(FetchClass010A::class, $unique['Group1']);
        $this->assertInstanceOf(FetchClass010C::class, $unique['Group2']);
        $this->assertSame('D', $unique['Group2']->val);
    }

    public function testFetchColumnGroupAndUnique()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test015', 'id int NOT NULL PRIMARY KEY, val VARCHAR(10), val2 VARCHAR(20)');
        $pdo->exec("INSERT INTO test015 VALUES(1, 'A', 'A2')");
        $pdo->exec("INSERT INTO test015 VALUES(2, 'A', 'B2')");

        $select = $pdo->prepare('SELECT id, val, val2 FROM test015 ORDER BY id');
        $select->execute();
        $this->assertSame(['1', '2'], $select->fetchAll(PDO::FETCH_COLUMN));
        $select->execute();
        $this->assertSame(['A2', 'B2'], $select->fetchAll(PDO::FETCH_COLUMN, 2));
        $select->execute();
        $this->assertSame([1 => ['A'], 2 => ['A']], $select->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_GROUP));
        $select->execute();
        $this->assertSame([1 => 'A', 2 => 'A'], $select->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_UNIQUE));
        $select->execute();
        $this->assertSame([1 => '1', 2 => '2'], $select->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_UNIQUE, 0));
        $select->execute();
        $this->assertSame([1 => 'A2', 2 => 'B2'], $select->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_UNIQUE, 2));

        $pairs = $pdo->query('SELECT val, val2 FROM test015 ORDER BY id');
        $this->assertSame(['A' => ['A2', 'B2']], $pairs->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_GROUP));
    }

    public function testSetFetchModeClassAndIterator()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test012', 'id int NOT NULL PRIMARY KEY, val VARCHAR(10), grp VARCHAR(10)');
        $pdo->exec("INSERT INTO test012 VALUES(1, 'A', 'Group1')");
        $pdo->exec("INSERT INTO test012 VALUES(2, 'B', 'Group2')");
        $select = 'SELECT val, grp FROM test012 ORDER BY id';

        $numeric = $pdo->query($select, PDO::FETCH_NUM)->fetchAll();
        $this->assertSame([['A', 'Group1'], ['B', 'Group2']], $numeric);

        $objects = $pdo->query($select, PDO::FETCH_CLASS, FetchModeClass::class)->fetchAll();
        $this->assertSame('N/A', $objects[0]->name);
        $this->assertSame('Group2', $objects[1]->grp);

        $stmt = $pdo->query($select, PDO::FETCH_NUM);
        $stmt->setFetchMode(PDO::FETCH_CLASS, FetchModeClass::class, ['Changed']);
        $changed = $stmt->fetchAll();
        $this->assertSame('Changed', $changed[0]->name);

        $seen = [];
        foreach ($pdo->query($select, PDO::FETCH_CLASS, FetchModeClass::class, ['WOW']) as $row) {
            $seen[] = [$row->name, $row->val, $row->grp];
        }
        $this->assertSame([['WOW', 'A', 'Group1'], ['WOW', 'B', 'Group2']], $seen);
    }

    public function testFetchBoundAndColumnCount()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test019', 'idx int NOT NULL PRIMARY KEY, txt VARCHAR(20)');
        foreach (['String0', 'String1', 'String2', 'String3'] as $i => $text) {
            $pdo->exec('INSERT INTO test019 VALUES(' . $i . ", '" . $text . "')");
        }
        $this->assertSame('4', $pdo->query('SELECT COUNT(*) FROM test019')->fetchColumn());

        $stmt = $pdo->prepare('SELECT idx, txt FROM test019 ORDER BY idx');
        $stmt->execute();
        $this->assertSame([
            0 => 'String0',
            1 => 'String1',
            2 => 'String2',
            3 => 'String3',
        ], $stmt->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_UNIQUE));

        $stmt->bindColumn('idx', $idx);
        $stmt->bindColumn('txt', $txt);
        $stmt->execute();
        $pairs = [];
        while ($stmt->fetch(PDO::FETCH_BOUND)) {
            $pairs[] = [$idx => $txt];
        }
        $this->assertSame([[0 => 'String0'], [1 => 'String1'], [2 => 'String2'], [3 => 'String3']], $pairs);

        $this->assertSame(2, $pdo->query('SELECT idx, txt FROM test019')->columnCount());
        $this->assertSame(1, $pdo->query('SELECT COUNT(*) FROM test019')->columnCount());
    }

    public function testExecuteParametersAndBindValue()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test021', 'id INT NOT NULL PRIMARY KEY, val VARCHAR(10), val2 VARCHAR(16)');
        $data = [
            ['10', 'Abc', 'zxy'],
            ['20', 'Def', 'wvu'],
            ['30', 'Ghi', 'tsr'],
        ];
        $stmt = $pdo->prepare('INSERT INTO test021 VALUES(?, ?, ?)');
        foreach ($data as $row) {
            $stmt->execute($row);
        }
        $named = $pdo->prepare('INSERT INTO test021 VALUES(:first, :second, :third)');
        foreach ($data as $row) {
            $named->execute([':first' => (string) ($row[0] + 5), ':second' => $row[1], ':third' => $row[2]]);
        }
        $this->assertSame('6', $pdo->query('SELECT COUNT(id) FROM test021')->fetchColumn());

        $this->table($pdo, 'test028', 'id int NOT NULL PRIMARY KEY, val1 VARCHAR(10), val2 VARCHAR(10), val3 VARCHAR(10)');
        $insert = $pdo->prepare('INSERT INTO test028 VALUES (1, ?, ?, ?)');
        foreach (['one', 'two', 'three'] as $i => $value) {
            $insert->bindValue($i + 1, $value);
        }
        $insert->execute();
        $this->assertSame([
            ['id' => '1', 'val1' => 'one', 'val2' => 'two', 'val3' => 'three'],
        ], $pdo->query('SELECT * FROM test028')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testBindParamDoesNotModifyTheVariable()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test024', 'id int, name varchar(10)');
        $stmt = $pdo->prepare('INSERT INTO test024 (id, name) VALUES(0, :name)');
        $name = null;
        $before = $name;
        $stmt->bindParam(':name', $name, PDO::PARAM_NULL);
        $this->assertSame($before, $name);
        $this->assertTrue($stmt->execute());
        $this->assertNull($pdo->query('SELECT name FROM test024 WHERE id = 0')->fetchColumn());
    }

    public function testFetchIntoAndProtectedProperty()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test025', 'id INT NOT NULL PRIMARY KEY, val VARCHAR(10), val2 VARCHAR(16)');
        $pdo->exec("INSERT INTO test025 VALUES(10, 'Abc', 'zxy')");
        $into = new FetchIntoRow();
        $stmt = $pdo->query('SELECT * FROM test025');
        $stmt->setFetchMode(PDO::FETCH_INTO, $into);
        $row = $stmt->fetch();
        $this->assertSame($into, $row);
        $this->assertSame('10', $into->id);
        $this->assertSame('Abc', $into->val);

        $stmt->setFetchMode(PDO::FETCH_INTO, new FetchIntoProtected());
        $stmt->execute();
        $this->expectException(\Error::class);
        $stmt->fetch();
    }

    public function testFetchLazyReusesOneRow()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test027', 'id int, name varchar(10)');
        $pdo->exec("INSERT INTO test027 (id, name) VALUES(1, 'test1')");
        $pdo->exec("INSERT INTO test027 (id, name) VALUES(2, 'test2')");
        $stmt = $pdo->query('SELECT * FROM test027 ORDER BY id', PDO::FETCH_LAZY);
        $this->assertSame('SELECT * FROM test027 ORDER BY id', $stmt->queryString);

        $alias = null;
        $texts = [];
        foreach ($stmt as $row) {
            $this->assertInstanceOf(PDORow::class, $row);
            if ($alias !== null) {
                $this->assertSame($alias, $row);
            }
            $texts[] = $row->id . $row->name;
            $alias = $row;
        }
        $this->assertSame(['1test1', '2test2'], $texts);
    }

    public function testCaseFoldingAndQuoteRoundTrip()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test032', 'id int NOT NULL PRIMARY KEY, val VARCHAR(10)');
        $pdo->exec("INSERT INTO test032 VALUES(1, 'A')");
        $pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
        $this->assertArrayHasKey('val', $pdo->query('SELECT * FROM test032')->fetch(PDO::FETCH_ASSOC));
        $pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
        $this->assertArrayHasKey('VAL', $pdo->query('SELECT * FROM test032')->fetch(PDO::FETCH_ASSOC));

        $unquoted = ' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\]^_`abcdefghijklmnopqrstuvwxyz{|}~';
        $pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
        $this->table($pdo, 'test033', 't char(' . strlen($unquoted) . ')');
        $pdo->exec('INSERT INTO test033 (t) VALUES(' . $pdo->quote($unquoted) . ')');
        $this->assertSame($unquoted, $pdo->query('SELECT t FROM test033')->fetchColumn());
        $this->assertSame("''", $pdo->quote(''));
    }

    public function testFetchKeyPair()
    {
        $pdo = $this->pdo([PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING]);
        $this->table($pdo, 'test034', 'a varchar(100), b varchar(100), c varchar(100)');
        for ($i = 0; $i < 5; $i++) {
            $pdo->exec("INSERT INTO test034 (a, b, c) VALUES('test" . $i . "', '" . $i . "', '" . $i . "')");
        }
        $this->assertSame(['test0' => '0'], $pdo->query('SELECT a, b FROM test034')->fetch(PDO::FETCH_KEY_PAIR));
        $all = $pdo->query('SELECT a, b FROM test034 ORDER BY a')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(['test0' => '0', 'test1' => '1', 'test2' => '2', 'test3' => '3', 'test4' => '4'], $all);

        $warnings = [];
        set_error_handler(function ($severity, $message) use (&$warnings) {
            $warnings[] = $message;
            return true;
        });
        try {
            $wrong = $pdo->query('SELECT * FROM test034')->fetch(PDO::FETCH_KEY_PAIR);
        } finally {
            restore_error_handler();
        }
        $this->assertFalse($wrong);
        $this->assertNotEmpty($warnings);

        $stmt = $pdo->query('SELECT a, b FROM test034 ORDER BY a');
        $stmt->setFetchMode(PDO::FETCH_KEY_PAIR);
        $this->assertSame($all, $stmt->fetchAll());
    }

    public function testFetchColumnRejectsABadIndex()
    {
        $pdo = $this->pdo([PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING]);
        $stmt = $pdo->prepare('SELECT 1');
        $warnings = [];
        set_error_handler(function ($severity, $message) use (&$warnings) {
            $warnings[] = $message;
            return true;
        });
        try {
            $negative = $stmt->execute() ? $stmt->fetchColumn(-1) : false;
            $stmt->execute();
            $missing = $stmt->fetchColumn(1);
        } finally {
            restore_error_handler();
        }
        $this->assertFalse($negative);
        $this->assertFalse($missing);
        $this->assertNotEmpty($warnings);
        $stmt->execute();
        $this->assertSame('1', $stmt->fetchColumn(0));
    }

    public function testFetchSerializeDoesNotCallConstructor()
    {
        $pdo = $this->pdo();
        $this->table($pdo, 'test44409', 'dat varchar(100)');
        $pdo->exec("INSERT INTO test44409 (dat) VALUES ('Data from DB')");
        $row = $pdo->query('SELECT * FROM test44409')->fetchAll(
            PDO::FETCH_CLASS | PDO::FETCH_SERIALIZE,
            FetchSerializeRow::class
        );
        $this->assertFalse($row[0]->constructed);
        $this->assertSame('Data from DB', $row[0]->payload);
    }
}
