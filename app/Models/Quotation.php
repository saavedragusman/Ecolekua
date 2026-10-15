<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A quotation of the ERP (COT-013). Its table, attributes and relations arrive with the quotation schema
 * (Phase 11a); until then the class exists so `QuotationPolicy` is discovered for the calculation endpoints,
 * which authorize against the class and never read a row.
 */
class Quotation extends Model {}
