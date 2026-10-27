<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('generation_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('capability', 20);
            $table->string('provider', 60);
            $table->string('model', 120)->nullable();
            $table->string('status', 20);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->decimal('cost_estimate', 10, 6)->nullable();
            $table->decimal('cost_actual', 10, 6)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->string('error_class', 120)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_calls');
    }
};
