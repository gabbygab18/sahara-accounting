<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → Users (owner only): staff accounts. Nobody is deleted, so their name stays on what they added. */
class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/users', [
            'users' => User::withCount('records')->orderBy('name')->get(['id', 'name', 'email', 'role', 'permissions', 'disabled_at', 'created_at'])
                ->map(fn (User $u) => [...$u->toArray(), 'permissions' => $u->permissionList()]),
            'permissions' => User::PERMISSIONS,
            'presets' => User::PRESETS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            ...$this->checklist(),
            'password' => ['required', 'string', 'min:8'],
        ]);
        User::forceCreate($data + ['email_verified_at' => now()]);

        return back()->with('success', "Account made for {$data['name']}. Give them the email and password.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            ...$this->checklist(),
            'active' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $self = $user->is($request->user());
        if ($self && (! in_array('settings', $data['permissions'], true) || ! $data['active'])) {
            return back()->withErrors(['user' => 'You cannot remove your own Settings access or deactivate yourself.']);
        }
        $user->fill([
            'name' => $data['name'],
            'role' => $data['role'],
            'permissions' => $data['permissions'],
            'disabled_at' => $data['active'] ? null : ($user->disabled_at ?? now()),
        ]);
        if ($data['password'] ?? null) {
            $user->password = $data['password'];
        }
        $user->save();

        return back()->with('success', "Saved {$user->name}.".(($data['password'] ?? null) ? ' New password set.' : ''));
    }

    /** The preset label and the ticked boxes from the checklist. */
    private function checklist(): array
    {
        return [
            'role' => ['required', Rule::in(User::ROLES)],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['distinct', Rule::in(array_keys(User::PERMISSIONS))],
        ];
    }
}
