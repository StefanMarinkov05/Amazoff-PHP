<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Hand-written, not Blueprint-generated. `blueprint:build` must be run with
 * `--only=models,factories` and this file restored afterwards, or it will be
 * overwritten with a plain Eloquent model missing the auth, Filament, and
 * role wiring below.
 */
class User extends Authenticatable implements FilamentUser, HasName
{
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * Roles that grant access to the Filament panel. Staff only — a plain
     * customer holds no role at all.
     *
     * @var list<string>
     */
    public const STAFF_ROLES = [
        'administrator',
        'content_editor',
        'warehouse_employee',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'email_verified_at',
        'password',
        'phone',
        'avatar_path',
        'is_active',
    ];

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
            'id' => 'integer',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Filament is not protected past login — this is what actually gates the
     * panel. Role-based rather than a Policy: panel access is not per-model
     * authorization, it is "is this user staff at all".
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // An allow-list, not a deny-list: a role added later reaches the panel
        // only once it is named in STAFF_ROLES, rather than by default.
        //
        // is_active is checked here rather than left to the login form because
        // a session outlives the row it authenticated against — deactivating
        // an employee has to end their panel access on the next request, not
        // at their next login.
        return $this->is_active && $this->hasAnyRole(self::STAFF_ROLES);
    }

    public function getFilamentName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<ProductReview, $this> */
    public function productReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    /** @return HasMany<Address, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /** @return HasMany<WishlistItem, $this> */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }
}
