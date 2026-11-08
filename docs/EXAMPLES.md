# Примеры: phpPdo (PHP 7.4)

Подключите библиотеку один раз:

```php
require __DIR__ . '/vendor/autoload.php';
// или
require __DIR__ . '/src/bootstrap.php';

use PhpPdo\PDO;
use PhpPdo\PDOException;
```

Рекомендуется всегда включать исключения при ошибках:

```php
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
];
```

---

## ODBC (`odbc:`)

Нужно расширение **odbc**. Асинхронных методов у ODBC-драйвера **нет** (только синхронный API `odbc_*`).

### Подключение

```php
$pdo = new PDO(
    'odbc:Driver=SQLite3;Database=/tmp/app.sqlite',
    '',
    '',
    $options + [
        // Для SQLite ODBC обычно нужна эмуляция prepare
        PDO::ATTR_EMULATE_PREPARES => true,
    ]
);
```

DSN из `odbc.ini`:

```php
$pdo = new PDO('odbc:MyDataSource', 'user', 'pass', $options);
```

### Синхронные запросы

```php
// DDL и команды без результата
$pdo->exec('CREATE TABLE items (id INTEGER, name VARCHAR(50))');
$pdo->exec("INSERT INTO items (id, name) VALUES (1, 'alpha')");

// SELECT
foreach ($pdo->query('SELECT id, name FROM items') as $row) {
    echo $row['name'], PHP_EOL;
}

// Подготовленный запрос (именованные :name переписываются в ?)
$stmt = $pdo->prepare('INSERT INTO items (id, name) VALUES (:id, :name)');
$stmt->execute(['id' => 2, 'name' => "O'Brien"]);

$stmt = $pdo->prepare('SELECT name FROM items WHERE id = ?');
$stmt->execute([1]);
$name = $stmt->fetchColumn();

// Кавычки и транзакция
$quoted = $pdo->quote("it's fine"); // 'it''s fine'
$pdo->beginTransaction();
$pdo->exec("UPDATE items SET name = 'beta' WHERE id = 1");
$pdo->commit();
```

### Асинхронность

Не поддерживается. Вызов `sendQueryAsync()` / `receiveQueryResult()` вернёт ошибку **IM001**.

```php
// $pdo->sendQueryAsync('SELECT 1'); // IM001
```

Обходной путь для параллелизма — отдельные процессы, очереди или драйверы **mysql** / **pgsql** с нативным async.

---

## MySQL (`mysql:`)

Нужны **ext-ffi** и клиентская библиотека (`libmariadb` / `libmysqlclient`).

### Подключение

```php
$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=app;charset=utf8mb4',
    'app',
    'secret',
    $options + [
        PDO::ATTR_EMULATE_PREPARES => true,  // по умолчанию true
        // PDO::ATTR_EMULATE_PREPARES => false, // нативный mysql_stmt_*
    ]
);
```

### Синхронные запросы

```php
$pdo->exec('CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, login VARCHAR(32))');
$pdo->exec("INSERT INTO users (login) VALUES ('alice')");
$id = $pdo->lastInsertId();

$stmt = $pdo->prepare('SELECT login FROM users WHERE id = ?');
$stmt->execute([$id]);
$login = $stmt->fetchColumn();

// Несколько результатов в одном запросе (MULTI_STATEMENTS)
$stmt = $pdo->query('SELECT 1 AS a; SELECT 2 AS b');
$row1 = $stmt->fetch(PDO::FETCH_ASSOC);
$stmt->nextRowset();
$row2 = $stmt->fetch(PDO::FETCH_ASSOC);
```

### Асинхронные запросы

Под капотом: `mysql_send_query` → `mysql_read_query_result` → чтение набора строк.

**Вариант 1 — два шага (отправить, потом забрать результат):**

```php
$pdo->sendQueryAsync('SELECT COUNT(*) FROM users');
$stmt = $pdo->receiveQueryResult();
$count = $stmt->fetchColumn();
```

**Вариант 2 — явное чтение протокола (редко нужно):**

```php
$pdo->sendQueryAsync('SELECT SLEEP(0)'); // любой SELECT
if (!$pdo->readQueryResult()) {
    // ошибка соединения
}
$stmt = $pdo->receiveQueryResult();
```

**Сокет для `stream_select` (мультиплексирование нескольких соединений):**

```php
$socket = $pdo->connectionSocket();
if ($socket !== null) {
    $read = [$socket];
    $write = null;
    $except = null;
    // ждём, пока на сокете появятся данные (таймаут 1 с)
    stream_select($read, $write, $except, 1);
}

$pdo->sendQueryAsync('SELECT 1');
$stmt = $pdo->receiveQueryResult();
```

`prepare()` / `execute()` по-прежнему **синхронные**; async относится к сырому SQL через `sendQueryAsync` / `receiveQueryResult` (и к внутреннему `query()`).

---

## PostgreSQL (`pgsql:`)

Нужны **ext-ffi** и **libpq**.

### Подключение

```php
$pdo = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=app',
    'app',
    'secret',
    $options
    // ATTR_EMULATE_PREPARES по умолчанию false — нативные $1, $2
);
```

### Синхронные запросы

```php
$pdo->exec('CREATE TABLE IF NOT EXISTS users (id SERIAL PRIMARY KEY, login TEXT)');
$pdo->exec("INSERT INTO users (login) VALUES ('bob')");
$id = $pdo->lastInsertId();

foreach ($pdo->query('SELECT id, login FROM users') as $row) {
    echo $row['login'], PHP_EOL;
}

// Нативные плейсхолдеры
$stmt = $pdo->prepare('SELECT login FROM users WHERE id = ?');
$stmt->execute([1]);

// Несколько команд в одной строке
$stmt = $pdo->query('SELECT 1 AS x; SELECT 2 AS y');
$stmt->nextRowset();
```

### Асинхронные запросы (блокирующий режим)

Отправка без немедленного `PQgetResult` — результат забирается отдельно (как у libpq):

```php
$pdo->sendQueryAsync('SELECT 42 AS answer');
$stmt = $pdo->receiveQueryResult();
$value = $stmt->fetchColumn();
```

### Асинхронные запросы (неблокирующий libpq)

```php
$pdo->setNonBlocking(true);

$pdo->sendQueryAsync('SELECT pg_sleep(0), 99 AS n');
$stmt = false;
while ($stmt === false) {
    if (!$pdo->consumeInput()) {
        throw new PDOException('consumeInput failed');
    }
  // Пока сервер занят — receiveQueryResult() вернёт false (не ошибка)
    if ($pdo->isQueryBusy()) {
        usleep(10_000);
        continue;
    }
    $stmt = $pdo->receiveQueryResult();
}

$n = $stmt->fetchColumn(); // 99

$pdo->setNonBlocking(false);
```

**Сокет и ожидание:**

```php
$pdo->sendQueryAsync('SELECT 1');
$fd = $pdo->connectionSocket();
if ($fd !== null) {
    $r = [$fd];
    stream_select($r, $w = null, $e = null, 5);
    $pdo->consumeInput();
}
$stmt = $pdo->receiveQueryResult();
```

Дополнительно для длинных запросов на запись:

```php
$pdo->sendQueryAsync('SELECT 1');
$pdo->flushConnection(); // PQflush — дожать исходящий буфер
```

### Поведение `receiveQueryResult()`

| Ситуация | Возврат |
|----------|---------|
| Результат готов | `PDOStatement` |
| PostgreSQL, non-blocking, ещё не готов | `false` (ошибки нет) |
| Ошибка SQL / соединения | `false` + `errorInfo()` / исключение |

---

## FFI preloading (MySQL / PostgreSQL)

Ускорение старта FFI: один раз сгенерировать заголовки и прописать `ffi.preload` в `php.ini`. Подробно: [PRELOAD.md](PRELOAD.md).

```sh
php ffi/generate-preload.php
```

```ini
ffi.enable=true
ffi.preload=/path/to/ffi/generated/mysql.h:/path/to/ffi/generated/pgsql.h
```

После этого обычное подключение без изменений кода:

```php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=app', 'user', 'pass');
```

---

## Сводка по async-методам PDO

| Метод | ODBC | MySQL | PostgreSQL |
|-------|------|-------|------------|
| `sendQueryAsync($sql)` | ✗ | ✓ | ✓ |
| `readQueryResult()` | ✗ | ✓ | — |
| `receiveQueryResult()` | ✗ | ✓ | ✓ |
| `connectionSocket()` | ✗ | ✓ | ✓ |
| `setNonBlocking($on)` | ✗ | ✗ | ✓ |
| `consumeInput()` | ✗ | ✗ | ✓ |
| `isQueryBusy()` | ✗ | ✗ | ✓ |
| `flushConnection()` | ✗ | ✗ | ✓ |

Синхронные **`query()`**, **`exec()`**, **`prepare()`** / **`execute()`** работают на **всех** драйверах одинаково с точки зрения PDO.
