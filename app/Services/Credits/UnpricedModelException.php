<?php

namespace App\Services\Credits;

use RuntimeException;

/**
 * A generation was requested whose model has no price anywhere (no admin
 * model_pricing row, no verified config rate). Paid generation never runs
 * at an unknown price (AITUBER mission §7).
 */
final class UnpricedModelException extends RuntimeException {}
