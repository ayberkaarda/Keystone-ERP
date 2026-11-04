<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class TreasuryPayment extends TreasuryRecord
{
    protected $table = 'treasury_payments';

    /** The offline sync position (2027_08_01_200700) is bookkeeping, never part of a response. */
    protected $hidden = ['sync_txid'];

    protected function casts(): array
    {
        return [];
    }

    /**
     * Invoice allocations in the order of the REST detail.
     *
     * @return HasMany<TreasuryAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(TreasuryAllocation::class, 'payment_id')->orderBy('treasury_allocations.id');
    }
}
