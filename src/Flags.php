<?php

namespace PhpPdo;

/**
 * Маски PHP 7.4. В PHP 8 флаги выборки сдвинуты, здесь остаются значения 7.4.
 */
final class Flags
{
    const FETCH_TYPE = 0xFFFF;

    public static function paramBase($type)
    {
        return ((int) $type) & 0xFFFF;
    }

    public static function fetchType($mode)
    {
        return ((int) $mode) & self::FETCH_TYPE;
    }

    public static function fetchFlags($mode)
    {
        return ((int) $mode) & ~self::FETCH_TYPE;
    }

    public static function isUnique($flags)
    {
        return ($flags & PDO::FETCH_UNIQUE) === PDO::FETCH_UNIQUE;
    }

    public static function isGroup($flags)
    {
        return ($flags & PDO::FETCH_GROUP) === PDO::FETCH_GROUP;
    }
}
