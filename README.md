# phpPdo

PDO written in PHP 7.4. It does not use the `pdo` extension.

If `ext-pdo` is not loaded, `PDO`, `PDOStatement`, `PDOException`, and `PDORow` are this library. If it is loaded, the same classes live in the `PhpPdo` namespace and do not replace the built-in ones.

Constants match PHP 7.4, including `PDO::FETCH_GROUP = 65536`. PHP 8 uses different values for several fetch flags.

## Drivers

| Scheme | How it talks to the database | Where it runs |
| --- | --- | --- |
| `odbc:` | `ext-odbc` (`odbc_connect`, `odbc_prepare`, `odbc_execute`, `odbc_exec`, and the other `odbc_*` functions) | Windows and Linux |
| `mysql:` | PHP FFI and MariaDB Connector/C (`libmariadb`) or `libmysqlclient` | Windows and Linux |
| `pgsql:` | PHP FFI and `libpq` | Windows and Linux |

`PDO::getAvailableDrivers()` lists `mysql` and `pgsql` when the client library loads, and `odbc` when the ODBC extension is loaded.

## Requirements

- PHP 7.4 or newer, using the PHP 7.4 language
- `ext-odbc`
- `ext-ffi` to connect to MySQL or PostgreSQL
- A 64-bit build. The native MySQL prepare path uses the MariaDB Connector/C `MYSQL_BIND` layout for Linux LP64 and Windows x64

## Install

```sh
composer require phppdo/phppdo
```

Composer loads `src/bootstrap.php`. You can require that file yourself instead.

Driver walkthroughs (synchronous and asynchronous queries): [docs/EXAMPLES.md](docs/EXAMPLES.md).

## Usage

```php
require __DIR__ . '/vendor/autoload.php';

$pdo = new PDO('odbc:Driver=SQLite3;Database=/tmp/app.sqlite', '', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => true,
]);

$pdo->exec('CREATE TABLE items (id INTEGER, name VARCHAR(50))');
$statement = $pdo->prepare('INSERT INTO items (id, name) VALUES (:id, :name)');
$statement->execute(['id' => 1, 'name' => "O'Brien"]);

foreach ($pdo->query('SELECT id, name FROM items') as $row) {
    echo $row['name'], PHP_EOL;
}
```

The default error mode is silent. Pass `PDO::ERRMODE_EXCEPTION` when a failed call should throw.

An ODBC DSN is a normal PDO DSN. Everything after `odbc:` is given to `odbc_connect`: a data source name from `odbc.ini`, or a full string such as `Driver=...;Database=...`.

```php
$mysql = new PDO('mysql:host=127.0.0.1;port=3306;dbname=app;charset=utf8mb4', 'app', 'secret', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pgsql = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=app', 'app', 'secret', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
```

Username and password arguments override the same keys in a PostgreSQL DSN. A PostgreSQL DSN may also carry `host`, `port`, `dbname`, `sslmode`, `connect_timeout`, `application_name`, `client_encoding`, `options`, and `service`.

### Prepared statements

`PDO::ATTR_EMULATE_PREPARES` starts off for ODBC, on for MySQL, and off for PostgreSQL.

Named `:name` placeholders are rewritten to positional ones. ODBC and MySQL use `?`. A native PostgreSQL statement uses `$1`, `$2`, and so on. Emulated PostgreSQL statements keep `?`.

Turn emulation on for an ODBC driver whose `odbc_prepare` fails inside `SQLDescribeParam`. The SQLite ODBC driver does that. Set it to false on MySQL to use `mysql_stmt_*`. `PDO::PGSQL_ATTR_DISABLE_PREPARES` keeps PostgreSQL parameters out of `PQprepare` and sends them with `PQexecParams`.

### Quoting and insert ids

`PDO::quote()` follows the driver. ODBC doubles an apostrophe, the SQL-92 rule. MySQL uses the connection's escape function. PostgreSQL uses `PQescapeStringConn`, and `PDO::PARAM_LOB` uses `PQescapeByteaConn`.

`lastInsertId()` on ODBC fails with SQLSTATE `IM001`: ODBC has no shared way to read an insert id, and `pdo_odbc` does not implement it either. MySQL returns `mysql_insert_id` and ignores a sequence name. PostgreSQL runs `SELECT LASTVAL()`, or `SELECT CURRVAL($1)` when a name is passed.

### Client libraries

Set `PDO_MYSQL_LIBRARY` or `PDO_PGSQL_LIBRARY` to a full path when the usual locations are not enough.

Otherwise each driver loads the first library that `FFI::cdef` accepts:

- Linux: `libmariadb.so.3`, then `libmysqlclient.so.21` and `libmysqlclient.so.18`; `libpq.so.5` on the common multiarch paths
- Windows: `libmariadb.dll` before `libmysql.dll`, and `libpq.dll`, beside `php.exe`, in sibling OSPanel modules, on `PATH`, and under Program Files

### FFI preloading

Parsing the C API with `FFI::cdef()` on every new PHP process is slow. phpPdo can use PHP’s `ffi.preload` so definitions are parsed once at startup and drivers take them through `FFI::scope()`.

1. Generate headers that embed the resolved client library path:

```sh
php ffi/generate-preload.php
composer run ffi-preload
```

This writes `ffi/generated/mysql.h` and `ffi/generated/pgsql.h` (git-ignored). The script prints their paths separated by `PATH_SEPARATOR`.

2. Point `php.ini` at those files:

```ini
ffi.enable=true
ffi.preload=/path/to/phppdo/ffi/generated/mysql.h:/path/to/phppdo/ffi/generated/pgsql.h
```

On Windows use `;` between paths instead of `:`.

The source headers live in `ffi/mysql.h` and `ffi/pgsql.h` with `FFI_SCOPE` names `PHPPDO_MYSQL` and `PHPPDO_PGSQL`. If preloading is not configured, drivers fall back to `FFI::cdef()` as before.

The Docker image runs `ffi/generate-preload.php` at build time and enables `ffi.preload` for the test suite.

## Tests

The small runner does not need PHPUnit:

```sh
php tests/run.php
```

PHPUnit 9.6 reads `phpunit.xml.dist`:

```sh
composer install
vendor/bin/phpunit
```

MySQL and PostgreSQL cases are skipped when their DSN is empty.

| Variable | Meaning |
| --- | --- |
| `PDO_ODBC_DSN` | ODBC connection string, including the `odbc:` scheme |
| `PDO_ODBC_USER`, `PDO_ODBC_PASSWORD` | ODBC login |
| `PDO_MYSQL_DSN`, `PDO_MYSQL_USER`, `PDO_MYSQL_PASSWORD` | MySQL connection |
| `PDO_PGSQL_DSN`, `PDO_PGSQL_USER`, `PDO_PGSQL_PASSWORD` | PostgreSQL connection |

The Docker image is PHP 7.4 with unixODBC, the SQLite ODBC driver, `ext-ffi`, MariaDB, and PostgreSQL. It does not install `ext-pdo`. Building the image runs `tests/run.php` and PHPUnit. Running the image runs them again.

```sh
docker build -t phppdo .
docker run --rm phppdo
```

## License

[MIT](LICENSE)
