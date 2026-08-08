<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Roles that grant access to the Filament panel. Staff roles only —
     * a plain customer has no role at all.
     *
     * @var array<int, string>
     */
    public const STAFF_ROLES = [
        'administrator',
        'content_editor',
        'warehouse_employee',
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
        ];
    }

    /**
     * Filament is not protected past login — this is what actually gates
     * the panel. Role-based rather than a Policy: panel access is not
     * per-model authorization, it is "is this user staff at all".
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAnyRole(self::STAFF_ROLES);
    }
}
