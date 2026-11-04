<?php

declare(strict_types=1);

namespace App\Modules\Treasury;

use App\Core\Company\CompanyContext;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Sync\Contracts\SyncResource;
use App\Core\Sync\Contracts\SyncWriter;
use App\Models\User;
use App\Modules\Identity\Contracts\CompanyAuthorizer;
use App\Modules\Settings\Contracts\SettingsRegistrar;
use App\Modules\Settings\Data\SettingDefinition;
use App\Modules\Settings\Enums\SettingType;
use App\Modules\Treasury\Contracts\PaymentReversalGuard;
use App\Modules\Treasury\Contracts\TreasuryAccountLookup;
use App\Modules\Treasury\Contracts\TreasuryInvoiceAccess;
use App\Modules\Treasury\Contracts\TreasuryMovementLookup;
use App\Modules\Treasury\Contracts\TreasuryMovementWriter;
use App\Modules\Treasury\Contracts\TreasuryPaymentLookup;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryPayment;
use App\Modules\Treasury\Models\TreasuryPaymentPlan;
use App\Modules\Treasury\Models\TreasuryTransfer;
use App\Modules\Treasury\Policies\TreasuryPaymentPolicy;
use App\Modules\Treasury\Services\AccountService;
use App\Modules\Treasury\Services\InvoiceBridge;
use App\Modules\Treasury\Services\PaymentService;
use App\Modules\Treasury\Services\UnmatchedPayments;
use App\Modules\Treasury\Sync\PaymentSyncResource;
use App\Modules\Treasury\Sync\PaymentSyncWriter;
use App\Modules\Workflow\Events\ApprovalCompleted;
use App\Modules\Workflow\Events\ApprovalRejected;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Translation\Translator;

final class TreasuryServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Contracts\AssistantTools::class, Assistant\Tools::class);
        $this->app->bind(TreasuryAccountLookup::class, AccountService::class);
        $this->app->bind(TreasuryMovementWriter::class, AccountService::class);
        $this->app->bind(TreasuryMovementLookup::class, AccountService::class);
        $this->app->bind(TreasuryPaymentLookup::class, PaymentService::class);
        $this->app->bind(TreasuryInvoiceAccess::class, InvoiceBridge::class);
        $this->app->bindIf(PaymentReversalGuard::class, UnmatchedPayments::class);
        // Offline sync of draft payments (offline-manifest `treasury-payments`), collected by the Sync module by tag.
        $this->app->tag([PaymentSyncResource::class], SyncResource::TAG);
        $this->app->tag([PaymentSyncWriter::class], SyncWriter::TAG);
        foreach ([Banking\BankingServiceProvider::class, Instruments\InstrumentsServiceProvider::class, Reports\ReportsServiceProvider::class] as $provider) {
            if (class_exists($provider)) {
                $this->app->register($provider);
            }
        }
    }

    public function boot(): void
    {
        $this->bootModule();
        $this->app->booted(function (): void {
            $translator = app(Translator::class);
            foreach (['tr', 'en', 'de', 'fr'] as $locale) {
                $translator->load('settings', 'registry', $locale);
                $labels = $translator->get('treasury::settings', [], $locale, false);
                if (! is_array($labels)) {
                    continue;
                }$lines = [];
                foreach ($labels as $key => $label) {
                    if (is_string($key) && is_string($label)) {
                        $lines['registry.'.$key] = $label;
                    }
                }$translator->addLines($lines, $locale, 'settings');
            }
        });
        Gate::define('treasury-access', static function (User $user, string $permission): bool {
            $company = app(CompanyContext::class)->idOrNull();

            return $company !== null && app(CompanyAuthorizer::class)->allows($user, $permission, $company);
        });
        Gate::policy(TreasuryPayment::class, TreasuryPaymentPolicy::class);
        Relation::morphMap(['treasury_payment' => TreasuryPayment::class, 'treasury_account' => TreasuryAccount::class, 'treasury_transfer' => TreasuryTransfer::class, 'treasury_payment_plan' => TreasuryPaymentPlan::class]);
        app(SettingsRegistrar::class)->register(new SettingDefinition('treasury.require_approval_over', SettingType::String, '', 'treasury', rules: ['nullable', 'regex:/^(\d{1,16}(\.\d{1,4})?)?$/']));
        Event::listen(ApprovalCompleted::class, static function (ApprovalCompleted $e): void {
            if ($e->documentType === PaymentService::APPROVAL_TYPE) {
                app(CompanyContext::class)->runAs($e->companyId, fn () => app(PaymentService::class)->approval($e->documentId, $e->requestId, true));
            }
        });
        Event::listen(ApprovalRejected::class, static function (ApprovalRejected $e): void {
            if ($e->documentType === PaymentService::APPROVAL_TYPE) {
                app(CompanyContext::class)->runAs($e->companyId, fn () => app(PaymentService::class)->approval($e->documentId, $e->requestId, false));
            }
        });
    }
}
