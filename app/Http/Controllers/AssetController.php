<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorized private-asset streaming. Generated media never leaves the
 * private disk except through this policy-checked endpoint (SECURITY.md).
 * `?download=1` switches Content-Disposition to attachment for explicit
 * downloads (e.g. SRT/MP4 export); the default stays inline for previews.
 */
class AssetController extends Controller
{
    public function show(Request $request, Project $project, Asset $asset): Response
    {
        $this->authorize('view', $asset);

        $disk = Storage::disk($asset->disk);

        abort_unless($disk->exists($asset->path), 404);

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return $disk->response($asset->path, basename($asset->path), [
            'Content-Type' => $asset->mime ?? 'application/octet-stream',
            'Content-Disposition' => $disposition.'; filename="'.basename($asset->path).'"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
