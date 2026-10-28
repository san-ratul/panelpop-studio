<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;

class AssetPolicy
{
    public function view(User $user, Asset $asset): bool
    {
        return $asset->project->user_id === $user->id;
    }

    public function update(User $user, Asset $asset): bool
    {
        return $asset->project->user_id === $user->id;
    }

    public function delete(User $user, Asset $asset): bool
    {
        return $asset->project->user_id === $user->id;
    }
}
