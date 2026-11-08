# FFI preloading (MySQL и PostgreSQL)

Preloading относится только к драйверам **mysql:** и **pgsql:**. ODBC через **ext-odbc** на FFI не опирается.

## Зачем это нужно

При первом подключении драйвер вызывает `FFI::cdef()`: PHP разбирает большой C-заголовок и загружает `libmariadb` / `libpq`. Это заметно на **каждом новом процессе** PHP (CLI, php-fpm, Apache module).

С **ffi.preload** разбор и привязка символов выполняются **один раз при старте PHP**. Дальше phpPdo берёт готовый контекст через **`FFI::scope()`** — без повторного парсинга заголовка.

| Режим | Поведение |
|-------|-----------|
| Preload настроен | `MysqlLibrary::ffi()` / `PgsqlLibrary::ffi()` → `FFI::scope('PHPPDO_MYSQL' \| 'PHPPDO_PGSQL')` |
| Preload не настроен | как раньше: перебор библиотек и `FFI::cdef()` |

Код приложения **не меняется**: те же `new PDO('mysql:...')` и `new PDO('pgsql:...')`.

## Что лежит в репозитории

| Файл | Назначение |
|------|------------|
| `ffi/mysql.h` | Определения C API MariaDB/MySQL, `#define FFI_SCOPE "PHPPDO_MYSQL"` |
| `ffi/pgsql.h` | Определения libpq, `#define FFI_SCOPE "PHPPDO_PGSQL"` |
| `ffi/generate-preload.php` | Находит DLL/.so и пишет `ffi/generated/*.h` с `#define FFI_LIB "..."` |
| `ffi/generated/` | Сгенерированные заголовки (в `.gitignore`, пути зависят от машины) |
| `src/FfiPreload.php` | `FFI::scope()`, загрузка без preload, генерация заголовков |

Имена scope зафиксированы: **`PHPPDO_MYSQL`**, **`PHPPDO_PGSQL`**.

## Быстрый старт

### 1. Сгенерировать заголовки

Из корня проекта (нужно **ext-ffi**):

```sh
php ffi/generate-preload.php
# или
composer run ffi-preload
```

На **stderr** — какая библиотека найдена:

```text
Wrote mysql preload header for C:\OSPanel\modules\MariaDB-10.11\lib\libmariadb.dll
Wrote pgsql preload header for C:\OSPanel\modules\PHP-7.4\libpq.dll
```

На **stdout** — пути для `ffi.preload`, разделитель **`PATH_SEPARATOR`** (`;` в Windows, `:` в Linux).

### 2. Прописать php.ini

Минимум:

```ini
[ffi]
ffi.enable=true
ffi.preload="C:\OSPanel\home\phpPdo\ffi\generated\mysql.h;C:\OSPanel\home\phpPdo\ffi\generated\pgsql.h"
```

Linux (пример):

```ini
ffi.enable=true
ffi.preload=/opt/phppdo/ffi/generated/mysql.h:/opt/phppdo/ffi/generated/pgsql.h
```

Если нужен только один драйвер, укажите один файл.

### 3. Перезапустить PHP

Preload читается при **старте** интерпретатора. После смены `php.ini` перезапустите php-fpm, Apache или откройте новый CLI-процесс.

### 4. Проверка

```php
<?php
require __DIR__ . '/src/bootstrap.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=test', 'user', 'pass', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
var_dump($pdo->query('SELECT 1')->fetchColumn());

// Клиентская версия libpq / MariaDB — признак, что FFI поднялся
echo PHP_EOL, PDO::getAvailableDrivers() ? implode(', ', PDO::getAvailableDrivers()) : '', PHP_EOL;
```

Отдельной функции «preload включён» в PDO нет: при рабочем scope загрузка просто быстрее.

## Переменные окружения

Перед генерацией можно зафиксировать путь к библиотеке (и на runtime без preload):

| Переменная | Драйвер |
|------------|---------|
| `PDO_MYSQL_LIBRARY` | Полный путь к `libmariadb.dll` / `.so` |
| `PDO_PGSQL_LIBRARY` | Полный путь к `libpq.dll` / `libpq.so.5` |

Скрипт `generate-preload.php` использует ту же логику поиска, что и `MysqlLibrary` / `PgsqlLibrary` (см. README).

**После смены библиотеки** (обновление MariaDB, другой PostgreSQL) перезапустите:

```sh
php ffi/generate-preload.php
```

и обновите `ffi.preload`, если пути изменились.

## Примеры по окружениям

### Open Server Panel (Windows)

1. PHP 7.4 с включённым **ffi** в `php.ini`.
2. Рядом с PHP или в `modules` уже лежат `libmariadb.dll` и `libpq.dll`.
3. Из каталога проекта:

```powershell
C:\OSPanel\modules\PHP-7.4\php.exe ffi\generate-preload.php
```

4. В `[ffi]` OSPanel добавьте `ffi.enable=true` и `ffi.preload` с путями из вывода скрипта (`;` между файлами).
5. Перезапустите сервер в панели.

### Linux (системные пакеты)

```sh
# Debian/Ubuntu: libmariadb-dev / libpq-dev для разработки, рантайм — libmariadb3, libpq5
php ffi/generate-preload.php

# Фрагмент для /etc/php/7.4/fpm/conf.d/99-phppdo-ffi.ini
```

### Docker-образ проекта

В `Dockerfile` preload включается при сборке:

```dockerfile
RUN php ffi/generate-preload.php > /tmp/phppdo-ffi.preload \
    && printf '%s\n%s\n' \
        'ffi.enable=true' \
        "ffi.preload=$(cat /tmp/phppdo-ffi.preload)" \
        > /usr/local/etc/php/conf.d/99-phppdo-ffi-preload.ini
```

Готовый образ уже грузит FFI при старте контейнера; отдельно настраивать не нужно.

### Только CLI, без правки глобального php.ini

Preload **нельзя** включить из скриста — только через `php.ini` (или `-d` при запуске CLI):

```sh
php -d ffi.enable=1 \
    -d "ffi.preload=/path/to/ffi/generated/mysql.h:/path/to/ffi/generated/pgsql.h" \
    your_script.php
```

## ffi.enable: `true`, `preload` и безопасность

| Значение | Смысл |
|----------|--------|
| `ffi.enable=true` | `FFI::cdef()` разрешён; preload-файлы из `ffi.preload` тоже работают |
| `ffi.enable=preload` | FFI только из preload (и CLI / preloaded-контекста); произвольный `cdef` в веб-запросе запрещён |
| `ffi.enable=false` | FFI выключен, mysql/pgsql драйвер недоступны |

Для production с preload часто ставят **`ffi.enable=true`**, чтобы при отсутствии preload оставался fallback на `cdef`. Строже — **`preload`**, если веб никогда не должен вызывать новый `cdef`.

phpPdo **не** требует `opcache.preload` (это другое: предзагрузка **PHP-классов**). Для FFI нужен именно **`ffi.preload`**.

## Внутренний поток (для отладки)

```text
Старт PHP
  → чтение ffi.preload
  → парсинг ffi/generated/mysql.h (FFI_LIB + FFI_SCOPE + C API)
  → парсинг ffi/generated/pgsql.h
  → символы в постоянной памяти scope

Запрос приложения
  → MysqlLibrary::ffi()
      → FfiPreload::ffi()
          → FFI::scope('PHPPDO_MYSQL')  // при успехе
          → иначе FFI::cdef(ffi/mysql.h, путь из candidates)
```

Обновили **исходные** `ffi/mysql.h` / `ffi/pgsql.h` (новые функции C API) — перегенерируйте `ffi/generated/*` и **перезапустите PHP**.

## Типичные проблемы

| Симптом | Что проверить |
|---------|----------------|
| `could not find driver` для mysql/pgsql | `extension=ffi`, `ffi.enable`, библиотека на диске |
| `Failed loading scope 'PHPPDO_MYSQL'` | Нет файла в `ffi.preload`, неверный путь, ошибка в сгенерированном `.h` |
| Preload warning при старте PHP | Неверный `FFI_LIB` (DLL переехала) — снова `generate-preload.php` |
| Работает в CLI, не в FPM | Разный `php.ini` — продублируйте `[ffi]` в `php-fpm.d` |
| Генератор пишет `Skip mysql` | Нет `.so`/`.dll` или задайте `PDO_MYSQL_LIBRARY` |

Проверка, что FFI жив:

```sh
php -r "echo extension_loaded('ffi') ? 'ffi on' : 'ffi off';"
php ffi/generate-preload.php
```

## См. также

- [EXAMPLES.md](EXAMPLES.md) — запросы к БД (синхронные и асинхронные)
- [README.md](../README.md) — установка, DSN, поиск библиотек
