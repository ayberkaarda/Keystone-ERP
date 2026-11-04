<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Http\Resources;

use App\Modules\Treasury\Models\TreasuryAllocation;
use App\Modules\Treasury\Models\TreasuryPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * One treasury payment as GET /treasury-payments/{id} returns it, and as the offline sync pulls
 * and pushes it (`treasury-payments`): the stored columns, the allocations embedded and
 * `allocations_redacted`.
 *
 * An allocation is shown only to a user who may view invoices of its module in the active
 * company (sales.invoices.view / purchasing.invoices.view); `allocations_redacted` tells that
 * some were left out. Editing such a payment needs those permissions anyway
 * (TreasuryCoreController::sources), so a client never rewrites allocations it cannot see.
 *
 * @mixin TreasuryPayment
 */
final class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TreasuryPayment $payment */
        $payment = $this->resource;
        $payment->loadMissing('allocations');
        /** @var iterable<TreasuryAllocation> $allocations */
        $allocations = $payment->getRelation('allocations');
        $visible = [];
        $hidden = 0;

        foreach ($allocations as $allocation) {
            $permission = $allocation->invoice_module === 'sales' ? 'sales.invoices.view' : 'purchasing.invoices.view';

            if (Gate::allows('treasury-access', [$permission])) {
                $visible[] = $allocation->getAttributes();
            } else {
                $hidden++;
            }
        }

        return [...$payment->attributesToArray(), 'allocations' => $visible, 'allocations_redacted' => $hidden > 0];
    }
}
