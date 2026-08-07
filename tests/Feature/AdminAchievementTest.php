<?php

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAchievementTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchant_cannot_read_platform_achievement_admin_api(): void
    {
        $merchant = User::factory()->create(['role' => 'merchant']);

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/admin/achievements')
            ->assertForbidden();
    }

    public function test_super_admin_can_create_an_achievement_with_money_target_in_cents(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/achievements', [
                'type' => 'badge',
                'metric' => 'revenue_total',
                'target' => 10000.50,
                'title' => 'Marco de teste',
                'active' => true,
                'sort_order' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('target_value', 1000050)
            ->assertJsonPath('target', 10000.5);

        $this->assertDatabaseHas('achievements', [
            'title' => 'Marco de teste',
            'target_value' => 1000050,
        ]);
    }
}
