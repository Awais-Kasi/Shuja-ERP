<?php

namespace App\Support\Tenancy;

use App\Models\Company;
use Closure;

/**
 * Holds the current company for the request/console lifecycle.
 *
 * Every tenant-scoped model reads this to filter and stamp rows, so that
 * data isolation between companies is enforced in one place.
 */
class TenantManager
{
    protected ?Company $company = null;

    public function set(?Company $company): void
    {
        $this->company = $company;
    }

    public function get(): ?Company
    {
        return $this->company;
    }

    public function id(): ?int
    {
        return $this->company?->id;
    }

    public function has(): bool
    {
        return $this->company !== null;
    }

    public function forget(): void
    {
        $this->company = null;
    }

    /**
     * Run a callback bound to a specific company, restoring the previous
     * context afterwards. Useful for jobs, seeders and cross-company reports.
     *
     * @template T
     * @param  Closure():T  $callback
     * @return T
     */
    public function withCompany(Company $company, Closure $callback): mixed
    {
        $previous = $this->company;
        $this->company = $company;

        try {
            return $callback();
        } finally {
            $this->company = $previous;
        }
    }
}
