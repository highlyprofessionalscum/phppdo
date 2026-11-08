#!/bin/sh
set -e

install -d -o mysql -g mysql /run/mysqld
if [ ! -d /var/lib/mysql/mysql ]; then
    if command -v mariadb-install-db >/dev/null 2>&1; then
        mariadb-install-db --user=mysql --datadir=/var/lib/mysql
    else
        mysql_install_db --user=mysql --datadir=/var/lib/mysql
    fi
fi

if ! mysqladmin --socket=/run/mysqld/mysqld.sock ping --silent; then
    mysqld --user=mysql --datadir=/var/lib/mysql --bind-address=127.0.0.1 --port=3306 --socket=/run/mysqld/mysqld.sock --pid-file=/run/mysqld/mysqld.pid &
fi
ready=0
i=0
while [ "$i" -lt 60 ]; do
    if mysqladmin --socket=/run/mysqld/mysqld.sock ping --silent; then
        ready=1
        break
    fi
    i=$((i + 1))
    sleep 1
done
if [ "$ready" -ne 1 ]; then
    echo "mysqld did not become ready" >&2
    exit 1
fi

mysql --socket=/run/mysqld/mysqld.sock -e "CREATE DATABASE IF NOT EXISTS phppdo; CREATE USER IF NOT EXISTS 'phppdo'@'127.0.0.1' IDENTIFIED BY 'phppdo'; GRANT ALL PRIVILEGES ON phppdo.* TO 'phppdo'@'127.0.0.1'; FLUSH PRIVILEGES;"

ver=$(ls /usr/lib/postgresql 2>/dev/null | head -n 1)
if [ -n "$ver" ]; then
    data="/var/lib/postgresql/$ver/main"
    install -d -o postgres -g postgres /run/postgresql "/var/lib/postgresql/$ver" "$data"
    if [ ! -f "$data/postgresql.conf" ]; then
        rm -rf "$data"
        install -d -o postgres -g postgres "$data"
        su postgres -c "/usr/lib/postgresql/$ver/bin/initdb -D '$data'"
    fi
    if ! su postgres -c "/usr/lib/postgresql/$ver/bin/pg_ctl -D '$data' status" >/dev/null 2>&1; then
        printf 'host phppdo phppdo 127.0.0.1/32 trust\n' >> "$data/pg_hba.conf"
        if ! su postgres -c "/usr/lib/postgresql/$ver/bin/pg_ctl -D '$data' -l /tmp/pg.log -o '-c listen_addresses=127.0.0.1 -c unix_socket_directories=/run/postgresql' start"; then
            echo "--- /tmp/pg.log ---" >&2
            cat /tmp/pg.log >&2 || true
            exit 1
        fi
    fi
    i=0
    ready=0
    while [ "$i" -lt 60 ]; do
        if su postgres -c "/usr/lib/postgresql/$ver/bin/pg_isready -h 127.0.0.1" >/dev/null 2>&1; then
            ready=1
            break
        fi
        i=$((i + 1))
        sleep 1
    done
    if [ "$ready" -ne 1 ]; then
        echo "postgres did not become ready" >&2
        exit 1
    fi
    su postgres -c "/usr/lib/postgresql/$ver/bin/psql -d postgres -c \"CREATE ROLE phppdo LOGIN PASSWORD 'phppdo'\"" || true
    su postgres -c "/usr/lib/postgresql/$ver/bin/psql -d postgres -c \"CREATE DATABASE phppdo OWNER phppdo\"" || true
fi

php tests/run.php
status=$?
if [ "$status" -ne 0 ]; then
    mysqladmin --socket=/run/mysqld/mysqld.sock shutdown || true
    exit "$status"
fi
php vendor/bin/phpunit
status=$?
mysqladmin --socket=/run/mysqld/mysqld.sock shutdown || true
if [ -n "$ver" ]; then
    su postgres -c "/usr/lib/postgresql/$ver/bin/pg_ctl -D '/var/lib/postgresql/$ver/main' stop" || true
fi
exit "$status"
