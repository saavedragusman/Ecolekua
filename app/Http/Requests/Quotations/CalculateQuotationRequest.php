<?php

namespace App\Http\Requests\Quotations;

use App\Models\Quotation;
use App\Support\Quotations\Calculation\CalculationInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The editor's unsaved lines for a live calculation (COT-009, COT-022). It needs `quotations.create`, and
 * also `quotations.negotiate` when it carries a negotiated price or an agreed advance (E-20, design
 * Decision 6). Only the structure is validated here; business problems come back in the calculation.
 */
class CalculateQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can('calculate', Quotation::class)) {
            return false;
        }

        return ! $this->carriesNegotiation() || $user->can('negotiate', Quotation::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return CalculationInput::rules();
    }

    private function carriesNegotiation(): bool
    {
        if ($this->filled('agreed_advance')) {
            return true;
        }

        $lines = $this->input('lines');

        foreach (is_array($lines) ? $lines : [] as $line) {
            if (is_array($line) && ! empty($line['negotiated'])) {
                return true;
            }
        }

        return false;
    }
}
