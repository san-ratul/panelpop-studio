<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Credit holds for in-flight generations (AITUBER mission §9). A
        // reservation reduces the SPENDABLE balance (balance minus open
        // holds) without writing a ledger movement; finalization converts
        // it into one Consume ledger row for the actual usage, and a failed
        // generation simply releases the hold. This keeps credit_transactions
        // a ledger of real movements only (running balance_after stays a
        // plain cumulative sum) while overspending stays impossible: all
        // holds and movements serialize on the user row lock.
        Schema::create('credit_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_job_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('amount_credits');
            $table->unsignedInteger('consumed_credits')->default(0);
            // held | consumed | released
            $table->string('status', 20)->default('held');
            $table->string('idempotency_key', 120)->unique();
            $table->string('description', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('generation_job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_reservations');
    }
};
