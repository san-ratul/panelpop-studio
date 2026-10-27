<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Story;
use App\Models\User;

class StoryPolicy
{
    public function view(User $user, Story $story): bool
    {
        return $story->project->user_id === $user->id;
    }

    public function create(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }

    public function update(User $user, Story $story): bool
    {
        return $story->project->user_id === $user->id;
    }

    public function delete(User $user, Story $story): bool
    {
        return $story->project->user_id === $user->id;
    }
}
