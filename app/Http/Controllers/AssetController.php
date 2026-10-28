<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Project;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorized private-asset streaming. Generated media never leaves the
 * private disk except through this policy-checked endpoint (SECURITY.md).
 */
class AssetController extends Controller
{
    public function show(Project $project, Asset $asset): Response
    {
        $this->authorize('view', $asset);

        $disk = Storage::disk($asset->disk);

        abort_unless($disk->exists($asset->path), 404);

        return $disk->response($asset->path, basename($asset->path), [
            'Content-Type' => $asset->mime ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
