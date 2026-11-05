<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** The checklist on Settings → Users. Each key is also a gate: `can:delete`, `$user->can('export')`. */
    public const PERMISSIONS = [
        'dashboard' => 'Summary (paid and unpaid totals)',
        'records' => 'See records',
        'add' => 'Add records',
        'edit' => 'Edit records',
        'delete' => 'Delete records',
        'confirm' => 'Confirm payments (double check)',
        'export' => 'Export Excel / CSV',
        'history' => 'Activity log',
        'settings' => 'Users and Google Sheets settings',
    ];

    public const PRESETS = [
        'admin' => ['dashboard', 'records', 'add', 'edit', 'delete', 'confirm', 'export', 'history', 'settings'],
        'staff' => ['dashboard', 'records', 'add', 'edit', 'export'],
    ];

    /** A preset name, or `custom` when the checklist was changed by hand. */
    public const ROLES = ['admin', 'staff', 'custom'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'permissions',
        'disabled_at',
    ];

    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }

    /** The ticked keys; admin and staff follow their preset, so new permissions reach them too. */
    public function permissionList(): array
    {
        return self::PRESETS[$this->role] ?? $this->permissions ?? [];
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->permissionList(), true);
    }

    /** The first page this account may open, for when the dashboard is not on its list. */
    public function home(): string
    {
        foreach (['dashboard' => 'dashboard', 'records' => 'records.index'] as $key => $route) {
            if ($this->hasPermission($key)) {
                return route($route, absolute: false);
            }
        }

        return route('profile.edit', absolute: false);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
        ];
    }
}
