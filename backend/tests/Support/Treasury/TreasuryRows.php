<?php

declare(strict_types=1);

namespace Tests\Support\Treasury;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\Support\Actors;
use Tests\Support\Purchasing\PurchKit;

/**
 * Treasury payments for the offline-manifest query tests: a purchasing world (PurchKit::world(),
 * accounting chart, supplier $w->party) gets a cash and a bank account and payments in both
 * directions, draft and posted, with dates, amounts and descriptions that sort and filter apart.
 */
final class TreasuryRows
{
    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: ?string, 4: bool}>  $payments  [direction, date, amount, description, posted]
     * @param  (Closure(): void)|null  $between  called before each payment (e.g. to move the clock)
     * @return list<string> payment ids
     */
    public static function seed(object $w, array $payments, ?Closure $between = null): array
    {
        $permissions = array_keys((require base_path('app/Modules/Treasury/permissions.php'))['permissions']);
        $user = Actors::user([...$permissions, ...PurchKit::ALL], $w->company);
        $cash = self::account($user, $w->company, 'QCASH', 'cash', '100');
        $bank = self::account($user, $w->company, 'QBANK', 'bank', '102');
        $ids = [];

        foreach ($payments as $i => [$direction, $date, $amount, $description, $posted]) {
            if ($between !== null) {
                $between();
            }

            $payment = PurchKit::post($user, $w->company, 'treasury-payments', [
                'direction' => $direction, 'account_id' => $i % 2 === 0 ? $cash : $bank, 'party_id' => $w->party->id,
                'counterparty_module' => 'purchasing', 'document_date' => $date, 'amount' => $amount, 'fx_rate' => '1',
                'description' => $description, 'reference' => 'Q-'.$i,
            ])->assertCreated()->json('data');

            if ($posted) {
                PurchKit::post($user, $w->company, "treasury-payments/{$payment['id']}/post", ['version' => $payment['version']])->assertOk();
            }

            $ids[] = (string) $payment['id'];
        }

        return $ids;
    }

    private static function account(User $user, string $company, string $code, string $type, string $gl): string
    {
        $glId = DB::table('accounts')->where('company_id', $company)->where('code', $gl)->value('id');

        return (string) PurchKit::post($user, $company, 'treasury-accounts', [
            'type' => $type, 'code' => $code, 'name' => $code, 'currency_code' => 'TRY', 'gl_account_id' => $glId,
        ])->assertCreated()->json('data.id');
    }
}
