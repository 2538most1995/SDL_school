<?php

namespace Tests\Unit;

use App\Support\ThaiAdministrativeAreaLookup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ThaiAdministrativeAreaLookupTest extends TestCase
{
    public function test_it_resolves_administrative_areas_from_the_bundled_system_snapshot_without_http(): void
    {
        Cache::forget('thai-administrative-areas-local-v1');
        Http::preventStrayRequests();

        $lookup = new ThaiAdministrativeAreaLookup;

        $this->assertSame([
            'subdistrict' => 'หน้าไม้',
            'district' => 'บางไทร',
            'province' => 'พระนครศรีอยุธยา',
        ], $lookup->resolve('140405'));
        $this->assertNull($lookup->resolve('invalid'));
        Http::assertNothingSent();

        Cache::forget('thai-administrative-areas-local-v1');
    }
}
