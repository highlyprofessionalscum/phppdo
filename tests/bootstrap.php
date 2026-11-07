<?php

require dirname(__DIR__) . '/src/bootstrap.php';

function assert_same($expected, $actual, $message = '')
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message !== '' ? $message . ': ' : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function assert_true($condition, $message = 'expected true')
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
