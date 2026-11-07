<?php

namespace PhpPdo;

class PDOStatement implements \IteratorAggregate
{
    /** @var string */
    public $queryString = '';

    /** @var PDO */
    private $pdo;

    /** @var string */
    private $nativeSql = '';

    /** @var array<int,array> */
    private $parts = [];

    /** @var array<int,array{kind:string,name:?string}> */
    private $placeholders = [];

    /** @var OdbcStatement|MysqlStatement|null */
    private $result;

    /** @var bool */
    private $emulate = true;

    /** @var int */
    private $cursor = PDO::CURSOR_FWDONLY;

    /** @var array<int|string,array<string,mixed>> */
    private $params = [];

    /** @var array<int|string,array<string,mixed>> */
    private $boundColumns = [];

    /** @var array<int,array<string,mixed>> */
    private $columns = [];

    /** @var bool */
    private $executed = false;

    /** @var int|null */
    private $fetchMode;

    /** @var string|null */
    private $fetchClass;

    /** @var array<int,mixed> */
    private $fetchCtor = [];

    /** @var object|null */
    private $fetchInto;

    /** @var callable|null */
    private $fetchFunc;

    /** @var int */
    private $fetchColumn = 0;

    /** @var string|null */
    private $errorCode;

    /** @var array<int,mixed> */
    private $errorInfo = [null, null, null];

    /** @var int */
    private $rowCount = 0;

    /** @var array<int,array<int,mixed>>|null */
    private $scroll;

    /** @var int */
    private $scrollPos = -1;

    /** @var bool */
    private $inFetch = false;

    /** @var PDORow|null */
    private $lazyRow;

    /** @var int */
    private $iterIndex = -1;

    /** @var mixed */
    private $iterRow = false;

    /** @var bool */
    private $iterStarted = false;

    /** @var string|null */
    private $sentSql;

    public function __construct()
    {
        if (get_class($this) === __CLASS__) {
            throw new PDOException('PDOStatement::__construct(): You cannot create a PDOStatement object directly');
        }
    }

    /**
     * @param array{nativeSql:string,parts:array,placeholders:array,error:?string} $parsed
     * @param array<int|string,mixed> $options
     * @return self
     */
    public static function create(PDO $pdo, $sql, array $parsed, $class, array $ctorArgs, array $options, $nativeHandle = null, $emulate = null)
    {
        $reflection = new \ReflectionClass($class);
        /** @var self $statement */
        $statement = $reflection->newInstanceWithoutConstructor();
        $statement->boot($pdo, $sql, $parsed, $options, $nativeHandle, $emulate);
        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getDeclaringClass()->getName() !== __CLASS__) {
            $constructor->setAccessible(true);
            $constructor->invokeArgs($statement, $ctorArgs);
        }
        return $statement;
    }

    /**
     * @param OdbcStatement $result
     * @param array<int,mixed> $ctorArgs
     * @return self
     */
    public static function fromExecuted(PDO $pdo, $sql, $result, $class, array $ctorArgs, array $options = [])
    {
        $parsed = SqlParser::parse($sql);
        if ($parsed['error'] !== null) {
            $parsed = [
                'nativeSql' => (string) $sql,
                'parts' => [['t', (string) $sql]],
                'placeholders' => [],
                'error' => null,
            ];
        }
        $statement = self::create($pdo, $sql, $parsed, $class, $ctorArgs, $options, null, true);
        $statement->acceptResult($result);
        $statement->executed = true;
        $statement->clearError();
        return $statement;
    }

    /**
     * @param array{nativeSql:string,parts:array,placeholders:array,error:?string} $parsed
     * @param array<int|string,mixed> $options
     */
    private function boot(PDO $pdo, $sql, array $parsed, array $options, $nativeHandle = null, $emulate = null)
    {
        $this->pdo = $pdo;
        $this->queryString = (string) $sql;
        $this->nativeSql = $parsed['nativeSql'];
        $this->parts = $parsed['parts'];
        $this->placeholders = $parsed['placeholders'];
        $this->emulate = $emulate === null ? (bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) : (bool) $emulate;
        $this->cursor = PDO::CURSOR_FWDONLY;
        if (isset($options[PDO::ATTR_CURSOR])) {
            $this->cursor = (int) $options[PDO::ATTR_CURSOR];
        }
        if (self::isCursor($nativeHandle)) {
            $this->result = $nativeHandle;
        }
    }

    public function bindColumn($column, &$var, $type = PDO::PARAM_STR, $maxLength = 0, $driverOptions = null)
    {
        unset($maxLength, $driverOptions);
        if (is_int($column) || (is_string($column) && ctype_digit($column))) {
            $key = (int) $column;
            if ($key < 1) {
                return $this->fail('HY000', 'Invalid column index');
            }
        } else {
            $key = (string) $column;
            if ($key === '') {
                return $this->fail('HY000', 'Invalid column name');
            }
        }
        $this->boundColumns[$key] = ['type' => (int) $type];
        $this->boundColumns[$key]['var'] = &$var;
        return true;
    }

    public function bindParam($parameter, &$variable, $type = PDO::PARAM_STR, $maxLength = 0, $driverOptions = null)
    {
        unset($driverOptions);
        $key = $this->parameterKey($parameter);
        if ($key === null) {
            return false;
        }
        $this->params[$key] = [
            'type' => (int) $type,
            'maxlen' => (int) $maxLength,
        ];
        $this->params[$key]['value'] = &$variable;
        return true;
    }

    public function bindValue($parameter, $value, $type = PDO::PARAM_STR)
    {
        $key = $this->parameterKey($parameter);
        if ($key === null) {
            return false;
        }
        $this->params[$key] = [
            'type' => (int) $type,
            'maxlen' => 0,
            'value' => $value,
        ];
        return true;
    }

    public function closeCursor()
    {
        $this->freeResult();
        $this->columns = [];
        $this->resetIterator();
        $this->clearError();
        return true;
    }

    public function columnCount()
    {
        return count($this->columns);
    }

    public function debugDumpParams()
    {
        echo 'SQL: [' . strlen($this->queryString) . '] ' . $this->queryString . "\n";
        if ($this->sentSql !== null) {
            echo 'Sent SQL: [' . strlen($this->sentSql) . '] ' . $this->sentSql . "\n";
        }
        echo 'Params:  ' . count($this->params) . "\n";
        $number = 0;
        $paramno = $this->sentSql !== null ? -1 : 0;
        foreach ($this->params as $key => $param) {
            $name = is_int($key) ? (string) $key : (string) $key;
            $shown = is_int($key) ? $name : ':' . ltrim($name, ':');
            echo 'Key: Name: [' . strlen($shown) . '] ' . $shown . "\n";
            echo 'paramno=' . ($this->sentSql !== null ? -1 : $paramno) . "\n";
            echo 'name=[' . strlen($shown) . '] "' . $shown . "\"\n";
            echo 'is_param=1' . "\n";
            echo 'param_type=' . (isset($param['type']) ? (int) $param['type'] : PDO::PARAM_STR) . "\n";
            $number++;
            $paramno++;
        }
    }

    public function errorCode()
    {
        return $this->errorCode;
    }

    public function errorInfo()
    {
        return $this->errorInfo;
    }

    public function execute(array $params = null)
    {
        $this->columns = [];
        $this->scroll = null;
        $this->scrollPos = -1;
        $this->rowCount = 0;
        $this->lazyRow = null;
        $this->sentSql = null;
        $this->resetIterator();
        if ($this->emulate) {
            $this->freeResult();
        }

        $ordered = $this->resolveParams($params);
        if ($ordered === null) {
            return false;
        }

        if ($this->emulate) {
            $sql = $this->buildEmulatedSql($ordered);
            $this->sentSql = $sql;
            $executed = $this->pdo->driver()->query($sql);
            if ($executed === null) {
                return $this->failDriver($this->pdo->driver()->errorInfo());
            }
            $this->acceptResult($executed);
        } else {
            if (!self::isCursor($this->result)) {
                $prepared = $this->pdo->driver()->prepare($this->nativeSql);
                if ($prepared === null) {
                    return $this->failDriver($this->pdo->driver()->errorInfo());
                }
                $this->result = $prepared;
            }
            if (!$this->result->execute($this->nativeValues($ordered))) {
                return $this->failDriver($this->result->errorInfo());
            }
            $this->acceptResult($this->result);
        }

        $this->executed = true;
        $this->clearError();
        return true;
    }

    public function fetch($mode = 0, $cursorOrientation = PDO::FETCH_ORI_NEXT, $cursorOffset = 0)
    {
        if ($this->inFetch) {
            return $this->fail('HY000', 'Cannot fetch while already fetching');
        }
        if (!$this->executed) {
            return $this->fail('HY000', 'no active result set');
        }
        $spec = $this->specFor($mode, array_slice(func_get_args(), 3));
        if ($spec === null) {
            return false;
        }
        $values = $this->readValues((int) $cursorOrientation, (int) $cursorOffset);
        if ($values === null) {
            return false;
        }
        return $this->materialize($values, $spec, false);
    }

    public function fetchAll($mode = 0)
    {
        if (!$this->executed) {
            return $this->fail('HY000', 'no active result set');
        }
        $args = array_slice(func_get_args(), 1);
        $spec = $this->specFor($mode, $args);
        if ($spec === null) {
            return false;
        }
        $type = Flags::fetchType($spec['mode']);
        $flags = Flags::fetchFlags($spec['mode']);
        $grouped = Flags::isGroup($flags) || Flags::isUnique($flags);
        $unique = Flags::isUnique($flags);

        if ($type === PDO::FETCH_KEY_PAIR && count($this->columns) !== 2) {
            return $this->fail('HY000', 'PDO::FETCH_KEY_PAIR requires exactly 2 columns');
        }

        $rows = [];
        while (true) {
            $values = $this->readValues(PDO::FETCH_ORI_NEXT, 0);
            if ($values === null) {
                break;
            }
            $groupKey = null;
            $value = $this->materialize($values, $spec, $grouped, $groupKey);
            if ($value === false && $this->errorCode !== null && $this->errorCode !== '00000' && $this->errorCode !== PDO::ERR_NONE) {
                return $rows;
            }
            if ($type === PDO::FETCH_KEY_PAIR) {
                if (is_array($value)) {
                    foreach ($value as $key => $item) {
                        $rows[$key] = $item;
                    }
                }
                continue;
            }
            if ($grouped) {
                $key = (string) $groupKey;
                if ($unique) {
                    $rows[$key] = $value;
                } else {
                    if (!array_key_exists($key, $rows)) {
                        $rows[$key] = [];
                    }
                    $rows[$key][] = $value;
                }
                continue;
            }
            $rows[] = $value;
        }
        return $rows;
    }

    public function fetchColumn($column = 0)
    {
        return $this->fetch(PDO::FETCH_COLUMN, PDO::FETCH_ORI_NEXT, 0, (int) $column);
    }

    public function fetchObject($class = 'stdClass', array $constructorArgs = [])
    {
        return $this->fetch(PDO::FETCH_CLASS, PDO::FETCH_ORI_NEXT, 0, $class, $constructorArgs);
    }

    public function getAttribute($attribute)
    {
        switch ((int) $attribute) {
            case PDO::ATTR_CURSOR:
                return $this->cursor;
            case PDO::ATTR_CURSOR_NAME:
                return self::isCursor($this->result) ? $this->result->cursorName() : null;
            default:
                return $this->fail('IM001', 'driver does not support this attribute');
        }
    }

    public function getColumnMeta($column)
    {
        $index = (int) $column;
        if (!isset($this->columns[$index])) {
            return false;
        }
        $meta = $this->columns[$index];
        return [
            'native_type' => $meta['native_type'],
            'pdo_type' => $meta['pdo_type'],
            'flags' => [],
            'table' => '',
            'name' => $meta['name'],
            'len' => $meta['len'],
            'precision' => $meta['precision'],
            'scale' => $meta['scale'],
        ];
    }

    public function nextRowset()
    {
        if (!self::isCursor($this->result)) {
            return false;
        }
        if (!$this->result->next()) {
            return false;
        }
        $this->acceptResult($this->result);
        $this->resetIterator();
        $this->clearError();
        return true;
    }

    public function rowCount()
    {
        return $this->rowCount;
    }

    public function setAttribute($attribute, $value)
    {
        if ((int) $attribute === PDO::ATTR_CURSOR) {
            if ($this->executed) {
                return $this->fail('HY000', 'cursor type cannot be changed after execute');
            }
            $this->cursor = (int) $value;
            return true;
        }
        return $this->fail('IM001', 'driver does not support this attribute');
    }

    public function setFetchMode($mode)
    {
        $args = array_slice(func_get_args(), 1);
        $spec = $this->interpretMode((int) $mode, $args, false);
        if ($spec === null) {
            return false;
        }
        $this->fetchMode = $spec['mode'];
        $this->fetchClass = $spec['class'];
        $this->fetchCtor = $spec['ctor'];
        $this->fetchInto = $spec['into'];
        $this->fetchFunc = $spec['func'];
        $this->fetchColumn = $spec['column'];
        return true;
    }

    public function getIterator()
    {
        return new PdoStatementIterator($this);
    }

    public function current()
    {
        return $this->valid() ? $this->iterRow : null;
    }

    public function key()
    {
        return $this->iterIndex < 0 ? null : $this->iterIndex;
    }

    public function next()
    {
        $this->iterIndex++;
        $this->iterRow = $this->fetch();
    }

    public function rewind()
    {
        if ($this->iterStarted) {
            $this->fail('HY000', 'PDOStatement::rewind(): An iterator cannot be rewound');
            return;
        }
        $this->iterStarted = true;
        $this->iterIndex = 0;
        $this->iterRow = $this->fetch();
    }

    public function valid()
    {
        return $this->iterRow !== false;
    }

    public function __sleep()
    {
        throw new \Exception("Serialization of 'PDOStatement' is not allowed");
    }

    public function __wakeup()
    {
        throw new \Exception("Unserialization of 'PDOStatement' is not allowed");
    }

    public function __destruct()
    {
        $this->freeResult();
    }

    /**
     * @param array<int,mixed> $args
     * @return array<string,mixed>|null
     */
    private function specFor($mode, array $args)
    {
        if ((int) $mode === 0 && $args === []) {
            return [
                'mode' => $this->activeMode(),
                'class' => $this->fetchClass,
                'ctor' => $this->fetchCtor,
                'into' => $this->fetchInto,
                'func' => $this->fetchFunc,
                'column' => $this->fetchColumn,
            ];
        }
        $interpreted = $this->interpretMode((int) $mode, $args, true);
        if ($interpreted === null) {
            return null;
        }
        if (Flags::fetchType($interpreted['mode']) === 0) {
            $interpreted['mode'] = $this->activeMode() | Flags::fetchFlags($interpreted['mode']);
            $interpreted['class'] = $interpreted['class'] !== null ? $interpreted['class'] : $this->fetchClass;
            $interpreted['ctor'] = $interpreted['ctor'] !== [] ? $interpreted['ctor'] : $this->fetchCtor;
            $interpreted['into'] = $interpreted['into'] !== null ? $interpreted['into'] : $this->fetchInto;
            $interpreted['func'] = $interpreted['func'] !== null ? $interpreted['func'] : $this->fetchFunc;
        }
        return $interpreted;
    }

    /**
     * @param array<int,mixed> $args
     * @return array<string,mixed>|null
     */
    private function interpretMode($mode, array $args, $allowCall)
    {
        unset($allowCall);
        $type = Flags::fetchType($mode);
        $class = null;
        $ctor = [];
        $into = null;
        $func = null;
        $column = 0;

        if ($type === PDO::FETCH_COLUMN) {
            if (isset($args[0])) {
                $column = (int) $args[0];
                if ($column < 0) {
                    $this->fail('HY000', 'Column index must be greater than or equal to 0');
                    return null;
                }
            } elseif (Flags::isGroup($mode) || Flags::isUnique($mode)) {
                $column = -1;
            }
        } elseif ($type === PDO::FETCH_CLASS) {
            if ((Flags::fetchFlags($mode) & PDO::FETCH_CLASSTYPE) === PDO::FETCH_CLASSTYPE) {
                if (isset($args[0]) && (!is_string($args[0]) || $args[0] === '')) {
                    $this->fail('HY000', 'No fetch class specified');
                    return null;
                }
            } elseif (!isset($args[0]) || !is_string($args[0]) || $args[0] === '') {
                $this->fail('HY000', 'No fetch class specified');
                return null;
            }
            if (isset($args[0])) {
                $class = $args[0];
            }
            if (isset($args[1])) {
                if (!is_array($args[1])) {
                    $this->fail('HY000', 'constructor arguments must be an array');
                    return null;
                }
                $ctor = $args[1];
            }
        } elseif ($type === PDO::FETCH_INTO) {
            if (!isset($args[0]) || !is_object($args[0])) {
                $this->fail('HY000', 'No fetch-into object specified.');
                return null;
            }
            $into = $args[0];
        } elseif ($type === PDO::FETCH_FUNC) {
            if (!isset($args[0]) || !is_callable($args[0])) {
                $this->fail('HY000', 'No fetch function specified');
                return null;
            }
            $func = $args[0];
        } elseif ($args !== []) {
            $this->fail('HY000', 'unexpected arguments for fetch mode');
            return null;
        }

        return [
            'mode' => (int) $mode,
            'class' => $class,
            'ctor' => $ctor,
            'into' => $into,
            'func' => $func,
            'column' => $column,
        ];
    }

    private function activeMode()
    {
        if ($this->fetchMode !== null) {
            return (int) $this->fetchMode;
        }
        return (int) $this->pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE);
    }

    /**
     * @param array<int,mixed>|null $input
     * @return array<int,array{value:mixed,type:int}>|null
     */
    private function resolveParams(array $input = null)
    {
        $map = $input === null ? $this->boundMap() : $this->inputMap($input);
        if ($map === null) {
            return null;
        }
        if ($this->placeholders === []) {
            if ($map !== []) {
                $this->fail('HY093', 'Invalid parameter number: number of bound variables does not match number of tokens');
                return null;
            }
            return [];
        }

        $named = $this->placeholders[0]['kind'] === 'named';
        if ($named) {
            $required = [];
            foreach ($this->placeholders as $placeholder) {
                $required[$placeholder['name']] = true;
            }
            $provided = [];
            foreach ($map as $key => $_) {
                if (!is_string($key)) {
                    $this->fail('HY093', 'Invalid parameter number: mixed named and positional parameters');
                    return null;
                }
                $provided[$key] = true;
            }
            if (count($provided) !== count($required) || array_diff_key($required, $provided) !== [] || array_diff_key($provided, $required) !== []) {
                $this->fail('HY093', 'Invalid parameter number: number of bound variables does not match number of tokens');
                return null;
            }
        } else {
            $expected = count($this->placeholders);
            if (count($map) !== $expected) {
                $this->fail('HY093', 'Invalid parameter number: number of bound variables does not match number of tokens');
                return null;
            }
            for ($number = 1; $number <= $expected; $number++) {
                if (!array_key_exists($number, $map)) {
                    $this->fail('HY093', 'Invalid parameter number: parameter was not defined');
                    return null;
                }
            }
        }

        $ordered = [];
        foreach ($this->placeholders as $index => $placeholder) {
            if ($placeholder['kind'] === 'named') {
                if (!array_key_exists($placeholder['name'], $map)) {
                    $this->fail('HY093', 'Invalid parameter number: parameter was not defined');
                    return null;
                }
                $ordered[] = $map[$placeholder['name']];
                continue;
            }
            $ordered[] = $map[$index + 1];
        }
        return $ordered;
    }

    /**
     * @return array<int|string,array{value:mixed,type:int}>
     */
    private function boundMap()
    {
        $map = [];
        foreach ($this->params as $key => $param) {
            $map[$key] = [
                'value' => $param['value'],
                'type' => isset($param['type']) ? (int) $param['type'] : PDO::PARAM_STR,
            ];
        }
        return $map;
    }

    /**
     * @param array<int|string,mixed> $input
     * @return array<int|string,array{value:mixed,type:int}>|null
     */
    private function inputMap(array $input)
    {
        if ($this->isList($input)) {
            $map = [];
            $number = 1;
            foreach ($input as $value) {
                $map[$number++] = ['value' => $value, 'type' => PDO::PARAM_STR];
            }
            return $map;
        }
        $map = [];
        foreach ($input as $key => $value) {
            if (is_int($key)) {
                $map[$key] = ['value' => $value, 'type' => PDO::PARAM_STR];
                continue;
            }
            $name = $this->normalizeName($key);
            if ($name === '') {
                $this->fail('HY093', 'Invalid parameter number: parameter was not defined');
                return null;
            }
            $map[$name] = ['value' => $value, 'type' => PDO::PARAM_STR];
        }
        return $map;
    }

    /**
     * @param array<int,array{value:mixed,type:int}> $ordered
     */
    private function buildEmulatedSql(array $ordered)
    {
        $sql = '';
        $cursor = 0;
        foreach ($this->parts as $part) {
            if ($part[0] === 't') {
                $sql .= $part[1];
                continue;
            }
            $item = $ordered[$cursor++];
            $sql .= SqlLiteral::render($item['value'], $item['type']);
        }
        return $sql;
    }

    /**
     * @param array<int,array{value:mixed,type:int}> $ordered
     * @return array<int,mixed>
     */
    private function nativeValues(array $ordered)
    {
        $values = [];
        foreach ($ordered as $item) {
            $value = $item['value'];
            if (is_bool($value)) {
                $values[] = $value ? '1' : '0';
            } elseif (is_float($value)) {
                $values[] = SqlLiteral::formatFloat($value);
            } elseif (is_resource($value)) {
                $read = stream_get_contents($value);
                $values[] = $read === false ? '' : $read;
            } else {
                $values[] = $value;
            }
        }
        return $values;
    }

    /**
     * @param OdbcStatement $result
     */
    private function acceptResult($result)
    {
        $this->result = $result;
        $this->columns = $result->columns();
        $max = $this->pdo->getAttribute(PDO::ATTR_MAX_COLUMN_LEN);
        $result->configure(is_numeric($max) ? (int) $max : 0);

        if ($this->columns === []) {
            $count = $result->numRows();
            $this->rowCount = $count === null || $count < 0 ? 0 : $count;
            $this->scroll = null;
            return;
        }

        if ($this->cursor === PDO::CURSOR_SCROLL) {
            $this->scroll = [];
            while (true) {
                $row = $result->fetch();
                if ($row === null) {
                    break;
                }
                $this->scroll[] = $row;
            }
            $this->scrollPos = -1;
            $this->rowCount = count($this->scroll);
            return;
        }

        $count = $result->numRows();
        $this->rowCount = $count === null ? -1 : $count;
        $this->scroll = null;
    }

    /**
     * @return array<int,mixed>|null
     */
    private function readValues($orientation, $offset)
    {
        if ($this->scroll !== null) {
            $position = $this->scrollPos;
            switch ($orientation) {
                case PDO::FETCH_ORI_NEXT:
                    $position++;
                    break;
                case PDO::FETCH_ORI_PRIOR:
                    $position--;
                    break;
                case PDO::FETCH_ORI_FIRST:
                    $position = 0;
                    break;
                case PDO::FETCH_ORI_LAST:
                    $position = count($this->scroll) - 1;
                    break;
                case PDO::FETCH_ORI_ABS:
                    $position = $offset - 1;
                    break;
                case PDO::FETCH_ORI_REL:
                    $position = $this->scrollPos + $offset;
                    break;
                default:
                    $this->fail('HY106', 'fetch orientation is not supported');
                    return null;
            }
            $this->scrollPos = $position;
            if ($position < 0 || $position >= count($this->scroll)) {
                return null;
            }
            return $this->scroll[$position];
        }

        if ($orientation !== PDO::FETCH_ORI_NEXT || $offset !== 0) {
            $this->fail('HY106', 'fetch orientation is not supported by a forward-only cursor');
            return null;
        }
        if ($this->columns === [] || !self::isCursor($this->result)) {
            return null;
        }
        return $this->result->fetch();
    }

    /**
     * @param array<int,mixed> $raw
     * @param array<string,mixed> $spec
     * @param string|null $groupKey
     * @return mixed
     */
    private function materialize(array $raw, array $spec, $withGroup, &$groupKey = null)
    {
        $this->inFetch = true;
        try {
            $converted = [];
            foreach ($this->columns as $index => $column) {
                $converted[$index] = RowFormatter::convert(
                    isset($raw[$index]) ? $raw[$index] : null,
                    $column,
                    (bool) $this->pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES),
                    (int) $this->pdo->getAttribute(PDO::ATTR_ORACLE_NULLS)
                );
            }
            $this->assignBound($converted);
            if (Flags::fetchType($spec['mode']) === PDO::FETCH_BOUND) {
                return true;
            }
            $spec['case'] = (int) $this->pdo->getAttribute(PDO::ATTR_CASE);
            $spec['query'] = $this->queryString;
            if (Flags::fetchType($spec['mode']) === PDO::FETCH_LAZY) {
                $spec['lazy'] = $this->lazyRow;
            }
            try {
                $presented = RowFormatter::present($this->columns, $converted, $spec['mode'], $spec, $withGroup, $groupKey);
                if ($presented instanceof PDORow) {
                    $this->lazyRow = $presented;
                }
                return $presented;
            } catch (PDOException $exception) {
                $info = is_array($exception->errorInfo) ? $exception->errorInfo : null;
                $message = is_array($info) && isset($info[2]) ? (string) $info[2] : $exception->getMessage();
                $state = $exception->getCode() !== '' ? (string) $exception->getCode() : 'HY000';
                return $this->fail($state, $message, null, $message);
            }
        } finally {
            $this->inFetch = false;
        }
    }

    /**
     * @param array<int,mixed> $converted
     */
    private function assignBound(array $converted)
    {
        if ($this->boundColumns === []) {
            return;
        }
        $byName = [];
        foreach ($this->columns as $index => $column) {
            $byName[strtolower($column['name'])] = $index;
        }
        foreach ($this->boundColumns as $key => &$binding) {
            if (is_int($key)) {
                $index = $key - 1;
            } elseif (ctype_digit((string) $key)) {
                $index = ((int) $key) - 1;
            } else {
                $lookup = strtolower((string) $key);
                if (!array_key_exists($lookup, $byName)) {
                    continue;
                }
                $index = $byName[$lookup];
            }
            if (!array_key_exists($index, $converted)) {
                continue;
            }
            $binding['var'] = RowFormatter::castBound($converted[$index], $binding['type']);
        }
        unset($binding);
    }

    private static function isCursor($result)
    {
        return $result instanceof OdbcStatement || $result instanceof MysqlStatement || $result instanceof PgsqlStatement;
    }

    private function freeResult()
    {
        if (self::isCursor($this->result)) {
            $this->result->free();
        }
        $this->result = null;
        $this->scroll = null;
    }

    /**
     * @param mixed $parameter
     * @return int|string|null
     */
    private function parameterKey($parameter)
    {
        if (is_int($parameter) || (is_string($parameter) && ctype_digit($parameter))) {
            $number = (int) $parameter;
            if ($number < 1) {
                $this->fail('HY093', 'Invalid parameter number: parameter must be greater than or equal to 1');
                return null;
            }
            return $number;
        }
        $name = $this->normalizeName($parameter);
        if ($name === '') {
            $this->fail('HY093', 'Invalid parameter number: parameter was not defined');
            return null;
        }
        return $name;
    }

    private function normalizeName($name)
    {
        $name = (string) $name;
        if ($name !== '' && $name[0] === ':') {
            $name = substr($name, 1);
        }
        return $name;
    }

    private function isList(array $input)
    {
        $expected = 0;
        foreach ($input as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }

    private function resetIterator()
    {
        $this->iterStarted = false;
        $this->iterIndex = -1;
        $this->iterRow = false;
    }

    private function clearError()
    {
        $this->errorCode = PDO::ERR_NONE;
        $this->errorInfo = [PDO::ERR_NONE, null, null];
        $this->pdo->adoptError(PDO::ERR_NONE, null, null);
    }

    /**
     * @param array{0:string,1:int|null,2:string} $info
     */
    private function failDriver(array $info)
    {
        return $this->fail($info[0], $info[2], $info[1], $info[2]);
    }

    private function fail($state, $message, $native = null, $driverMessage = null)
    {
        $driverMessage = $driverMessage === null ? $message : $driverMessage;
        $this->errorCode = $state;
        $this->errorInfo = [$state, $native, $driverMessage];
        $this->pdo->adoptError($state, $native, $driverMessage);
        $text = 'SQLSTATE[' . $state . ']: ' . $message;
        $mode = (int) $this->pdo->errorMode();
        if ($mode === PDO::ERRMODE_EXCEPTION) {
            throw new PDOException($text, $state, null, $this->errorInfo);
        }
        if ($mode === PDO::ERRMODE_WARNING) {
            trigger_error($text, E_USER_WARNING);
        }
        return false;
    }
}

/**
 * Итератор выражения. Отдельный объект нужен, чтобы подкласс с getIterator()
 * мог обернуть курсор родителя через IteratorIterator($stmt, 'PDOStatement').
 */
class PdoStatementIterator implements \Iterator
{
    /** @var PDOStatement */
    private $statement;

    public function __construct(PDOStatement $statement)
    {
        $this->statement = $statement;
    }

    public function current()
    {
        return $this->statement->current();
    }

    public function key()
    {
        return $this->statement->key();
    }

    public function next()
    {
        $this->statement->next();
    }

    public function rewind()
    {
        $this->statement->rewind();
    }

    public function valid()
    {
        return $this->statement->valid();
    }
}
