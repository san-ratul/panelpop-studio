<?php

namespace App\Exceptions;

use RuntimeException;

final class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $available,
        public readonly int $required,
    ) {
        parent::__construct(sprintf(
            'Insufficient credits: %d available, %d required. Top up your credit balance to continue.',
            $available,
            $required,
        ));
    }
}
