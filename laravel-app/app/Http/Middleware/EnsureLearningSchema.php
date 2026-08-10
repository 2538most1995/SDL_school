<?php

namespace App\Http\Middleware;

use App\Services\Learning\LearningSchemaReadiness;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EnsureLearningSchema
{
    public function __construct(private readonly LearningSchemaReadiness $schema) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('system_data.enabled')) {
            return $next($request);
        }

        try {
            $this->schema->ensure();
        } catch (Throwable $exception) {
            report($exception);

            return new JsonResponse([
                'message' => 'ฐานข้อมูลโมดูลการเรียนรู้ยังไม่พร้อม กรุณาให้ผู้ดูแลรัน php artisan migrate --force แล้วลองอีกครั้ง',
            ], 503);
        }

        return $next($request);
    }
}
