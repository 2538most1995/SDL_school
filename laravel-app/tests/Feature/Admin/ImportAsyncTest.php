<?php

namespace Tests\Feature\Admin;

use App\Jobs\ProcessLegacyZipImport;
use App\Models\District;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

final class ImportAsyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_returns_immediately_and_dispatches_import_to_queue(): void
    {
        Storage::fake('local');
        Bus::fake();
        Cache::clear();
        config()->set('legacy.enabled', true);
        config()->set('legacy.write_enabled', true);
        $district = District::create(['name' => 'อำเภอทดสอบ', 'code' => 'async-import']);
        $user = User::factory()->create(['role' => 'admin', 'district_id' => $district->id]);
        Sanctum::actingAs($user);

        $source = tempnam(sys_get_temp_dir(), 'sena-async-import-');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($source, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('1/student.dbf', 'student');
        $zip->close();

        try {
            $response = $this->post('/api/v1/admin/imports', [
                'archive' => new UploadedFile($source, 'student-data.zip', 'application/zip', null, true),
                'academic_term' => '1/2569',
            ], ['Accept' => 'application/json'])
                ->assertAccepted()
                ->assertJsonPath('data.status', 'queued');

            $jobId = (string) $response->json('data.job_id');
            Storage::disk('local')->assertExists("import-queue/{$district->id}/{$jobId}.zip");
            Bus::assertDispatched(ProcessLegacyZipImport::class, fn (ProcessLegacyZipImport $job): bool => $job->jobId === $jobId
                && $job->districtId === $district->id
                && $job->userId === $user->id
                && $job instanceof ShouldQueue);

            $this->getJson("/api/v1/admin/imports/jobs/{$jobId}")
                ->assertOk()
                ->assertJsonPath('data.status', 'queued')
                ->assertJsonPath('data.district_id', $district->id);
        } finally {
            @unlink($source);
        }
    }
}
