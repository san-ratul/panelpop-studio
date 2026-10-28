<?php

namespace App\Console\Commands;

use App\Enums\AssetType;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\GenerationJobGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Stale-render sweep (PHASE_06: scheduler entry). A worker crash can leave a
 * queued/running render job row forever, which would block every future
 * render of the project, or a project stuck in `rendering`. Also removes
 * orphaned render temp dirs and quarantined gate artifacts older than the
 * configured retention.
 */
class SweepStaleRenders extends Command
{
    protected $signature = 'render:sweep-stale';

    protected $description = 'Cancel stale render jobs, resolve stuck rendering projects, and remove orphaned render temp/quarantine files';

    private const ORPHAN_HOURS = 24;

    public function handle(GenerationJobGuard $guard): int
    {
        $projects = Project::query()
            ->whereHas('generationJobs', fn ($query) => $query
                ->where('type', GenerationJobType::VideoRender->value)
                ->whereIn('status', [GenerationJobStatus::Queued->value, GenerationJobStatus::Running->value])
                ->where('updated_at', '<', now()->subMinutes(GenerationJobGuard::STALE_MINUTES)))
            ->pluck('id');

        $cancelled = 0;

        foreach ($projects as $projectId) {
            $project = Project::query()->find($projectId);

            if ($project === null) {
                continue;
            }

            $cancelled += $guard->cancelStale($project, GenerationJobType::VideoRender);
        }

        $resolved = 0;

        Project::query()
            ->where('status', ProjectStatus::Rendering->value)
            ->each(function (Project $project) use (&$resolved): void {
                $hasActiveJob = $project->generationJobs()
                    ->where('type', GenerationJobType::VideoRender->value)
                    ->whereIn('status', [GenerationJobStatus::Queued->value, GenerationJobStatus::Running->value])
                    ->where('updated_at', '>=', now()->subMinutes(GenerationJobGuard::STALE_MINUTES))
                    ->exists();

                if ($hasActiveJob) {
                    return;
                }

                $hasCompletedVideo = $project->assets()
                    ->where('type', AssetType::Video->value)
                    ->exists();

                $project->update([
                    'status' => $hasCompletedVideo ? ProjectStatus::Completed : ProjectStatus::Failed,
                ]);

                $resolved++;
            });

        $tempCleaned = $this->cleanTempDirs();
        $quarantineCleaned = $this->cleanQuarantine();

        $this->info(sprintf(
            'Render sweep: %d stale job(s) cancelled, %d stuck project(s) resolved, %d temp dir(s) and %d quarantined artifact(s) removed.',
            $cancelled,
            $resolved,
            $tempCleaned,
            $quarantineCleaned,
        ));

        return self::SUCCESS;
    }

    private function cleanTempDirs(): int
    {
        $root = (string) config('ffmpeg.temp_dir', storage_path('app/rendering'));
        $cutoff = now()->subHours((int) config('ffmpeg.temp_max_age_hours', self::ORPHAN_HOURS))->getTimestamp();

        if (! is_dir($root)) {
            return 0;
        }

        $removed = 0;

        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) !== false && filemtime($dir) < $cutoff) {
                $this->deleteDirectory($dir);
                $removed++;
            }
        }

        return $removed;
    }

    private function cleanQuarantine(): int
    {
        $disk = Storage::disk((string) config('ai.artifact_disk'));
        $cutoff = now()->subHours(self::ORPHAN_HOURS);

        $removed = 0;

        foreach ($disk->directories('renders') ?: [] as $projectDir) {
            $quarantine = $projectDir.'/quarantine';

            foreach ($disk->files($quarantine) ?: [] as $file) {
                $lastModified = $disk->lastModified($file);

                if ($lastModified < $cutoff->getTimestamp()) {
                    $disk->delete($file);
                    $removed++;
                }
            }
        }

        return $removed;
    }

    private function deleteDirectory(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
