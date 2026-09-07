<?php

namespace App\Console\Commands;

use App\Models\StudentApiClient;
use Illuminate\Console\Command;

final class RevokeStudentDataApiToken extends Command
{
    protected $signature = 'system:revoke-student-api-token
        {client : ID ของ Student Data API client}';

    protected $description = 'Revoke one Student Data API client token';

    public function handle(): int
    {
        $clientId = filter_var($this->argument('client'), FILTER_VALIDATE_INT);
        $client = $clientId === false ? null : StudentApiClient::query()->find($clientId);

        if ($client === null) {
            $this->error('ไม่พบ Student Data API client');

            return self::FAILURE;
        }
        if ($client->revoked_at !== null) {
            $this->info('Token นี้ถูกเพิกถอนแล้ว');

            return self::SUCCESS;
        }

        $client->forceFill(['revoked_at' => now()])->save();
        $this->info("เพิกถอน Student Data API client #{$client->id} เรียบร้อยแล้ว");

        return self::SUCCESS;
    }
}
