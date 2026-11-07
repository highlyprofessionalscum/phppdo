<?php

namespace PhpPdo\Tests;

use PhpPdo\Flags;
use PhpPdo\PDO;
use PhpPdo\PDOException;
use PhpPdo\RowFormatter;
use PhpPdo\SqlLiteral;
use PhpPdo\SqlParser;
use PHPUnit\Framework\TestCase;

class UnitTest extends TestCase
{
    public function testPhp74FetchFlags()
    {
        $this->assertSame(65536, PDO::FETCH_GROUP);
        $this->assertSame(196608, PDO::FETCH_UNIQUE);
        $this->assertSame(1, PDO::CASE_UPPER);
        $this->assertSame(2, PDO::CASE_LOWER);
        $this->assertSame(20, PDO::ATTR_EMULATE_PREPARES);
        $this->assertSame(19, PDO::ATTR_DEFAULT_FETCH_MODE);
        $this->assertSame('00000', PDO::ERR_NONE);
        $this->assertContains('odbc', PDO::getAvailableDrivers());
        $this->assertSame(PDO::PARAM_STR, Flags::paramBase(PDO::PARAM_INPUT_OUTPUT | PDO::PARAM_STR));
        $this->assertSame(PDO::PARAM_INT, Flags::paramBase(PDO::PARAM_STR_NATL | PDO::PARAM_INT));
    }

    public function testConstantsMatchNativePdoWhenBothExist()
    {
        if (!class_exists('PDO', false) || (new \ReflectionClass('PDO'))->getName() === PDO::class) {
            $this->assertTrue(true);
            return;
        }

        $native = new \ReflectionClass('PDO');
        $ours = new \ReflectionClass(PDO::class);
        foreach ($ours->getReflectionConstants() as $constant) {
            $name = $constant->getName();
            if (!$native->hasConstant($name)) {
                continue;
            }
            $this->assertSame($native->getConstant($name), $constant->getValue(), 'PDO::' . $name);
        }
    }

    public function testParserRewritesPlaceholdersLikePdo()
    {
        $named = SqlParser::parse('SELECT * FROM t WHERE id = :id AND name = :name AND id = :id');
        $this->assertNull($named['error']);
        $this->assertSame('SELECT * FROM t WHERE id = ? AND name = ? AND id = ?', $named['nativeSql']);

        $positional = SqlParser::parse('SELECT * FROM t WHERE a = ? AND b = ?? AND c = ?');
        $this->assertNull($positional['error']);
        $this->assertSame('SELECT * FROM t WHERE a = ? AND b = ? AND c = ?', $positional['nativeSql']);
        $this->assertCount(2, $positional['placeholders']);

        $quoted = SqlParser::parse("SELECT ':name', \"?\" FROM t WHERE note = 'it''s :fine'");
        $this->assertSame([], $quoted['placeholders']);

        $comment = SqlParser::parse("SELECT 1 -- :hidden\n, :shown");
        $this->assertCount(1, $comment['placeholders']);
        $this->assertSame('shown', $comment['placeholders'][0]['name']);

        $block = SqlParser::parse('SELECT /* :no */ :yes');
        $this->assertSame('yes', $block['placeholders'][0]['name']);

        $cast = SqlParser::parse('SELECT id::int, :id');
        $this->assertSame('SELECT id::int, ?', $cast['nativeSql']);

        $mixed = SqlParser::parse('SELECT :name, ?');
        $this->assertSame('mixed named and positional parameters', $mixed['error']);
    }

    public function testBug40285QuotedColonDoesNotLoop()
    {
        $parsed = SqlParser::parse("INSERT INTO test40285 VALUES( ':id', 'name', 'section', 22)");
        $this->assertNull($parsed['error']);
        $this->assertSame([], $parsed['placeholders']);
    }

    public function testBug71447CommentsDoNotCreatePlaceholders()
    {
        $line = SqlParser::parse("SELECT -- That's all folks!\n '\"abc\":8000'");
        $this->assertSame([], $line['placeholders']);

        $inside = SqlParser::parse("SELECT '\"abc\":8001 -- Wat?'");
        $this->assertSame([], $inside['placeholders']);

        $block = SqlParser::parse("SELECT /* That's all folks! */ '\"abc\":8003'");
        $this->assertSame([], $block['placeholders']);

        $question = SqlParser::parse("SELECT -- What about question marks here?\n * FROM t WHERE id = ?");
        $this->assertCount(1, $question['placeholders']);
        $this->assertSame('positional', $question['placeholders'][0]['kind']);
    }

    public function testLiteralQuoting()
    {
        $this->assertSame("'O''Brien'", SqlLiteral::render("O'Brien", PDO::PARAM_STR));
        $this->assertSame('NULL', SqlLiteral::render(null, PDO::PARAM_STR));
        $this->assertSame('NULL', SqlLiteral::render('x', PDO::PARAM_NULL));
        $this->assertSame('42', SqlLiteral::render('42', PDO::PARAM_INT));
        $this->assertSame('0', SqlLiteral::render(false, PDO::PARAM_BOOL));
        $this->assertSame('1', SqlLiteral::render(true, PDO::PARAM_BOOL));
        $this->assertSame("'42'", SqlLiteral::render(42, PDO::PARAM_STR));
        $this->assertSame("''", SqlLiteral::render('', PDO::PARAM_STR));
    }

    public function testFormatterShapesRows()
    {
        $columns = [
            ['name' => 'Id', 'native_type' => 'INTEGER', 'precision' => 10, 'scale' => 0],
            ['name' => 'Name', 'native_type' => 'VARCHAR', 'precision' => 20, 'scale' => 0],
        ];
        $values = [
            RowFormatter::convert('7', $columns[0], false, PDO::NULL_NATURAL),
            RowFormatter::convert('Ann', $columns[1], false, PDO::NULL_NATURAL),
        ];
        $this->assertSame(7, $values[0]);

        $assoc = RowFormatter::present($columns, $values, PDO::FETCH_ASSOC, ['case' => PDO::CASE_LOWER]);
        $this->assertSame(['id' => 7, 'name' => 'Ann'], $assoc);

        $grouped = [];
        $key = null;
        $row = RowFormatter::present($columns, $values, PDO::FETCH_ASSOC | PDO::FETCH_GROUP, ['case' => PDO::CASE_LOWER], true, $key);
        $grouped[$key][] = $row;
        $this->assertSame('7', $key);
        $this->assertSame(['name' => 'Ann'], $grouped['7'][0]);
    }

    public function testDsnRejectsOtherDrivers()
    {
        $cases = [
            '' => 'invalid data source name',
            'odbc:' => 'invalid data source name',
            'sqlsrv:host=localhost' => 'could not find driver',
        ];
        foreach ($cases as $dsn => $needle) {
            try {
                new PDO($dsn);
                $this->fail('DSN must fail: ' . $dsn);
            } catch (PDOException $exception) {
                $this->assertStringContainsString($needle, $exception->getMessage());
            }
        }
    }

    public function testMissingOdbcDsnIsAnError()
    {
        if (!extension_loaded('odbc')) {
            $this->markTestSkipped('ext-odbc is not loaded');
        }
        try {
            new PDO('odbc:DSN=phppdo_missing_dsn_zz', '', '');
            $this->fail('unknown ODBC DSN must fail');
        } catch (PDOException $exception) {
            $this->assertNotSame('00000', $exception->getCode());
        }
    }
}
