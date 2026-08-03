<?php

namespace Tests\Unit;

use App\Support\ThaiAdministrativeAreaLookup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ThaiAdministrativeAreaLookupTest extends TestCase
{
    public function test_it_resolves_official_subdistrict_codes_and_caches_the_reference_data(): void
    {
        Cache::forget('thai-administrative-areas-v1');
        Http::fake([
            'www.data.go.th/*' => Http::response([
                'success' => true,
                'result' => [
                    'records' => [[
                        'TA_ID' => 140405,
                        'TAMBON_T' => 'ต. บ้านแพน',
                        'AMPHOE_T' => 'อ. เสนา',
                        'CHANGWAT_T' => 'จ. พระนครศรีอยุธยา',
                    ]],
                ],
            ]),
        ]);

        $lookup = new ThaiAdministrativeAreaLookup;

        $this->assertSame([
            'subdistrict' => 'บ้านแพน',
            'district' => 'เสนา',
            'province' => 'พระนครศรีอยุธยา',
        ], $lookup->resolve('140405'));
        $this->assertNull($lookup->resolve('invalid'));
        Http::assertSentCount(1);

        Cache::forget('thai-administrative-areas-v1');
    }
}
