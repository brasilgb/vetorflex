<?php

namespace App\Http\Controllers\RootAdmin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Logs e auditoria de toda a plataforma, somente leitura, com filtros por evento, empresa e período.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:100'],
            'company' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return Inertia::render('root-admin/audit', [
            'logs' => AuditLog::query()->with(['user:id,name', 'company:id,name'])
                ->when($filters['event'] ?? null, fn (Builder $query, string $event) => $query->where('event', 'like', "{$event}%"))
                ->when($filters['company'] ?? null, fn (Builder $query, int $company) => $query->where('company_id', $company))
                ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('occurred_at', '>=', "{$from} 00:00:00"))
                ->when($filters['until'] ?? null, fn (Builder $query, string $until) => $query->where('occurred_at', '<=', "{$until} 23:59:59"))
                ->latest('occurred_at')->latest('id')->paginate(50)->withQueryString()
                ->through(fn (AuditLog $log): array => self::present($log)),
            'filters' => [
                'event' => $filters['event'] ?? null,
                'company' => isset($filters['company']) ? (int) $filters['company'] : null,
                'from' => $filters['from'] ?? null,
                'until' => $filters['until'] ?? null,
            ],
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** @return array{id: int, event: string, company: string|null, user: string|null, subject: string|null, context: array<string, mixed>, occurred_at: string} */
    public static function present(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'event' => $log->event,
            'company' => $log->company?->name,
            'user' => $log->user?->name,
            'subject' => $log->auditable_type === null ? null : class_basename($log->auditable_type)."#{$log->auditable_id}",
            'context' => $log->context ?? [],
            'occurred_at' => $log->occurred_at->toIso8601String(),
        ];
    }
}
