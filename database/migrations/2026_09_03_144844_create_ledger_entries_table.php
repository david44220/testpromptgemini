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
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete()->index();
            $table->foreignId('ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete()->index();
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
