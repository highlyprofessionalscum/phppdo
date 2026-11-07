<?php

use PhpPdo\SqlParser;

return function () {
    $named = SqlParser::parse('SELECT * FROM t WHERE id = :id AND name = :name AND id = :id');
    assert_same(null, $named['error']);
    assert_same('SELECT * FROM t WHERE id = ? AND name = ? AND id = ?', $named['nativeSql']);
    assert_same('id', $named['placeholders'][0]['name']);
    assert_same('name', $named['placeholders'][1]['name']);
    assert_same('id', $named['placeholders'][2]['name']);

    $positional = SqlParser::parse('SELECT * FROM t WHERE a = ? AND b = ?? AND c = ?');
    assert_same(null, $positional['error']);
    assert_same('SELECT * FROM t WHERE a = ? AND b = ? AND c = ?', $positional['nativeSql']);
    assert_same(2, count($positional['placeholders']));

    $quoted = SqlParser::parse("SELECT ':name', \"?\" FROM t WHERE note = 'it''s :fine'");
    assert_same(null, $quoted['error']);
    assert_same([], $quoted['placeholders']);

    $comment = SqlParser::parse("SELECT 1 -- :hidden\n, :shown");
    assert_same(null, $comment['error']);
    assert_same(1, count($comment['placeholders']));
    assert_same('shown', $comment['placeholders'][0]['name']);

    $block = SqlParser::parse('SELECT /* :no */ :yes');
    assert_same('yes', $block['placeholders'][0]['name']);
    assert_same(1, count($block['placeholders']));

    $cast = SqlParser::parse('SELECT id::int, :id');
    assert_same(1, count($cast['placeholders']));
    assert_same('SELECT id::int, ?', $cast['nativeSql']);

    $mixed = SqlParser::parse('SELECT :name, ?');
    assert_same('mixed named and positional parameters', $mixed['error']);

    $identifier = SqlParser::parse('SELECT a:b, :name');
    assert_same(1, count($identifier['placeholders']));
    assert_same('name', $identifier['placeholders'][0]['name']);
};
