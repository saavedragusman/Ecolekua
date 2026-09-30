<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A domain rule rejected an operation (design "Business rules enforced in Actions").
 * Actions throw it before writing anything, or inside their transaction so it rolls back.
 * Rendered as an error flash by RenderBusinessRuleViolation (registered in bootstrap/app.php).
 */
class BusinessRuleViolation extends RuntimeException {}
