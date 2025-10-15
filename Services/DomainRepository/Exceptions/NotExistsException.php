<?php

namespace Partners2016\Framework\Campaigns\Services\DomainRepository\Exceptions;

use Throwable;

class NotExistsException extends \Exception
{
    public function __construct(string $message = "Domain not exists in file", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}