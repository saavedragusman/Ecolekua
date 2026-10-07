<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The submitted main phone already belongs to other customers (CLI-012, DEC-CLI-03, E-14). It is a
 * warning, not a rule: the caller repeats the request with `confirm_duplicate_phone = true` to
 * save anyway. Thrown by the Actions after validation and before any write; rendered by
 * RenderDuplicatePhoneWarning (registered in bootstrap/app.php).
 */
class DuplicatePhoneWarning extends RuntimeException
{
    public const MESSAGE = 'Este teléfono ya está registrado en otro cliente. Revise las coincidencias y confirme si desea guardar de todos modos.';

    /**
     * @param  list<array{id: int, name: string, document: ?string, status: string, status_label: string}>  $matches
     */
    public function __construct(public readonly array $matches)
    {
        parent::__construct(self::MESSAGE);
    }
}
