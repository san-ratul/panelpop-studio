<?php

namespace App\Http\Controllers;

use App\AI\Exceptions\ProviderException;
use App\AI\Support\VoiceCatalog;
use App\Enums\GenerationJobType;
use App\Http\Controllers\Support\GenerationJobPresenter;
use App\Http\Requests\CharacterRequest;
use App\Models\Character;
use App\Models\Project;
use App\Services\GenerationJobGuard;
use App\Services\VoicePreviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CharacterController extends Controller
{
    public function __construct(
        private readonly GenerationJobGuard $jobGuard,
        private readonly VoicePreviewService $voicePreview,
    ) {}

    public function index(Project $project): Response
    {
        $this->authorize('view', $project);

        $referenceJobs = GenerationJobPresenter::recentForProject($project, GenerationJobType::CharacterReference);

        return Inertia::render('Characters/Index', [
            'project' => ProjectController::present($project),
            'characters' => $project->characters()->orderBy('name')->get()->map(
                static fn (Character $character): array => self::present($character)
            ),
            'referenceJobs' => $referenceJobs,
            'referenceJobActive' => GenerationJobPresenter::hasActive($referenceJobs),
            'voiceGroups' => VoiceCatalog::voiceGroups(),
            'stylePresets' => VoiceCatalog::stylePresets(),
            'defaultVoice' => (string) config('ai.openai.tts.default_voice', 'alloy'),
        ]);
    }

    public function store(CharacterRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('create', [Character::class, $project]);

        $project->characters()->create($request->validated());

        return redirect()->route('characters.index', $project)
            ->with('success', 'Character added.');
    }

    public function edit(Project $project, Character $character): Response
    {
        $this->authorize('view', $character);

        return Inertia::render('Characters/Edit', [
            'project' => ProjectController::present($project),
            'character' => self::present($character),
            'voiceGroups' => VoiceCatalog::voiceGroups(),
            'stylePresets' => VoiceCatalog::stylePresets(),
            'defaultVoice' => (string) config('ai.openai.tts.default_voice', 'alloy'),
        ]);
    }

    public function update(CharacterRequest $request, Project $project, Character $character): RedirectResponse
    {
        $this->authorize('update', $character);

        $character->update($request->validated());

        return redirect()->route('characters.index', $project)
            ->with('success', 'Character updated.');
    }

    public function destroy(Project $project, Character $character): RedirectResponse
    {
        $this->authorize('delete', $character);

        $character->delete();

        return redirect()->route('characters.index', $project)
            ->with('success', 'Character deleted.');
    }

    public function generateReference(Project $project, Character $character): RedirectResponse
    {
        $this->authorize('update', $character);

        $this->jobGuard->cancelStaleForSubject(
            $project,
            GenerationJobType::CharacterReference,
            'character_id',
            $character->id,
        );

        $active = $this->jobGuard->activeJobForSubject(
            $project,
            GenerationJobType::CharacterReference,
            'character_id',
            $character->id,
        );

        if ($active !== null) {
            throw ValidationException::withMessages([
                'reference' => sprintf(
                    'A reference image is already %s for %s. Wait for it to finish.',
                    $active->status->value,
                    $character->name,
                ),
            ]);
        }

        $this->jobGuard->launch($project, GenerationJobType::CharacterReference, [
            'character_id' => $character->id,
            'character_name' => $character->name,
        ]);

        return redirect()->route('characters.index', $project)
            ->with('success', sprintf('Reference image generation queued for %s.', $character->name));
    }

    /**
     * Streams a short voice preview for the selected voice + style so the
     * user can listen before saving. The response IS the audio bytes (the
     * preview component fetches it via XHR and plays it); nothing is
     * persisted as a production asset, and identical selections reuse the
     * cached provider artifact.
     */
    public function previewVoice(Request $request, Project $project): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorize('create', [Character::class, $project]);

        $data = $request->validate([
            'voice' => ['nullable', 'string', 'max:120'],
            'style' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $preview = $this->voicePreview->preview($project, $data['voice'] ?? null, $data['style'] ?? null);
        } catch (ProviderException $exception) {
            throw ValidationException::withMessages([
                'preview' => sprintf('Voice preview failed: %s', $exception->getMessage()),
            ]);
        }

        $disk = Storage::disk($preview['disk']);

        abort_unless($disk->exists($preview['path']), 404);

        return response()->streamDownload(function () use ($disk, $preview): void {
            echo $disk->get($preview['path']);
        }, 'voice-preview.'.pathinfo($preview['path'], PATHINFO_EXTENSION), [
            'Content-Type' => $preview['mime'],
            'Cache-Control' => 'private, max-age=3600',
            'X-Preview-Reused' => $preview['reused'] ? '1' : '0',
        ]);
    }

    public static function present(Character $character): array
    {
        return [
            'id' => $character->id,
            'name' => $character->name,
            'description' => $character->description,
            'consistency_notes' => $character->consistency_notes,
            'voice_config' => $character->voice_config,
            'has_reference_image' => $character->reference_asset_id !== null,
            'reference_image_url' => $character->reference_asset_id !== null
                ? route('assets.show', ['project' => $character->project_id, 'asset' => $character->reference_asset_id])
                : null,
        ];
    }
}
