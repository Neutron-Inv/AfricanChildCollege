<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditService
{
    /**
     * Record an audit log entry for the current tenant / actor.
     */
    public function log(string $action, string $entity, ?string $entityId = null, ?array $meta = null): AuditLog
    {
        $request = app()->bound('request') ? app('request') : null;

        $requestId = $request?->headers->get('X-Request-Id');
        if (! $requestId && $request) {
            $requestId = $request->attributes->get('request_id');
            if (! $requestId) {
                $requestId = (string) Str::ulid();
                $request->attributes->set('request_id', $requestId);
            }
        }

        $user = Auth::user();

        return AuditLog::withoutGlobalScopes()->create([
            'school_id' => Tenant::id() ?? $user?->school_id,
            'actor_id' => $user?->getKey(),
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'request_id' => $requestId,
            'meta' => $meta,
        ]);
    }

    /**
     * Static convenience wrapper.
     */
    public static function record(string $action, string $entity, ?string $entityId = null, ?array $meta = null): AuditLog
    {
        return app(self::class)->log($action, $entity, $entityId, $meta);
    }
}
