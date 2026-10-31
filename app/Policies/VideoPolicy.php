<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Models\Video;

class VideoPolicy
{
    public function view(User $user, Video $video): bool
    {
        return $video->project->user_id === $user->id;
    }

    public function create(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }

    public function update(User $user, Video $video): bool
    {
        return $video->project->user_id === $user->id;
    }

    public function delete(User $user, Video $video): bool
    {
        return $video->project->user_id === $user->id;
    }
}
