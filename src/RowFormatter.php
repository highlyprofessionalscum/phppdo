<?php

namespace PhpPdo;

/**
 * Приведение значений ODBC и сборка строки в режиме выборки PDO.
 */
final class RowFormatter
{
    /**
     * @param mixed $raw
     * @param array<string,mixed> $column
     * @param bool $stringify
     * @param int $nullMode
     * @return mixed
     */
    public static function convert($raw, array $column, $stringify, $nullMode)
    {
        if ($raw === false) {
            $raw = null;
        }
        if ($raw === '' && (int) $nullMode === PDO::NULL_EMPTY_STRING) {
            $raw = null;
        }
        if ($raw === null) {
            if ((int) $nullMode === PDO::NULL_TO_STRING) {
                return '';
            }
            return null;
        }

        $string = is_string($raw) ? $raw : SqlLiteral::asString($raw);
        if ($stringify) {
            return $string;
        }

        $native = strtoupper((string) $column['native_type']);
        if (self::isBoolType($native)) {
            return $string === '1' || $string === 't' || $string === 'T' || strcasecmp($string, 'true') === 0;
        }
        if (self::isIntType($native)) {
            if (preg_match('/^-?\d+$/', $string) && self::fitsInt($string)) {
                return (int) $string;
            }
            return $string;
        }
        if (self::isFloatType($native)) {
            $scale = isset($column['scale']) ? (int) $column['scale'] : 0;
            if ($scale === 0 && preg_match('/^-?\d+$/', $string) && self::fitsInt($string)) {
                return (int) $string;
            }
            if (in_array($native, ['DECIMAL', 'NUMERIC', 'NUMBER', 'CURRENCY'], true)) {
                return $string;
            }
            return (float) $string;
        }
        return $string;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function castBound($value, $type)
    {
        if ($value === null) {
            return null;
        }
        $base = Flags::paramBase($type);
        if ($base === PDO::PARAM_INT) {
            return (int) $value;
        }
        if ($base === PDO::PARAM_BOOL) {
            return (bool) $value;
        }
        if ($base === PDO::PARAM_NULL) {
            return null;
        }
        if ($base === PDO::PARAM_STR || $base === PDO::PARAM_LOB) {
            return is_string($value) ? $value : SqlLiteral::asString($value);
        }
        return $value;
    }

    /**
     * @param array<int,array<string,mixed>> $columns
     * @param array<int,mixed> $values
     * @param int $mode
     * @param array<string,mixed> $spec
     * @param bool $withGroup
     * @param string|null $groupKey
     * @return mixed
     */
    public static function present(array $columns, array $values, $mode, array $spec, $withGroup = false, &$groupKey = null)
    {
        $type = Flags::fetchType($mode);
        $flags = Flags::fetchFlags($mode);
        if ($type === 0) {
            $type = PDO::FETCH_BOTH;
        }

        $grouped = (bool) $withGroup;
        $index = 0;
        $class = isset($spec['class']) ? $spec['class'] : null;
        $ctor = isset($spec['ctor']) ? $spec['ctor'] : [];
        $late = ($flags & PDO::FETCH_PROPS_LATE) === PDO::FETCH_PROPS_LATE;

        if ($type === PDO::FETCH_CLASS && ($flags & PDO::FETCH_CLASSTYPE) === PDO::FETCH_CLASSTYPE) {
            $className = isset($values[$index]) && $values[$index] !== null ? rtrim((string) $values[$index]) : '';
            $index++;
            if ($className !== '' && class_exists($className)) {
                $class = $className;
            } elseif (!is_string($class) || $class === '' || !class_exists($class)) {
                $class = 'stdClass';
            }
        }

        if ($grouped) {
            $groupKey = isset($values[$index]) && $values[$index] !== null ? (string) $values[$index] : '';
            $index++;
        } else {
            $groupKey = null;
        }

        if ($type === PDO::FETCH_COLUMN) {
            $column = isset($spec['column']) ? (int) $spec['column'] : 0;
            if ($grouped && $column < 0) {
                $column = $index;
            }
            if (!array_key_exists($column, $values)) {
                throw new PDOException('SQLSTATE[HY000]: Invalid column index', 'HY000', null, ['HY000', null, 'Invalid column index']);
            }
            return $values[$column];
        }

        if ($type === PDO::FETCH_KEY_PAIR) {
            if (count($values) !== 2) {
                throw new PDOException(
                    'SQLSTATE[HY000]: PDO::FETCH_KEY_PAIR fetch mode requires the result set to contain exactly 2 columns.',
                    'HY000',
                    null,
                    ['HY000', null, 'PDO::FETCH_KEY_PAIR fetch mode requires the result set to contain exactly 2 columns.']
                );
            }
            $key = $values[0] === null ? '' : (string) $values[0];
            return [$key => $values[1]];
        }

        if ($type === PDO::FETCH_FUNC) {
            $args = array_slice($values, $index);
            $func = isset($spec['func']) ? $spec['func'] : null;
            if (!is_callable($func)) {
                throw new PDOException('SQLSTATE[HY000]: No fetch function specified', 'HY000', null, ['HY000', null, 'No fetch function specified']);
            }
            return call_user_func_array($func, $args);
        }

        if ($type === PDO::FETCH_LAZY) {
            $assoc = [];
            $names = self::names($columns, isset($spec['case']) ? $spec['case'] : PDO::CASE_NATURAL);
            for ($cursor = $index; $cursor < count($values); $cursor++) {
                $assoc[$names[$cursor]] = $values[$cursor];
            }
            $query = isset($spec['query']) ? $spec['query'] : '';
            if (isset($spec['lazy']) && $spec['lazy'] instanceof PDORow) {
                return $spec['lazy']->refill($query, $assoc);
            }
            return PDORow::fromFetched($query, $assoc);
        }

        $names = self::names($columns, isset($spec['case']) ? $spec['case'] : PDO::CASE_NATURAL);

        if ($type === PDO::FETCH_CLASS && ($flags & PDO::FETCH_SERIALIZE) === PDO::FETCH_SERIALIZE) {
            trigger_error('PDO::FETCH_SERIALIZE is deprecated', E_USER_DEPRECATED);
            $payload = isset($values[$index]) ? (string) $values[$index] : '';
            if (!is_string($class) || !class_exists($class)) {
                throw new PDOException('SQLSTATE[HY000]: cannot unserialize class', 'HY000', null, ['HY000', null, 'cannot unserialize class']);
            }
            $reflection = new \ReflectionClass($class);
            $object = $reflection->newInstanceWithoutConstructor();
            if ($object instanceof \Serializable) {
                $object->unserialize($payload);
                return $object;
            }
            throw new PDOException('SQLSTATE[HY000]: cannot unserialize class', 'HY000', null, ['HY000', null, 'cannot unserialize class']);
        }

        if ($type === PDO::FETCH_NUM) {
            $row = [];
            for ($cursor = $index; $cursor < count($values); $cursor++) {
                $row[] = $values[$cursor];
            }
            return $row;
        }

        if ($type === PDO::FETCH_ASSOC || $type === PDO::FETCH_NAMED || $type === PDO::FETCH_BOTH) {
            $row = [];
            for ($cursor = $index; $cursor < count($values); $cursor++) {
                self::putNamed($row, $names[$cursor], $values[$cursor], $type === PDO::FETCH_NAMED);
                if ($type === PDO::FETCH_BOTH) {
                    $row[$cursor] = $values[$cursor];
                }
            }
            return $row;
        }

        if ($type === PDO::FETCH_INTO) {
            if (!isset($spec['into']) || !is_object($spec['into'])) {
                throw new PDOException('SQLSTATE[HY000]: No fetch-into object specified.', 'HY000', null, ['HY000', null, 'No fetch-into object specified.']);
            }
            $object = $spec['into'];
            for ($cursor = $index; $cursor < count($values); $cursor++) {
                $object->{$names[$cursor]} = $values[$cursor];
            }
            return $object;
        }

        if ($type === PDO::FETCH_OBJ || $type === PDO::FETCH_CLASS) {
            $className = $type === PDO::FETCH_OBJ ? 'stdClass' : $class;
            if (!is_string($className) || $className === '' || !class_exists($className)) {
                if ($type === PDO::FETCH_CLASS) {
                    throw new PDOException('SQLSTATE[HY000]: No fetch class specified', 'HY000', null, ['HY000', null, 'No fetch class specified']);
                }
                $className = 'stdClass';
            }
            $reflection = new \ReflectionClass($className);
            $object = $reflection->newInstanceWithoutConstructor();
            if ($late && $reflection->getConstructor() !== null) {
                $constructor = $reflection->getConstructor();
                $constructor->setAccessible(true);
                $constructor->invokeArgs($object, $ctor);
            }

            $props = [];
            for ($cursor = $index; $cursor < count($values); $cursor++) {
                $props[$names[$cursor]] = $values[$cursor];
            }
            if ($type === PDO::FETCH_CLASS && !$late) {
                self::assignProps($object, $props);
                self::callCtor($object, $ctor);
            } else {
                self::assignProps($object, $props);
            }
            return $object;
        }

        if ($type === PDO::FETCH_BOUND) {
            return true;
        }

        throw new PDOException('SQLSTATE[HY000]: unsupported fetch mode', 'HY000', null, ['HY000', null, 'unsupported fetch mode']);
    }

    /**
     * @param array<string,mixed> $row
     * @param mixed $value
     */
    private static function putNamed(array &$row, $name, $value, $named)
    {
        if (!$named || !array_key_exists($name, $row)) {
            $row[$name] = $value;
            return;
        }
        if (!is_array($row[$name]) || self::isList($row[$name]) === false) {
            $row[$name] = [$row[$name]];
        }
        $row[$name][] = $value;
    }

    private static function isList(array $value)
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }

    /**
     * @param array<int,array<string,mixed>> $columns
     * @return array<int,string>
     */
    private static function names(array $columns, $case)
    {
        $names = [];
        foreach ($columns as $index => $column) {
            $name = (string) $column['name'];
            if ((int) $case === PDO::CASE_UPPER) {
                $name = strtoupper($name);
            } elseif ((int) $case === PDO::CASE_LOWER) {
                $name = strtolower($name);
            }
            $names[$index] = $name;
        }
        return $names;
    }

    /**
     * @param array<string,mixed> $props
     */
    private static function assignProps($object, array $props)
    {
        $reflection = new \ReflectionObject($object);
        foreach ($props as $name => $value) {
            if ($reflection->hasProperty($name)) {
                $property = $reflection->getProperty($name);
                $property->setAccessible(true);
                $property->setValue($object, $value);
                continue;
            }
            $object->{$name} = $value;
        }
    }

    private static function callCtor($object, array $ctor)
    {
        $reflection = new \ReflectionObject($object);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return;
        }
        $constructor->setAccessible(true);
        $constructor->invokeArgs($object, $ctor);
    }

    private static function isIntType($native)
    {
        return in_array($native, ['INTEGER', 'LONG', 'SHORT', 'TINYINT', 'BIGINT', 'INT', 'SMALLINT', 'COUNTER', 'SERIAL'], true);
    }

    private static function isBoolType($native)
    {
        return in_array($native, ['BIT', 'BOOLEAN', 'BOOL'], true);
    }

    private static function isFloatType($native)
    {
        return in_array($native, ['DOUBLE', 'FLOAT', 'REAL', 'DECIMAL', 'NUMERIC', 'NUMBER', 'CURRENCY'], true);
    }

    private static function fitsInt($string)
    {
        if (PHP_INT_SIZE < 8) {
            $limit = '2147483647';
            $negative = '2147483648';
        } else {
            $limit = '9223372036854775807';
            $negative = '9223372036854775808';
        }
        $digits = $string[0] === '-' ? substr($string, 1) : $string;
        $max = $string[0] === '-' ? $negative : $limit;
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return true;
        }
        if (strlen($digits) !== strlen($max)) {
            return strlen($digits) < strlen($max);
        }
        return strcmp($digits, $max) <= 0;
    }
}
