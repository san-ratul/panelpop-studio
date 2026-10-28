<?php

namespace Tests\Support;

use App\Services\Render\ProcessResult;
use App\Services\Render\ProcessRunner;

/**
 * Deterministic ProcessRunner stub for Phase 6 tests: records every call
 * (asserting argument arrays/timeouts) and answers from a queued handler
 * list. A missing handler answers success without output.
 */
final class FakeProcessRunner implements ProcessRunner
{
    /**
     * Every executed call: ['args' => list<string>, 'timeout' => float].
     *
     * @var list<array{args: list<string>, timeout: float}>
     */
    public array $calls = [];

    /** @var list<callable(list<string>, float): ProcessResult> */
    private array $handlers = [];

    /**
     * @param  callable(list<string>, float): ProcessResult  ...$handlers
     */
    public function queue(callable ...$handlers): void
    {
        $this->handlers = array_merge($this->handlers, array_values($handlers));
    }

    public function run(array $args, float $timeoutSeconds): ProcessResult
    {
        $this->calls[] = ['args' => $args, 'timeout' => $timeoutSeconds];

        $handler = array_shift($this->handlers);

        return $handler !== null
            ? $handler($args, $timeoutSeconds)
            : new ProcessResult(0, '', '', false, false);
    }
}
