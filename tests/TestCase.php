<?php

namespace Tests;

use App\Models\Project;
use App\Models\Video;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * Creates one video inside a project (the video-scoped pipeline unit).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeVideo(Project $project, array $attributes = []): Video
    {
        return Video::factory()->for($project)->create($attributes);
    }
}
