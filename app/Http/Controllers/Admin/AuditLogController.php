<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request, TenantManager $tenant): Response
    {
        $event = $request->string('event')->toString();
        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();

        $logs = AuditLog::query()
            ->where('company_id', $tenant->id())
            ->with('user:id,name')
            ->when($event, fn ($q) => $q->where('event', $event))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('id')
            ->paginate(30)
            ->through(fn (AuditLog $l) => [
                'id' => $l->id,
                'at' => $l->created_at?->toDayDateTimeString(),
                'user' => $l->user?->name ?? 'System',
                'event' => $l->event,
                'model' => class_basename($l->auditable_type),
                'record' => $l->auditable_id,
                'changed' => $this->changedKeys($l),
                'url' => $l->url,
            ]);

        return Inertia::render('admin/audit/index', [
            'logs' => $logs,
            'filters' => ['event' => $event ?: null, 'from' => $from, 'to' => $to],
        ]);
    }

    private function changedKeys(AuditLog $log): string
    {
        $keys = array_keys($log->new_values ?? $log->old_values ?? []);
        $keys = array_values(array_diff($keys, ['created_at', 'updated_at', 'company_id']));

        return Str::limit(implode(', ', $keys), 80);
    }
}
