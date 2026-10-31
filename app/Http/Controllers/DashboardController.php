<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $projects = $request->user()->projects()
            ->withCount('videos')
            ->orderByDesc('updated_at')
            ->get()
            ->map(static fn (Project $project): array => [
                ...ProjectController::present($project),
                'video_count' => $project->videos_count,
                'updated_at' => $project->updated_at->toISOString(),
            ])
            ->values()
            ->all();

        return Inertia::render('Dashboard', [
            'projects' => $projects,
        ]);
    }
}
