<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('capability', 20);
            $table->string('provider', 60);
            $table->string('label', 120);
            $table->boolean('is_default');
            $table->boolean('is_active')->default(true);
            $table->json('config');
            $table->text('credentials');
            $table->timestamps();

            $table->index(['capability', 'owner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_settings');
    }
};
