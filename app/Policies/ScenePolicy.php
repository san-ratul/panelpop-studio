<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Scene;
use App\Models\User;

class ScenePolicy
{
    public function view(User $user, Scene $scene): bool
    {
        return $scene->project->user_id === $user->id;
    }

    public function create(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }

    public function update(User $user, Scene $scene): bool
    {
        return $scene->project->user_id === $user->id;
    }

    public function delete(User $user, Scene $scene): bool
    {
        return $scene->project->user_id === $user->id;
    }
}
