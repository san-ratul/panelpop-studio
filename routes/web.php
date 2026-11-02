<?php

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminModelPricingController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\CostAnalyticsController;
use App\Http\Controllers\CostCallReportController;
use App\Http\Controllers\CostEstimateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PricingSimulationController;
use App\Http\Controllers\ProjectAudioController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectRenderController;
use App\Http\Controllers\ProviderSettingsController;
use App\Http\Controllers\SceneController;
use App\Http\Controllers\SceneDialogueLineController;
use App\Http\Controllers\StoryboardController;
use App\Http\Controllers\StoryController;
use App\Http\Controllers\ToolLibraryController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\VoiceLibraryController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware('auth')
    ->name('dashboard');

// Tool library (AITUBER mission §6B): searchable generation-tool presets
// over the shared pipeline.
Route::get('/tools', [ToolLibraryController::class, 'index'])
    ->middleware('auth')
    ->name('tools.index');

// Internal admin area — admins only (D-030): aggregate accounting data and
// operational reports must never be reachable by ordinary users. The admin
// sidebar entries merely aid discoverability; this gate is the boundary.
Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
    Route::get('/costs', [CostAnalyticsController::class, 'index'])->name('costs.index');
    Route::get('/costs/calls', [CostCallReportController::class, 'index'])->name('costs.calls');
    Route::get('/pricing-simulation', [PricingSimulationController::class, 'index'])->name('pricing.simulation');
    Route::get('/models', [AdminModelPricingController::class, 'index'])->name('models.index');
    Route::post('/models', [AdminModelPricingController::class, 'store'])->name('models.store');
    Route::patch('/models/{modelPricing}', [AdminModelPricingController::class, 'update'])->name('models.update');
    Route::delete('/models/{modelPricing}', [AdminModelPricingController::class, 'destroy'])->name('models.destroy');
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
    Route::post('/users/{user}/credits', [AdminUserController::class, 'adjust'])->name('users.credits');
});

Route::middleware('auth')->group(function () {
    // Estimate-before-generate (AITUBER mission §8): the backend is the
    // single authority for credit pricing — the frontend re-asks whenever
    // generation parameters change and never computes prices itself.
    Route::post('/generation/estimate', [CostEstimateController::class, 'store'])->name('generation.estimate');

    // Voice library (AITUBER mission §10): provider-synced ElevenLabs
    // catalog with search/filter/preview.
    Route::get('/voices', [VoiceLibraryController::class, 'index'])->name('voices.index');
    Route::post('/voices/sync', [VoiceLibraryController::class, 'sync'])->name('voices.sync');
    Route::get('/voices/{voice}/preview', [VoiceLibraryController::class, 'preview'])
        ->withoutScopedBindings()
        ->name('voices.preview');

    Route::get('/settings/providers', [ProviderSettingsController::class, 'index'])->name('provider-settings.index');
    Route::post('/settings/providers', [ProviderSettingsController::class, 'store'])->name('provider-settings.store');
    Route::patch('/settings/providers/{providerSetting}', [ProviderSettingsController::class, 'update'])->name('provider-settings.update');
    Route::delete('/settings/providers/{providerSetting}', [ProviderSettingsController::class, 'destroy'])->name('provider-settings.destroy');

    Route::get('/settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/settings/password', [PasswordController::class, 'update'])->name('password.update');
});

Route::middleware('auth')->scopeBindings()->group(function () {
    Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
    Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    // Project videos: a project owns multiple independent videos; the video
    // listing is the workspace entry point for each of them.
    Route::get('/projects/{project}/videos', [VideoController::class, 'index'])->name('videos.index');
    Route::get('/projects/{project}/videos/create', [VideoController::class, 'create'])->name('videos.create');
    Route::post('/projects/{project}/videos', [VideoController::class, 'store'])->name('videos.store');
    Route::get('/projects/{project}/videos/{video}/edit', [VideoController::class, 'edit'])->name('videos.edit');
    Route::patch('/projects/{project}/videos/{video}', [VideoController::class, 'update'])->name('videos.update');
    Route::delete('/projects/{project}/videos/{video}', [VideoController::class, 'destroy'])->name('videos.destroy');
    Route::post('/projects/{project}/videos/{video}/duplicate', [VideoController::class, 'duplicate'])->name('videos.duplicate');
    Route::post('/projects/{project}/videos/{video}/archive', [VideoController::class, 'archive'])->name('videos.archive');
    Route::post('/projects/{project}/videos/{video}/restore', [VideoController::class, 'restore'])->name('videos.restore');

    Route::get('/projects/{project}/videos/{video}/story/edit', [StoryController::class, 'edit'])->name('story.edit');
    Route::put('/projects/{project}/videos/{video}/story', [StoryController::class, 'save'])->name('story.save');
    Route::post('/projects/{project}/videos/{video}/story/generate', [StoryController::class, 'generate'])->name('story.generate');

    // Characters are a project-level reusable library (shared across all
    // videos of the project).
    Route::get('/projects/{project}/characters', [CharacterController::class, 'index'])->name('characters.index');
    Route::post('/projects/{project}/characters', [CharacterController::class, 'store'])->name('characters.store');
    Route::post('/projects/{project}/characters/voice-preview', [CharacterController::class, 'previewVoice'])->name('characters.preview-voice');
    Route::post('/projects/{project}/characters/{character}/generate-reference', [CharacterController::class, 'generateReference'])->name('characters.generate-reference');
    Route::get('/projects/{project}/characters/{character}/edit', [CharacterController::class, 'edit'])->name('characters.edit');
    Route::patch('/projects/{project}/characters/{character}', [CharacterController::class, 'update'])->name('characters.update');
    Route::delete('/projects/{project}/characters/{character}', [CharacterController::class, 'destroy'])->name('characters.destroy');

    Route::get('/projects/{project}/videos/{video}/storyboard', [StoryboardController::class, 'show'])->name('storyboard.show');
    Route::post('/projects/{project}/videos/{video}/storyboard/complete', [StoryboardController::class, 'complete'])->name('storyboard.complete');
    Route::post('/projects/{project}/videos/{video}/storyboard/generate-plan', [StoryboardController::class, 'generatePlan'])->name('storyboard.generate-plan');
    Route::post('/projects/{project}/videos/{video}/storyboard/generate-images', [StoryboardController::class, 'generateImages'])->name('storyboard.generate-images');
    Route::post('/projects/{project}/videos/{video}/storyboard/generate-videos', [StoryboardController::class, 'generateVideos'])->name('storyboard.generate-videos');
    Route::post('/projects/{project}/videos/{video}/storyboard/generate-full', [StoryboardController::class, 'generateFullVideo'])->name('storyboard.generate-full');
    Route::post('/projects/{project}/videos/{video}/scenes/{scene}/generate-video', [StoryboardController::class, 'generateSceneVideo'])->name('scenes.generate-video');
    Route::post('/projects/{project}/videos/{video}/storyboard/assign-voices', [StoryboardController::class, 'assignVoices'])->name('storyboard.assign-voices');

    Route::get('/projects/{project}/videos/{video}/audio', [ProjectAudioController::class, 'show'])->name('audio.show');
    Route::post('/projects/{project}/videos/{video}/audio/upload', [ProjectAudioController::class, 'storeUpload'])->name('audio.upload');
    Route::post('/projects/{project}/videos/{video}/audio/generate', [ProjectAudioController::class, 'generateVoice'])->name('audio.generate');
    Route::patch('/projects/{project}/videos/{video}/audio/narration-voice', [ProjectAudioController::class, 'updateNarrationVoice'])->name('audio.narration-voice');
    Route::patch('/projects/{project}/videos/{video}/audio/presentation-mode', [ProjectAudioController::class, 'updatePresentationMode'])->name('audio.presentation-mode');
    // Music/SFX assets are project-level (video_id NULL), so the {asset}
    // binding must not be scoped under the video — authorization goes through
    // the AssetPolicy instead.
    Route::patch('/projects/{project}/videos/{video}/audio/assets/{asset}/licence', [ProjectAudioController::class, 'updateLicence'])->withoutScopedBindings()->name('audio-assets.update-licence');
    Route::delete('/projects/{project}/videos/{video}/audio/assets/{asset}', [ProjectAudioController::class, 'destroyAsset'])->withoutScopedBindings()->name('audio-assets.destroy');
    Route::post('/projects/{project}/videos/{video}/scenes/{scene}/generate-narration', [ProjectAudioController::class, 'generateNarration'])->name('scenes.generate-narration');
    Route::post('/projects/{project}/videos/{video}/scenes/{scene}/dialogue-lines/{dialogueLine}/generate-voice', [ProjectAudioController::class, 'generateLineVoice'])->name('dialogue-lines.generate-voice');

    Route::post('/projects/{project}/videos/{video}/scenes', [SceneController::class, 'store'])->name('scenes.store');
    Route::post('/projects/{project}/videos/{video}/scenes/reorder', [SceneController::class, 'reorder'])->name('scenes.reorder');
    Route::post('/projects/{project}/videos/{video}/scenes/{scene}/generate-image', [SceneController::class, 'generateImage'])->name('scenes.generate-image');
    Route::patch('/projects/{project}/videos/{video}/scenes/{scene}', [SceneController::class, 'update'])->name('scenes.update');
    Route::delete('/projects/{project}/videos/{video}/scenes/{scene}', [SceneController::class, 'destroy'])->name('scenes.destroy');

    Route::get('/projects/{project}/videos/{video}/render', [ProjectRenderController::class, 'show'])->name('render.show');
    Route::post('/projects/{project}/videos/{video}/render', [ProjectRenderController::class, 'store'])->name('render.store');
    Route::post('/projects/{project}/videos/{video}/render/metadata/generate', [ProjectRenderController::class, 'generateMetadata'])->name('render.metadata.generate');
    Route::patch('/projects/{project}/videos/{video}/render/metadata', [ProjectRenderController::class, 'saveMetadata'])->name('render.metadata.update');

    Route::get('/projects/{project}/assets/{asset}', [AssetController::class, 'show'])->name('assets.show');

    Route::post('/projects/{project}/videos/{video}/scenes/{scene}/dialogue-lines', [SceneDialogueLineController::class, 'store'])->name('dialogue-lines.store');
    Route::patch('/projects/{project}/videos/{video}/scenes/{scene}/dialogue-lines/{dialogueLine}', [SceneDialogueLineController::class, 'update'])->name('dialogue-lines.update');
    Route::delete('/projects/{project}/videos/{video}/scenes/{scene}/dialogue-lines/{dialogueLine}', [SceneDialogueLineController::class, 'destroy'])->name('dialogue-lines.destroy');
});
