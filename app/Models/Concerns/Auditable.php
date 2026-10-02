<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\Auth;

/**
 * Records create / update / delete events for a model into audit_logs,
 * capturing the acting user, company and changed attributes.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => $model->writeAudit('created', null, $model->auditableAttributes()));

        static::updated(function ($model): void {
            $changed = array_keys($model->getChanges());
            if ($changed === ['updated_at'] || $changed === []) {
                return;
            }
            $old = array_intersect_key($model->getOriginal(), $model->getChanges());
            $model->writeAudit('updated', $old, $model->getChanges());
        });

        static::deleted(fn ($model) => $model->writeAudit('deleted', $model->auditableAttributes(), null));
    }

    protected function auditableAttributes(): array
    {
        return collect($this->getAttributes())
            ->except($this->auditExcluded())
            ->all();
    }

    protected function auditExcluded(): array
    {
        return ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];
    }

    protected function writeAudit(string $event, ?array $old, ?array $new): void
    {
        $context = $this->auditRequestContext();

        AuditLog::create([
            'company_id' => $this->company_id ?? app(TenantManager::class)->id(),
            'user_id' => Auth::id(),
            'event' => $event,
            'auditable_type' => $this->getMorphClass(),
            'auditable_id' => $this->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $context['ip'],
            'user_agent' => $context['ua'],
            'url' => $context['url'],
        ]);
    }

    /**
     * @return array{ip: ?string, ua: ?string, url: ?string}
     */
    protected function auditRequestContext(): array
    {
        try {
            $request = request();
            if ($request && $request->hasSession()) {
                return [
                    'ip' => $request->ip(),
                    'ua' => $request->userAgent(),
                    'url' => $request->fullUrl(),
                ];
            }
        } catch (\Throwable) {
            // console / queue context — no request available
        }

        return ['ip' => null, 'ua' => null, 'url' => null];
    }
}
