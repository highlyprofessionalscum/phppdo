<?php

namespace PhpPdo;

/**
 * Общая поддержка ffi.preload: заголовки на диске и FFI::scope().
 */
final class FfiPreload
{
    /**
     * @return \FFI|null
     */
    public static function scope($name)
    {
        try {
            return \FFI::scope((string) $name);
        } catch (\Throwable $exception) {
            unset($exception);
            return null;
        }
    }

    /**
     * @return string
     */
    public static function headerCode($relativePath)
    {
        static $cache = array();
        $key = (string) $relativePath;
        if (!isset($cache[$key])) {
            $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
            $code = @file_get_contents($path);
            if (!is_string($code) || $code === '') {
                throw new \RuntimeException('FFI header is missing: ' . $path);
            }
            $cache[$key] = $code;
        }
        return $cache[$key];
    }

    /**
     * @return string
     */
    public static function injectLibraryDirective($headerCode, $libraryPath)
    {
        $escaped = addcslashes((string) $libraryPath, "\\\"\n\r");
        if (strpos($headerCode, '#define FFI_LIB') !== false) {
            return preg_replace(
                '/#define FFI_LIB "[^"]*"/',
                '#define FFI_LIB "' . $escaped . '"',
                $headerCode,
                1
            );
        }
        return preg_replace(
            '/(#define FFI_SCOPE "[^"]+"\s*\n)/',
            '$1#define FFI_LIB "' . $escaped . '"' . "\n",
            $headerCode,
            1
        );
    }

    public static function writePreloadHeader($sourceRelative, $destPath, $libraryPath)
    {
        $code = self::injectLibraryDirective(self::headerCode($sourceRelative), $libraryPath);
        $dir = dirname($destPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($destPath, $code);
    }
}
