<?php

namespace App\Exceptions;

/**
 * Render pre-flight failure: carries actionable, sanitized messages only
 * (SECURITY.md — no internal paths, binaries or traces).
 */
class RenderPreFlightException extends \RuntimeException
{
    /**
     * @param  list<string>  $messages
     */
    public function __construct(public readonly array $messages)
    {
        parent::__construct($messages === [] ? 'The project is not ready to render.' : $messages[0]);
    }
}
