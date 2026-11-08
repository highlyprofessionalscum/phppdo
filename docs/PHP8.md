# PHP 8 and fetch-mode constants

`PhpPdo\PDO` keeps **PHP 7.4** numeric values for fetch flags (for example `PDO::FETCH_GROUP = 65536`). If `ext-pdo` is loaded in the same process, classes live under `PhpPdo\` and do not replace the built-in `PDO`.

When you upgrade the runtime to PHP 8.x but still use this library on PHP 7.4 language level, application code must continue to use **7.4** flag numbers with `PhpPdo\PDO`.

When you mix code written against **PHP 8 ext-pdo** literals, use `PhpPdo\Php8FetchFlags` as a reference and `Php8FetchFlags::toPhp74FetchMode()` to remap mode integers before passing them to `PhpPdo\PDO`.

## Flag values (fetch modifiers)

| Constant | PHP 7.4 (phpPdo) | PHP 8.0+ (ext-pdo) |
| --- | ---: | ---: |
| `FETCH_GROUP` | 65536 | 524288 |
| `FETCH_UNIQUE` | 196608 | 983040 |
| `FETCH_CLASSTYPE` | 262144 | 786432 |
| `FETCH_SERIALIZE` | 524288 | 1048576 |
| `FETCH_PROPS_LATE` | 1048576 | 2097152 |

Base fetch types (`FETCH_ASSOC`, `FETCH_NUM`, …) are unchanged.

## Example

```php
use PhpPdo\PDO;
use PhpPdo\Php8FetchFlags;

// Code copied from a PHP 8 project using ext-pdo literals:
$modeFromPhp8 = PDO::FETCH_CLASS | 524288; // WRONG mask if 524288 was FETCH_GROUP on PHP 8

$pdo = new PDO($dsn, $user, $pass);
$rows = $pdo->query($sql)->fetchAll(
    Php8FetchFlags::toPhp74FetchMode($modeFromPhp8)
);
```

For greenfield code on phpPdo, prefer `PhpPdo\PDO::FETCH_*` constants directly and avoid remapping.
