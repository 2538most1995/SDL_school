<?php

namespace Tests\Feature;

use Tests\TestCase;

class PortalDemoTest extends TestCase
{
    public function test_portal_demo_returns_a_safe_versioned_payload(): void
    {
        $this->getJson('/api/v1/portal-demo')
            ->assertOk()
            ->assertJsonPath('data.mode', 'demo')
            ->assertJsonPath('data.viewer.role', 'student')
            ->assertJsonCount(4, 'data.summary')
            ->assertJsonPath('meta.contains_personal_data', false)
            ->assertJsonMissingPath('data.viewer.citizen_id');
    }

    public function test_authenticated_identity_endpoint_rejects_guests(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
