<?php

namespace App\Matches;

use RuntimeException;

/** Ett ogiltigt drag. $errorCode är API-felkoden (t.ex. not_your_turn, out_of_range). */
class IllegalMove extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
