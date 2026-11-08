# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

Versioning targets [Semantic Versioning](https://semver.org/) once `1.0.0` is published. Until then, treat `main` as development.

## [Unreleased]

### Added

- MySQL: SSL options and `mysql_ssl_set` before connect; constructor `MYSQL_ATTR_INIT_COMMAND`; unbuffered queries (`MYSQL_ATTR_USE_BUFFERED_QUERY`).
- PostgreSQL: driver options in constructor; `PGSQL_ATTR_TRANSACTION_STATUS` and `PGSQL_ATTR_AUTOCOMMIT` (driver-specific attribute numbers, same as ext-pdo).
- Async query API for MySQL and PostgreSQL; ODBC returns **IM001** (by design).
- FFI preload for MySQL/PostgreSQL (`ffi/generate-preload.php`, [docs/PRELOAD.md](docs/PRELOAD.md)).
- CI (GitHub Actions): PHPStan level 6 with baseline, `tests/run.php`, Docker build with full PHPUnit.
- Docs: [COMPATIBILITY](docs/COMPATIBILITY.md) (emulate prepare + IM001 tables), [PHP 8 fetch flags](docs/PHP8.md), [integration notes](docs/INTEGRATION.md) (Laravel 7 / Doctrine).
- Composer scripts: `test`, `test-phpunit`, `test-docker`, `analyse`, `check`, `ci-local` (`test` + `ffi-preload` + `test-docker`).
- `PhpPdo\Php8FetchFlags` — reference values for ext-pdo on PHP 8.x.

### Changed

- Docker image installs PHPUnit via Composer instead of downloading a phar during build.

## [0.1.0] - 2025-11-08

### Added

- Initial public release: userland `PDO` for PHP 7.4 (ODBC, MySQL FFI, PostgreSQL FFI).
- PHPUnit suite and Docker-based integration tests.

[Unreleased]: https://github.com/highlyprofessionalscum/phppdo/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/highlyprofessionalscum/phppdo/releases/tag/v0.1.0
