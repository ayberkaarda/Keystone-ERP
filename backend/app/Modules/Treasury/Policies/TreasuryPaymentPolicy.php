<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Policies;

use App\Core\Company\CompanyContext;
use App\Models\User;
use App\Modules\Identity\Contracts\CompanyAuthorizer;
use App\Modules\Treasury\Models\TreasuryPayment;

/**
 * Record abilities of a treasury payment for callers that work with policies (the offline sync
 * push). The REST controller checks the same permissions through the `treasury-access` gate;
 * both read treasury.payments.<action> in the active company, so neither path is wider than
 * the other. A payment of another company is never allowed (the company scope hides it, this
 * is the second line).
 */
final class TreasuryPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allowed($user, null, 'treasury.payments.view');
    }

    public function view(User $user, TreasuryPayment $payment): bool
    {
        return $this->allowed($user, $payment, 'treasury.payments.view');
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, null, 'treasury.payments.create');
    }

    public function update(User $user, TreasuryPayment $payment): bool
    {
        return $this->allowed($user, $payment, 'treasury.payments.update');
    }

    public function delete(User $user, TreasuryPayment $payment): bool
    {
        return $this->allowed($user, $payment, 'treasury.payments.delete');
    }

    private function allowed(User $user, ?TreasuryPayment $payment, string $permission): bool
    {
        $company = app(CompanyContext::class)->idOrNull();

        return $company !== null
            && ($payment === null || $payment->company_id === $company)
            && app(CompanyAuthorizer::class)->allows($user, $permission, $company);
    }
}
