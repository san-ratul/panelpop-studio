<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-scopes the content pipeline from project-level to video-level:
 *
 * - stories and video_metadata become 1:1 with a video (was: 1:1 with project);
 * - scenes become video-owned (unique scene numbering per video);
 * - video-scoped assets (scene images, voice audio, renders) gain a video
 *   reference while project-level assets (character references, music, SFX)
 *   keep their project ownership;
 * - generation jobs gain an optional video reference (project-level jobs such
 *   as character reference generation stay project-owned);
 * - provider calls gain an optional video reference so per-video cost
 *   attribution is exact.
 *
 * Every step is additive-first with a full backfill from the 1:1 mapping that
 * existed before videos (one video per project), so existing installations
 * lose no data. Constraints are only tightened after the backfill.
 */
return new class extends Migration
{
    /** Job types that belong to a video's generation pipeline. */
    private const VIDEO_SCOPED_JOB_TYPES = [
        'story_generation',
        'scene_planning',
        'scene_image',
        'voice_generation',
        'video_render',
        'thumbnail',
        'subtitle_export',
    ];

    /** Asset types that belong to a video rather than the project library. */
    private const VIDEO_SCOPED_ASSET_TYPES = [
        'scene_image',
        'voice_audio',
        'video',
        'thumbnail',
        'subtitle',
    ];

    public function up(): void
    {
        // 1. Add nullable video references everywhere. Each step checks
        //    first: MySQL DDL does not run inside a transaction, so a partial
        //    application (connection loss mid-migration) must be resumable.
        if (! Schema::hasColumn('stories', 'video_id')) {
            Schema::table('stories', function (Blueprint $table): void {
                $table->foreignId('video_id')->nullable()->after('id');
            });
        }

        if (! Schema::hasColumn('scenes', 'video_id')) {
            Schema::table('scenes', function (Blueprint $table): void {
                $table->foreignId('video_id')->nullable()->after('id');
            });
        }

        if (! Schema::hasColumn('assets', 'video_id')) {
            Schema::table('assets', function (Blueprint $table): void {
                $table->foreignId('video_id')->nullable()->after('project_id');
                $table->index(['video_id', 'type']);
            });
        }

        if (! Schema::hasColumn('generation_jobs', 'video_id')) {
            Schema::table('generation_jobs', function (Blueprint $table): void {
                $table->foreignId('video_id')->nullable()->after('project_id');
                $table->index(['video_id', 'status']);
            });
        }

        if (! Schema::hasColumn('video_metadata', 'video_id')) {
            Schema::table('video_metadata', function (Blueprint $table): void {
                $table->foreignId('video_id')->nullable()->after('id');
            });
        }

        if (! Schema::hasColumn('provider_calls', 'video_id')) {
            Schema::table('provider_calls', function (Blueprint $table): void {
                $table->foreignId('video_id')->nullable()->after('project_id');
                $table->index('video_id');
            });
        }

        // 2. Backfill. Every project has exactly one video at this point
        //    (created by the previous migration), so the project join is 1:1.
        //    Rows already migrated (video_id set) are left untouched; joins
        //    are guarded because steps 3–5 may have partially applied on a
        //    previous interrupted run (MySQL DDL is not transactional).
        if (Schema::hasColumn('stories', 'project_id')) {
            DB::statement('UPDATE stories s JOIN videos v ON v.project_id = s.project_id SET s.video_id = v.id WHERE s.video_id IS NULL');
        }

        if (Schema::hasColumn('scenes', 'project_id')) {
            DB::statement('UPDATE scenes sc JOIN videos v ON v.project_id = sc.project_id SET sc.video_id = v.id WHERE sc.video_id IS NULL');
        }

        if (Schema::hasColumn('video_metadata', 'project_id')) {
            DB::statement('UPDATE video_metadata vm JOIN videos v ON v.project_id = vm.project_id SET vm.video_id = v.id WHERE vm.video_id IS NULL');
        }

        DB::statement(
            "UPDATE assets a JOIN videos v ON v.project_id = a.project_id
             SET a.video_id = v.id
             WHERE a.video_id IS NULL AND a.type IN ('scene_image', 'voice_audio', 'video', 'thumbnail', 'subtitle')"
        );
        DB::statement(
            "UPDATE generation_jobs gj JOIN videos v ON v.project_id = gj.project_id
             SET gj.video_id = v.id
             WHERE gj.video_id IS NULL AND gj.type IN ('story_generation', 'scene_planning', 'scene_image', 'voice_generation', 'video_render', 'thumbnail', 'subtitle_export')"
        );
        DB::statement(
            'UPDATE provider_calls pc JOIN generation_jobs gj ON gj.id = pc.generation_job_id
             SET pc.video_id = gj.video_id
             WHERE pc.video_id IS NULL AND gj.video_id IS NOT NULL'
        );
        // Rows recorded without a generation job still attribute to the video
        // when their operation belongs to the video pipeline.
        DB::statement(
            "UPDATE provider_calls pc JOIN videos v ON v.project_id = pc.project_id
             SET pc.video_id = v.id
             WHERE pc.video_id IS NULL AND pc.operation IN ('story', 'scene_planning', 'video_metadata', 'character_reference', 'scene_image', 'narration_tts', 'dialogue_tts')"
        );

        // 3. Stories: 1:1 with video (was 1:1 with project).
        if (Schema::hasColumn('stories', 'project_id')) {
            if (in_array('stories_project_id_foreign', $this->foreignKeyNames('stories'), true)) {
                Schema::table('stories', function (Blueprint $table): void {
                    $table->dropForeign(['project_id']);
                });
            }

            // Older installs may not carry the unique index (it exists on
            // fresh databases); drop it only when present.
            if (in_array('stories_project_id_unique', $this->indexNames('stories'), true)) {
                Schema::table('stories', function (Blueprint $table): void {
                    $table->dropUnique(['project_id']);
                });
            }

            Schema::table('stories', function (Blueprint $table): void {
                $table->dropColumn('project_id');
            });
        }

        $storyForeignKeys = $this->foreignKeyNames('stories');
        $storyIndexes = $this->indexNames('stories');

        Schema::table('stories', function (Blueprint $table) use ($storyForeignKeys, $storyIndexes): void {
            $table->foreignId('video_id')->nullable(false)->change();

            if (! in_array('stories_video_id_foreign', $storyForeignKeys, true)) {
                $table->foreign('video_id')->references('id')->on('videos')->cascadeOnDelete();
            }

            if (! in_array('stories_video_id_unique', $storyIndexes, true)) {
                $table->unique('video_id');
            }
        });

        // 4. Scenes: video-owned, unique numbering per video. The FK must be
        //    dropped first — MySQL enforces it through the shared
        //    (project_id, status) index, which cannot drop while the FK uses
        //    it.
        if (Schema::hasColumn('scenes', 'project_id')) {
            if (in_array('scenes_project_id_foreign', $this->foreignKeyNames('scenes'), true)) {
                Schema::table('scenes', function (Blueprint $table): void {
                    $table->dropForeign(['project_id']);
                });
            }

            $sceneIndexes = $this->indexNames('scenes');

            Schema::table('scenes', function (Blueprint $table) use ($sceneIndexes): void {
                if (in_array('scenes_project_id_number_unique', $sceneIndexes, true)) {
                    $table->dropUnique(['project_id', 'number']);
                }

                if (in_array('scenes_project_id_status_index', $sceneIndexes, true)) {
                    $table->dropIndex(['project_id', 'status']);
                }
            });

            Schema::table('scenes', function (Blueprint $table): void {
                $table->dropColumn('project_id');
            });
        }

        $sceneForeignKeys = $this->foreignKeyNames('scenes');
        $sceneIndexes = $this->indexNames('scenes');

        Schema::table('scenes', function (Blueprint $table) use ($sceneForeignKeys, $sceneIndexes): void {
            $table->foreignId('video_id')->nullable(false)->change();

            if (! in_array('scenes_video_id_foreign', $sceneForeignKeys, true)) {
                $table->foreign('video_id')->references('id')->on('videos')->cascadeOnDelete();
            }

            if (! in_array('scenes_video_id_number_unique', $sceneIndexes, true)) {
                $table->unique(['video_id', 'number']);
            }

            if (! in_array('scenes_video_id_status_index', $sceneIndexes, true)) {
                $table->index(['video_id', 'status']);
            }
        });

        // 5. Video metadata: 1:1 with video (was 1:1 with project).
        if (Schema::hasColumn('video_metadata', 'project_id')) {
            if (in_array('video_metadata_project_id_foreign', $this->foreignKeyNames('video_metadata'), true)) {
                Schema::table('video_metadata', function (Blueprint $table): void {
                    $table->dropForeign(['project_id']);
                });
            }

            if (in_array('video_metadata_project_id_unique', $this->indexNames('video_metadata'), true)) {
                Schema::table('video_metadata', function (Blueprint $table): void {
                    $table->dropUnique(['project_id']);
                });
            }

            Schema::table('video_metadata', function (Blueprint $table): void {
                $table->dropColumn('project_id');
            });
        }

        $metadataForeignKeys = $this->foreignKeyNames('video_metadata');
        $metadataIndexes = $this->indexNames('video_metadata');

        Schema::table('video_metadata', function (Blueprint $table) use ($metadataForeignKeys, $metadataIndexes): void {
            $table->foreignId('video_id')->nullable(false)->change();

            if (! in_array('video_metadata_video_id_foreign', $metadataForeignKeys, true)) {
                $table->foreign('video_id')->references('id')->on('videos')->cascadeOnDelete();
            }

            if (! in_array('video_metadata_video_id_unique', $metadataIndexes, true)) {
                $table->unique('video_id');
            }
        });

        // 6. provider_calls: keep nullable video reference (project-level
        //    calls remain possible) with an exact FK.
        Schema::table('provider_calls', function (Blueprint $table): void {
            $table->foreign('video_id')->references('id')->on('videos')->nullOnDelete();
        });
    }

    /**
     * Existing index names on a table (lowercased), so partial applications
     * of this migration can resume safely.
     *
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        return array_map(
            static fn (array $index): string => strtolower((string) ($index['name'] ?? '')),
            Schema::getIndexes($table),
        );
    }

    /**
     * Existing FK constraint names on a table (lowercased), so partial
     * applications of this migration can resume safely.
     *
     * @return list<string>
     */
    private function foreignKeyNames(string $table): array
    {
        return array_map(
            static fn (array $foreignKey): string => strtolower((string) ($foreignKey['name'] ?? '')),
            Schema::getForeignKeys($table),
        );
    }

    public function down(): void
    {
        // Reverse is only safe while every project has at most one video;
        // additional videos (and their content) are removed by the video
        // cascade first.
        DB::statement('DELETE v FROM videos v JOIN projects p ON p.id = v.project_id
            WHERE v.id NOT IN (SELECT MIN(id) FROM videos GROUP BY project_id)');

        Schema::table('provider_calls', function (Blueprint $table): void {
            $table->dropForeign(['video_id']);
            $table->dropIndex(['video_id']);
            $table->dropColumn('video_id');
        });

        Schema::table('video_metadata', function (Blueprint $table): void {
            $table->dropForeign(['video_id']);
            $table->dropUnique(['video_id']);
        });
        Schema::table('video_metadata', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->after('id');
            $table->dropColumn('video_id');
        });
        DB::statement('UPDATE video_metadata vm JOIN videos v ON v.id = vm.video_id SET vm.project_id = v.project_id');
        Schema::table('video_metadata', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable(false)->change();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique('project_id');
        });

        Schema::table('scenes', function (Blueprint $table): void {
            $table->dropForeign(['video_id']);
            $table->dropUnique(['video_id', 'number']);
            $table->dropIndex(['video_id', 'status']);
        });
        Schema::table('scenes', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->after('id');
            $table->dropColumn('video_id');
        });
        DB::statement('UPDATE scenes sc JOIN videos v ON v.id = sc.video_id SET sc.project_id = v.project_id');
        Schema::table('scenes', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable(false)->change();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status']);
        });

        Schema::table('stories', function (Blueprint $table): void {
            $table->dropForeign(['video_id']);
            $table->dropUnique(['video_id']);
        });
        Schema::table('stories', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->after('id');
            $table->dropColumn('video_id');
        });
        DB::statement('UPDATE stories s JOIN videos v ON v.id = s.video_id SET s.project_id = v.project_id');
        Schema::table('stories', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable(false)->change();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique('project_id');
        });

        Schema::table('generation_jobs', function (Blueprint $table): void {
            $table->dropIndex(['video_id', 'status']);
            $table->dropColumn('video_id');
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->dropIndex(['video_id', 'type']);
            $table->dropColumn('video_id');
        });
    }
};
