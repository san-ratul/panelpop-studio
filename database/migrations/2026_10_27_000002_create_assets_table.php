<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('disk', 50)->default('media-private');
            $table->string('path', 1024);
            $table->string('original_filename', 255)->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->json('meta')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index(['project_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
