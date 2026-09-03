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
        Schema::create('duel_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('session_secret', 64);
            $table->string('ticket_token', 64)->nullable()->unique();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('ticks_elapsed')->nullable();
            $table->decimal('final_distance', 10, 2)->nullable();
            $table->unsignedBigInteger('final_score')->nullable();
            $table->string('inputs_hash', 64)->nullable();
            $table->json('input_log')->nullable();
            $table->string('client_signature', 128)->nullable();
            $table->string('audit_status')->default('PENDING')->index();
            $table->string('audit_failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['match_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('duel_runs');
    }
};
