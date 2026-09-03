<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('duel_runs', function (Blueprint $table) {
            $table->string('session_secret', 64)->nullable()->change();
            $table->string('ticket_hash', 64)->nullable()->index()->after('ticket_token');
            $table->timestamp('ticket_expires_at')->nullable()->after('ticket_hash');
        });
    }

    public function down(): void
    {
        Schema::table('duel_runs', function (Blueprint $table) {
            $table->dropIndex(['ticket_hash']);
            $table->dropColumn(['ticket_hash', 'ticket_expires_at']);
        });
    }
};
