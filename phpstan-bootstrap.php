<?php

if (!class_exists('FFI', false)) {
    class FFI
    {
        public static function scope($name)
        {
            return new self();
        }

        public static function cdef($code, $lib = null)
        {
            return new self();
        }

        public static function isNull($ptr)
        {
            return $ptr === null;
        }

        public static function new($type)
        {
            return null;
        }

        public static function string($ptr, $length = 0)
        {
            return '';
        }

        public static function addr($ptr)
        {
            return null;
        }

        public static function cast($type, $ptr)
        {
            return null;
        }

        public static function memcpy($dst, $src, $size)
        {
        }
    }
}
