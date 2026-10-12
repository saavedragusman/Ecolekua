<?php

namespace App\Console\Commands;

use App\Actions\Products\ImportCatalog as ImportCatalogAction;
use App\Support\Products\Import\CatalogImportReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-time initial load of the catalog (PRD-018, design Decision 19). Thin like a controller:
 * non-interactive, it calls the Action and prints its report, which holds file names, row numbers,
 * column names and reasons only, never cell values. Nothing is written to disk by the command, and
 * an unexpected failure is logged by class only because exception messages can carry SQL bindings
 * (cell values). The files are expected under `storage/app/private/imports/` and are deleted by the
 * operator after a clean run; they are never versioned.
 */
class ImportCatalog extends Command
{
    protected $signature = 'catalog:import
        {--combinations= : Ruta del archivo CSV de combinaciones}
        {--combos= : Ruta del archivo CSV de combos (puede traer solo el encabezado)}
        {--colors= : Ruta del archivo CSV de colores (puede traer solo el encabezado)}
        {--author= : Correo del usuario interno activo que figura como creador}';

    protected $description = 'Carga inicial única del catálogo desde archivos CSV (todo o nada)';

    public function handle(ImportCatalogAction $action): int
    {
        try {
            $report = $action->handle(
                $this->path('combinations'),
                $this->path('combos'),
                $this->path('colors'),
                $this->path('author'),
            );
        } catch (Throwable $failure) {
            Log::error('catalog:import failed', ['exception' => $failure::class]);
            $this->error('No se importó nada: la importación falló ('.class_basename($failure).').');

            return self::FAILURE;
        }

        return $this->print($report);
    }

    private function path(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) ? $value : null;
    }

    private function print(CatalogImportReport $report): int
    {
        foreach ($report->problems as $problem) {
            $this->error($problem);
        }

        if ($report->errors !== []) {
            $this->error('No se importó nada: los archivos tienen '.count($report->errors).' error(es).');
            $this->table(
                ['Archivo', 'Fila', 'Columna', 'Motivo'],
                array_map(fn (array $error): array => [$error['file'], $error['row'], $error['column'], $error['reason']], $report->errors),
            );
        }

        if ($report->hasBlockingFindings()) {
            return self::FAILURE;
        }

        $this->info('Se importó el catálogo: '.implode(', ', array_map(
            fn (string $kind, int $count): string => "{$count} {$kind}",
            array_keys($report->imported),
            $report->imported,
        )).'.');

        return self::SUCCESS;
    }
}
