# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- `PhpPdo\AsyncQueryPoll` for socket polling after async sends.
- `PDO::PHPPDO_ATTR_AUTO_RECONNECT` for MySQL and PostgreSQL (non-persistent).
- ODBC `lastInsertId()` for SQLite ODBC (`SELECT last_insert_rowid()`).
- ODBC `ATTR_PREFETCH` mapped to `SQL_ATTR_ROW_ARRAY_SIZE`.
- MySQL native prepared statements in unbuffered mode (`MYSQL_ATTR_USE_BUFFERED_QUERY`).
- GitHub Actions job `phpunit` with MySQL/PostgreSQL service containers.
- PHPStan `stubs/ffi-cdata.stub` for `FFI\CData` properties.

## [1.0.0] - 2026-11-09

### Added

- Userland PDO for PHP 7.4: ODBC, MySQL (FFI), PostgreSQL (FFI).
- Async query API (MySQL and PostgreSQL).
- FFI preload tooling and documentation.
- MySQL pdo_mysql parity: SSL, `INIT_COMMAND`, unbuffered queries.
- PostgreSQL constructor options and transaction status attribute.
- CI (PHPStan, `composer check`, Docker integration tests).
- Documentation: COMPATIBILITY, INTEGRATION, PHP8 fetch flags, EXAMPLES.
- Composer DX scripts and committed `composer.lock`.
- `PhpPdo\Php8FetchFlags`.

### Changed

- Docker image uses Composer for PHPUnit.

## [0.1.0] - 2025-11-08

### Added

- Initial public release.

[Unreleased]: https://github.com/highlyprofessionalscum/phppdo/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/highlyprofessionalscum/phppdo/compare/v0.1.0...v1.0.0
[0.1.0]: https://github.com/highlyprofessionalscum/phppdo/releases/tag/v0.1.0
