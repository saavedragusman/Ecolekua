<?php

namespace App\Console\Commands;

use App\Actions\Users\CreateFirstAdministrator as CreateFirstAdministratorAction;
use App\Exceptions\BusinessRuleViolation;
use App\Support\Audit\AuditOrigin;
use App\Support\Users\UserRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\text;

/**
 * Interactive bootstrap of the first administrator (DEC-021, design Decision 17). Thin like a
 * controller: it collects and validates the identity, calls the Action and prints the temporary
 * password once. The password is never logged, audited or stored in plain text.
 */
class CreateFirstAdministrator extends Command
{
    protected $signature = 'users:create-administrator';

    protected $description = 'Crea el primer administrador con una contraseña temporal (solo si no hay un administrador activo)';

    public function handle(CreateFirstAdministratorAction $action): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Este comando es interactivo: no puede ejecutarse con --no-interaction ni sin terminal.');

            return self::FAILURE;
        }

        $data = [
            'first_name' => trim(text(label: 'Nombre', required: true)),
            'last_name' => trim(text(label: 'Apellido', required: true)),
            'email' => trim(text(label: 'Correo electrónico', required: true)),
        ];

        $validator = Validator::make($data, UserRules::identity());

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        try {
            $result = $action->handle(
                $data['first_name'],
                $data['last_name'],
                $data['email'],
                AuditOrigin::console($this->getName() ?? 'users:create-administrator'),
            );
        } catch (BusinessRuleViolation $violation) {
            $this->error($violation->getMessage());

            return self::FAILURE;
        }

        $this->info("Administrador creado: {$result['user']->email}");
        $this->line("Contraseña temporal: {$result['password']}");
        $this->warn('Anótela ahora: no se volverá a mostrar. Deberá cambiarla en el primer inicio de sesión.');

        return self::SUCCESS;
    }
}
