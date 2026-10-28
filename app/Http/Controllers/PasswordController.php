<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Settings/Password');
    }

    public function update(UpdatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->update([
            'password' => Hash::make($request->validated('password')),
        ]);

        // Keep the current session authenticated after the hash rotation:
        // the session guard compares this value against the stored hash.
        $request->session()->put('password_hash_web', $user->refresh()->getAuthPassword());

        return redirect()
            ->route('password.edit')
            ->with('status', 'password-updated');
    }
}
