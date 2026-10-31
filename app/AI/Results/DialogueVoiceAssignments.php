<?php

namespace App\AI\Results;

/**
 * AI dialogue voice assignments for a batch of lines, with provider usage
 * metadata for the ledger.
 */
final class DialogueVoiceAssignments
{
    /**
     * @param  list<PlannedVoiceAssignment>  $assignments
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly array $assignments,
        public readonly ?ProviderUsage $usage = null,
        public readonly ?float $costActual = null,
        public readonly array $meta = [],
    ) {}
}
