<?php

namespace App\Console\Commands;

use App\Actions\Customers\ImportCustomers as ImportCustomersAction;
use App\Support\Customers\ImportReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-time import of the existing customers (CLI-018, design Decision 14). Thin like a controller:
 * non-interactive, it calls the Action and prints its report, which holds row numbers, column names
 * and reasons only, never cell values. Nothing is written to disk by the command, and an unexpected
 * failure is logged by class only because exception messages can carry SQL bindings (cell values).
 */
class ImportCustomers extends Command
{
    protected $signature = 'customers:import
        {file : Ruta del archivo CSV en UTF-8}
        {--author= : Correo del usuario interno activo que figura como creador}
        {--confirm-duplicate-phones : Importa aunque haya teléfonos repetidos, después de revisarlos}';

    protected $description = 'Carga única de clientes desde un archivo CSV (todo o nada)';

    public function handle(ImportCustomersAction $action): int
    {
        $author = $this->option('author');

        try {
            $report = $action->handle(
                (string) $this->argument('file'),
                is_string($author) ? $author : null,
                (bool) $this->option('confirm-duplicate-phones'),
            );
        } catch (Throwable $failure) {
            Log::error('customers:import failed', ['exception' => $failure::class]);
            $this->error('No se guardó ningún cliente: la importación falló ('.class_basename($failure).').');

            return self::FAILURE;
        }

        return $this->print($report);
    }

    private function print(ImportReport $report): int
    {
        foreach ($report->problems as $problem) {
            $this->error($problem);
        }

        if ($report->errors !== []) {
            $this->error('No se importó ningún cliente: el archivo tiene '.count($report->errors).' error(es).');
            $this->table(
                ['Fila', 'Columna', 'Motivo'],
                array_map(fn (array $error): array => [$error['row'], $error['column'], $error['reason']], $report->errors),
            );
        }

        if ($report->warnings !== []) {
            $this->warn('Teléfonos repetidos:');
            $this->table(
                ['Fila', 'Advertencia'],
                array_map(fn (array $warning): array => [$warning['row'], $warning['reason']], $report->warnings),
            );
        }

        if ($report->hasBlockingFindings()) {
            return self::FAILURE;
        }

        if ($report->imported === 0) {
            $this->error('No se importó ningún cliente. Revise las advertencias y vuelva a ejecutar con --confirm-duplicate-phones para importar de todos modos.');

            return self::FAILURE;
        }

        $this->info($report->imported === 1 ? 'Se importó 1 cliente.' : "Se importaron {$report->imported} clientes.");

        return self::SUCCESS;
    }
}
