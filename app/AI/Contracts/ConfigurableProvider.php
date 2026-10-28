<?php

namespace App\AI\Contracts;

use App\AI\ResolvedProvider;

/**
 * Implemented by real provider adapters that need their resolution context
 * (credential, model, settings) injected after the manager resolves them.
 * Fakes do not implement this; the capability contracts stay unchanged.
 */
interface ConfigurableProvider
{
    public function configure(ResolvedProvider $provider): void;
}
