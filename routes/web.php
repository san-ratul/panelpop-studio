<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProviderSettingsController;
use App\Http\Controllers\SceneController;
use App\Http\Controllers\SceneDialogueLineController;
use App\Http\Controllers\StoryboardController;
use App\Http\Controllers\StoryController;
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

Route::middleware('auth')->group(function () {
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

    Route::get('/projects/{project}/story/edit', [StoryController::class, 'edit'])->name('story.edit');
    Route::put('/projects/{project}/story', [StoryController::class, 'save'])->name('story.save');
    Route::post('/projects/{project}/story/generate', [StoryController::class, 'generate'])->name('story.generate');

    Route::get('/projects/{project}/characters', [CharacterController::class, 'index'])->name('characters.index');
    Route::post('/projects/{project}/characters', [CharacterController::class, 'store'])->name('characters.store');
    Route::get('/projects/{project}/characters/{character}/edit', [CharacterController::class, 'edit'])->name('characters.edit');
    Route::patch('/projects/{project}/characters/{character}', [CharacterController::class, 'update'])->name('characters.update');
    Route::delete('/projects/{project}/characters/{character}', [CharacterController::class, 'destroy'])->name('characters.destroy');

    Route::get('/projects/{project}/storyboard', [StoryboardController::class, 'show'])->name('storyboard.show');
    Route::post('/projects/{project}/storyboard/complete', [StoryboardController::class, 'complete'])->name('storyboard.complete');
    Route::post('/projects/{project}/storyboard/generate-plan', [StoryboardController::class, 'generatePlan'])->name('storyboard.generate-plan');

    Route::post('/projects/{project}/scenes', [SceneController::class, 'store'])->name('scenes.store');
    Route::post('/projects/{project}/scenes/reorder', [SceneController::class, 'reorder'])->name('scenes.reorder');
    Route::patch('/projects/{project}/scenes/{scene}', [SceneController::class, 'update'])->name('scenes.update');
    Route::delete('/projects/{project}/scenes/{scene}', [SceneController::class, 'destroy'])->name('scenes.destroy');

    Route::post('/projects/{project}/scenes/{scene}/dialogue-lines', [SceneDialogueLineController::class, 'store'])->name('dialogue-lines.store');
    Route::patch('/projects/{project}/scenes/{scene}/dialogue-lines/{dialogueLine}', [SceneDialogueLineController::class, 'update'])->name('dialogue-lines.update');
    Route::delete('/projects/{project}/scenes/{scene}/dialogue-lines/{dialogueLine}', [SceneDialogueLineController::class, 'destroy'])->name('dialogue-lines.destroy');
});
