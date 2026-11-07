<?php

use PhpPdo\PDO;
use PhpPdo\RowFormatter;

class FormatterProbe
{
    public $Id;
    public $Name;
    public $tag;
    public $constructedAfterProps = false;
    public $constructedBeforeProps = false;

    public function __construct($tag)
    {
        $this->tag = $tag;
        $this->constructedAfterProps = $this->Id !== null;
        $this->constructedBeforeProps = $this->Id === null;
    }
}

return function () {
    $columns = [
        ['name' => 'Id', 'native_type' => 'INTEGER', 'precision' => 10, 'scale' => 0],
        ['name' => 'Name', 'native_type' => 'VARCHAR', 'precision' => 20, 'scale' => 0],
        ['name' => 'Name', 'native_type' => 'VARCHAR', 'precision' => 20, 'scale' => 0],
    ];
    $values = [
        RowFormatter::convert('7', $columns[0], false, PDO::NULL_NATURAL),
        RowFormatter::convert('Ann', $columns[1], false, PDO::NULL_NATURAL),
        RowFormatter::convert('', $columns[2], false, PDO::NULL_EMPTY_STRING),
    ];
    assert_same(7, $values[0]);
    assert_same(null, $values[2]);

    $assoc = RowFormatter::present($columns, [$values[0], $values[1]], PDO::FETCH_ASSOC, ['case' => PDO::CASE_LOWER]);
    assert_same(['id' => 7, 'name' => 'Ann'], $assoc);

    $both = RowFormatter::present($columns, [$values[0], $values[1]], PDO::FETCH_BOTH, ['case' => PDO::CASE_NATURAL]);
    assert_same(7, $both['Id']);
    assert_same(7, $both[0]);
    assert_same('Ann', $both[1]);

    $named = RowFormatter::present($columns, $values, PDO::FETCH_NAMED, ['case' => PDO::CASE_NATURAL]);
    assert_same(['Ann', null], $named['Name']);

    $groupKey = null;
    $grouped = RowFormatter::present(
        $columns,
        [$values[0], $values[1]],
        PDO::FETCH_ASSOC | PDO::FETCH_GROUP,
        ['case' => PDO::CASE_NATURAL],
        true,
        $groupKey
    );
    assert_same('7', $groupKey);
    assert_same(['Name' => 'Ann'], $grouped);

    $pair = RowFormatter::present($columns, [$values[0], $values[1]], PDO::FETCH_KEY_PAIR, []);
    assert_same(['7' => 'Ann'], $pair);

    $object = RowFormatter::present($columns, [$values[0], $values[1]], PDO::FETCH_CLASS, [
        'class' => FormatterProbe::class,
        'ctor' => ['from-ctor'],
        'case' => PDO::CASE_NATURAL,
    ]);
    assert_same(7, $object->Id);
    assert_same('Ann', $object->Name);
    assert_same('from-ctor', $object->tag);
    assert_true($object->constructedAfterProps);

    $late = RowFormatter::present(
        $columns,
        [$values[0], $values[1]],
        PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE,
        [
            'class' => FormatterProbe::class,
            'ctor' => ['late'],
            'case' => PDO::CASE_NATURAL,
        ]
    );
    assert_same(7, $late->Id);
    assert_same('late', $late->tag);
    assert_true($late->constructedBeforeProps);
    assert_true($late->constructedAfterProps === false);
};
