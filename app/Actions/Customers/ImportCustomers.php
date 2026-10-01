<?php

namespace App\Actions\Customers;

use App\Enums\CustomerType;
use App\Enums\DocumentType;
use App\Enums\VenezuelanState;
use App\Models\Customer;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Customers\CustomerCsvReader;
use App\Support\Customers\CustomerRules;
use App\Support\Customers\DocumentNumber;
use App\Support\Customers\ImportReport;
use App\Support\Customers\InvalidCustomerCsv;
use App\Support\Customers\PhoneNumber;
use App\Support\Users\UserRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * One-time, all-or-nothing import of the existing customers (CLI-018, design Decision 14).
 *
 * Phase 0 checks the author, then the file; phase 1 validates every row with the same
 * `CustomerRules` as the HTTP form and compares documents and phones between rows and against the
 * database; only when nothing blocks, phase 2 creates every customer through `CreateCustomer` in
 * one transaction, so any exception leaves the database untouched. It is not a generic import
 * engine: it knows this one file layout.
 */
class ImportCustomers
{
    private const COMMAND = 'customers:import';

    /** Validation attribute of a scalar or nested field => CSV column. */
    private const COLUMN_BY_ATTRIBUTE = [
        'type' => 'tipo',
        'name' => 'nombre',
        'document_type' => 'tipo_documento',
        'document_number' => 'numero_documento',
        'phone' => 'telefono',
        'email' => 'correo',
        'birthday_day' => 'cumpleanos_dia',
        'birthday_month' => 'cumpleanos_mes',
        'anniversary_day' => 'aniversario_dia',
        'anniversary_month' => 'aniversario_mes',
        'notes' => 'observaciones',
        'contact.name' => 'contacto_nombre',
        'contact.position' => 'contacto_cargo',
        'contact.phone' => 'contacto_telefono',
        'contact.email' => 'contacto_correo',
        'address.line' => 'direccion',
        'address.city' => 'direccion_ciudad',
        'address.state' => 'direccion_estado',
        'address.reference' => 'direccion_referencia',
        'advisor_id' => 'correo_asesora',
    ];

    /** Columns that make up the optional nested objects. */
    private const CONTACT_COLUMNS = ['contacto_nombre', 'contacto_cargo', 'contacto_telefono', 'contacto_correo'];

    private const ADDRESS_COLUMNS = ['direccion', 'direccion_ciudad', 'direccion_estado', 'direccion_referencia'];

    public function __construct(
        private readonly CustomerCsvReader $reader,
        private readonly CreateCustomer $createCustomer,
    ) {}

    /**
     * @throws \Throwable when a row fails during creation; nothing has been saved then
     */
    public function handle(string $path, ?string $authorEmail, bool $confirmDuplicatePhones): ImportReport
    {
        $author = $this->activeUser($authorEmail);

        if ($author === null) {
            return ImportReport::blocked([
                trim((string) $authorEmail) === ''
                    ? 'Debe indicar el autor con --author=correo.'
                    : 'El autor indicado no existe o está inactivo.',
            ]);
        }

        try {
            $rows = $this->reader->read($path);
        } catch (InvalidCustomerCsv $invalid) {
            return ImportReport::blocked($invalid->problems);
        }

        $errors = [];
        $prepared = [];
        $documents = [];
        $phones = [];

        foreach ($rows as $row) {
            ['input' => $input, 'advisor' => $advisor, 'errors' => $rowErrors, 'phone' => $phone] = $this->validated($row['cells'], $row['line'], $documents);

            array_push($errors, ...$rowErrors);

            if ($phone !== null) {
                $phones[$phone][] = $row['line'];
            }

            $prepared[] = ['line' => $row['line'], 'cells' => $row['cells'], 'input' => $input, 'advisor' => $advisor];
        }

        $warnings = $this->phoneWarnings($phones);

        if ($errors !== [] || ($warnings !== [] && ! $confirmDuplicatePhones)) {
            return new ImportReport(errors: $errors, warnings: $warnings);
        }

        return $this->create($prepared, $author, $warnings);
    }

    private function activeUser(?string $email): ?User
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return User::query()
            ->where('email', UserRules::normalizeEmail($email))
            ->where('is_active', true)
            ->first();
    }

    /**
     * Phase 1 for one row.
     *
     * @param  array<string, string>  $cells
     * @param  array<string, int>  $documents  normalized document => first row (updated here)
     * @return array{input: array<string, mixed>, advisor: ?User, errors: list<array{row: int, column: string, reason: string}>, phone: ?string}
     */
    private function validated(array $cells, int $line, array &$documents): array
    {
        $input = $this->input($cells);
        $validator = Validator::make($input, CustomerRules::customer());

        foreach (CustomerRules::after() as $check) {
            $validator->after($check);
        }

        $errors = [];

        foreach ($validator->errors()->messages() as $attribute => $reasons) {
            foreach ($reasons as $reason) {
                $errors[] = ['row' => $line, 'column' => $this->column($attribute, $cells), 'reason' => $reason];
            }
        }

        $failed = $validator->errors();

        if (! $failed->has('document_type') && ! $failed->has('document_number')) {
            $repeated = $this->repeatedDocument($input, $line, $documents);

            if ($repeated !== null) {
                $errors[] = ['row' => $line, 'column' => 'numero_documento', 'reason' => $repeated];
            }
        }

        $advisor = null;

        if (($cells['correo_asesora'] ?? '') !== '') {
            $advisor = $this->activeUser($cells['correo_asesora']);

            if ($advisor === null || ! $advisor->isEligibleAdvisor()) {
                $advisor = null;
                $errors[] = ['row' => $line, 'column' => 'correo_asesora', 'reason' => __('validation.customer_advisor_ineligible')];
            }
        }

        return [
            'input' => $input,
            'advisor' => $advisor,
            'errors' => $errors,
            'phone' => $failed->has('phone') ? null : PhoneNumber::parse((string) $input['phone'])->e164(),
        ];
    }

    /**
     * The row's cells in the shape of the HTTP form. Blank cells are left out (not sent as null),
     * so each rule reports once, exactly as for a request that omits the field.
     *
     * @param  array<string, string>  $cells
     * @return array<string, mixed>
     */
    private function input(array $cells): array
    {
        $contact = $this->present([
            'name' => $cells['contacto_nombre'],
            'position' => $cells['contacto_cargo'],
            'phone' => $cells['contacto_telefono'],
            'email' => $cells['contacto_correo'],
        ]);
        $address = $this->present([
            'line' => $cells['direccion'],
            'city' => $cells['direccion_ciudad'],
            'state' => $this->stateValue($cells['direccion_estado']),
            'reference' => $cells['direccion_referencia'],
        ]);

        $type = mb_strtolower($cells['tipo']);
        $documentType = mb_strtolower($cells['tipo_documento']);

        return $this->present([
            'type' => $type === 'empresa' ? CustomerType::Company->value : $type,
            'name' => $cells['nombre'],
            'document_type' => $documentType === 'pasaporte' ? DocumentType::Passport->value : $documentType,
            'document_number' => $cells['numero_documento'],
            'phone' => $cells['telefono'],
            'email' => $cells['correo'],
            'birthday_day' => $this->number($cells['cumpleanos_dia']),
            'birthday_month' => $this->number($cells['cumpleanos_mes']),
            'anniversary_day' => $this->number($cells['aniversario_dia']),
            'anniversary_month' => $this->number($cells['aniversario_mes']),
            'notes' => $cells['observaciones'],
            'contact' => $contact ?: null,
            'address' => $address ?: null,
        ]);
    }

    /**
     * Whole numbers become integers (as in a JSON request); anything else is kept for the
     * `integer` rule to reject.
     */
    private function number(string $cell): int|string
    {
        return ctype_digit($cell) ? (int) $cell : $cell;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function present(array $values): array
    {
        return array_filter($values, fn (mixed $value): bool => $value !== '' && $value !== null);
    }

    /**
     * The enum value of the state whose label matches the cell, ignoring case and accents
     * (DEC-CLI-33); any other text is kept so the enum rule rejects it.
     */
    private function stateValue(string $cell): string
    {
        return VenezuelanState::fromLabel($cell)->value ?? $cell;
    }

    /**
     * The CSV column of a validation attribute. A failing nested object as a whole points to its
     * first filled column.
     *
     * @param  array<string, string>  $cells
     */
    private function column(string $attribute, array $cells): string
    {
        $group = match ($attribute) {
            'contact' => self::CONTACT_COLUMNS,
            'address' => self::ADDRESS_COLUMNS,
            default => null,
        };

        if ($group === null) {
            return self::COLUMN_BY_ATTRIBUTE[$attribute] ?? $attribute;
        }

        foreach ($group as $column) {
            if ($cells[$column] !== '') {
                return $column;
            }
        }

        return $group[0];
    }

    /**
     * "Documento repetido en la fila N" when an earlier row has the same normalized document.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, int>  $documents
     */
    private function repeatedDocument(array $input, int $line, array &$documents): ?string
    {
        $type = isset($input['document_type']) ? DocumentType::tryFrom((string) $input['document_type']) : null;

        if ($type === null || ! isset($input['document_number'])) {
            return null;
        }

        $key = $type->value.'|'.DocumentNumber::normalize($type, (string) $input['document_number']);

        if (isset($documents[$key])) {
            return "Documento repetido en la fila {$documents[$key]}.";
        }

        $documents[$key] = $line;

        return null;
    }

    /**
     * Phones repeated between rows or already registered (DEC-CLI-22), by row.
     *
     * @param  array<string, list<int>>  $phones  normalized phone => rows
     * @return list<array{row: int, reason: string}>
     */
    private function phoneWarnings(array $phones): array
    {
        $registered = Customer::query()->whereIn('phone', array_keys($phones))->orderBy('id')->get()->groupBy('phone');
        $warnings = [];

        foreach ($phones as $phone => $lines) {
            foreach ($lines as $line) {
                foreach ($registered->get($phone, []) as $customer) {
                    $warnings[] = ['row' => $line, 'reason' => "El teléfono ya está registrado en el cliente #{$customer->id} ({$customer->name})."];
                }

                $others = array_values(array_diff($lines, [$line]));

                if ($others !== []) {
                    $label = count($others) === 1 ? 'la fila' : 'las filas';
                    $warnings[] = ['row' => $line, 'reason' => "El teléfono se repite en {$label} ".implode(', ', $others).'.'];
                }
            }
        }

        usort($warnings, fn (array $a, array $b): int => $a['row'] <=> $b['row']);

        return $warnings;
    }

    /**
     * Phase 2: every row in one transaction; `confirmDuplicatePhone` is true because the phones
     * were already reviewed (or confirmed) in phase 1. Only the row's own advisor is assigned
     * (DEC-CLI-23) and each audit row carries the console origin and the import marker (DEC-CLI-24).
     *
     * A row rejected by a validation error (a document taken by another process, an advisor
     * deactivated since phase 1) rolls the whole import back and is reported like a phase 1
     * error: line, CSV column and reason, never the cell values.
     *
     * @param  list<array{line: int, cells: array<string, string>, input: array<string, mixed>, advisor: ?User}>  $prepared
     * @param  list<array{row: int, reason: string}>  $warnings
     */
    private function create(array $prepared, User $author, array $warnings): ImportReport
    {
        $origin = AuditOrigin::console(self::COMMAND);
        $errors = [];

        try {
            DB::transaction(function () use ($prepared, $author, $origin, &$errors): void {
                foreach ($prepared as $row) {
                    try {
                        $this->createCustomer->handle(
                            $row['input'],
                            $author,
                            confirmDuplicatePhone: true,
                            origin: $origin,
                            autoAssign: false,
                            advisor: $row['advisor'],
                            auditContext: ['import' => true, 'import_row' => $row['line']],
                        );
                    } catch (ValidationException $rejected) {
                        $errors = $this->rejectionErrors($row['line'], $row['cells'], $rejected);

                        throw $rejected;
                    }
                }
            });
        } catch (ValidationException) {
            return new ImportReport(errors: $errors, warnings: $warnings);
        }

        return new ImportReport(warnings: $warnings, imported: count($prepared));
    }

    /**
     * @param  array<string, string>  $cells
     * @return list<array{row: int, column: string, reason: string}>
     */
    private function rejectionErrors(int $line, array $cells, ValidationException $rejected): array
    {
        $errors = [];

        foreach ($rejected->errors() as $attribute => $reasons) {
            foreach ($reasons as $reason) {
                $errors[] = ['row' => $line, 'column' => $this->column($attribute, $cells), 'reason' => $reason];
            }
        }

        return $errors;
    }
}
