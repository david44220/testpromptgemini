<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->unsignedSmallInteger('rake_bps')->default(1000)->after('stake_amount_cents');
            $table->timestamp('in_progress_at')->nullable()->after('status');
            $table->timestamp('abandon_deadline_at')->nullable()->index()->after('in_progress_at');
            $table->timestamp('first_run_submitted_at')->nullable()->after('abandon_deadline_at');
            $table->timestamp('forfeit_deadline_at')->nullable()->index()->after('first_run_submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex(['abandon_deadline_at']);
            $table->dropIndex(['forfeit_deadline_at']);
            $table->dropColumn([
                'rake_bps',
                'in_progress_at',
                'abandon_deadline_at',
                'first_run_submitted_at',
                'forfeit_deadline_at',
            ]);
        });
    }
};
