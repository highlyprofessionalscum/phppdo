<?php

namespace PhpPdo;

/**
 * Строка ленивой выборки PDO::FETCH_LAZY.
 * Числовой ключ — номер столбца, остальные — имена столбцов.
 */
class PDORow implements \ArrayAccess
{
    /** @var string */
    public $queryString = '';

    /** @var array<string,mixed> */
    private $values = [];

    public function __construct()
    {
        throw new PDOException('You may not create a PDORow manually');
    }

    /**
     * @param array<string,mixed> $values
     * @return self
     */
    public static function fromFetched($queryString, array $values)
    {
        $reflection = new \ReflectionClass(self::class);
        $row = $reflection->newInstanceWithoutConstructor();
        return $row->refill($queryString, $values);
    }

    /**
     * @param array<string,mixed> $values
     * @return self
     */
    public function refill($queryString, array $values)
    {
        $this->queryString = (string) $queryString;
        $this->values = $values;
        return $this;
    }

    public function __debugInfo()
    {
        return ['queryString' => $this->queryString] + $this->values;
    }

    public function __get($name)
    {
        $found = false;
        $value = $this->column($name, $found);
        if (!$found) {
            trigger_error('Undefined property: PDORow::$' . $name, E_USER_NOTICE);
            return null;
        }
        return $value;
    }

    public function __isset($name)
    {
        $found = false;
        $value = $this->column($name, $found);
        return $found && $value !== null;
    }

    public function __set($name, $value)
    {
        unset($name, $value);
        throw new \Error('Cannot write to PDORow property');
    }

    public function __unset($name)
    {
        unset($name);
        throw new \Error('Cannot unset PDORow property');
    }

    public function offsetExists($offset)
    {
        $found = false;
        $value = $this->column($offset, $found);
        return $found && $value !== null;
    }

    public function offsetGet($offset)
    {
        if ($offset === null) {
            throw new \Error('Cannot append to PDORow offset');
        }
        $found = false;
        return $this->column($offset, $found);
    }

    public function offsetSet($offset, $value)
    {
        unset($value);
        if ($offset === null) {
            throw new \Error('Cannot append to PDORow offset');
        }
        throw new \Error('Cannot write to PDORow offset');
    }

    public function offsetUnset($offset)
    {
        unset($offset);
        throw new \Error('Cannot unset PDORow offset');
    }

    /**
     * @param mixed $offset
     * @param bool $found
     * @return mixed
     */
    private function column($offset, &$found)
    {
        $found = false;
        if (is_int($offset) || (is_string($offset) && preg_match('/^-?\d+$/', $offset) === 1)) {
            $index = (int) $offset;
            $values = array_values($this->values);
            if ($index < 0 || !array_key_exists($index, $values)) {
                return null;
            }
            $found = true;
            return $values[$index];
        }
        $name = (string) $offset;
        if (!array_key_exists($name, $this->values)) {
            return null;
        }
        $found = true;
        return $this->values[$name];
    }
}
