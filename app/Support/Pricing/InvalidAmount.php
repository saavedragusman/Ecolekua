<?php

namespace App\Support\Pricing;

use InvalidArgumentException;

/**
 * A text that is not a valid amount for the requested scale (DT-01, design Decision 3). Callers turn
 * it into a field error in Spanish; the message here is technical.
 */
class InvalidAmount extends InvalidArgumentException {}
