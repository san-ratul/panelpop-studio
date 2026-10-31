<?php

namespace App\Policies;

use App\Models\Story;
use App\Models\User;
use App\Models\Video;

class StoryPolicy
{
    public function view(User $user, Story $story): bool
    {
        return $story->video->project->user_id === $user->id;
    }

    public function create(User $user, Video $video): bool
    {
        return $video->project->user_id === $user->id;
    }

    public function update(User $user, Story $story): bool
    {
        return $story->video->project->user_id === $user->id;
    }

    public function delete(User $user, Story $story): bool
    {
        return $story->video->project->user_id === $user->id;
    }
}
