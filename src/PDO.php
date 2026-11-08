<?php

namespace PhpPdo;

class PDO
{
    const PARAM_NULL = 0;
    const PARAM_INT = 1;
    const PARAM_STR = 2;
    const PARAM_LOB = 3;
    const PARAM_STMT = 4;
    const PARAM_BOOL = 5;
    const PARAM_INPUT_OUTPUT = (PHP_INT_SIZE < 8 || PHP_OS_FAMILY === 'Windows') ? (-2147483647 - 1) : 2147483648;
    const PARAM_STR_NATL = 1073741824;
    const PARAM_STR_CHAR = 536870912;

    const PARAM_EVT_ALLOC = 0;
    const PARAM_EVT_FREE = 1;
    const PARAM_EVT_EXEC_PRE = 2;
    const PARAM_EVT_EXEC_POST = 3;
    const PARAM_EVT_FETCH_PRE = 4;
    const PARAM_EVT_FETCH_POST = 5;
    const PARAM_EVT_NORMALIZE = 6;

    const FETCH_LAZY = 1;
    const FETCH_ASSOC = 2;
    const FETCH_NUM = 3;
    const FETCH_BOTH = 4;
    const FETCH_OBJ = 5;
    const FETCH_BOUND = 6;
    const FETCH_COLUMN = 7;
    const FETCH_CLASS = 8;
    const FETCH_INTO = 9;
    const FETCH_FUNC = 10;
    const FETCH_NAMED = 11;
    const FETCH_KEY_PAIR = 12;
    const FETCH_GROUP = 65536;
    const FETCH_UNIQUE = 196608;
    const FETCH_CLASSTYPE = 262144;
    const FETCH_SERIALIZE = 524288;
    const FETCH_PROPS_LATE = 1048576;

    const FETCH_ORI_NEXT = 0;
    const FETCH_ORI_PRIOR = 1;
    const FETCH_ORI_FIRST = 2;
    const FETCH_ORI_LAST = 3;
    const FETCH_ORI_ABS = 4;
    const FETCH_ORI_REL = 5;

    const ATTR_AUTOCOMMIT = 0;
    const ATTR_PREFETCH = 1;
    const ATTR_TIMEOUT = 2;
    const ATTR_ERRMODE = 3;
    const ATTR_SERVER_VERSION = 4;
    const ATTR_CLIENT_VERSION = 5;
    const ATTR_SERVER_INFO = 6;
    const ATTR_CONNECTION_STATUS = 7;
    const ATTR_CASE = 8;
    const ATTR_CURSOR_NAME = 9;
    const ATTR_CURSOR = 10;
    const ATTR_ORACLE_NULLS = 11;
    const ATTR_PERSISTENT = 12;
    const ATTR_STATEMENT_CLASS = 13;
    const ATTR_FETCH_TABLE_NAMES = 14;
    const ATTR_FETCH_CATALOG_NAMES = 15;
    const ATTR_DRIVER_NAME = 16;
    const ATTR_STRINGIFY_FETCHES = 17;
    const ATTR_MAX_COLUMN_LEN = 18;
    const ATTR_DEFAULT_FETCH_MODE = 19;
    const ATTR_EMULATE_PREPARES = 20;
    const ATTR_DEFAULT_STR_PARAM = 21;

    const ERRMODE_SILENT = 0;
    const ERRMODE_WARNING = 1;
    const ERRMODE_EXCEPTION = 2;

    const CASE_NATURAL = 0;
    const CASE_UPPER = 1;
    const CASE_LOWER = 2;

    const NULL_NATURAL = 0;
    const NULL_EMPTY_STRING = 1;
    const NULL_TO_STRING = 2;

    const ERR_NONE = '00000';

    const CURSOR_FWDONLY = 0;
    const CURSOR_SCROLL = 1;

    const ODBC_ATTR_USE_CURSOR_LIBRARY = 1000;
    const ODBC_ATTR_ASSUME_UTF8 = 1001;

    const MYSQL_ATTR_USE_BUFFERED_QUERY = 1000;
    const MYSQL_ATTR_LOCAL_INFILE = 1001;
    const MYSQL_ATTR_INIT_COMMAND = 1002;
    const MYSQL_ATTR_COMPRESS = 1003;
    const MYSQL_ATTR_DIRECT_QUERY = 1004;
    const MYSQL_ATTR_FOUND_ROWS = 1005;
    const MYSQL_ATTR_IGNORE_SPACE = 1006;
    const MYSQL_ATTR_SSL_KEY = 1007;
    const MYSQL_ATTR_SSL_CERT = 1008;
    const MYSQL_ATTR_SSL_CA = 1009;
    const MYSQL_ATTR_SSL_CAPATH = 1010;
    const MYSQL_ATTR_SSL_CIPHER = 1011;
    const MYSQL_ATTR_SERVER_PUBLIC_KEY = 1012;
    const MYSQL_ATTR_MULTI_STATEMENTS = 1013;
    const MYSQL_ATTR_SSL_VERIFY_SERVER_CERT = 1014;

    const PGSQL_ATTR_DISABLE_PREPARES = 1000;
    const PGSQL_TRANSACTION_IDLE = 0;
    const PGSQL_TRANSACTION_ACTIVE = 1;
    const PGSQL_TRANSACTION_INTRANS = 2;
    const PGSQL_TRANSACTION_INERROR = 3;
    const PGSQL_TRANSACTION_UNKNOWN = 4;

    /** @var OdbcClient|null */
    private $driver;

    /** @var bool */
    private $persistent = false;

    /** @var bool */
    private $inTransaction = false;

    /** @var int */
    private $errMode = self::ERRMODE_SILENT;

    /** @var int */
    private $case = self::CASE_NATURAL;

    /** @var int */
    private $nullMode = self::NULL_NATURAL;

    /** @var bool */
    private $stringify = false;

    /** @var bool */
    private $emulatePrepares = false;

    /** @var bool */
    private $autoCommit = true;

    /** @var int */
    private $defaultFetchMode = self::FETCH_BOTH;

    /** @var int */
    private $defaultStrParam = self::PARAM_STR;

    /** @var int */
    private $maxColumnLen = 0;

    /** @var int */
    private $timeout = 0;

    /** @var int */
    private $cursorLibrary = 0;

    /** @var bool */
    private $fetchTableNames = false;

    /** @var bool */
    private $fetchCatalogNames = false;

    /** @var string */
    private $statementClass = PDOStatement::class;

    /** @var array<int,mixed> */
    private $statementCtor = [];

    /** @var string|null */
    private $errorCode;

    /** @var array<int,mixed> */
    private $errorInfo = [null, null, null];

    /** @var string */
    private $dsnRest = '';

    /**
     * @param string $dsn
     * @param string|null $username
     * @param string|null $password
     * @param array<int|string,mixed>|null $options
     */
    public function __construct($dsn, $username = null, $password = null, array $options = null)
    {
        list($scheme, $this->dsnRest) = self::parseDsn($dsn);
        $options = $options === null ? [] : $options;
        if (isset($options[self::ATTR_PERSISTENT])) {
            $this->persistent = (bool) $options[self::ATTR_PERSISTENT];
        }
        if (isset($options[self::ODBC_ATTR_USE_CURSOR_LIBRARY])) {
            $this->cursorLibrary = (int) $options[self::ODBC_ATTR_USE_CURSOR_LIBRARY];
        }
        if (isset($options[self::ATTR_ERRMODE])) {
            $this->errMode = (int) $options[self::ATTR_ERRMODE];
        }

        if ($scheme === 'mysql') {
            if (!MysqlLibrary::available()) {
                throw new PDOException('could not find driver');
            }
            $this->emulatePrepares = true;
            $this->defaultStrParam = self::PARAM_STR_CHAR;
            $this->driver = MysqlClient::connect($this->dsnRest, $username, $password, $this->persistent, $options);
        } elseif ($scheme === 'pgsql') {
            if (!PgsqlLibrary::available()) {
                throw new PDOException('could not find driver');
            }
            $this->emulatePrepares = false;
            $this->driver = PgsqlClient::connect($this->dsnRest, $username, $password, $this->persistent);
        } else {
            $this->driver = OdbcClient::connect(
                $this->dsnRest,
                $username,
                $password,
                $this->cursorLibrary,
                $this->persistent
            );
        }
        if (!$this->driver->connected()) {
            $info = $this->driver->errorInfo();
            throw new PDOException(
                'SQLSTATE[' . $info[0] . ']: ' . $info[2],
                $info[0],
                null,
                $info
            );
        }

        foreach ($options as $name => $value) {
            if ($name === self::ATTR_PERSISTENT || $name === self::ODBC_ATTR_USE_CURSOR_LIBRARY || $name === self::ATTR_ERRMODE) {
                continue;
            }
            if (!$this->setAttribute($name, $value)) {
                $info = $this->errorInfo;
                $state = $this->errorCode !== null ? $this->errorCode : 'HY000';
                $message = isset($info[2]) ? (string) $info[2] : 'unsupported attribute';
                throw new PDOException('SQLSTATE[' . $state . ']: ' . $message, $state, null, $info);
            }
        }
    }

    public function beginTransaction()
    {
        $this->assertOpen();
        if ($this->inTransaction) {
            $this->throwAlways('HY000', 'There is already an active transaction');
        }
        if (!$this->driver->begin($this->autoCommit)) {
            return $this->failDriver();
        }
        $this->inTransaction = true;
        $this->clearError();
        return true;
    }

    public function commit()
    {
        $this->assertOpen();
        if (!$this->inTransaction) {
            $this->throwAlways('HY000', 'There is no active transaction');
        }
        if (!$this->driver->commit($this->autoCommit)) {
            return $this->failDriver();
        }
        $this->inTransaction = false;
        $this->clearError();
        return true;
    }

    public function rollBack()
    {
        $this->assertOpen();
        if (!$this->inTransaction) {
            $this->throwAlways('HY000', 'There is no active transaction');
        }
        if (!$this->driver->rollBack($this->autoCommit)) {
            return $this->failDriver();
        }
        $this->inTransaction = false;
        $this->clearError();
        return true;
    }

    public function inTransaction()
    {
        return $this->inTransaction;
    }

    public function errorCode()
    {
        return $this->errorCode;
    }

    public function errorInfo()
    {
        return $this->errorInfo;
    }

    public function exec($statement)
    {
        $this->assertOpen();
        $count = $this->driver->exec((string) $statement);
        if ($count === false) {
            return $this->failDriver();
        }
        $this->clearError();
        return $count;
    }

    /**
     * Режим ошибок без сброса SQLSTATE. getAttribute() сбрасывает его, как PDO.
     */
    public function errorMode()
    {
        return $this->errMode;
    }

    public function getAttribute($attribute)
    {
        $this->clearError();
        switch ((int) $attribute) {
            case self::ATTR_AUTOCOMMIT:
                return $this->autoCommit;
            case self::ATTR_ERRMODE:
                return $this->errMode;
            case self::ATTR_CASE:
                return $this->case;
            case self::ATTR_ORACLE_NULLS:
                return $this->nullMode;
            case self::ATTR_STRINGIFY_FETCHES:
                return $this->stringify;
            case self::ATTR_EMULATE_PREPARES:
                return $this->emulatePrepares;
            case self::ATTR_DEFAULT_FETCH_MODE:
                return $this->defaultFetchMode;
            case self::ATTR_DEFAULT_STR_PARAM:
                return $this->defaultStrParam;
            case self::ATTR_PERSISTENT:
                return $this->persistent;
            case self::ATTR_DRIVER_NAME:
                return $this->driverName();
            case self::ATTR_CLIENT_VERSION:
                if ($this->driverName() === 'mysql' || $this->driverName() === 'pgsql') {
                    return $this->driver->clientVersion();
                }
                $version = phpversion('odbc');
                return 'ODBC-' . ($version !== false && $version !== '' ? $version : PHP_VERSION);
            case self::ATTR_SERVER_VERSION:
                if ($this->driverName() === 'mysql' || $this->driverName() === 'pgsql') {
                    return $this->driver->serverVersion();
                }
                return null;
            case self::ATTR_SERVER_INFO:
                if ($this->driverName() === 'mysql' || $this->driverName() === 'pgsql') {
                    return $this->driver->serverInfo();
                }
                return null;
            case self::ATTR_PREFETCH:
            case self::ATTR_CURSOR_NAME:
                return null;
            case self::ATTR_CONNECTION_STATUS:
                if ($this->driverName() === 'pgsql') {
                    return $this->driver->connectionStatus();
                }
                return $this->driver !== null && $this->driver->connected() ? 'Connected' : null;
            case self::ATTR_STATEMENT_CLASS:
                return [$this->statementClass, $this->statementCtor];
            case self::ATTR_MAX_COLUMN_LEN:
                return $this->maxColumnLen;
            case self::ATTR_TIMEOUT:
                return $this->timeout;
            case self::ATTR_FETCH_TABLE_NAMES:
                return $this->fetchTableNames;
            case self::ATTR_FETCH_CATALOG_NAMES:
                return $this->fetchCatalogNames;
            case self::ODBC_ATTR_USE_CURSOR_LIBRARY:
                if ($this->driverName() === 'mysql') {
                    return $this->driver->mysqlAttribute(self::MYSQL_ATTR_USE_BUFFERED_QUERY);
                }
                if ($this->driverName() === 'pgsql') {
                    return $this->driver->pgsqlAttribute(self::PGSQL_ATTR_DISABLE_PREPARES);
                }
                return $this->cursorLibrary;
            case self::ODBC_ATTR_ASSUME_UTF8:
                if ($this->driverName() === 'mysql') {
                    return $this->driver->mysqlAttribute(self::MYSQL_ATTR_LOCAL_INFILE);
                }
                return $this->driver !== null && $this->driver->assumeUtf8();
            default:
                if ($this->driverName() === 'mysql' && (int) $attribute === self::MYSQL_ATTR_DIRECT_QUERY) {
                    return $this->emulatePrepares;
                }
                if ($this->driverName() === 'mysql' && (int) $attribute >= self::MYSQL_ATTR_INIT_COMMAND && (int) $attribute <= self::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT) {
                    return $this->driver->mysqlAttribute((int) $attribute);
                }
                $this->fail('IM001', 'driver does not support this attribute');
                return null;
        }
    }

    public static function getAvailableDrivers()
    {
        $drivers = [];
        if (MysqlLibrary::available()) {
            $drivers[] = 'mysql';
        }
        if (PgsqlLibrary::available()) {
            $drivers[] = 'pgsql';
        }
        if (extension_loaded('odbc')) {
            $drivers[] = 'odbc';
        }
        return $drivers;
    }

    public function lastInsertId($name = null)
    {
        $this->assertOpen();
        if ($this->driverName() === 'mysql') {
            unset($name);
            $this->clearError();
            return $this->driver->insertId();
        }
        if ($this->driverName() === 'pgsql') {
            $id = $this->driver->insertId($name);
            if ($id === null) {
                return $this->failDriver();
            }
            $this->clearError();
            return $id;
        }
        unset($name);
        return $this->fail('IM001', 'driver does not support lastInsertId()');
    }

    public function prepare($statement, array $options = [])
    {
        $this->assertOpen();
        $parsed = SqlParser::parse($statement);
        if ($parsed['error'] !== null) {
            return $this->fail('HY093', $parsed['error']);
        }

        $class = $this->statementClass;
        $ctor = $this->statementCtor;
        if (isset($options[self::ATTR_STATEMENT_CLASS])) {
            $resolved = $this->resolveStatementClass($options[self::ATTR_STATEMENT_CLASS]);
            if ($resolved === null) {
                return false;
            }
            $class = $resolved[0];
            $ctor = $resolved[1];
        }

        $emulate = $this->emulatePrepares;
        if (array_key_exists(self::ATTR_EMULATE_PREPARES, $options)) {
            $emulate = (bool) $options[self::ATTR_EMULATE_PREPARES];
        }
        if (!$emulate && $this->driverName() === 'pgsql') {
            $parsed['nativeSql'] = SqlParser::dollars($parsed['parts']);
        }

        $handle = null;
        if (!$emulate) {
            $handle = $this->driver->prepare($parsed['nativeSql']);
            if ($handle === null) {
                return $this->failDriver();
            }
        }

        $this->clearError();
        return PDOStatement::create($this, (string) $statement, $parsed, $class, $ctor, $options, $handle, $emulate);
    }

    public function query($statement)
    {
        $args = func_get_args();
        if (count($args) > 1) {
            $this->assertQueryFetchArgs($args);
        }
        $this->assertOpen();
        $this->clearError();
        $executed = $this->driver->query((string) $statement);
        if ($executed === null) {
            return $this->failDriver();
        }

        $stmt = PDOStatement::fromExecuted(
            $this,
            (string) $statement,
            $executed,
            $this->statementClass,
            $this->statementCtor
        );
        $args = func_get_args();
        if (count($args) > 1) {
            call_user_func_array([$stmt, 'setFetchMode'], array_slice($args, 1));
        }
        return $stmt;
    }

    /**
     * @return bool
     */
    public function sendQueryAsync($statement)
    {
        $this->assertOpen();
        $this->clearError();
        $name = $this->driverName();
        if ($name !== 'mysql' && $name !== 'pgsql') {
            return $this->fail('IM001', 'driver does not support async queries');
        }
        if (!$this->driver->sendQueryAsync((string) $statement)) {
            return $this->failDriver();
        }
        return true;
    }

    /**
     * @return bool
     */
    public function readQueryResult()
    {
        $this->assertOpen();
        $this->clearError();
        if ($this->driverName() !== 'mysql') {
            return $this->fail('IM001', 'driver does not support this async operation');
        }
        if (!$this->driver->readQueryResult()) {
            return $this->failDriver();
        }
        return true;
    }

    /**
     * @return PDOStatement|false|null false — результат ещё не готов (pgsql, non-blocking)
     */
    public function receiveQueryResult()
    {
        $this->assertOpen();
        $this->clearError();
        $name = $this->driverName();
        if ($name !== 'mysql' && $name !== 'pgsql') {
            return $this->fail('IM001', 'driver does not support async queries');
        }
        $executed = $this->driver->receiveQueryResult();
        if ($executed === null) {
            if ($this->driver->hasAsyncQueryPending() && $name === 'pgsql') {
                return false;
            }
            return $this->failDriver();
        }
        return PDOStatement::fromExecuted(
            $this,
            '',
            $executed,
            $this->statementClass,
            $this->statementCtor
        );
    }

    /**
     * @return int|null
     */
    public function connectionSocket()
    {
        $this->assertOpen();
        if ($this->driverName() !== 'mysql' && $this->driverName() !== 'pgsql') {
            return null;
        }
        return $this->driver->connectionSocket();
    }

    /**
     * @return bool
     */
    public function setNonBlocking($enable)
    {
        $this->assertOpen();
        $this->clearError();
        if ($this->driverName() !== 'pgsql') {
            return $this->fail('IM001', 'driver does not support non-blocking mode');
        }
        if (!$this->driver->setNonBlocking($enable)) {
            return $this->failDriver();
        }
        return true;
    }

    /**
     * @return bool
     */
    public function consumeInput()
    {
        $this->assertOpen();
        $this->clearError();
        if ($this->driverName() !== 'pgsql') {
            return $this->fail('IM001', 'driver does not support consumeInput');
        }
        if (!$this->driver->consumeInput()) {
            return $this->failDriver();
        }
        return true;
    }

    /**
     * @return bool
     */
    public function isQueryBusy()
    {
        $this->assertOpen();
        if ($this->driverName() !== 'pgsql') {
            return false;
        }
        return $this->driver->isQueryBusy();
    }

    /**
     * @return bool
     */
    public function flushConnection()
    {
        $this->assertOpen();
        $this->clearError();
        if ($this->driverName() !== 'pgsql') {
            return $this->fail('IM001', 'driver does not support flushConnection');
        }
        if (!$this->driver->flushConnection()) {
            return $this->failDriver();
        }
        return true;
    }

    public function quote($string, $type = self::PARAM_STR)
    {
        $this->assertOpen();
        $this->clearError();
        if ($this->driverName() === 'mysql') {
            return $this->driver->quote($string, $type, $this->defaultStrParam);
        }
        if ($this->driverName() === 'pgsql') {
            $quoted = $this->driver->quote($string, $type);
            if ($quoted === null) {
                return $this->failDriver();
            }
            return $quoted;
        }
        return SqlLiteral::render($string, $type);
    }

    public function setAttribute($attribute, $value)
    {
        $this->clearError();
        switch ((int) $attribute) {
            case self::ATTR_AUTOCOMMIT:
                if ($this->inTransaction) {
                    return $this->fail('HY000', 'Cannot change autocommit mode while a transaction is already open');
                }
                $enabled = (bool) $value;
                if ($this->driver !== null && $this->driver->connected() && $enabled !== $this->autoCommit) {
                    if (!$this->driver->setConnectAutoCommit($enabled)) {
                        return $this->failDriver();
                    }
                }
                $this->autoCommit = $enabled;
                return true;
            case self::ATTR_ERRMODE:
                $mode = (int) $value;
                if ($mode !== self::ERRMODE_SILENT && $mode !== self::ERRMODE_WARNING && $mode !== self::ERRMODE_EXCEPTION) {
                    return $this->fail('HY000', 'invalid error mode');
                }
                $this->errMode = $mode;
                return true;
            case self::ATTR_CASE:
                $case = (int) $value;
                if ($case !== self::CASE_NATURAL && $case !== self::CASE_LOWER && $case !== self::CASE_UPPER) {
                    return $this->fail('HY000', 'invalid case folding');
                }
                $this->case = $case;
                return true;
            case self::ATTR_ORACLE_NULLS:
                $mode = (int) $value;
                if ($mode !== self::NULL_NATURAL && $mode !== self::NULL_EMPTY_STRING && $mode !== self::NULL_TO_STRING) {
                    return $this->fail('HY000', 'invalid null conversion');
                }
                $this->nullMode = $mode;
                return true;
            case self::ATTR_STRINGIFY_FETCHES:
                $this->stringify = (bool) $value;
                return true;
            case self::ATTR_EMULATE_PREPARES:
                $this->emulatePrepares = (bool) $value;
                return true;
            case self::ATTR_DEFAULT_FETCH_MODE:
                $this->defaultFetchMode = (int) $value;
                return true;
            case self::ATTR_DEFAULT_STR_PARAM:
                $this->defaultStrParam = (int) $value;
                return true;
            case self::ATTR_MAX_COLUMN_LEN:
                $this->maxColumnLen = (int) $value;
                return true;
            case self::ATTR_TIMEOUT:
                $this->timeout = (int) $value;
                if ($this->driver !== null) {
                    $this->driver->setTimeout($this->timeout);
                }
                return true;
            case self::ATTR_STATEMENT_CLASS:
                $resolved = $this->resolveStatementClass($value);
                if ($resolved === null) {
                    return false;
                }
                $this->statementClass = $resolved[0];
                $this->statementCtor = $resolved[1];
                return true;
            case self::ATTR_FETCH_TABLE_NAMES:
                $this->fetchTableNames = (bool) $value;
                return true;
            case self::ATTR_FETCH_CATALOG_NAMES:
                $this->fetchCatalogNames = (bool) $value;
                return true;
            case self::ODBC_ATTR_ASSUME_UTF8:
                if ($this->driverName() === 'mysql') {
                    return $this->driver->setMysqlAttribute(self::MYSQL_ATTR_LOCAL_INFILE, $value);
                }
                if ($this->driver !== null) {
                    $this->driver->setAssumeUtf8((bool) $value);
                }
                return true;
            case self::ODBC_ATTR_USE_CURSOR_LIBRARY:
                if ($this->driverName() === 'mysql') {
                    return $this->driver->setMysqlAttribute(self::MYSQL_ATTR_USE_BUFFERED_QUERY, $value);
                }
                if ($this->driverName() === 'pgsql') {
                    return $this->driver->setPgsqlAttribute(self::PGSQL_ATTR_DISABLE_PREPARES, $value);
                }
                return $this->fail('HY000', 'ODBC_ATTR_USE_CURSOR_LIBRARY can only be set in the constructor');
            case self::MYSQL_ATTR_INIT_COMMAND:
            case self::MYSQL_ATTR_COMPRESS:
            case self::MYSQL_ATTR_FOUND_ROWS:
            case self::MYSQL_ATTR_IGNORE_SPACE:
            case self::MYSQL_ATTR_SSL_KEY:
            case self::MYSQL_ATTR_SSL_CERT:
            case self::MYSQL_ATTR_SSL_CA:
            case self::MYSQL_ATTR_SSL_CAPATH:
            case self::MYSQL_ATTR_SSL_CIPHER:
            case self::MYSQL_ATTR_SERVER_PUBLIC_KEY:
            case self::MYSQL_ATTR_MULTI_STATEMENTS:
            case self::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT:
                if ($this->driverName() !== 'mysql') {
                    return $this->fail('IM001', 'driver does not support this attribute');
                }
                return $this->driver->setMysqlAttribute((int) $attribute, $value);
            case self::MYSQL_ATTR_DIRECT_QUERY:
                if ($this->driverName() !== 'mysql') {
                    return $this->fail('IM001', 'driver does not support this attribute');
                }
                $this->emulatePrepares = (bool) $value;
                return true;
            case self::ATTR_PERSISTENT:
                return $this->fail('HY000', 'PDO::ATTR_PERSISTENT can only be set in the constructor');
            default:
                return $this->fail('IM001', 'driver does not support this attribute');
        }
    }

    /**
     * @return OdbcClient
     */
    public function driver()
    {
        return $this->driver;
    }

    /**
     * @return resource|object|null
     */
    public function nativeConnection()
    {
        return $this->driver === null ? null : $this->driver->handle();
    }

    /**
     * @param string $state
     * @param int|null $native
     * @param string|null $message
     */
    public function adoptError($state, $native, $message)
    {
        $this->errorCode = $state;
        $this->errorInfo = [$state, $native, $message];
    }

    public function __sleep()
    {
        throw new \Exception("Serialization of 'PDO' is not allowed");
    }

    public function __wakeup()
    {
        throw new \Exception("Unserialization of 'PDO' is not allowed");
    }

    public function __destruct()
    {
        if ($this->driver === null) {
            return;
        }
        $this->driver->close($this->inTransaction);
        $this->inTransaction = false;
    }

    private static function parseDsn($dsn)
    {
        $dsn = (string) $dsn;
        $colon = strpos($dsn, ':');
        if ($colon === false || $colon === 0) {
            throw new PDOException('invalid data source name');
        }
        $driver = strtolower(substr($dsn, 0, $colon));
        $rest = substr($dsn, $colon + 1);
        if ($driver !== 'odbc' && $driver !== 'mysql' && $driver !== 'pgsql') {
            throw new PDOException('could not find driver');
        }
        if ($rest === '') {
            throw new PDOException('invalid data source name');
        }
        return [$driver, $rest];
    }

    private function driverName()
    {
        if ($this->driver !== null && method_exists($this->driver, 'name')) {
            return $this->driver->name();
        }
        return 'odbc';
    }

    /**
     * @param mixed $value
     * @return array{0:string,1:array<int,mixed>}|null
     */
    private function resolveStatementClass($value)
    {
        if (!is_array($value) || !isset($value[0]) || !is_string($value[0]) || $value[0] === '') {
            $this->fail('HY000', 'PDO::ATTR_STATEMENT_CLASS requires array(classname, ctor_args)');
            return null;
        }
        $ctor = [];
        if (isset($value[1])) {
            if (!is_array($value[1])) {
                $this->fail('HY000', 'constructor arguments must be an array');
                return null;
            }
            $ctor = $value[1];
        }
        if (!class_exists($value[0])) {
            $this->fail('HY000', 'user-supplied statement class must be a valid class');
            return null;
        }
        if (!is_a($value[0], PDOStatement::class, true)) {
            $this->fail('HY000', 'user-supplied statement class must be derived from PDOStatement');
            return null;
        }
        $reflection = new \ReflectionClass($value[0]);
        if ($reflection->isAbstract()) {
            $this->fail('HY000', 'user-supplied statement class must not be abstract');
            return null;
        }
        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->isPublic() && strcasecmp($constructor->getDeclaringClass()->getName(), PDOStatement::class) !== 0 && strcasecmp($constructor->getDeclaringClass()->getName(), 'PDOStatement') !== 0) {
            $this->fail('HY000', 'user-supplied statement class cannot have a public constructor');
            return null;
        }
        return [$value[0], $ctor];
    }

    /**
     * @param array<int,mixed> $args
     */
    private function assertQueryFetchArgs(array $args)
    {
        $mode = $args[1];
        if (!is_int($mode)) {
            $type = gettype($mode);
            if ($type === 'integer') {
                $type = 'int';
            } elseif ($type === 'NULL') {
                $type = 'null';
            }
            throw new \TypeError('PDO::query(): Argument #2 ($fetchMode) must be of type ?int, ' . $type . ' given');
        }

        $variadic = count($args) - 2;
        $total = count($args);
        $type = Flags::fetchType($mode);
        $flags = Flags::fetchFlags($mode);
        if ($type === self::FETCH_COLUMN || $type === self::FETCH_INTO) {
            if ($variadic !== 1) {
                throw new \ArgumentCountError('PDO::query() expects exactly 3 arguments for the fetch mode provided, ' . $total . ' given');
            }
            return;
        }
        if ($type === self::FETCH_CLASS) {
            if (($flags & self::FETCH_CLASSTYPE) === self::FETCH_CLASSTYPE) {
                if ($variadic !== 0) {
                    throw new \ArgumentCountError('PDO::query() expects exactly 2 arguments for the fetch mode provided, ' . $total . ' given');
                }
                return;
            }
            if ($variadic === 0) {
                throw new \ArgumentCountError('PDO::query() expects at least 3 arguments for the fetch mode provided, ' . $total . ' given');
            }
            if ($variadic > 2) {
                throw new \ArgumentCountError('PDO::query() expects at most 4 arguments for the fetch mode provided, ' . $total . ' given');
            }
            return;
        }
        if ($variadic !== 0) {
            throw new \ArgumentCountError('PDO::query() expects exactly 2 arguments for the fetch mode provided, ' . $total . ' given');
        }
    }

    private function assertOpen()
    {
        if ($this->driver === null || !$this->driver->connected()) {
            $this->throwAlways('HY000', 'connection is closed');
        }
    }

    private function clearError()
    {
        $this->errorCode = self::ERR_NONE;
        $this->errorInfo = [self::ERR_NONE, null, null];
    }

    private function throwAlways($state, $message)
    {
        $this->errorCode = $state;
        $this->errorInfo = [$state, null, $message];
        throw new PDOException('SQLSTATE[' . $state . ']: ' . $message, $state, null, $this->errorInfo);
    }

    private function failDriver()
    {
        $info = $this->driver->errorInfo();
        return $this->fail($info[0], $info[2], $info[1], $info[2]);
    }

    private function fail($state, $message, $native = null, $driverMessage = null)
    {
        $driverMessage = $driverMessage === null ? $message : $driverMessage;
        $this->errorCode = $state;
        $this->errorInfo = [$state, $native, $driverMessage];
        $text = 'SQLSTATE[' . $state . ']: ' . $message;
        if ($this->errMode === self::ERRMODE_EXCEPTION) {
            throw new PDOException($text, $state, null, $this->errorInfo);
        }
        if ($this->errMode === self::ERRMODE_WARNING) {
            trigger_error($text, E_USER_WARNING);
        }
        return false;
    }
}
