<?php

namespace PhpPdo;

/**
 * Простой poll по сокетам для async send/receive (MySQL и PostgreSQL).
 */
final class AsyncQueryPoll
{
    /**
     * Ждёт готовности MySQL async-запроса (после sendQueryAsync).
     */
    public static function waitMysql(PDO $pdo, $timeoutSeconds = 30)
    {
        $timeoutSeconds = (int) $timeoutSeconds;
        if ($timeoutSeconds < 1) {
            $timeoutSeconds = 1;
        }
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (!$pdo->hasAsyncQueryPending()) {
                return true;
            }
            $socket = $pdo->connectionSocket();
            if ($socket === null) {
                return false;
            }
            $read = [$socket];
            $write = null;
            $except = null;
            $remaining = (int) ceil(($deadline - microtime(true)) * 1000000);
            if ($remaining < 1) {
                break;
            }
            $ready = @stream_select($read, $write, $except, 0, min($remaining, 500000));
            if ($ready === false) {
                return false;
            }
            if ($ready > 0 && $pdo->readQueryResult()) {
                return true;
            }
        }
        return !$pdo->hasAsyncQueryPending();
    }

    /**
     * Ждёт готовности PostgreSQL (non-blocking, после sendQueryAsync).
     */
    public static function waitPgsql(PDO $pdo, $timeoutSeconds = 30)
    {
        $timeoutSeconds = (int) $timeoutSeconds;
        if ($timeoutSeconds < 1) {
            $timeoutSeconds = 1;
        }
        if (!$pdo->setNonBlocking(true)) {
            return false;
        }
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            while ($pdo->consumeInput()) {
            }
            if (!$pdo->isQueryBusy()) {
                return true;
            }
            $socket = $pdo->connectionSocket();
            if ($socket === null) {
                usleep(10000);
                continue;
            }
            $read = [$socket];
            $write = null;
            $except = null;
            $remaining = (int) ceil(($deadline - microtime(true)) * 1000000);
            if ($remaining < 1) {
                break;
            }
            $ready = @stream_select($read, $write, $except, 0, min($remaining, 500000));
            if ($ready === false) {
                return false;
            }
            if ($ready === 0) {
                continue;
            }
        }
        return !$pdo->isQueryBusy();
    }
}
