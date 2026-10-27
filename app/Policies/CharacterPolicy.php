<?php

namespace App\Policies;

use App\Models\Character;
use App\Models\Project;
use App\Models\User;

class CharacterPolicy
{
    public function view(User $user, Character $character): bool
    {
        return $character->project->user_id === $user->id;
    }

    public function create(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }

    public function update(User $user, Character $character): bool
    {
        return $character->project->user_id === $user->id;
    }

    public function delete(User $user, Character $character): bool
    {
        return $character->project->user_id === $user->id;
    }
}
