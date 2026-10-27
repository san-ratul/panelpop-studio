<?php

namespace App\Policies;

use App\Models\DialogueLine;
use App\Models\Scene;
use App\Models\User;

class DialogueLinePolicy
{
    public function view(User $user, DialogueLine $line): bool
    {
        return $line->scene->project->user_id === $user->id;
    }

    public function create(User $user, Scene $scene): bool
    {
        return $scene->project->user_id === $user->id;
    }

    public function update(User $user, DialogueLine $line): bool
    {
        return $line->scene->project->user_id === $user->id;
    }

    public function delete(User $user, DialogueLine $line): bool
    {
        return $line->scene->project->user_id === $user->id;
    }
}
