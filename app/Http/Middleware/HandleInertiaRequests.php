<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? $request->user()->only('id', 'name', 'email') : null,
            ],
            'appName' => config('app.name'),
            'routes' => $this->routeManifest(),
            'status' => fn (): mixed => $request->session()->get('status'),
            // Controllers flash operation feedback under these keys (dozens
            // of redirect ->with('success'|'warning') calls); AppLayout
            // renders them as banners.
            'flashSuccess' => fn (): mixed => $request->session()->get('success'),
            'flashWarning' => fn (): mixed => $request->session()->get('warning'),
        ];
    }

    /**
     * Name → URI manifest consumed by the frontend route() helper
     * (resources/js/route.js). Built from the router itself so frontend URLs
     * stay in sync with the backend route definitions. URIs only — no
     * methods, actions, or secrets.
     *
     * @return array<string, string>
     */
    private function routeManifest(): array
    {
        $manifest = [];

        foreach (Route::getRoutes()->getRoutesByName() as $name => $route) {
            $manifest[$name] = $route->uri();
        }

        return $manifest;
    }
}
