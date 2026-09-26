<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class AuditService
{
    /**
     * Log an audit trail entry.
     *
     * @param  array<string, mixed>|null  $context
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function log(
        ?User $user,
        ?int $districtId,
        string $event,
        ?string $auditableType = null,
        mixed $auditableId = null,
        ?string $ipAddress = null,
        ?array $context = null,
        ?array $before = null,
        ?array $after = null,
        ?string $requestId = null,
    ): void {
        try {
            if (! Schema::hasTable('audit_logs')) {
                return;
            }

            DB::table('audit_logs')->insert([
                'user_id' => $user?->id,
                'district_id' => $districtId ?? $user?->district_id,
                'event' => substr($event, 0, 120),
                'auditable_type' => $auditableType ? substr($auditableType, 0, 191) : null,
                'auditable_id' => is_numeric($auditableId) ? (int) $auditableId : null,
                'ip_address' => $ipAddress ? substr($ipAddress, 0, 45) : null,
                'request_id' => $requestId ? substr($requestId, 0, 64) : null,
                'context' => $context !== null ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
                'before' => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
                'after' => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Log an audit trail entry directly from an incoming HTTP Request.
     *
     * @param  array<string, mixed>|null  $context
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function logFromRequest(
        Request $request,
        string $event,
        ?string $auditableType = null,
        mixed $auditableId = null,
        ?array $context = null,
        ?array $before = null,
        ?array $after = null,
    ): void {
        $user = $request->user();
        $districtId = $request->attributes->get('district_id')
            ?: ($user?->district_id !== null ? (int) $user->district_id : null);

        self::log(
            user: $user,
            districtId: $districtId,
            event: $event,
            auditableType: $auditableType,
            auditableId: $auditableId,
            ipAddress: $request->ip(),
            context: $context,
            before: $before,
            after: $after,
            requestId: (string) ($request->header('X-Request-Id') ?: null),
        );
    }
}
