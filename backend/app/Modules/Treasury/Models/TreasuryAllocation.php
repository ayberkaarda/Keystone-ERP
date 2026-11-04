<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Models;

use App\Core\Company\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model of one payment allocation, for the payment's `allocations` embed. Rows are
 * written by PaymentService through the query builder; this model never writes. No casts and
 * no timestamp handling: attributes are the stored column values, as the query builder reads
 * them, so the REST detail and the pulled row carry the same strings.
 *
 * @property string $id
 * @property string $company_id
 * @property string $payment_id
 * @property string $invoice_module
 */
final class TreasuryAllocation extends Model
{
    use BelongsToCompany;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'treasury_allocations';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = ['*'];
}
