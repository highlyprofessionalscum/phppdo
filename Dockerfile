# PHP 7.4 и расширение odbc. Расширение pdo намеренно не ставится:
# класс PDO из этого проекта занимает его место.
#
#   docker build -t phppdo .
#   docker run --rm phppdo
# Сборка ставит PHPUnit и прогоняет tests/run.php и vendor/bin/phpunit.

FROM php:7.4-cli-bullseye

COPY docker/odbc.config.m4 /tmp/odbc.config.m4

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libsqliteodbc \
        pkg-config \
        unixodbc \
        unixodbc-dev \
    && docker-php-source extract \
    && cd /usr/src/php/ext/odbc \
    && cp /tmp/odbc.config.m4 config.m4 \
    && phpize \
    && ./configure --with-unixODBC=yes \
    && make -j"$(nproc)" \
    && make install \
    && docker-php-ext-enable odbc \
    && docker-php-source delete \
    && rm -rf /var/lib/apt/lists/* \
    && so=$(find /usr -name 'libsqlite3odbc.so' | head -n 1) \
    && test -n "$so" \
    && printf '[SQLite3]\nDescription=SQLite3 ODBC\nDriver=%s\nUsageCount=1\n' "$so" > /etc/odbcinst.ini \
    && php -m | grep -qx odbc \
    && ! php -m | grep -qx pdo

RUN sed -i \
        -e 's|http://deb.debian.org/debian-security|http://archive.debian.org/debian-security|g' \
        -e 's|http://deb.debian.org/debian |http://archive.debian.org/debian |g' \
        /etc/apt/sources.list \
    && printf 'Acquire::Check-Valid-Until "false";\n' > /etc/apt/apt.conf.d/99no-check-valid \
    && apt-get update \
    && apt-get install -y -o Acquire::Retries=6 --no-install-recommends libffi-dev mariadb-server libmariadb3 \
    || apt-get install -y -o Acquire::Retries=6 --no-install-recommends libffi-dev mariadb-server libmariadb3 \
    && docker-php-ext-install ffi \
    && rm -rf /var/lib/apt/lists/*

RUN apt-get update \
    && apt-get install -y -o Acquire::Retries=6 --no-install-recommends postgresql postgresql-client libpq5 \
    || apt-get install -y -o Acquire::Retries=6 --no-install-recommends postgresql postgresql-client libpq5 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY . /app

ENV PDO_ODBC_DSN="odbc:Driver=SQLite3;Database=/tmp/phppdo.sqlite"
ENV PDO_MYSQL_DSN="mysql:host=127.0.0.1;port=3306;dbname=phppdo;charset=utf8mb4"
ENV PDO_MYSQL_USER="phppdo"
ENV PDO_MYSQL_PASSWORD="phppdo"
ENV PDO_PGSQL_DSN="pgsql:host=127.0.0.1;port=5432;dbname=phppdo"
ENV PDO_PGSQL_USER="phppdo"
ENV PDO_PGSQL_PASSWORD="phppdo"

RUN curl -fsSL -o /usr/local/bin/phpunit https://phar.phpunit.de/phpunit-9.6.22.phar \
    && chmod +x /usr/local/bin/phpunit docker/mysql-tests.sh \
    && docker/mysql-tests.sh

CMD ["docker/mysql-tests.sh"]
