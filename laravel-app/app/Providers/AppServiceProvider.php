<?php

namespace App\Providers;

use App\Domain\Students\Repositories\DemoStudentRepository;
use App\Domain\Students\Repositories\LegacyStudentRepository;
use App\Domain\Students\Repositories\StudentRepository;
use App\Support\ApplicationBasePath;
use App\Support\LegacyFptMemoReader;
use App\Support\ThaiAdministrativeAreaLookup;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            LegacyFptMemoReader::class,
            fn (): LegacyFptMemoReader => new LegacyFptMemoReader((string) config('system_data.fpt_memo_root')),
        );
        $this->app->scoped(StudentRepository::class, function (Application $app): StudentRepository {
            $systemStudentDataEnabled = (bool) config('system_data.student_enabled');

            if (! $systemStudentDataEnabled) {
                return $app->make(DemoStudentRepository::class);
            }

            return new LegacyStudentRepository(
                DB::connection(),
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
        // Production data is intentionally self-contained. Any future use of
        // Laravel's HTTP client must be explicitly reviewed instead of silently
        // turning a page request into an external data dependency.
        Http::preventStrayRequests();

        Vite::createAssetPathsUsing(function (string $path, ?bool $secure = null): string {
            try {
                $request = request();
                $basePath = ApplicationBasePath::resolve($request);
                $configured = parse_url((string) config('app.asset_url')) ?: [];
                $origin = isset($configured['scheme'], $configured['host'])
                    ? $configured['scheme'].'://'.$configured['host'].(isset($configured['port']) ? ':'.$configured['port'] : '')
                    : $request->getSchemeAndHttpHost();

                return rtrim($origin.$basePath, '/').'/'.ltrim($path, '/');
            } catch (\Throwable) {
                return app('url')->asset($path, $secure);
            }
        });

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
