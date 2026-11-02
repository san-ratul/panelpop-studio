<?php

namespace App\Http\Controllers;

use App\AI\Support\ToolRegistry;
use App\Enums\PresentationMode;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public static function presentationModes(): array
    {
        return array_map(
            static fn (PresentationMode $mode): array => ['value' => $mode->value, 'label' => $mode->label()],
            PresentationMode::cases()
        );
    }

    /**
     * Catalog options for the visual style + language dropdowns
     * (config/catalog.php). The current stored value is merged in so
     * existing projects with custom values remain selectable.
     */
    public static function styleOptions(?string $current = null): array
    {
        return self::catalogOptions('visual_styles', $current);
    }

    public static function languageOptions(?string $current = null): array
    {
        return self::catalogOptions('languages', $current);
    }

    private static function catalogOptions(string $key, ?string $current): array
    {
        $catalog = collect(config('catalog.'.$key, []))
            ->map(static fn (array $entry): array => ['value' => (string) $entry['value'], 'label' => (string) $entry['label']]);

        if (is_string($current) && $current !== '' && ! $catalog->contains(static fn (array $entry): bool => $entry['value'] === $current)) {
            $catalog->push(['value' => $current, 'label' => $current.' (current)']);
        }

        return $catalog->values()->all();
    }

    public function create(Request $request): Response
    {
        $toolSlug = $request->query('tool');
        $tool = is_string($toolSlug) && $toolSlug !== '' ? ToolRegistry::find($toolSlug) : null;

        return Inertia::render('Projects/Create', [
            'presentationModes' => self::presentationModes(),
            'visualStyles' => self::styleOptions(),
            'languages' => self::languageOptions(),
            'toolPreset' => $tool === null ? null : [
                'slug' => $toolSlug,
                'label' => (string) ($tool['label'] ?? $toolSlug),
                'visual_style' => (string) ($tool['visual_style'] ?? ''),
                'presentation_mode' => (string) ($tool['presentation_mode'] ?? ''),
                'brief_scaffold' => (string) ($tool['brief_scaffold'] ?? ''),
            ],
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $project = $request->user()->projects()->create($request->validated());

        // New projects start with an empty video workspace; the project's
        // visual style + language become the defaults every video inherits.
        return redirect()->route('videos.index', $project)
            ->with('success', 'Project created. Create your first video next.');
    }

    public function edit(Request $request, Project $project): Response
    {
        $this->authorize('update', $project);

        return Inertia::render('Projects/Edit', [
            'project' => self::present($project),
            'presentationModes' => self::presentationModes(),
            'visualStyles' => self::styleOptions($project->visual_style),
            'languages' => self::languageOptions($project->language),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $project->update($request->validated());

        return redirect()->route('dashboard')->with('success', 'Project updated.');
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        DB::transaction(fn (): bool => (bool) $project->delete());

        return redirect()->route('dashboard')->with('success', 'Project deleted.');
    }

    public static function present(Project $project): array
    {
        return [
            'id' => $project->id,
            'title' => $project->title,
            'presentation_mode' => $project->presentation_mode->value,
            'target_duration_seconds' => $project->target_duration_seconds,
            'visual_style' => $project->visual_style,
            'language' => $project->language,
            'status' => $project->status->value,
            'settings' => $project->settings,
        ];
    }
}
