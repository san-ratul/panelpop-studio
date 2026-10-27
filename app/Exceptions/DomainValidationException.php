<?php

namespace App\Exceptions;

use RuntimeException;

class DomainValidationException extends RuntimeException
{
    /**
     * @param  array<string, string>  $errors
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct($errors['duration_sum'] ?? reset($errors));
    }
}
