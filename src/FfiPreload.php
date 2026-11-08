<?php

namespace PhpPdo;

/**
 * Preload-заголовки, FFI::scope и общая загрузка libmysql/libpq.
 */
final class FfiPreload
{
    /** @var array<string,\FFI> */
    private static $libraries = array();

    /** @var array<string,bool> */
    private static $unavailable = array();

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
     * @param array<int,string> $candidates
     */
    public static function available($scope, $headerRelative, array $candidates)
    {
        $scope = (string) $scope;
        if (!empty(self::$unavailable[$scope])) {
            return false;
        }
        try {
            return self::ffi($scope, $headerRelative, $candidates, '') instanceof \FFI;
        } catch (\Throwable $exception) {
            unset($exception);
            self::$unavailable[$scope] = true;
            return false;
        }
    }

    /**
     * @param array<int,string> $candidates
     * @return \FFI
     */
    public static function ffi($scope, $headerRelative, array $candidates, $notFoundMessage)
    {
        $scope = (string) $scope;
        if (isset(self::$libraries[$scope]) && self::$libraries[$scope] instanceof \FFI) {
            return self::$libraries[$scope];
        }
        if (!extension_loaded('ffi')) {
            throw new \RuntimeException('ext-ffi is not loaded');
        }
        $scoped = self::scope($scope);
        if ($scoped instanceof \FFI) {
            self::$libraries[$scope] = $scoped;
            return self::$libraries[$scope];
        }
        $header = self::headerCode($headerRelative);
        $last = null;
        foreach ($candidates as $path) {
            try {
                self::$libraries[$scope] = \FFI::cdef($header, $path);
                return self::$libraries[$scope];
            } catch (\Throwable $exception) {
                $last = $exception;
            }
        }
        $message = (string) $notFoundMessage;
        throw $last !== null ? $last : new \RuntimeException($message !== '' ? $message : 'FFI library was not found');
    }

    /**
     * @param array<int,string> $candidates
     * @return string|null
     */
    public static function probe($headerRelative, array $candidates)
    {
        if (!extension_loaded('ffi')) {
            return null;
        }
        $header = self::headerCode($headerRelative);
        foreach ($candidates as $path) {
            try {
                \FFI::cdef($header, $path);
                return $path;
            } catch (\Throwable $exception) {
                unset($exception);
            }
        }
        return null;
    }

    /**
     * const char* из FFI: строка PHP, CData или пусто.
     *
     * @param mixed $value
     */
    public static function cString($value)
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value instanceof \FFI\CData) {
            if (\FFI::isNull($value)) {
                return '';
            }
            return \FFI::string($value);
        }
        return '';
    }

    /**
     * @param array<int,string> $moduleHints
     * @param array<int,string> $programFileGlobs
     * @param array<int,string> $programFileSubdirs пустая строка — искать в каталоге из glob как есть
     * @param array<int,string> $dllNames
     * @return array<int,string>
     */
    public static function windowsDllCandidates(array $moduleHints, array $programFileGlobs, array $programFileSubdirs, array $dllNames)
    {
        $dirs = array();
        $binary = defined('PHP_BINARY') ? dirname(PHP_BINARY) : '';
        if ($binary !== '') {
            $dirs[] = $binary;
            $modules = dirname($binary);
            if (is_dir($modules)) {
                $entries = scandir($modules);
                if (is_array($entries)) {
                    foreach ($entries as $entry) {
                        if (!self::moduleHintMatches($entry, $moduleHints)) {
                            continue;
                        }
                        foreach (array('bin', 'lib') as $sub) {
                            $dirs[] = $modules . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . $sub;
                        }
                    }
                }
            }
        }
        $path = getenv('PATH');
        if (is_string($path) && $path !== '') {
            foreach (explode(PATH_SEPARATOR, $path) as $dir) {
                if ($dir !== '') {
                    $dirs[] = $dir;
                }
            }
        }
        foreach ($programFileGlobs as $pattern) {
            $matches = glob($pattern, GLOB_ONLYDIR);
            if (!is_array($matches)) {
                continue;
            }
            foreach ($matches as $match) {
                foreach ($programFileSubdirs as $sub) {
                    if ($sub === '') {
                        $dirs[] = $match;
                        continue;
                    }
                    $dirs[] = rtrim($match, '\\/') . DIRECTORY_SEPARATOR . $sub;
                }
            }
        }
        $found = array();
        foreach ($dirs as $dir) {
            foreach ($dllNames as $name) {
                $file = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . $name;
                if (is_file($file)) {
                    $found[$file] = $file;
                }
            }
        }
        return array_merge(array_values($found), $dllNames);
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

    /**
     * @param array<int,string> $hints
     */
    private static function moduleHintMatches($entry, array $hints)
    {
        foreach ($hints as $hint) {
            if (stripos($entry, $hint) !== false) {
                return true;
            }
        }
        return false;
    }
}
