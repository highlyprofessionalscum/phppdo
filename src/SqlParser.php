<?php

namespace PhpPdo;

/**
 * Разбор плейсхолдеров SQL в стиле PDO.
 * Именованные параметры переписываются в позиционные «?», как это делает pdo_odbc.
 */
final class SqlParser
{
    /**
     * @param string $sql
     * @return array{nativeSql:string,parts:array<int,array>,placeholders:array<int,array{kind:string,name:?string}>,error:?string}
     */
    public static function parse($sql)
    {
        $sql = (string) $sql;
        $length = strlen($sql);
        $index = 0;
        $text = '';
        $parts = [];
        $placeholders = [];
        $sawNamed = false;
        $sawPositional = false;

        $pushText = function () use (&$text, &$parts) {
            if ($text !== '') {
                $parts[] = ['t', $text];
                $text = '';
            }
        };

        while ($index < $length) {
            $char = $sql[$index];

            if ($char === "'") {
                $text .= self::copyQuoted($sql, $length, $index, "'");
                continue;
            }
            if ($char === '"') {
                $text .= self::copyQuoted($sql, $length, $index, '"');
                continue;
            }
            if ($char === '[') {
                $text .= self::copyBracket($sql, $length, $index);
                continue;
            }
            if ($char === '-' && $index + 1 < $length && $sql[$index + 1] === '-') {
                $text .= self::copyLineComment($sql, $length, $index);
                continue;
            }
            if ($char === '/' && $index + 1 < $length && $sql[$index + 1] === '*') {
                $text .= self::copyBlockComment($sql, $length, $index);
                continue;
            }

            if ($char === '?') {
                if ($index + 1 < $length && $sql[$index + 1] === '?') {
                    $text .= '?';
                    $index += 2;
                    continue;
                }
                $sawPositional = true;
                $pushText();
                $placeholders[] = ['kind' => 'positional', 'name' => null];
                $parts[] = ['p', count($placeholders) - 1];
                $index++;
                continue;
            }

            if ($char === ':') {
                if ($index + 1 < $length && $sql[$index + 1] === ':') {
                    $text .= '::';
                    $index += 2;
                    continue;
                }
                $previous = $index > 0 ? $sql[$index - 1] : '';
                if ($previous !== '' && ctype_alnum($previous)) {
                    $text .= $char;
                    $index++;
                    continue;
                }
                if ($index + 1 < $length && preg_match('/\G([A-Za-z_][A-Za-z0-9_]*)/', $sql, $match, 0, $index + 1)) {
                    $sawNamed = true;
                    $pushText();
                    $placeholders[] = ['kind' => 'named', 'name' => $match[1]];
                    $parts[] = ['p', count($placeholders) - 1];
                    $index += 1 + strlen($match[1]);
                    continue;
                }
            }

            $text .= $char;
            $index++;
        }

        $pushText();

        if ($sawNamed && $sawPositional) {
            return [
                'nativeSql' => $sql,
                'parts' => [],
                'placeholders' => [],
                'error' => 'mixed named and positional parameters',
            ];
        }

        $nativeSql = '';
        foreach ($parts as $part) {
            $nativeSql .= $part[0] === 't' ? $part[1] : '?';
        }

        return [
            'nativeSql' => $nativeSql,
            'parts' => $parts,
            'placeholders' => $placeholders,
            'error' => null,
        ];
    }

    /**
     * Плейсхолдеры PostgreSQL: $1, $2. Литерал из «??» остаётся знаком вопроса.
     *
     * @param array<int,array> $parts
     */
    public static function dollars(array $parts)
    {
        $sql = '';
        $number = 0;
        foreach ($parts as $part) {
            if ($part[0] === 't') {
                $sql .= $part[1];
                continue;
            }
            $number++;
            $sql .= '$' . $number;
        }
        return $sql;
    }

    private static function copyQuoted($sql, $length, &$index, $quote)
    {
        $out = $sql[$index];
        $index++;
        while ($index < $length) {
            $out .= $sql[$index];
            if ($sql[$index] === $quote) {
                if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                    $out .= $quote;
                    $index += 2;
                    continue;
                }
                $index++;
                break;
            }
            $index++;
        }
        return $out;
    }

    private static function copyBracket($sql, $length, &$index)
    {
        $out = $sql[$index];
        $index++;
        while ($index < $length) {
            $out .= $sql[$index];
            if ($sql[$index] === ']') {
                $index++;
                break;
            }
            $index++;
        }
        return $out;
    }

    private static function copyLineComment($sql, $length, &$index)
    {
        $out = '';
        while ($index < $length) {
            $out .= $sql[$index];
            if ($sql[$index] === "\n") {
                $index++;
                break;
            }
            $index++;
        }
        return $out;
    }

    private static function copyBlockComment($sql, $length, &$index)
    {
        $out = '';
        while ($index < $length) {
            $out .= $sql[$index];
            if ($sql[$index] === '/' && $index > 0 && isset($sql[$index - 1]) && $sql[$index - 1] === '*') {
                $index++;
                break;
            }
            $index++;
        }
        return $out;
    }
}
