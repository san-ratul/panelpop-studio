<?php

namespace App\Services\Render;

use Illuminate\Support\Facades\Storage;

/**
 * Resolves absolute local filesystem paths for private-disk artifacts so
 * FFmpeg can read them. Non-local disks (e.g. S3) and any path that escapes
 * the disk root resolve to null — rendering then fails pre-flight with an
 * actionable message instead of touching arbitrary filesystem locations
 * (SECURITY.md: no path traversal, no arbitrary filesystem access).
 */
final class LocalStoragePaths
{
    public static function absolute(string $disk, string $path): ?string
    {
        if ($disk === '' || $path === '') {
            return null;
        }

        $storage = Storage::disk($disk);

        if (! method_exists($storage, 'path')) {
            return null; // not a local filesystem adapter
        }

        try {
            $absolute = $storage->path($path);
            $root = realpath($storage->path(''));
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($absolute) || ! is_file($absolute)) {
            return null;
        }

        $real = realpath($absolute);

        if ($root === false || $real === false) {
            return null;
        }

        if ($real !== $root && ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }
}
