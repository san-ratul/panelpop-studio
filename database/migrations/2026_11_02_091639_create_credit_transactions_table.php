<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only user credit ledger (AITUBER mission §9). Every row is
        // one immutable REAL balance movement: purchases/grants add credits,
        // consumption spends them (negative), refunds and admin adjustments
        // correct the balance. Holds live in credit_reservations, so the
        // spendable balance is the signed SUM over user_id — derived, never
        // a mutable column — and balance_after_credits records the running
        // total for audit. idempotency_key makes every movement replay-safe.
        Schema::create('credit_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->integer('amount_credits');
            $table->integer('balance_after_credits');
            $table->char('currency', 3)->default('USD');
            $table->foreignId('generation_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('credit_reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->string('description', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'type']);
            $table->index('generation_job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
