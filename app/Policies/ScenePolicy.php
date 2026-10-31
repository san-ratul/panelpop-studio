<?php

namespace App\Policies;

use App\Models\Scene;
use App\Models\User;
use App\Models\Video;

class ScenePolicy
{
    public function view(User $user, Scene $scene): bool
    {
        return $scene->video->project->user_id === $user->id;
    }

    public function create(User $user, Video $video): bool
    {
        return $video->project->user_id === $user->id;
    }

    public function update(User $user, Scene $scene): bool
    {
        return $scene->video->project->user_id === $user->id;
    }

    public function delete(User $user, Scene $scene): bool
    {
        return $scene->video->project->user_id === $user->id;
    }
}
