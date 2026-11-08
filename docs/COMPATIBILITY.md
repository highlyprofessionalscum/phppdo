# PDO vs phpPdo

Comparison against PHP 7.4 **ext-pdo** (`pdo_odbc`, `pdo_mysql`, `pdo_pgsql`). This library intentionally mirrors PHP 7.4 constants and behavior; see [PHP8.md](PHP8.md) when running on newer PHP with native `ext-pdo` loaded elsewhere.

## Drivers

| Feature | ext-pdo | phpPdo |
| --- | --- | --- |
| `odbc:` | `pdo_odbc` | `ext-odbc` functions |
| `mysql:` | `pdo_mysql` | FFI + libmariadb / libmysqlclient |
| `pgsql:` | `pdo_pgsql` | FFI + libpq |
| `getAvailableDrivers()` | Built-in list | Libraries/extensions detected at runtime |

## `ATTR_EMULATE_PREPARES` by driver

| Driver | phpPdo default | Emulated (`true`) | Native (`false`) |
| --- | --- | --- | --- |
| **ODBC** | **false** | `SqlParser` rewrites `:name` → `?`, values inlined via `odbc_exec` / bound `odbc_execute` when prepare works | `odbc_prepare` + `odbc_execute` (fails on some drivers, e.g. SQLite ODBC, during `SQLDescribeParam`) |
| **MySQL** | **true** | Same rewriter, `?` placeholders, `mysql_real_query` | `mysql_stmt_prepare` + `MYSQL_BIND` (MariaDB 3.x / LP64 layout) |
| **PostgreSQL** | **false** | Rewriter + `?`, sent with `PQexecParams` when `PGSQL_ATTR_DISABLE_PREPARES` is **true** | `PQprepare` / `PQexecPrepared` with `$1`, `$2`, … |

**Practical defaults (match common production setups):**

| Goal | Suggested setting |
| --- | --- |
| SQLite via ODBC | `ATTR_EMULATE_PREPARES => true` |
| MySQL app ported from old PHP with emulation on | leave default **true**, or **false** for native statements |
| PostgreSQL with server-side plans | default **false**; `PGSQL_ATTR_DISABLE_PREPARES => true` to force `PQexecParams` only |

`PDO::MYSQL_ATTR_DIRECT_QUERY` on MySQL sets the same switch as `ATTR_EMULATE_PREPARES` (native path when **false**).

## Core API (supported)

| API | ODBC | MySQL | PostgreSQL |
| --- | --- | --- | --- |
| `query`, `exec`, `prepare`, `quote` | yes | yes | yes |
| Transactions (`beginTransaction`, …) | yes | yes | yes |
| `fetch*` modes (7.4 flag values) | yes | yes | yes |
| `nextRowset()` | driver-dependent | yes (multi-statements) | yes |
| Persistent connections | `odbc_pconnect` | pool in process | pool in process |
| `getAttribute` / `setAttribute` | partial | MySQL attrs + generics | pgsql attrs + generics |

## **IM001** (not supported on this driver)

| API / attribute | ODBC | MySQL | PostgreSQL |
| --- | --- | --- | --- |
| `lastInsertId()` | SQLite ODBC only | OK | OK |
| `sendQueryAsync` / `receiveQueryResult` | **IM001** | OK | OK |
| `readQueryResult` | **IM001** | OK | **IM001** |
| `setNonBlocking` / `consumeInput` / `flushConnection` | **IM001** | **IM001** | OK |
| `connectionSocket` / `isQueryBusy` | **IM001** | OK / `isQueryBusy` → false | OK |
| Unknown `PDO::ATTR_*` or statement attribute | **IM001** | **IM001** if not mysql-specific | **IM001** if not pgsql-specific |
| Wrong driver's `MYSQL_*` / `PGSQL_*` attribute | **IM001** | — | — |

Other gaps (not always **IM001**): ODBC scrollable cursors depend on the driver; `ATTR_PREFETCH` is stored but not passed to ODBC; many Oracle-specific attrs are absent.

Driver-specific attributes reuse the same integer ids as PHP (e.g. `1000` = `MYSQL_ATTR_USE_BUFFERED_QUERY` on mysql and `PGSQL_ATTR_DISABLE_PREPARES` on pgsql). Always use the constant name for the active driver.

## MySQL (pdo_mysql parity)

| Topic | ext-pdo | phpPdo |
| --- | --- | --- |
| SSL `MYSQL_ATTR_SSL_*` | at connect | at connect via `mysql_ssl_set` / `mysql_options` |
| `MYSQL_ATTR_INIT_COMMAND` | constructor option | constructor option (runs after connect) |
| `MYSQL_ATTR_USE_BUFFERED_QUERY` | `mysql_store_result` vs `use_result` | same |
| Multi-statements / `nextRowset()` | yes | yes (when enabled) |

## PostgreSQL (pdo_pgsql parity)

| Topic | ext-pdo | phpPdo |
| --- | --- | --- |
| `PGSQL_ATTR_DISABLE_PREPARES` | yes | yes (constructor + `setAttribute`) |
| `PQtransactionStatus` | via driver attrs / connection | `PGSQL_ATTR_TRANSACTION_STATUS` (pgsql only) |
| Autocommit | `ATTR_AUTOCOMMIT` / libpq | `ATTR_AUTOCOMMIT` and `PGSQL_ATTR_AUTOCOMMIT` (pgsql) |

## Errors

SQLSTATE and `PDOException` follow the same shapes as ext-pdo where the underlying API provides them. Silent and warning error modes behave like PHP 7.4 PDO.
