<?php

namespace App\Services;

class SuppressedContactException extends \RuntimeException
{
    public function __construct(string $message = 'This contact has opted out. Nothing may be sent to this number.')
    {
        parent::__construct($message);
    }
}
