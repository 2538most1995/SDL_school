<?php

namespace App\Providers;

use App\Contracts\LegacyIdentityProvider;
use App\Domain\Students\Repositories\DemoStudentRepository;
use App\Domain\Students\Repositories\LegacyStudentRepository;
use App\Domain\Students\Repositories\StudentRepository;
use App\Services\Legacy\LegacyIdentityService;
use App\Support\LegacyFptMemoReader;
use App\Support\ThaiAdministrativeAreaLookup;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LegacyIdentityProvider::class, LegacyIdentityService::class);
        $this->app->singleton(
            LegacyFptMemoReader::class,
            fn (): LegacyFptMemoReader => new LegacyFptMemoReader((string) config('legacy.fpt_memo_root')),
        );
        $this->app->scoped(StudentRepository::class, function (Application $app): StudentRepository {
            $legacyEnabled = (bool) config('legacy.student_enabled');

            if (! $legacyEnabled) {
                return $app->make(DemoStudentRepository::class);
            }

            if (config('database.connections.legacy') === null) {
                throw new LogicException('LEGACY_STUDENT_ENABLED requires a configured legacy database connection.');
            }

            return new LegacyStudentRepository(
                DB::connection('legacy'),
                $app->make(ThaiAdministrativeAreaLookup::class),
                $app->make(LegacyFptMemoReader::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        try {
            $request = request();
            $host = $request->getHttpHost();
            $serverHost = (string) ($_SERVER['HTTP_HOST'] ?? '');

            if (str_contains($host, 'localhost') || str_contains($host, '127.0.0.1')
                || str_contains($serverHost, 'localhost') || str_contains($serverHost, '8888')
                || str_contains($serverHost, '127.0.0.1')) {
                $basePath = (string) ($request->server('SENA_APP_BASE_PATH') ?? $_SERVER['SENA_APP_BASE_PATH'] ?? '');
                $scheme = $request->getScheme() ?: 'http';
                $actualHost = $serverHost ?: $host;
                $dynamicAppUrl = rtrim($scheme.'://'.$actualHost.$basePath, '/');

                config(['app.url' => $dynamicAppUrl, 'app.asset_url' => $dynamicAppUrl]);
                $url = app('url');
                $url->forceRootUrl($dynamicAppUrl);
                $url->useAssetOrigin($dynamicAppUrl);
            }
        } catch (\Throwable) {
            // Ignore during early bootstrap
        }
    }
}
