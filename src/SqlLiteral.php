<?php

namespace PhpPdo;

/**
 * Литералы SQL-92 для эмуляции prepare и PDO::quote.
 * Строка заключается в апострофы, апостроф удваивается.
 */
final class SqlLiteral
{
    public static function render($value, $type)
    {
        $base = Flags::paramBase($type);
        if ($value === null || $base === PDO::PARAM_NULL) {
            return 'NULL';
        }
        if (is_resource($value)) {
            $read = stream_get_contents($value);
            $value = $read === false ? '' : $read;
        }
        if ($base === PDO::PARAM_INT) {
            return (string) (int) $value;
        }
        if ($base === PDO::PARAM_BOOL) {
            return self::asBool($value) ? '1' : '0';
        }
        return "'" . str_replace("'", "''", self::asString($value)) . "'";
    }

    public static function formatFloat($value)
    {
        if (is_nan($value) || is_infinite($value)) {
            return '0';
        }
        $formatted = rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');
        if ($formatted === '' || $formatted === '-' || $formatted === '-0') {
            return '0';
        }
        return $formatted;
    }

    public static function asString($value)
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return self::formatFloat($value);
        }
        if (is_resource($value)) {
            $read = stream_get_contents($value);
            return $read === false ? '' : $read;
        }
        if ($value === null) {
            return '';
        }
        return (string) $value;
    }

    private static function asBool($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return $value !== '' && $value !== '0';
        }
        return (bool) $value;
    }
}
