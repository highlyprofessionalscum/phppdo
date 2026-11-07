<?php

namespace PhpPdo;

/**
 * Клиент MariaDB/MySQL через PHP FFI и libmysqlclient.
 * Текстовый протокол повторяет pdo_mysql с эмуляцией prepare.
 * Нативный prepare использует mysql_stmt_* и MYSQL_BIND libmariadb 3.x (LP64).
 */
final class MysqlLibrary
{
    /** @var \FFI|null */
    private static $ffi;

    /** @var bool */
    private static $unavailable = false;

    public static function available()
    {
        if (self::$unavailable) {
            return false;
        }
        try {
            return self::ffi() instanceof \FFI;
        } catch (\Throwable $exception) {
            unset($exception);
            self::$unavailable = true;
            return false;
        }
    }

    /**
     * @return \FFI
     */
    public static function ffi()
    {
        if (self::$ffi instanceof \FFI) {
            return self::$ffi;
        }
        if (!extension_loaded('ffi')) {
            throw new \RuntimeException('ext-ffi is not loaded');
        }
        $last = null;
        foreach (self::candidates() as $path) {
            try {
                self::$ffi = \FFI::cdef(self::header(), $path);
                return self::$ffi;
            } catch (\Throwable $exception) {
                $last = $exception;
            }
        }
        throw $last !== null ? $last : new \RuntimeException('libmysqlclient was not found');
    }

    /**
     * @return array<int,string>
     */
    private static function candidates()
    {
        $candidates = [];
        $env = getenv('PDO_MYSQL_LIBRARY');
        if (is_string($env) && $env !== '') {
            $candidates[] = $env;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            return array_merge($candidates, self::windowsLibraries());
        }
        return array_merge($candidates, [
            '/usr/lib/x86_64-linux-gnu/libmariadb.so.3',
            '/usr/lib/aarch64-linux-gnu/libmariadb.so.3',
            'libmariadb.so.3',
            'libmysqlclient.so.21',
            'libmysqlclient.so.18',
        ]);
    }

    /**
     * Полные пути к libmariadb.dll и libmysql.dll. FFI на Windows не ищет их рядом с сервером.
     *
     * @return array<int,string>
     */
    private static function windowsLibraries()
    {
        $names = array('libmariadb.dll', 'libmysql.dll');
        $dirs = array();
        $binary = defined('PHP_BINARY') ? dirname(PHP_BINARY) : '';
        if ($binary !== '') {
            $dirs[] = $binary;
            $modules = dirname($binary);
            if (is_dir($modules)) {
                $entries = scandir($modules);
                if (is_array($entries)) {
                    foreach ($entries as $entry) {
                        if (stripos($entry, 'MariaDB') === false && stripos($entry, 'MySQL') === false) {
                            continue;
                        }
                        $dirs[] = $modules . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . 'lib';
                        $dirs[] = $modules . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . 'bin';
                    }
                }
            }
        }
        $path = getenv('PATH');
        if (is_string($path) && $path !== '') {
            foreach (explode(PATH_SEPARATOR, $path) as $dir) {
                if ($dir !== '') {
                    $dirs[] = $dir;
                }
            }
        }
        foreach (array(
            'C:\\Program Files\\MariaDB*',
            'C:\\Program Files\\MySQL\\MySQL Server*',
            'C:\\Program Files (x86)\\MariaDB*',
            'C:\\Program Files (x86)\\MySQL\\MySQL Server*',
        ) as $pattern) {
            $matches = glob($pattern, GLOB_ONLYDIR);
            if (!is_array($matches)) {
                continue;
            }
            foreach ($matches as $dir) {
                $dirs[] = $dir . '\\lib';
                $dirs[] = $dir . '\\bin';
            }
        }
        $found = array();
        foreach ($dirs as $dir) {
            foreach ($names as $name) {
                $file = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . $name;
                if (is_file($file)) {
                    $found[$file] = $file;
                }
            }
        }
        return array_merge(array_values($found), $names);
    }

    /**
     * const char* из FFI уже приходит строкой PHP.
     *
     * @param mixed $value
     */
    public static function text($value)
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value instanceof \FFI\CData) {
            return \FFI::string($value);
        }
        return '';
    }

    private static function header()
    {
        return <<<'C'
typedef struct st_mysql { int unused; } MYSQL;
typedef struct st_mysql_res { int unused; } MYSQL_RES;
typedef struct st_mysql_stmt { int unused; } MYSQL_STMT;
typedef struct st_mysql_field {
    char *name;
    char *org_name;
    char *table;
    char *org_table;
    char *db;
    char *catalog;
    char *def;
    unsigned long length;
    unsigned long max_length;
    unsigned int name_length;
    unsigned int org_name_length;
    unsigned int table_length;
    unsigned int org_table_length;
    unsigned int db_length;
    unsigned int catalog_length;
    unsigned int def_length;
    unsigned int flags;
    unsigned int decimals;
    unsigned int charsetnr;
    int type;
    void *extension;
} MYSQL_FIELD;
typedef struct st_mysql_bind {
    unsigned long *length;
    char *is_null;
    void *buffer;
    char *error;
    unsigned char *row_ptr;
    void *store_param_func;
    void *fetch_result;
    void *skip_result;
    unsigned long buffer_length;
    unsigned long offset;
    unsigned long length_value;
    unsigned int param_number;
    unsigned int pack_length;
    int buffer_type;
    char error_value;
    char is_unsigned;
    char long_data_used;
    char is_null_value;
    char pad[4];
    void *extension;
} MYSQL_BIND;

MYSQL *mysql_init(MYSQL *mysql);
MYSQL *mysql_real_connect(MYSQL *mysql, const char *host, const char *user, const char *passwd, const char *db, unsigned int port, const char *unix_socket, unsigned long client_flag);
void mysql_close(MYSQL *mysql);
int mysql_ping(MYSQL *mysql);
int mysql_options(MYSQL *mysql, int option, const void *arg);
int mysql_set_character_set(MYSQL *mysql, const char *csname);
int mysql_real_query(MYSQL *mysql, const char *query, unsigned long length);
int mysql_select_db(MYSQL *mysql, const char *db);
MYSQL_RES *mysql_store_result(MYSQL *mysql);
void mysql_free_result(MYSQL_RES *result);
unsigned int mysql_field_count(MYSQL *mysql);
unsigned int mysql_num_fields(MYSQL_RES *result);
unsigned int mysql_errno(MYSQL *mysql);
const char *mysql_error(MYSQL *mysql);
const char *mysql_sqlstate(MYSQL *mysql);
unsigned long long mysql_affected_rows(MYSQL *mysql);
unsigned long long mysql_insert_id(MYSQL *mysql);
const char *mysql_get_server_info(MYSQL *mysql);
const char *mysql_get_client_info(void);
const char *mysql_get_host_info(MYSQL *mysql);
const char *mysql_stat(MYSQL *mysql);
int mysql_autocommit(MYSQL *mysql, int mode);
int mysql_commit(MYSQL *mysql);
int mysql_rollback(MYSQL *mysql);
unsigned long mysql_real_escape_string(MYSQL *mysql, char *to, const char *from, unsigned long length);
char **mysql_fetch_row(MYSQL_RES *result);
unsigned long *mysql_fetch_lengths(MYSQL_RES *result);
MYSQL_FIELD *mysql_fetch_field_direct(MYSQL_RES *result, unsigned int fieldnr);
int mysql_next_result(MYSQL *mysql);
int mysql_more_results(MYSQL *mysql);

MYSQL_STMT *mysql_stmt_init(MYSQL *mysql);
int mysql_stmt_prepare(MYSQL_STMT *stmt, const char *query, unsigned long length);
int mysql_stmt_execute(MYSQL_STMT *stmt);
int mysql_stmt_bind_param(MYSQL_STMT *stmt, MYSQL_BIND *bind);
int mysql_stmt_bind_result(MYSQL_STMT *stmt, MYSQL_BIND *bind);
int mysql_stmt_store_result(MYSQL_STMT *stmt);
int mysql_stmt_fetch(MYSQL_STMT *stmt);
int mysql_stmt_close(MYSQL_STMT *stmt);
unsigned int mysql_stmt_errno(MYSQL_STMT *stmt);
const char *mysql_stmt_error(MYSQL_STMT *stmt);
const char *mysql_stmt_sqlstate(MYSQL_STMT *stmt);
unsigned int mysql_stmt_field_count(MYSQL_STMT *stmt);
MYSQL_RES *mysql_stmt_result_metadata(MYSQL_STMT *stmt);
unsigned long long mysql_stmt_affected_rows(MYSQL_STMT *stmt);
unsigned long long mysql_stmt_insert_id(MYSQL_STMT *stmt);
C;
    }
}

/**
 * Соединение mysql: как pdo_mysql_db_handle.
 */
final class MysqlClient
{
    const CLIENT_FOUND_ROWS = 2;
    const CLIENT_COMPRESS = 32;
    const CLIENT_IGNORE_SPACE = 256;
    const CLIENT_MULTI_STATEMENTS = 65536;
    const CLIENT_MULTI_RESULTS = 131072;

    /** @var \FFI\CData|null */
    private $mysql;

    /** @var bool */
    private $persistent = false;

    /** @var OdbcError */
    private $einfo;

    /** @var array<int,mixed> */
    private $attrs = [];

    /** @var array<int,self> */
    private static $pool = [];

    /**
     * @param array<int|string,mixed> $options
     * @return self
     */
    public static function connect($dsn, $user, $password, $persistent, array $options)
    {
        $client = new self();
        $client->persistent = (bool) $persistent;
        $client->einfo = new OdbcError();
        $client->attrs = [
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            PDO::MYSQL_ATTR_LOCAL_INFILE => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ];
        if (!MysqlLibrary::available()) {
            $client->einfo->set('IM002', null, 'could not find driver', 'mysql_init');
            return $client;
        }

        $parts = self::parseDsn($dsn);
        $host = isset($parts['host']) && $parts['host'] !== '' ? $parts['host'] : 'localhost';
        $port = isset($parts['port']) ? (int) $parts['port'] : 3306;
        $db = isset($parts['dbname']) ? $parts['dbname'] : '';
        $socket = isset($parts['unix_socket']) ? $parts['unix_socket'] : '';
        $charset = isset($parts['charset']) ? $parts['charset'] : '';
        if ($user === null || $user === '') {
            $user = isset($parts['user']) ? $parts['user'] : '';
        }
        if ($password === null) {
            $password = isset($parts['password']) ? $parts['password'] : '';
        }

        $key = $user . "\0" . $host . "\0" . $port . "\0" . $db . "\0" . $socket;
        if ($client->persistent && isset(self::$pool[$key]) && self::$pool[$key]->ping()) {
            return self::$pool[$key];
        }

        $ffi = MysqlLibrary::ffi();
        $mysql = $ffi->mysql_init(null);
        if (\FFI::isNull($mysql)) {
            $client->einfo->set('HY000', null, 'mysql_init failed', 'mysql_init');
            return $client;
        }

        $flags = self::CLIENT_MULTI_RESULTS;
        $multi = !array_key_exists(PDO::MYSQL_ATTR_MULTI_STATEMENTS, $options) || $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS];
        if ($multi) {
            $flags |= self::CLIENT_MULTI_STATEMENTS;
            $client->attrs[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = true;
        } else {
            $client->attrs[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
        }
        if (!empty($options[PDO::MYSQL_ATTR_FOUND_ROWS])) {
            $flags |= self::CLIENT_FOUND_ROWS;
            $client->attrs[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
        }
        if (!empty($options[PDO::MYSQL_ATTR_COMPRESS])) {
            $flags |= self::CLIENT_COMPRESS;
            $client->attrs[PDO::MYSQL_ATTR_COMPRESS] = true;
        }
        if (!empty($options[PDO::MYSQL_ATTR_IGNORE_SPACE])) {
            $flags |= self::CLIENT_IGNORE_SPACE;
            $client->attrs[PDO::MYSQL_ATTR_IGNORE_SPACE] = true;
        }

        $connected = $ffi->mysql_real_connect(
            $mysql,
            $host,
            (string) $user,
            (string) $password,
            $db === '' ? null : $db,
            $port,
            $socket === '' ? null : $socket,
            $flags
        );
        if (\FFI::isNull($connected)) {
            $client->captureOn($mysql);
            $ffi->mysql_close($mysql);
            return $client;
        }
        $client->mysql = $mysql;
        if ($charset !== '' && $ffi->mysql_set_character_set($mysql, $charset) !== 0) {
            $client->captureOn($mysql);
            $ffi->mysql_close($mysql);
            $client->mysql = null;
            return $client;
        }
        $client->einfo->clear();
        if ($client->persistent) {
            self::$pool[$key] = $client;
        }
        return $client;
    }

    public function name()
    {
        return 'mysql';
    }

    public function connected()
    {
        return $this->mysql !== null && !\FFI::isNull($this->mysql);
    }

    /**
     * @return \FFI\CData|null
     */
    public function handle()
    {
        return $this->mysql;
    }

    public function close($inTransaction)
    {
        if (!$this->connected() || $this->persistent) {
            return;
        }
        $ffi = MysqlLibrary::ffi();
        if ($inTransaction) {
            $ffi->mysql_rollback($this->mysql);
        }
        $ffi->mysql_close($this->mysql);
        $this->mysql = null;
    }

    /**
     * @return MysqlStatement|null
     */
    public function prepare($sql)
    {
        if (!$this->connected()) {
            $this->einfo->set('HY000', null, 'connection is closed', 'mysql_stmt_prepare');
            return null;
        }
        $this->drain();
        $ffi = MysqlLibrary::ffi();
        $stmt = $ffi->mysql_stmt_init($this->mysql);
        if (\FFI::isNull($stmt)) {
            $this->capture();
            return null;
        }
        if ($ffi->mysql_stmt_prepare($stmt, (string) $sql, strlen((string) $sql)) !== 0) {
            $this->captureStmt($stmt);
            $ffi->mysql_stmt_close($stmt);
            return null;
        }
        $this->einfo->clear();
        return MysqlStatement::native($this, $stmt);
    }

    /**
     * @return MysqlStatement|null
     */
    public function query($sql)
    {
        if (!$this->connected()) {
            $this->einfo->set('HY000', null, 'connection is closed', 'mysql_real_query');
            return null;
        }
        $this->drain();
        $ffi = MysqlLibrary::ffi();
        if ($ffi->mysql_real_query($this->mysql, (string) $sql, strlen((string) $sql)) !== 0) {
            $this->capture();
            return null;
        }
        $loaded = $this->readResult();
        if ($loaded === null) {
            return null;
        }
        $this->einfo->clear();
        return MysqlStatement::buffered($this, $loaded['columns'], $loaded['rows'], $loaded['affected']);
    }

    /**
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
        return $count < 0 ? 0 : $count;
    }

    public function begin($autoCommit)
    {
        if ($autoCommit) {
            return $this->setConnectAutoCommit(false);
        }
        $this->einfo->clear();
        return true;
    }

    public function commit($autoCommit)
    {
        if (!$this->connected()) {
            $this->einfo->set('HY000', null, 'connection is closed', 'mysql_commit');
            return false;
        }
        if (MysqlLibrary::ffi()->mysql_commit($this->mysql) !== 0) {
            $this->capture();
            return false;
        }
        if ($autoCommit && !$this->setConnectAutoCommit(true)) {
            return false;
        }
        $this->einfo->clear();
        return true;
    }

    public function rollBack($autoCommit)
    {
        if (!$this->connected()) {
            $this->einfo->set('HY000', null, 'connection is closed', 'mysql_rollback');
            return false;
        }
        if (MysqlLibrary::ffi()->mysql_rollback($this->mysql) !== 0) {
            $this->capture();
            return false;
        }
        if ($autoCommit && !$this->setConnectAutoCommit(true)) {
            return false;
        }
        $this->einfo->clear();
        return true;
    }

    public function setConnectAutoCommit($enabled)
    {
        if (!$this->connected()) {
            $this->einfo->set('HY000', null, 'connection is closed', 'mysql_autocommit');
            return false;
        }
        if (MysqlLibrary::ffi()->mysql_autocommit($this->mysql, $enabled ? 1 : 0) !== 0) {
            $this->capture();
            return false;
        }
        $this->einfo->clear();
        return true;
    }

    public function setTimeout($seconds)
    {
        unset($seconds);
        return $this->connected();
    }

    /**
     * @return array{0:string,1:int|null,2:string}
     */
    public function errorInfo()
    {
        return $this->einfo->info();
    }

    public function insertId()
    {
        if (!$this->connected()) {
            return '0';
        }
        return (string) MysqlLibrary::ffi()->mysql_insert_id($this->mysql);
    }

    public function quote($string, $type, $default)
    {
        $flags = ((int) $type) & (PDO::PARAM_STR_NATL | PDO::PARAM_STR_CHAR);
        if ($flags === 0) {
            $flags = ((int) $default) & (PDO::PARAM_STR_NATL | PDO::PARAM_STR_CHAR);
        }
        $national = ($flags & PDO::PARAM_STR_NATL) === PDO::PARAM_STR_NATL
            && ($flags & PDO::PARAM_STR_CHAR) !== PDO::PARAM_STR_CHAR;
        $text = (string) $string;
        $ffi = MysqlLibrary::ffi();
        $to = \FFI::new('char[' . (strlen($text) * 2 + 1) . ']');
        $written = $ffi->mysql_real_escape_string($this->mysql, $to, $text, strlen($text));
        return ($national ? "N'" : "'") . \FFI::string($to, (int) $written) . "'";
    }

    public function serverVersion()
    {
        return $this->connected() ? MysqlLibrary::text(MysqlLibrary::ffi()->mysql_get_server_info($this->mysql)) : null;
    }

    public function serverInfo()
    {
        return $this->connected() ? MysqlLibrary::text(MysqlLibrary::ffi()->mysql_get_host_info($this->mysql)) : null;
    }

    public function clientVersion()
    {
        if (!MysqlLibrary::available()) {
            return null;
        }
        return MysqlLibrary::text(MysqlLibrary::ffi()->mysql_get_client_info());
    }

    /**
     * @param mixed $value
     */
    public function setMysqlAttribute($attribute, $value)
    {
        $attribute = (int) $attribute;
        if ($attribute === PDO::MYSQL_ATTR_INIT_COMMAND) {
            $this->attrs[$attribute] = $value;
            if (!is_string($value) || $value === '') {
                return true;
            }
            return $this->query($value) !== null;
        }
        if ($attribute === PDO::MYSQL_ATTR_USE_BUFFERED_QUERY || $attribute === PDO::MYSQL_ATTR_LOCAL_INFILE || $attribute === PDO::MYSQL_ATTR_MULTI_STATEMENTS || $attribute === PDO::MYSQL_ATTR_FOUND_ROWS || $attribute === PDO::MYSQL_ATTR_COMPRESS || $attribute === PDO::MYSQL_ATTR_IGNORE_SPACE || $attribute === PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT) {
            $this->attrs[$attribute] = (bool) $value;
            return true;
        }
        $this->attrs[$attribute] = $value;
        return true;
    }

    public function mysqlAttribute($attribute)
    {
        $attribute = (int) $attribute;
        return array_key_exists($attribute, $this->attrs) ? $this->attrs[$attribute] : null;
    }

    /**
     * @return array{columns:array,rows:array,affected:int}|null
     */
    public function nextResult()
    {
        if (!$this->connected()) {
            return null;
        }
        $ffi = MysqlLibrary::ffi();
        if (!$ffi->mysql_more_results($this->mysql)) {
            return null;
        }
        if ($ffi->mysql_next_result($this->mysql) !== 0) {
            $this->capture();
            return null;
        }
        return $this->readResult();
    }

    public function adopt(OdbcError $error)
    {
        $this->einfo->copyFrom($error);
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
     * @return array<string,string>
     */
    private static function parseDsn($dsn)
    {
        $parts = [];
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

    private function ping()
    {
        return $this->connected() && MysqlLibrary::ffi()->mysql_ping($this->mysql) === 0;
    }

    /**
     * @return array{columns:array,rows:array,affected:int}|null
     */
    private function readResult()
    {
        $ffi = MysqlLibrary::ffi();
        if ($ffi->mysql_field_count($this->mysql) === 0) {
            return [
                'columns' => [],
                'rows' => [],
                'affected' => $this->affectedCount(),
            ];
        }
        $result = $ffi->mysql_store_result($this->mysql);
        if (\FFI::isNull($result)) {
            $this->capture();
            return null;
        }
        $columns = $this->describe($result);
        $rows = $this->readRows($result, count($columns));
        $affected = $this->affectedCount();
        $ffi->mysql_free_result($result);
        return [
            'columns' => $columns,
            'rows' => $rows,
            'affected' => $affected,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function describe($result)
    {
        $ffi = MysqlLibrary::ffi();
        $count = $ffi->mysql_num_fields($result);
        $columns = [];
        for ($index = 0; $index < $count; $index++) {
            $field = $ffi->mysql_fetch_field_direct($result, $index);
            $nameLength = (int) $field->name_length;
            if ($nameLength < 0 || $nameLength > 1024) {
                $nameLength = 0;
            }
            $name = $nameLength === 0 ? ('column' . ($index + 1)) : \FFI::string($field->name, $nameLength);
            $native = self::typeName((int) $field->type);
            $columns[] = [
                'name' => $name,
                'native_type' => $native,
                'len' => (int) $field->length,
                'precision' => (int) $field->length,
                'scale' => (int) $field->decimals,
                'pdo_type' => self::pdoType($native),
            ];
        }
        return $columns;
    }

    /**
     * @return array<int,array<int,mixed>>
     */
    private function readRows($result, $count)
    {
        $ffi = MysqlLibrary::ffi();
        $rows = [];
        while (true) {
            $row = $ffi->mysql_fetch_row($result);
            if ($row === null || \FFI::isNull($row)) {
                break;
            }
            $lengths = $ffi->mysql_fetch_lengths($result);
            $values = [];
            for ($index = 0; $index < $count; $index++) {
                $cell = $row[$index];
                if ($cell === null || \FFI::isNull($cell)) {
                    $values[] = null;
                    continue;
                }
                $values[] = \FFI::string($cell, (int) $lengths[$index]);
            }
            $rows[] = $values;
        }
        return $rows;
    }

    private function affectedCount()
    {
        if (MysqlLibrary::ffi()->mysql_errno($this->mysql) !== 0) {
            return 0;
        }
        $count = MysqlLibrary::ffi()->mysql_affected_rows($this->mysql);
        if (!is_int($count) || $count < 0) {
            return 0;
        }
        return $count;
    }

    private function drain()
    {
        if (!$this->connected()) {
            return;
        }
        $ffi = MysqlLibrary::ffi();
        while ($ffi->mysql_more_results($this->mysql)) {
            if ($ffi->mysql_next_result($this->mysql) !== 0) {
                break;
            }
            $result = $ffi->mysql_store_result($this->mysql);
            if (!\FFI::isNull($result)) {
                $ffi->mysql_free_result($result);
            }
        }
    }

    private function capture()
    {
        $this->captureOn($this->mysql);
    }

    /**
     * @param \FFI\CData|null $mysql
     */
    private function captureOn($mysql)
    {
        $ffi = MysqlLibrary::ffi();
        $state = MysqlLibrary::text($ffi->mysql_sqlstate($mysql));
        $code = (int) $ffi->mysql_errno($mysql);
        $message = MysqlLibrary::text($ffi->mysql_error($mysql));
        if ($state === '') {
            $state = 'HY000';
        }
        if ($message === '') {
            $message = 'mysql error ' . $code;
        }
        $this->einfo->set($state, $code === 0 ? null : $code, $message, 'mysql');
    }

    /**
     * @param \FFI\CData $stmt
     */
    private function captureStmt($stmt)
    {
        $ffi = MysqlLibrary::ffi();
        $state = MysqlLibrary::text($ffi->mysql_stmt_sqlstate($stmt));
        $code = (int) $ffi->mysql_stmt_errno($stmt);
        $message = MysqlLibrary::text($ffi->mysql_stmt_error($stmt));
        if ($state === '') {
            $state = 'HY000';
        }
        $this->einfo->set($state, $code === 0 ? null : $code, $message === '' ? 'mysql statement error' : $message, 'mysql_stmt');
    }

    private static function typeName($type)
    {
        switch ($type) {
            case 1:
                return 'TINYINT';
            case 2:
                return 'SHORT';
            case 3:
                return 'LONG';
            case 4:
                return 'FLOAT';
            case 5:
                return 'DOUBLE';
            case 8:
                return 'BIGINT';
            case 9:
            case 13:
                return 'INT';
            case 16:
                return 'BIT';
            case 0:
            case 246:
                return 'DECIMAL';
            case 249:
            case 250:
            case 251:
            case 252:
                return 'BLOB';
            default:
                return 'VARCHAR';
        }
    }

    private static function pdoType($native)
    {
        if (in_array($native, ['TINYINT', 'SHORT', 'LONG', 'BIGINT', 'INT'], true)) {
            return PDO::PARAM_INT;
        }
        if ($native === 'BLOB') {
            return PDO::PARAM_LOB;
        }
        return PDO::PARAM_STR;
    }
}

/**
 * Результат mysql_store_result или mysql_stmt.
 */
final class MysqlStatement
{
    /** @var MysqlClient */
    private $client;

    /** @var \FFI\CData|null */
    private $stmt;

    /** @var array<int,\FFI\CData> */
    private $keepers = [];

    /** @var array<int,array<string,mixed>> */
    private $columns = [];

    /** @var array<int,array<int,mixed>> */
    private $rows = [];

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
    public static function buffered(MysqlClient $client, array $columns, array $rows, $affected)
    {
        $statement = new self($client);
        $statement->columns = $columns;
        $statement->rows = $rows;
        $statement->affected = (int) $affected;
        return $statement;
    }

    /**
     * @param \FFI\CData $stmt
     * @return self
     */
    public static function native(MysqlClient $client, $stmt)
    {
        $statement = new self($client);
        $statement->stmt = $stmt;
        return $statement;
    }

    private function __construct(MysqlClient $client)
    {
        $this->client = $client;
        $this->einfo = new OdbcError();
    }

    /**
     * @param array<int,mixed> $params
     */
    public function execute(array $params)
    {
        if ($this->stmt === null || \FFI::isNull($this->stmt)) {
            $this->einfo->set('HY000', null, 'statement is closed', 'mysql_stmt_execute');
            return false;
        }
        $ffi = MysqlLibrary::ffi();
        $this->keepers = [];
        $count = count($params);
        if ($count > 0) {
            $binds = $ffi->new('MYSQL_BIND[' . $count . ']');
            $this->keepers[] = $binds;
            foreach ($params as $index => $value) {
                $this->bindOne($binds[$index], $value);
            }
            if ($ffi->mysql_stmt_bind_param($this->stmt, \FFI::addr($binds[0])) !== 0) {
                $this->captureStmt();
                return false;
            }
        }
        if ($ffi->mysql_stmt_execute($this->stmt) !== 0) {
            $this->captureStmt();
            return false;
        }
        $this->columns = [];
        $this->rows = [];
        $this->index = 0;
        if ($ffi->mysql_stmt_field_count($this->stmt) === 0) {
            $affected = $ffi->mysql_stmt_affected_rows($this->stmt);
            $this->affected = is_int($affected) && $affected >= 0 ? $affected : 0;
            $this->einfo->clear();
            return true;
        }
        if (!$this->fetchNativeRows()) {
            return false;
        }
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
        if ($this->columns === []) {
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
        if ($this->stmt !== null && !\FFI::isNull($this->stmt)) {
            MysqlLibrary::ffi()->mysql_stmt_close($this->stmt);
            $this->stmt = null;
        }
        $this->keepers = [];
        $this->rows = [];
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
     * @param \FFI\CData $bind
     * @param mixed $value
     */
    private function bindOne($bind, $value)
    {
        $ffi = MysqlLibrary::ffi();
        $isNull = $ffi->new('char');
        $length = $ffi->new('unsigned long');
        $this->keepers[] = $isNull;
        $this->keepers[] = $length;
        $bind->is_null = \FFI::addr($isNull);
        $bind->length = \FFI::addr($length);
        if ($value === null) {
            $isNull->cdata = "\x01";
            $bind->buffer_type = 6;
            $length->cdata = 0;
            return;
        }
        $isNull->cdata = "\x00";
        if (is_int($value)) {
            $slot = $ffi->new('long long');
            $slot->cdata = $value;
            $this->keepers[] = $slot;
            $bind->buffer = $ffi->cast('void*', \FFI::addr($slot));
            $bind->buffer_type = 8;
            $bind->buffer_length = 8;
            $bind->is_unsigned = "\x00";
            $length->cdata = 8;
            return;
        }
        $text = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        $size = strlen($text);
        $buffer = $ffi->new('char[' . ($size + 1) . ']');
        if ($size > 0) {
            $ffi->memcpy($buffer, $text, $size);
        }
        $this->keepers[] = $buffer;
        $bind->buffer = $ffi->cast('void*', $buffer);
        $bind->buffer_type = 254;
        $bind->buffer_length = $size;
        $length->cdata = $size;
    }

    private function fetchNativeRows()
    {
        $ffi = MysqlLibrary::ffi();
        $meta = $ffi->mysql_stmt_result_metadata($this->stmt);
        if (\FFI::isNull($meta)) {
            $this->captureStmt();
            return false;
        }
        $count = $ffi->mysql_num_fields($meta);
        $this->columns = [];
        $buffers = [];
        $nulls = [];
        $lengths = [];
        $binds = $ffi->new('MYSQL_BIND[' . $count . ']');
        $this->keepers[] = $binds;
        for ($index = 0; $index < $count; $index++) {
            $field = $ffi->mysql_fetch_field_direct($meta, $index);
            $nameLength = (int) $field->name_length;
            if ($nameLength < 0 || $nameLength > 1024) {
                $nameLength = 0;
            }
            $name = $nameLength === 0 ? ('column' . ($index + 1)) : \FFI::string($field->name, $nameLength);
            $native = $this->nativeName((int) $field->type);
            $this->columns[] = [
                'name' => $name,
                'native_type' => $native,
                'len' => (int) $field->length,
                'precision' => (int) $field->length,
                'scale' => (int) $field->decimals,
                'pdo_type' => PDO::PARAM_STR,
            ];
            $width = (int) $field->length;
            if ($width < 1) {
                $width = 1;
            }
            if ($width > 1048576) {
                $width = 1048576;
            }
            $buffer = $ffi->new('char[' . ($width + 1) . ']');
            $isNull = $ffi->new('char');
            $length = $ffi->new('unsigned long');
            $this->keepers[] = $buffer;
            $this->keepers[] = $isNull;
            $this->keepers[] = $length;
            $buffers[] = $buffer;
            $nulls[] = $isNull;
            $lengths[] = $length;
            $binds[$index]->buffer = $ffi->cast('void*', $buffer);
            $binds[$index]->buffer_type = 254;
            $binds[$index]->buffer_length = $width;
            $binds[$index]->is_null = \FFI::addr($isNull);
            $binds[$index]->length = \FFI::addr($length);
        }
        $ffi->mysql_free_result($meta);
        if ($ffi->mysql_stmt_bind_result($this->stmt, \FFI::addr($binds[0])) !== 0) {
            $this->captureStmt();
            return false;
        }
        if ($ffi->mysql_stmt_store_result($this->stmt) !== 0) {
            $this->captureStmt();
            return false;
        }
        while (true) {
            $status = $ffi->mysql_stmt_fetch($this->stmt);
            if ($status === 100) {
                break;
            }
            if ($status !== 0 && $status !== 101) {
                $this->captureStmt();
                return false;
            }
            $values = [];
            for ($index = 0; $index < $count; $index++) {
                if (self::byte($nulls[$index]->cdata) !== 0) {
                    $values[] = null;
                    continue;
                }
                $size = (int) $lengths[$index]->cdata;
                if ($size < 0) {
                    $size = 0;
                }
                $values[] = \FFI::string($buffers[$index], $size);
            }
            $this->rows[] = $values;
        }
        $affected = $ffi->mysql_stmt_affected_rows($this->stmt);
        $this->affected = is_int($affected) && $affected >= 0 ? $affected : count($this->rows);
        return true;
    }

    private static function byte($value)
    {
        if (is_int($value)) {
            return $value & 255;
        }
        if (is_string($value) && $value !== '') {
            return ord($value);
        }
        return 0;
    }

    private function nativeName($type)
    {
        switch ($type) {
            case 1:
                return 'TINYINT';
            case 2:
                return 'SHORT';
            case 3:
                return 'LONG';
            case 8:
                return 'BIGINT';
            case 9:
                return 'INT';
            case 4:
                return 'FLOAT';
            case 5:
                return 'DOUBLE';
            case 246:
            case 0:
                return 'DECIMAL';
            default:
                return 'VARCHAR';
        }
    }

    private function captureStmt()
    {
        $ffi = MysqlLibrary::ffi();
        $state = MysqlLibrary::text($ffi->mysql_stmt_sqlstate($this->stmt));
        $code = (int) $ffi->mysql_stmt_errno($this->stmt);
        $message = MysqlLibrary::text($ffi->mysql_stmt_error($this->stmt));
        if ($state === '') {
            $state = 'HY000';
        }
        $this->einfo->set($state, $code === 0 ? null : $code, $message === '' ? 'mysql statement error' : $message, 'mysql_stmt');
        $this->client->adopt($this->einfo);
    }
}
