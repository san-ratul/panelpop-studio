<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Introduces the Video domain model: a project owns multiple independent
 * videos, and every video carries its own story, scenes, audio, render
 * outputs and generation history. Existing projects are migrated safely:
 * each project receives exactly one video that inherits the project's
 * current pipeline state, so no generated data is lost or re-parented
 * incorrectly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            // Per-video pipeline state, mirroring the ProjectStatus lifecycle.
            $table->string('status', 30)->default('draft');
            // Storyboard duration target for this video; new videos inherit
            // the project default.
            $table->unsignedSmallInteger('target_duration_seconds')->default(60);
            // Optional video-level overrides; NULL inherits the project default.
            $table->string('presentation_mode_override', 30)->nullable();
            $table->string('visual_style_override', 100)->nullable();
            $table->string('language_override', 10)->nullable();
            // Video-level settings (e.g. narration voice override); project
            // settings act as the fallback layer.
            $table->json('settings')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'sort_order']);
            $table->index(['project_id', 'status']);
        });

        // Backfill: one video per existing project, seeded from the story
        // title when present, carrying the project's pipeline status so the
        // aggregate (recomputed by the application afterwards) is unchanged.
        $projectIds = DB::table('projects')->orderBy('id')->pluck('id');
        foreach ($projectIds as $index => $projectId) {
            $project = DB::table('projects')->where('id', $projectId)->first();
            $story = DB::table('stories')->where('project_id', $projectId)->first();

            DB::table('videos')->insert([
                'project_id' => $projectId,
                'title' => $story?->title ?? $project->title,
                'status' => $project->status,
                'target_duration_seconds' => $project->target_duration_seconds,
                'sort_order' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Rolling back merges each project's FIRST video back into the
        // project-level pipeline state; additional videos (only possible on
        // a post-migration install) are removed with their content by the
        // cascade. Generated media on disk is intentionally left untouched.
        foreach (DB::table('projects')->orderBy('id')->pluck('id') as $projectId) {
            $video = DB::table('videos')->where('project_id', $projectId)->orderBy('id')->first();

            if ($video === null) {
                continue;
            }

            DB::table('projects')->where('id', $projectId)->update(['status' => $video->status]);
        }

        Schema::dropIfExists('videos');
    }
};
