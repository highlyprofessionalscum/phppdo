<?php

namespace PhpPdo;

/**
 * Разбор хвоста DSN mysql:/pgsql: (ключ=значение через точку с запятой).
 */
final class DsnParts
{
    /**
     * @return array<string,string>
     */
    public static function parse($dsn)
    {
        $parts = array();
        foreach (explode(';', (string) $dsn) as $piece) {
            if ($piece === '') {
                continue;
            }
            $eq = strpos($piece, '=');
            if ($eq === false) {
                continue;
            }
            $parts[strtolower(trim(substr($piece, 0, $eq)))] = trim(substr($piece, $eq + 1));
        }
        return $parts;
    }
}
