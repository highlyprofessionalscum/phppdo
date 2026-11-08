<?php

namespace PhpPdo;

/**
 * Диагностика вызова ODBC, как pdo_odbc_errinfo.
 */
final class OdbcError
{
    /** @var string */
    public $state = '00000';

    /** @var int|null */
    public $native;

    /** @var string */
    public $message = '';

    /** @var string */
    public $what = '';

    public function clear()
    {
        $this->state = '00000';
        $this->native = null;
        $this->message = '';
        $this->what = '';
    }

    /**
     * @param int|null $native
     */
    public function set($state, $native, $message, $what)
    {
        $this->state = $state;
        $this->native = $native;
        $this->message = $message;
        $this->what = $what;
    }

    public function copyFrom(self $other)
    {
        $this->state = $other->state;
        $this->native = $other->native;
        $this->message = $other->message;
        $this->what = $other->what;
    }

    /**
     * @return array{0:string,1:int|null,2:string}
     */
    public function info()
    {
        return [$this->state, $this->native, $this->message];
    }
}

/**
 * Общие вызовы расширения odbc. Предупреждения не попадают в вывод PHP.
 */
trait OdbcApi
{
    /**
     * @param callable $callback
     * @return array{0:mixed,1:string|null}
     */
    private static function invoke($callback)
    {
        $warning = null;
        set_error_handler(static function ($severity, $message) use (&$warning) {
            $warning = $message;
            return true;
        });
        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }
        return [$result, $warning];
    }

    private static function isHandle($value)
    {
        return is_resource($value) || is_object($value);
    }

    private static function stateOf($handle)
    {
        $state = self::isHandle($handle) ? odbc_error($handle) : odbc_error();
        if (!is_string($state) || $state === '' || $state === '00000') {
            return 'HY000';
        }
        return $state;
    }

    private static function messageOf($handle, $warning, $fallback)
    {
        $driver = self::isHandle($handle) ? odbc_errormsg($handle) : odbc_errormsg();
        if (is_string($driver) && $driver !== '') {
            return $driver;
        }
        if (is_string($warning) && $warning !== '') {
            return $warning;
        }
        return $fallback;
    }
}

/**
 * Соединение драйвера. Соответствует pdo_odbc_db_handle и odbc_methods:
 * closer, preparer, doer, begin, commit, rollback, check_liveness.
 */
final class OdbcClient
{
    use OdbcApi;

    /** @var resource|object|null */
    private $dbc;

    /** @var bool */
    private $persistent = false;

    /** @var bool */
    private $assumeUtf8 = false;

    /** @var string */
    private $dsnRest = '';

    /** @var int */
    private $prefetchRows = 0;

    /** @var OdbcError */
    private $einfo;

    private function __construct()
    {
        $this->einfo = new OdbcError();
    }

    /**
     * @param string $dsn
     * @param string|null $user
     * @param string|null $password
     * @param int $cursor
     * @param bool $persistent
     * @return self
     */
    public static function connect($dsn, $user, $password, $cursor, $persistent)
    {
        $client = new self();
        $client->persistent = (bool) $persistent;
        $client->dsnRest = (string) $dsn;
        if (!function_exists('odbc_connect')) {
            $client->einfo->set('IM001', null, 'the PHP odbc extension is not loaded', 'SQLConnect');
            return $client;
        }

        $user = $user === null ? '' : (string) $user;
        $password = $password === null ? '' : (string) $password;
        $function = $client->persistent ? 'odbc_pconnect' : 'odbc_connect';
        list($handle, $warning) = self::invoke(function () use ($function, $dsn, $user, $password, $cursor) {
            return $function($dsn, $user, $password, (int) $cursor);
        });
        if (!self::isHandle($handle)) {
            $client->record(null, $warning, 'SQLConnect', 'odbc_connect failed');
            return $client;
        }

        $client->dbc = $handle;
        $client->einfo->clear();
        return $client;
    }

    public function name()
    {
        return 'odbc';
    }

    public function connected()
    {
        return self::isHandle($this->dbc);
    }

    /**
     * @return resource|object|null
     */
    public function handle()
    {
        return $this->dbc;
    }

    public function assumeUtf8()
    {
        return $this->assumeUtf8;
    }

    public function setAssumeUtf8($enabled)
    {
        $this->assumeUtf8 = (bool) $enabled;
    }

    /**
     * odbc_handle_closer. Постоянное соединение не закрывается.
     *
     * @param bool $inTransaction
     */
    public function close($inTransaction)
    {
        if (!$this->connected() || $this->persistent) {
            return;
        }
        if ($inTransaction) {
            self::invoke(function () {
                return odbc_rollback($this->dbc);
            });
        }
        self::invoke(function () {
            return odbc_close($this->dbc);
        });
        $this->dbc = null;
    }

    /**
     * odbc_handle_preparer: SQLPrepare, курсор остаётся у вызывающего кода.
     *
     * @param string $sql
     * @return OdbcStatement|null
     */
    public function prepare($sql)
    {
        if (!$this->connected()) {
            $this->record(null, null, 'SQLPrepare', 'connection is closed');
            return null;
        }
        list($result, $warning) = self::invoke(function () use ($sql) {
            return odbc_prepare($this->dbc, $sql);
        });
        if (!self::isHandle($result)) {
            $this->record($this->dbc, $warning, 'SQLPrepare', 'odbc_prepare failed');
            return null;
        }
        $this->einfo->clear();
        return new OdbcStatement($this, $result);
    }

    /**
     * SQLExecDirect с сохранённым результатом (PDO::query).
     *
     * @param string $sql
     * @return OdbcStatement|null
     */
    public function query($sql)
    {
        if (!$this->connected()) {
            $this->record(null, null, 'SQLExecDirect', 'connection is closed');
            return null;
        }
        list($result, $warning) = self::invoke(function () use ($sql) {
            return odbc_exec($this->dbc, $sql);
        });
        if (!self::isHandle($result)) {
            $this->record($this->dbc, $warning, 'SQLExecDirect', 'odbc_exec failed');
            return null;
        }
        $this->einfo->clear();
        return new OdbcStatement($this, $result);
    }

    /**
     * odbc_handle_doer: выполнить, вернуть число строк и освободить курсор.
     *
     * @param string $sql
     * @return int|false
     */
    public function exec($sql)
    {
        $statement = $this->query($sql);
        if ($statement === null) {
            return false;
        }
        $count = $statement->numRows();
        $statement->free();
        if ($count === null || $count < 0) {
            return 0;
        }
        return $count;
    }

    /**
     * odbc_handle_begin. Снимает автокоммит, не меняя атрибут соединения.
     *
     * @param bool $autoCommit
     */
    public function begin($autoCommit)
    {
        if ($autoCommit) {
            return $this->setConnectAutoCommit(false);
        }
        $this->einfo->clear();
        return true;
    }

    /**
     * odbc_handle_commit.
     *
     * @param bool $autoCommit
     */
    public function commit($autoCommit)
    {
        if (!$this->connected()) {
            $this->record(null, null, 'SQLEndTran: Commit', 'connection is closed');
            return false;
        }
        list($ok, $warning) = self::invoke(function () {
            return odbc_commit($this->dbc);
        });
        if (!$ok) {
            $this->record($this->dbc, $warning, 'SQLEndTran: Commit', 'odbc_commit failed');
            return false;
        }
        if ($autoCommit && !$this->setConnectAutoCommit(true)) {
            return false;
        }
        $this->einfo->clear();
        return true;
    }

    /**
     * odbc_handle_rollback.
     *
     * @param bool $autoCommit
     */
    public function rollBack($autoCommit)
    {
        if (!$this->connected()) {
            $this->record(null, null, 'SQLEndTran: Rollback', 'connection is closed');
            return false;
        }
        list($ok, $warning) = self::invoke(function () {
            return odbc_rollback($this->dbc);
        });
        if (!$ok) {
            $this->record($this->dbc, $warning, 'SQLEndTran: Rollback', 'odbc_rollback failed');
            return false;
        }
        if ($autoCommit && !$this->setConnectAutoCommit(true)) {
            return false;
        }
        $this->einfo->clear();
        return true;
    }

    /**
     * SQL_ATTR_AUTOCOMMIT.
     *
     * @param bool $enabled
     */
    public function setConnectAutoCommit($enabled)
    {
        if (!$this->connected()) {
            $this->record(null, null, 'SQLSetConnectAttr AUTOCOMMIT', 'connection is closed');
            return false;
        }
        $enabled = (bool) $enabled;
        $what = $enabled ? 'SQLSetConnectAttr AUTOCOMMIT = ON' : 'SQLSetConnectAttr AUTOCOMMIT = OFF';
        list($ok, $warning) = self::invoke(function () use ($enabled) {
            return odbc_autocommit($this->dbc, $enabled);
        });
        if (!$ok) {
            $this->record($this->dbc, $warning, $what, 'odbc_autocommit failed');
            return false;
        }
        $this->einfo->clear();
        return true;
    }

    /**
     * SQL_ATTR_LOGIN_TIMEOUT через odbc_setoption. Ошибка драйвера не стирает соединение.
     *
     * @param int $seconds
     */
    public function setTimeout($seconds)
    {
        if (!function_exists('odbc_setoption') || !$this->connected()) {
            return false;
        }
        list($ok, ) = self::invoke(function () use ($seconds) {
            return odbc_setoption($this->dbc, 1, 113, (int) $seconds);
        });
        return (bool) $ok;
    }

    /**
     * SQL_ATTR_ROW_ARRAY_SIZE (ODBC 3).
     */
    public function setPrefetch($rows)
    {
        $this->prefetchRows = (int) $rows;
        if (!function_exists('odbc_setoption') || !$this->connected() || $this->prefetchRows < 1) {
            return $this->prefetchRows >= 0;
        }
        list($ok, ) = self::invoke(function () {
            return odbc_setoption($this->dbc, 1, 27, $this->prefetchRows);
        });
        return (bool) $ok;
    }

    public function prefetch()
    {
        return $this->prefetchRows;
    }

    /**
     * SQLite ODBC: SELECT last_insert_rowid(). Other drivers: null (IM001).
     *
     * @return string|null
     */
    public function insertId($name = null)
    {
        unset($name);
        if (!$this->connected() || !$this->isSqliteDsn()) {
            return null;
        }
        $statement = $this->query('SELECT last_insert_rowid()');
        if ($statement === null) {
            return null;
        }
        $row = $statement->fetch();
        $statement->free();
        if (!is_array($row) || !isset($row[0])) {
            return null;
        }
        return (string) $row[0];
    }

    private function isSqliteDsn()
    {
        return stripos($this->dsnRest, 'sqlite') !== false;
    }

    /**
     * @return array{0:string,1:int|null,2:string}
     */
    public function errorInfo()
    {
        return $this->einfo->info();
    }

    public function absorb(OdbcError $error)
    {
        $this->einfo->copyFrom($error);
    }

    private function record($handle, $warning, $what, $fallback)
    {
        $this->einfo->set(self::stateOf($handle), null, self::messageOf($handle, $warning, $fallback), $what);
    }
}

/**
 * Курсор драйвера. Соответствует pdo_odbc_stmt и odbc_stmt_methods:
 * execute, fetch, describe, next_rowset, close.
 */
final class OdbcStatement
{
    use OdbcApi;

    /** @var OdbcClient */
    private $client;

    /** @var resource|object|null */
    private $stmt;

    /** @var OdbcError */
    private $einfo;

    /** @var array<int,array<string,mixed>> */
    private $columns = [];

    /** @var bool */
    private $described = false;

    /**
     * @param resource|object $stmt
     */
    public function __construct(OdbcClient $client, $stmt)
    {
        $this->client = $client;
        $this->stmt = $stmt;
        $this->einfo = new OdbcError();
    }

    /**
     * @param array<int,mixed> $params
     */
    public function execute(array $params)
    {
        if (!self::isHandle($this->stmt)) {
            $this->record(null, null, 'SQLExecute', 'statement is closed');
            return false;
        }
        list($ok, $warning) = self::invoke(function () use ($params) {
            return odbc_execute($this->stmt, $params);
        });
        $this->described = false;
        $this->columns = [];
        if (!$ok) {
            $this->record($this->client->handle(), $warning, 'SQLExecute', 'odbc_execute failed');
            return false;
        }
        $this->einfo->clear();
        $this->client->absorb($this->einfo);
        return true;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function columns()
    {
        if ($this->described) {
            return $this->columns;
        }
        $this->columns = $this->describe();
        $this->described = true;
        return $this->columns;
    }

    /**
     * @param int $maxColumnLen
     */
    public function configure($maxColumnLen)
    {
        if (!self::isHandle($this->stmt)) {
            return;
        }
        if (defined('ODBC_BINMODE_RETURN') && function_exists('odbc_binmode')) {
            self::invoke(function () {
                return odbc_binmode($this->stmt, ODBC_BINMODE_RETURN);
            });
        }
        if ($maxColumnLen > 0 && function_exists('odbc_longreadlen')) {
            self::invoke(function () use ($maxColumnLen) {
                return odbc_longreadlen($this->stmt, (int) $maxColumnLen);
            });
        }
    }

    /**
     * @return array<int,mixed>|null
     */
    public function fetch()
    {
        if (!self::isHandle($this->stmt)) {
            return null;
        }
        $columnCount = count($this->columns());
        list($fetched, ) = self::invoke(function () {
            return odbc_fetch_row($this->stmt);
        });
        if (!$fetched) {
            return null;
        }
        $values = [];
        for ($column = 1; $column <= $columnCount; $column++) {
            $values[] = odbc_result($this->stmt, $column);
        }
        return $values;
    }

    /**
     * @return int|null
     */
    public function numRows()
    {
        if (!self::isHandle($this->stmt)) {
            return null;
        }
        list($count, ) = self::invoke(function () {
            return odbc_num_rows($this->stmt);
        });
        if ($count === false || $count === null) {
            return null;
        }
        return (int) $count;
    }

    public function next()
    {
        if (!self::isHandle($this->stmt)) {
            return false;
        }
        list($ok, ) = self::invoke(function () {
            return odbc_next_result($this->stmt);
        });
        if (!$ok) {
            return false;
        }
        $this->described = false;
        $this->columns = [];
        return true;
    }

    /**
     * @return string|null
     */
    public function cursorName()
    {
        if (!function_exists('odbc_cursor') || !self::isHandle($this->stmt)) {
            return null;
        }
        list($name, ) = self::invoke(function () {
            return odbc_cursor($this->stmt);
        });
        if (!is_string($name) || $name === '') {
            return null;
        }
        return $name;
    }

    public function free()
    {
        if (!self::isHandle($this->stmt)) {
            return;
        }
        self::invoke(function () {
            return odbc_free_result($this->stmt);
        });
        $this->stmt = null;
        $this->described = false;
        $this->columns = [];
    }

    /**
     * @return array{0:string,1:int|null,2:string}
     */
    public function errorInfo()
    {
        return $this->einfo->info();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function describe()
    {
        if (!self::isHandle($this->stmt)) {
            return [];
        }
        list($count, ) = self::invoke(function () {
            return odbc_num_fields($this->stmt);
        });
        $count = (int) $count;
        $columns = [];
        for ($column = 1; $column <= $count; $column++) {
            $name = odbc_field_name($this->stmt, $column);
            $native = odbc_field_type($this->stmt, $column);
            $length = odbc_field_len($this->stmt, $column);
            $scale = function_exists('odbc_field_scale') ? odbc_field_scale($this->stmt, $column) : 0;
            $precision = function_exists('odbc_field_precision') ? odbc_field_precision($this->stmt, $column) : $length;
            $nativeName = $native === false || $native === null ? '' : (string) $native;
            $columns[] = [
                'name' => $name === false || $name === null ? ('column' . $column) : (string) $name,
                'native_type' => $nativeName,
                'len' => (int) $length,
                'precision' => (int) $precision,
                'scale' => (int) $scale,
                'pdo_type' => self::pdoType($nativeName),
            ];
        }
        return $columns;
    }

    private static function pdoType($native)
    {
        $native = strtoupper($native);
        $ints = ['INTEGER', 'LONG', 'SHORT', 'TINYINT', 'BIGINT', 'INT', 'SMALLINT', 'COUNTER', 'SERIAL', 'BIT', 'BOOLEAN', 'BOOL'];
        $lobs = ['BLOB', 'BINARY', 'VARBINARY', 'LONGVARBINARY', 'IMAGE', 'CLOB', 'LONGVARCHAR'];
        if (in_array($native, $ints, true)) {
            return PDO::PARAM_INT;
        }
        if (in_array($native, $lobs, true)) {
            return PDO::PARAM_LOB;
        }
        return PDO::PARAM_STR;
    }

    private function record($handle, $warning, $what, $fallback)
    {
        $this->einfo->set(self::stateOf($handle), null, self::messageOf($handle, $warning, $fallback), $what);
        $this->client->absorb($this->einfo);
    }
}
