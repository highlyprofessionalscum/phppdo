<?php

namespace PhpPdo;

class PDOException extends \RuntimeException
{
    /** @var array|null */
    public $errorInfo;

    /**
     * @param string $message
     * @param string|int $code SQLSTATE
     * @param \Throwable|null $previous
     * @param array|null $errorInfo
     */
    public function __construct($message = '', $code = 0, $previous = null, array $errorInfo = null)
    {
        parent::__construct($message, 0, $previous);
        $this->code = (string) $code;
        $this->errorInfo = $errorInfo;
    }
}
