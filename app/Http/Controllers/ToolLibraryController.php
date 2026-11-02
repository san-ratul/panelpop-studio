<?php

namespace App\Http\Controllers;

use App\AI\Support\ToolRegistry;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tool library (AITUBER mission §6B): the searchable catalog of generation
 * tools. Every tool is a preset over the shared pipeline; "Use tool" deep-
 * links into project creation with the preset applied.
 */
final class ToolLibraryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tools/Index', [
            'tools' => ToolRegistry::options(),
        ]);
    }
}
