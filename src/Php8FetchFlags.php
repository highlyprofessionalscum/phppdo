<?php

namespace PhpPdo;

/**
 * Reference fetch-mode flag values from ext-pdo on PHP 8.x (see docs/PHP8.md).
 * phpPdo keeps PHP 7.4 values on PhpPdo\PDO.
 */
final class Php8FetchFlags
{
    public const FETCH_GROUP = 524288;
    public const FETCH_UNIQUE = 983040;
    public const FETCH_CLASSTYPE = 786432;
    public const FETCH_SERIALIZE = 1048576;
    public const FETCH_PROPS_LATE = 2097152;

    private const PHP74_TO_PHP8 = [
        PDO::FETCH_GROUP => self::FETCH_GROUP,
        PDO::FETCH_UNIQUE => self::FETCH_UNIQUE,
        PDO::FETCH_CLASSTYPE => self::FETCH_CLASSTYPE,
        PDO::FETCH_SERIALIZE => self::FETCH_SERIALIZE,
        PDO::FETCH_PROPS_LATE => self::FETCH_PROPS_LATE,
    ];

    /**
     * Map a fetch mode integer that uses PHP 8 ext-pdo flag bits to phpPdo (PHP 7.4) bits.
     */
    public static function toPhp74FetchMode($mode)
    {
        $mode = (int) $mode;
        $type = Flags::fetchType($mode);
        $flags = Flags::fetchFlags($mode);
        $mapped = 0;
        foreach (self::PHP74_TO_PHP8 as $php74 => $php8) {
            if (($flags & $php8) === $php8) {
                $flags = ($flags & ~$php8) | $php74;
            }
        }
        return $type | $mapped;
    }

    /**
     * Map phpPdo (PHP 7.4) fetch flags to PHP 8 ext-pdo flag bits.
     */
    public static function toPhp8FetchMode($mode)
    {
        $mode = (int) $mode;
        $type = Flags::fetchType($mode);
        $flags = Flags::fetchFlags($mode);
        foreach (self::PHP74_TO_PHP8 as $php74 => $php8) {
            if (($flags & $php74) === $php74) {
                $flags = ($flags & ~$php74) | $php8;
            }
        }
        return $type | $flags;
    }
}
