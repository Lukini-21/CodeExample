<?php

namespace Partners2016\Framework\Campaigns\Services\DomainRepository\Exceptions;

use Throwable;

class AlreadyAddedException extends \Exception
{
    public function __construct(string $message = "Domain already added", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}