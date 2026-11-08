<?php

namespace PhpPdo;

/**
 * Клиент PostgreSQL через PHP FFI и libpq.
 * Текстовый протокол и PQprepare повторяют pdo_pgsql PHP 7.4.
 */
final class PgsqlLibrary
{
    const FFI_SCOPE = 'PHPPDO_PGSQL';
    const HEADER = 'ffi/pgsql.h';

    public static function available()
    {
        return FfiPreload::available(self::FFI_SCOPE, self::HEADER, self::candidates());
    }

    /**
     * @return \FFI
     */
    public static function ffi()
    {
        return FfiPreload::ffi(self::FFI_SCOPE, self::HEADER, self::candidates(), 'libpq was not found');
    }

    /**
     * @return string|null
     */
    public static function probeLibrary()
    {
        return FfiPreload::probe(self::HEADER, self::candidates());
    }

    /**
     * @return array<int,string>
     */
    private static function candidates()
    {
        $candidates = array();
        $env = getenv('PDO_PGSQL_LIBRARY');
        if (is_string($env) && $env !== '') {
            $candidates[] = $env;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            return array_merge($candidates, FfiPreload::windowsDllCandidates(
                array('PostgreSQL', 'pgsql'),
                array(
                    'C:\\Program Files\\PostgreSQL\\*\\bin',
                    'C:\\Program Files (x86)\\PostgreSQL\\*\\bin',
                ),
                array(''),
                array('libpq.dll')
            ));
        }
        return array_merge($candidates, array(
            '/usr/lib/x86_64-linux-gnu/libpq.so.5',
            '/usr/lib/aarch64-linux-gnu/libpq.so.5',
            'libpq.so.5',
        ));
    }
}

/**
 * Соединение pgsql:, как pdo_pgsql_db_handle.
 */
final class PgsqlClient
{
    /** @var \FFI\CData|null */
    private $conn;

    /** @var bool */
    private $persistent = false;

    /** @var bool */
    private $pending = false;

    /** @var bool */
    private $disablePrepares = false;

    /** @var int */
    private $counter = 0;

    /** @var OdbcError */
    private $einfo;

    /** @var array<string,self> */
    private static $pool = array();

    /**
     * @return self
     */
    public static function connect($dsn, $user, $password, $persistent)
    {
        $client = new self();
        $client->persistent = (bool) $persistent;
        $client->einfo = new OdbcError();
        if (!PgsqlLibrary::available()) {
            $client->einfo->set('IM002', null, 'could not find driver', 'PQconnectdb');
            return $client;
        }
        $parts = DsnParts::parse($dsn);
        if ($user !== null && $user !== '') {
            $parts['user'] = (string) $user;
        }
        if ($password !== null) {
            $parts['password'] = (string) $password;
        }
        $conninfo = self::conninfo($parts);
        if ($client->persistent && isset(self::$pool[$conninfo]) && self::$pool[$conninfo]->connected()) {
            return self::$pool[$conninfo];
        }
        $ffi = PgsqlLibrary::ffi();
        $conn = $ffi->PQconnectdb($conninfo);
        if ($conn === null || \FFI::isNull($conn) || $ffi->PQstatus($conn) !== 0) {
            $client->captureConn($conn);
            if ($conn !== null && !\FFI::isNull($conn)) {
                $ffi->PQfinish($conn);
            }
            return $client;
        }
        $client->conn = $conn;
        $client->einfo->clear();
        if ($client->persistent) {
            self::$pool[$conninfo] = $client;
        }
        return $client;
    }

    public function name()
    {
        return 'pgsql';
    }

    public function connected()
    {
        return $this->conn !== null && !\FFI::isNull($this->conn) && PgsqlLibrary::ffi()->PQstatus($this->conn) === 0;
    }

    public function close($inTransaction)
    {
        if (!$this->connected() || $this->persistent) {
            return;
        }
        if ($inTransaction) {
            $this->command('ROLLBACK');
        }
        $this->drain();
        PgsqlLibrary::ffi()->PQfinish($this->conn);
        $this->conn = null;
    }

    public function begin($autoCommit)
    {
        unset($autoCommit);
        return $this->command('BEGIN');
    }

    public function commit($autoCommit)
    {
        unset($autoCommit);
        return $this->command('COMMIT');
    }

    public function rollBack($autoCommit)
    {
        unset($autoCommit);
        return $this->command('ROLLBACK');
    }

    public function setConnectAutoCommit($enabled)
    {
        if (!$this->connected()) {
            return false;
        }
        $status = PgsqlLibrary::ffi()->PQtransactionStatus($this->conn);
        if ($enabled) {
            return $status === PDO::PGSQL_TRANSACTION_IDLE || $this->command('COMMIT');
        }
        return $status !== PDO::PGSQL_TRANSACTION_IDLE || $this->command('BEGIN');
    }

    public function setTimeout($seconds)
    {
        unset($seconds);
        return $this->connected();
    }

    public function assumeUtf8()
    {
        return false;
    }

    public function setAssumeUtf8($enabled)
    {
        unset($enabled);
    }

    /**
     * @return array{0:string,1:int|null,2:string}
     */
    public function errorInfo()
    {
        return $this->einfo->info();
    }

    public function adopt(OdbcError $error)
    {
        $this->einfo->copyFrom($error);
    }

    /**
     * @return string|null
     */
    public function insertId($name)
    {
        if (!$this->connected()) {
            $this->einfo->set('HY000', null, 'connection is closed', 'LASTVAL');
            return null;
        }
        $this->drain();
        $ffi = PgsqlLibrary::ffi();
        if ($name === null || $name === '') {
            $result = $ffi->PQexec($this->conn, 'SELECT LASTVAL()');
        } else {
            $values = $ffi->new('char*[1]');
            $buffer = $ffi->new('char[' . (strlen((string) $name) + 1) . ']');
            \FFI::memcpy($buffer, (string) $name, strlen((string) $name));
            $values[0] = $ffi->cast('char*', $buffer);
            $result = $ffi->PQexecParams($this->conn, 'SELECT CURRVAL($1)', 1, null, $values, null, null, 0);
        }
        if ($result === null || \FFI::isNull($result) || $ffi->PQresultStatus($result) !== 2) {
            $this->captureResult($result);
            if ($result !== null && !\FFI::isNull($result)) {
                $ffi->PQclear($result);
            }
            return null;
        }
        $id = FfiPreload::cString($ffi->PQgetvalue($result, 0, 0));
        $ffi->PQclear($result);
        $this->einfo->clear();
        return $id;
    }

    /**
     * @return string|null
     */
    public function quote($string, $type)
    {
        if (!$this->connected()) {
            $this->einfo->set('HY000', null, 'connection is closed', 'PQescapeStringConn');
            return null;
        }
        $ffi = PgsqlLibrary::ffi();
        $text = (string) $string;
        if (((int) $type & 65535) === PDO::PARAM_LOB) {
            $length = $ffi->new('size_t');
            $escaped = $ffi->PQescapeByteaConn($this->conn, $text, strlen($text), \FFI::addr($length));
            if ($escaped === null || \FFI::isNull($escaped)) {
                $this->captureConn($this->conn);
                return null;
            }
            $size = (int) $length->cdata;
            if ($size > 0) {
                $size--;
            }
            $literal = "'" . \FFI::string($escaped, $size) . "'";
            $ffi->PQfreemem($escaped);
            return $literal;
        }
        $to = $ffi->new('char[' . (strlen($text) * 2 + 1) . ']');
        $error = $ffi->new('int');
        $written = $ffi->PQescapeStringConn($this->conn, $to, $text, strlen($text), \FFI::addr($error));
        if ($error->cdata) {
            $this->captureConn($this->conn);
            return null;
        }
        return "'" . \FFI::string($to, (int) $written) . "'";
    }

    public function serverVersion()
    {
        if (!$this->connected()) {
            return null;
        }
        return FfiPreload::cString(PgsqlLibrary::ffi()->PQparameterStatus($this->conn, 'server_version'));
    }

    public function serverInfo()
    {
        if (!$this->connected()) {
            return null;
        }
        $ffi = PgsqlLibrary::ffi();
        return 'PID: ' . $ffi->PQbackendPID($this->conn)
            . '; Client Encoding: ' . FfiPreload::cString($ffi->PQparameterStatus($this->conn, 'client_encoding'))
            . '; Is Superuser: ' . FfiPreload::cString($ffi->PQparameterStatus($this->conn, 'is_superuser'))
            . '; Session Authorization: ' . FfiPreload::cString($ffi->PQparameterStatus($this->conn, 'session_authorization'))
            . '; Date Style: ' . FfiPreload::cString($ffi->PQparameterStatus($this->conn, 'DateStyle'));
    }

    public function clientVersion()
    {
        if (!PgsqlLibrary::available()) {
            return null;
        }
        $version = (int) PgsqlLibrary::ffi()->PQlibVersion();
        $major = (int) floor($version / 10000);
        if ($major >= 10) {
            return $major . '.' . ($version % 10000);
        }
        return $major . '.' . ((int) floor($version / 100) % 100) . '.' . ($version % 100);
    }

    public function connectionStatus()
    {
        if (!$this->connected()) {
            return 'Bad connection.';
        }
        return 'Connection OK; waiting to send.';
    }

    public function pgsqlAttribute($attribute)
    {
        if ((int) $attribute === PDO::PGSQL_ATTR_DISABLE_PREPARES) {
            return $this->disablePrepares;
        }
        return null;
    }

    public function setPgsqlAttribute($attribute, $value)
    {
        if ((int) $attribute === PDO::PGSQL_ATTR_DISABLE_PREPARES) {
            $this->disablePrepares = (bool) $value;
            return true;
        }
        return false;
    }

    /**
     * @return PgsqlStatement|null
     */
    public function prepare($sql)
    {
        if (!$this->connected()) {
            $this->einfo->set('HY000', null, 'connection is closed', 'PQprepare');
            return null;
        }
        $this->drain();
        if ($this->disablePrepares) {
            $this->einfo->clear();
            return PgsqlStatement::direct($this, (string) $sql);
        }
        $this->counter++;
        $name = sprintf('pdo_stmt_%08x', $this->counter);
        $ffi = PgsqlLibrary::ffi();
        $result = $ffi->PQprepare($this->conn, $name, (string) $sql, 0, null);
        if ($result === null || \FFI::isNull($result) || $ffi->PQresultStatus($result) !== 1) {
            $this->captureResult($result);
            if ($result !== null && !\FFI::isNull($result)) {
                $ffi->PQclear($result);
            }
            return null;
        }
        $ffi->PQclear($result);
        $this->einfo->clear();
        return PgsqlStatement::prepared($this, $name);
    }

    /**
     * @return PgsqlStatement|null
     */
    public function query($sql)
    {
        if (!$this->connected()) {
            $this->einfo->set('HY000', null, 'connection is closed', 'PQsendQuery');
            return null;
        }
        $this->drain();
        if (PgsqlLibrary::ffi()->PQsendQuery($this->conn, (string) $sql) !== 1) {
            $this->captureConn($this->conn);
            return null;
        }
        $this->pending = true;
        $loaded = $this->takeResult();
        if ($loaded === null) {
            $this->drain();
            return null;
        }
        $this->einfo->clear();
        return PgsqlStatement::buffered($this, $loaded['columns'], $loaded['rows'], $loaded['affected']);
    }

    /**
     * @return int|false
     */
    public function exec($sql)
    {
        if (!$this->connected()) {
            $this->einfo->set('HY000', null, 'connection is closed', 'PQexec');
            return false;
        }
        $this->drain();
        $ffi = PgsqlLibrary::ffi();
        $result = $ffi->PQexec($this->conn, (string) $sql);
        if ($result === null || \FFI::isNull($result)) {
            $this->captureConn($this->conn);
            return false;
        }
        $status = $ffi->PQresultStatus($result);
        if ($status !== 1 && $status !== 2 && $status !== 0) {
            $this->captureResult($result);
            $ffi->PQclear($result);
            return false;
        }
        $count = $status === 1 ? self::affected($result) : 0;
        $ffi->PQclear($result);
        $this->einfo->clear();
        return $count;
    }

    /**
     * @return array{columns:array,rows:array,affected:int}|null
     */
    public function nextResult()
    {
        if (!$this->pending) {
            return null;
        }
        $loaded = $this->takeResult();
        if ($loaded === null) {
            return null;
        }
        $this->einfo->clear();
        return $loaded;
    }

    /**
     * @param array<int,mixed> $params
     * @return array{columns:array,rows:array,affected:int}|null
     */
    public function executePrepared($name, array $params)
    {
        return $this->runStatement($name, null, $params);
    }

    /**
     * @param array<int,mixed> $params
     * @return array{columns:array,rows:array,affected:int}|null
     */
    public function executeParams($sql, array $params)
    {
        return $this->runStatement(null, $sql, $params);
    }

    public function drain()
    {
        if (!$this->pending || $this->conn === null) {
            return;
        }
        $ffi = PgsqlLibrary::ffi();
        while (true) {
            $result = $ffi->PQgetResult($this->conn);
            if ($result === null || \FFI::isNull($result)) {
                break;
            }
            $ffi->PQclear($result);
        }
        $this->pending = false;
    }

    private function command($sql)
    {
        if (!$this->connected()) {
            $this->einfo->set('HY000', null, 'connection is closed', $sql);
            return false;
        }
        $this->drain();
        $ffi = PgsqlLibrary::ffi();
        $result = $ffi->PQexec($this->conn, $sql);
        if ($result === null || \FFI::isNull($result) || $ffi->PQresultStatus($result) !== 1) {
            $this->captureResult($result);
            if ($result !== null && !\FFI::isNull($result)) {
                $ffi->PQclear($result);
            }
            return false;
        }
        $ffi->PQclear($result);
        $this->einfo->clear();
        return true;
    }

    /**
     * @param array<int,mixed> $params
     * @return array{columns:array,rows:array,affected:int}|null
     */
    private function runStatement($name, $sql, array $params)
    {
        if (!$this->connected()) {
            $this->einfo->set('HY000', null, 'connection is closed', 'PQexecPrepared');
            return null;
        }
        $this->drain();
        $ffi = PgsqlLibrary::ffi();
        $count = count($params);
        $values = null;
        $keepers = array();
        if ($count > 0) {
            $values = $ffi->new('char*[' . $count . ']');
            $keepers[] = $values;
            foreach (array_values($params) as $index => $value) {
                if ($value === null) {
                    $values[$index] = null;
                    continue;
                }
                $text = is_bool($value) ? ($value ? 't' : 'f') : (string) $value;
                $buffer = $ffi->new('char[' . (strlen($text) + 1) . ']');
                if ($text !== '') {
                    \FFI::memcpy($buffer, $text, strlen($text));
                }
                $keepers[] = $buffer;
                $values[$index] = $ffi->cast('char*', $buffer);
            }
        }
        if ($name !== null) {
            $result = $ffi->PQexecPrepared($this->conn, $name, $count, $values, null, null, 0);
        } else {
            $result = $ffi->PQexecParams($this->conn, (string) $sql, $count, null, $values, null, null, 0);
        }
        unset($keepers);
        if ($result === null || \FFI::isNull($result)) {
            $this->captureConn($this->conn);
            return null;
        }
        $status = $ffi->PQresultStatus($result);
        if ($status !== 1 && $status !== 2 && $status !== 0) {
            $this->captureResult($result);
            $ffi->PQclear($result);
            return null;
        }
        $loaded = self::materialize($result, $status);
        $ffi->PQclear($result);
        $this->einfo->clear();
        return $loaded;
    }

    /**
     * @return array{columns:array,rows:array,affected:int}|null
     */
    private function takeResult()
    {
        $ffi = PgsqlLibrary::ffi();
        $result = $ffi->PQgetResult($this->conn);
        if ($result === null || \FFI::isNull($result)) {
            $this->pending = false;
            return null;
        }
        $status = $ffi->PQresultStatus($result);
        if ($status !== 1 && $status !== 2 && $status !== 0) {
            $this->captureResult($result);
            $ffi->PQclear($result);
            $this->drain();
            return null;
        }
        $loaded = self::materialize($result, $status);
        $ffi->PQclear($result);
        return $loaded;
    }

    /**
     * @return array{columns:array,rows:array,affected:int}
     */
    private static function materialize($result, $status)
    {
        $ffi = PgsqlLibrary::ffi();
        $fieldCount = $ffi->PQnfields($result);
        $columns = array();
        for ($index = 0; $index < $fieldCount; $index++) {
            $oid = (int) $ffi->PQftype($result, $index);
            $native = self::typeName($oid);
            $modifier = (int) $ffi->PQfmod($result, $index);
            $scale = 0;
            if ($oid === 1700 && $modifier >= 4) {
                $scale = ($modifier - 4) & 65535;
            }
            $columns[] = array(
                'name' => FfiPreload::cString($ffi->PQfname($result, $index)),
                'native_type' => $native,
                'len' => (int) $ffi->PQfsize($result, $index),
                'precision' => $modifier,
                'scale' => $scale,
                'pdo_type' => self::pdoType($native),
            );
        }
        $rows = array();
        $rowCount = $ffi->PQntuples($result);
        for ($row = 0; $row < $rowCount; $row++) {
            $values = array();
            for ($index = 0; $index < $fieldCount; $index++) {
                if ($ffi->PQgetisnull($result, $row, $index)) {
                    $values[] = null;
                    continue;
                }
                $values[] = FfiPreload::cString($ffi->PQgetvalue($result, $row, $index));
            }
            $rows[] = $values;
        }
        return array(
            'columns' => $columns,
            'rows' => $rows,
            'affected' => $status === 1 ? self::affected($result) : count($rows),
        );
    }

    private static function affected($result)
    {
        $tuples = FfiPreload::cString(PgsqlLibrary::ffi()->PQcmdTuples($result));
        if ($tuples === '' || !preg_match('/^-?\d+$/', $tuples)) {
            return 0;
        }
        return (int) $tuples;
    }

    /**
     * @param \FFI\CData|null $result
     */
    private function captureResult($result)
    {
        $ffi = PgsqlLibrary::ffi();
        $state = 'HY000';
        $code = null;
        $message = 'postgresql error';
        if ($result !== null && !\FFI::isNull($result)) {
            $status = (int) $ffi->PQresultStatus($result);
            $code = $status === 0 ? null : $status;
            $sqlstate = FfiPreload::cString($ffi->PQresultErrorField($result, 67));
            $primary = FfiPreload::cString($ffi->PQresultErrorField($result, 77));
            if ($sqlstate !== '') {
                $state = $sqlstate;
            }
            if ($primary !== '') {
                $message = $primary;
            } else {
                $message = self::trimMessage(FfiPreload::cString($ffi->PQresultErrorMessage($result)));
            }
        } elseif ($this->conn !== null) {
            $message = self::trimMessage(FfiPreload::cString($ffi->PQerrorMessage($this->conn)));
        }
        if ($message === '') {
            $message = 'postgresql error';
        }
        $this->einfo->set($state, $code, $message, 'pgsql');
    }

    /**
     * @param \FFI\CData|null $conn
     */
    private function captureConn($conn)
    {
        $message = 'could not connect';
        if ($conn !== null && !\FFI::isNull($conn)) {
            $message = self::trimMessage(FfiPreload::cString(PgsqlLibrary::ffi()->PQerrorMessage($conn)));
        }
        if ($message === '') {
            $message = 'could not connect';
        }
        $this->einfo->set('08006', null, $message, 'PQconnectdb');
    }

    private static function trimMessage($message)
    {
        $message = (string) $message;
        $length = strlen($message);
        if ($length === 0) {
            return '';
        }
        $index = $length - 1;
        if ($index > 1 && ($message[$index - 1] === "\r" || $message[$index - 1] === "\n") && $message[$index] === '.') {
            $index--;
        }
        while ($index > 0 && ($message[$index] === "\r" || $message[$index] === "\n")) {
            $index--;
        }
        return substr($message, 0, $index + 1);
    }

    private static function typeName($oid)
    {
        switch ((int) $oid) {
            case 16:
                return 'BOOL';
            case 20:
                return 'BIGINT';
            case 21:
                return 'SMALLINT';
            case 23:
            case 26:
                return 'INTEGER';
            case 700:
                return 'FLOAT';
            case 701:
                return 'DOUBLE';
            case 1700:
                return 'NUMERIC';
            default:
                return 'VARCHAR';
        }
    }

    private static function pdoType($native)
    {
        if ($native === 'BOOL') {
            return PDO::PARAM_BOOL;
        }
        if ($native === 'INTEGER' || $native === 'SMALLINT' || $native === 'BIGINT') {
            return PDO::PARAM_INT;
        }
        return PDO::PARAM_STR;
    }

    /**
     * @param array<string,string> $parts
     */
    private static function conninfo(array $parts)
    {
        $map = array(
            'host' => 'host',
            'port' => 'port',
            'dbname' => 'dbname',
            'user' => 'user',
            'password' => 'password',
            'sslmode' => 'sslmode',
            'options' => 'options',
            'connect_timeout' => 'connect_timeout',
            'application_name' => 'application_name',
            'client_encoding' => 'client_encoding',
            'service' => 'service',
        );
        $chunks = array();
        foreach ($map as $key => $name) {
            if (!isset($parts[$key]) || $parts[$key] === '') {
                continue;
            }
            $chunks[] = $name . '=' . self::connValue($parts[$key]);
        }
        return implode(' ', $chunks);
    }

    private static function connValue($value)
    {
        return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $value) . "'";
    }
}

/**
 * Результат PQgetResult или подготовленного оператора.
 */
final class PgsqlStatement
{
    /** @var PgsqlClient */
    private $client;

    /** @var string|null */
    private $name;

    /** @var string|null */
    private $sql;

    /** @var array<int,array<string,mixed>> */
    private $columns = array();

    /** @var array<int,array<int,mixed>> */
    private $rows = array();

    /** @var int */
    private $index = 0;

    /** @var int */
    private $affected = 0;

    /** @var OdbcError */
    private $einfo;

    /**
     * @param array<int,array<string,mixed>> $columns
     * @param array<int,array<int,mixed>> $rows
     * @return self
     */
    public static function buffered(PgsqlClient $client, array $columns, array $rows, $affected)
    {
        $statement = new self($client);
        $statement->columns = $columns;
        $statement->rows = $rows;
        $statement->affected = (int) $affected;
        return $statement;
    }

    /**
     * @return self
     */
    public static function prepared(PgsqlClient $client, $name)
    {
        $statement = new self($client);
        $statement->name = $name;
        return $statement;
    }

    /**
     * @return self
     */
    public static function direct(PgsqlClient $client, $sql)
    {
        $statement = new self($client);
        $statement->sql = $sql;
        return $statement;
    }

    private function __construct(PgsqlClient $client)
    {
        $this->client = $client;
        $this->einfo = new OdbcError();
    }

    /**
     * @param array<int,mixed> $params
     */
    public function execute(array $params)
    {
        if ($this->name !== null) {
            $loaded = $this->client->executePrepared($this->name, $params);
        } elseif ($this->sql !== null) {
            $loaded = $this->client->executeParams($this->sql, $params);
        } else {
            $this->einfo->set('HY000', null, 'statement is closed', 'PQexecPrepared');
            return false;
        }
        if ($loaded === null) {
            $info = $this->client->errorInfo();
            $this->einfo->set($info[0], $info[1], $info[2], 'pgsql');
            return false;
        }
        $this->columns = $loaded['columns'];
        $this->rows = $loaded['rows'];
        $this->affected = (int) $loaded['affected'];
        $this->index = 0;
        $this->einfo->clear();
        return true;
    }

    /**
     * @return array<int,mixed>|null
     */
    public function fetch()
    {
        if ($this->index >= count($this->rows)) {
            return null;
        }
        $row = $this->rows[$this->index];
        $this->index++;
        return $row;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function columns()
    {
        return $this->columns;
    }

    public function configure($maxColumnLen)
    {
        unset($maxColumnLen);
        return true;
    }

    /**
     * @return int|null
     */
    public function numRows()
    {
        if ($this->columns === array()) {
            return $this->affected;
        }
        return count($this->rows);
    }

    public function next()
    {
        $loaded = $this->client->nextResult();
        if ($loaded === null) {
            return false;
        }
        $this->columns = $loaded['columns'];
        $this->rows = $loaded['rows'];
        $this->affected = (int) $loaded['affected'];
        $this->index = 0;
        return true;
    }

    /**
     * @return string|null
     */
    public function cursorName()
    {
        return null;
    }

    public function free()
    {
        if ($this->name !== null && $this->client->connected()) {
            $this->client->exec('DEALLOCATE ' . $this->name);
            $this->name = null;
        }
        $this->rows = array();
        $this->columns = array();
    }

    /**
     * @return array{0:string,1:int|null,2:string}
     */
    public function errorInfo()
    {
        return $this->einfo->info();
    }
}
