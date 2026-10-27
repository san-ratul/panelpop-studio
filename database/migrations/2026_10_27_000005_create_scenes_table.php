<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('story_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->decimal('duration_seconds', 5, 2);
            $table->text('background_description');
            $table->text('image_prompt');
            $table->text('narration_text')->nullable();
            $table->foreignId('image_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->json('speech_bubble_settings')->nullable();
            $table->string('status', 30)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('rendered_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenes');
    }
};
