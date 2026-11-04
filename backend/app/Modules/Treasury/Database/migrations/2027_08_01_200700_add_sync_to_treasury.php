<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Offline sync position for treasury payments (`treasury-payments` in the offline manifest):
 * the payment header is tracked, its allocations touch it (they are embedded in the row, not a
 * resource of their own).
 *
 * Guards: treasury_payments_immutable (BEFORE UPDATE OR DELETE, treasury_document_immutable)
 * sorts before zz_sync_touch, so it compares rows with sync_txid still unchanged. It refuses
 * every update of a posted or reversed payment except the reversal, and a parent touch is such
 * an update; it never reaches one: treasury_allocations_immutable refuses any allocation
 * change under a posted or reversed payment before the statement's AFTER trigger could touch
 * the parent. Allocations change only while the payment is a draft or approved (save, and the
 * settlement amounts post() writes before it sets `posted`), so the guard needs no exemption.
 *
 * Transfers, plans, movements and accounts stay out of sync: they are not offline resources,
 * and movements are the append-only ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treasury_payments', function (Blueprint $table): void {
            $table->syncable();
        });

        Schema::table('treasury_allocations', function (Blueprint $table): void {
            $table->syncable(parent: 'treasury_payments', parentKey: ['payment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('treasury_allocations', function (Blueprint $table): void {
            $table->dropSyncable(parent: 'treasury_payments');
        });

        Schema::table('treasury_payments', function (Blueprint $table): void {
            $table->dropSyncable();
        });
    }
};
