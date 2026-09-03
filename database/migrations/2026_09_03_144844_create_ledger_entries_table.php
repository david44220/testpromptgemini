<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('transaction_group_id')->index();
            $table->foreignId('wallet_id')->nullable()->index();
            $table->foreign('wallet_id', 'fk_ledger_entries_wallet')->references('id')->on('wallets')->nullOnDelete();
            $table->foreignId('ledger_account_id')->nullable()->index();
            $table->foreign('ledger_account_id', 'fk_ledger_entries_account')->references('id')->on('ledger_accounts')->nullOnDelete();
            $table->string('type');
            $table->unsignedBigInteger('amount_cents');
            $table->string('category');
            $table->nullableMorphs('reference');
            $table->string('description');
            $table->bigInteger('balance_after_cents');
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
