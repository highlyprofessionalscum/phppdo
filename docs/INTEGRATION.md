# Using phpPdo in applications

phpPdo is a **library**, not a drop-in PHP extension. Applications that expect `ext-pdo` must load this package **without** `ext-pdo` enabled, or import `PhpPdo\PDO` explicitly.

## Composer

```json
{
    "require": {
        "phppdo/phppdo": "^0.1"
    }
}
```

`vendor/autoload.php` loads `src/bootstrap.php`. When `ext-pdo` is absent, global class names `PDO`, `PDOStatement`, `PDOException`, and `PDORow` refer to this implementation.

## Plain PHP / legacy bootstrap

```php
require __DIR__ . '/vendor/autoload.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=app', 'user', 'pass', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
```

Use a **PHP 7.4** runtime with `ext-odbc` and, for MySQL/PostgreSQL, `ext-ffi` plus client libraries.

## Laravel (7.x legacy, PHP 7.4, **without** `ext-pdo`)

Requirements: FPM/CLI built **without** `pdo` (but with `odbc` + `ffi` as needed). Composer autoload must run before the framework touches `PDO`.

### Option A — global `PDO` alias (smallest change)

`vendor/autoload.php` already loads `bootstrap.php`. If `ext-pdo` is missing, `new PDO(...)` in application code is phpPdo. Laravel’s `Illuminate\Database\Connectors\Connector` still calls `new PDO(...)` — it works as long as the PHP binary has no native `PDO` class.

`.env` stays familiar:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=app
DB_USERNAME=app
DB_PASSWORD=secret
```

Ensure `config/database.php` uses `mysql` / `pgsql` drivers (not `sqlite` unless via `odbc:`).

### Option B — explicit `PhpPdo\PDO` connector (Laravel 7)

Register a custom connector when you need extra driver options (SSL, `INIT_COMMAND`, preload-friendly stack):

```php
// app/Database/PhpPdoConnector.php
namespace App\Database;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use PhpPdo\PDO;

class PhpPdoConnector extends Connector implements ConnectorInterface
{
    public function connect(array $config)
    {
        $dsn = $this->getDsn($config);
        $options = $config['options'] ?? [];
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;

        return $this->createConnection($dsn, $config, $options);
    }

    protected function createPdoConnection($dsn, $username, $password, array $options)
    {
        return new PDO($dsn, $username, $password, $options);
    }
}
```

```php
// app/Providers/AppServiceProvider.php — boot()
use App\Database\PhpPdoConnector;
use Illuminate\Support\Facades\DB;

DB::extend('mysql', function ($config, $name) {
    $config['name'] = $name;
    $connector = new PhpPdoConnector();
    $pdo = $connector->connect($config);
    return new \Illuminate\Database\MySqlConnection($pdo, $config['database'], $config['prefix'], $config);
});
```

Duplicate the `extend` block for `pgsql` with `PostgresConnection` if needed. This is illustrative — trim to your Laravel version’s connection class.

**Caveats:** packages that type-hint `\PDO` against **ext-pdo** internals, or expect `pdo_mysql`/`pdo_pgsql` in `phpinfo()`, will not recognize phpPdo. Eloquent itself only needs a working PDO surface.

## Doctrine DBAL 2.x / 3.x

Inject phpPdo as the low-level connection (works well for legacy apps that already wrap DBAL):

```php
require __DIR__ . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use PhpPdo\PDO;

$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
    'app',
    'secret',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]
);

$connection = DriverManager::getConnection(['pdo' => $pdo]);
$connection->fetchOne('SELECT 1');
```

DBAL 3 with URL config and **without** native `ext-pdo` is awkward (built-in drivers assume the extension). Prefer the `pdo` key or a thin factory in your bootstrap:

```php
// bootstrap.php fragment
return DriverManager::getConnection([
    'pdo' => new \PhpPdo\PDO(
        getenv('DATABASE_URL') ?: 'pgsql:host=127.0.0.1;dbname=app',
        getenv('DB_USER'),
        getenv('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    ),
]);
```

Entity Manager (ORM) needs no change once `EntityManager` is built from that DBAL connection.

## Symfony

Register a `PDO` service in `services.yaml` pointing at `PhpPdo\PDO`, or rely on bootstrap aliases when ext-pdo is disabled.

## When not to use phpPdo

- You need `ext-pdo` drivers not implemented here (SQLite without ODBC, SQL Server, etc.).
- The stack requires PDO features this library marks as **IM001** (ODBC `lastInsertId`, ODBC async).
- You run PHP 8+ **with** ext-pdo in the same project and expect identical global `PDO` — use `PhpPdo\` namespace explicitly or disable one of the two.

See [COMPATIBILITY.md](COMPATIBILITY.md) for a feature matrix.
