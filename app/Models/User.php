<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Tenancy\TenantManager;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property int|null $default_company_id
 * @property string $name
 * @property string $email
 * @property bool $is_super_admin
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'default_company_id', 'is_super_admin'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

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
            'is_super_admin' => 'boolean',
            /* @chisel-2fa */
            'two_factor_confirmed_at' => 'datetime',
            /* @end-chisel-2fa */
        ];
    }

    /**
     * Companies this user belongs to, with their per-company role.
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user')
            ->withPivot(['role_id', 'is_default'])
            ->withTimestamps();
    }

    public function belongsToCompany(int $companyId): bool
    {
        return $this->companies()->whereKey($companyId)->exists();
    }

    /**
     * The user's role within the currently active company.
     */
    public function currentRole(): ?Role
    {
        $companyId = app(TenantManager::class)->id();

        if (! $companyId) {
            return null;
        }

        $roleId = $this->companies()
            ->where('companies.id', $companyId)
            ->first()?->getRelationValue('pivot')?->role_id;

        return $roleId ? Role::query()->withoutGlobalScopes()->find($roleId) : null;
    }

    /**
     * All ability slugs granted to the user in the active company.
     *
     * @return array<int, string>
     */
    public function permissionNames(): array
    {
        return $this->currentRole()?->permissions()->pluck('name')->all() ?? [];
    }

    public function hasPermission(string $ability): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        return in_array($ability, $this->permissionNames(), true);
    }
}
