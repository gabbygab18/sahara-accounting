<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Each checklist key on Settings → Users is a gate (`can:quotations`, `$user->can('costs')`).
        Gate::before(fn (User $user, string $ability) => array_key_exists($ability, User::PERMISSIONS) ? $user->hasPermission($ability) : null);
    }
}
