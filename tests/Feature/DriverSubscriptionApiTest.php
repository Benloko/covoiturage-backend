<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverSubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_driver_can_access_driver_subscription_endpoints(): void
    {
        $passenger = $this->createUser('passenger', 'subscription-passenger@example.com');

        Sanctum::actingAs($passenger);

        $this->getJson('/api/driver/subscription')
            ->assertForbidden()
            ->assertJsonPath('message', 'Acces reserve aux conducteurs.');

        $this->postJson('/api/driver/subscription/renew', [
            'plan' => 'monthly',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Acces reserve aux conducteurs.');
    }

    public function test_driver_subscription_defaults_to_none_when_not_configured(): void
    {
        $driver = $this->createUser('driver', 'subscription-driver-none@example.com');
        $driver->forceFill(['driver_wallet_balance_fcfa' => 2000])->save();

        Sanctum::actingAs($driver);

        $this->getJson('/api/driver/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.status', 'none')
            ->assertJsonPath('subscription.plan', null)
            ->assertJsonPath('subscription.remaining_days', 0)
            ->assertJsonPath('wallet.available_balance_fcfa', 2000)
            ->assertJsonPath('wallet.can_pay_weekly', true)
            ->assertJsonPath('wallet.can_pay_monthly', true)
            ->assertJsonCount(2, 'plans');
    }

    public function test_driver_can_renew_subscription_with_wallet_balance_and_extend_from_current_expiry(): void
    {
        $now = Carbon::create(2026, 6, 2, 10, 0, 0, 'UTC');
        Carbon::setTestNow($now);

        try {
            $driver = $this->createUser('driver', 'subscription-driver-renew@example.com');
            $currentExpiresAt = $now->copy()->addDays(3);

            $driver->forceFill([
                'driver_wallet_balance_fcfa' => 3000,
                'settings' => [
                    'driver_subscription' => [
                        'plan' => 'weekly',
                        'started_at' => $now->copy()->subDays(4)->toIso8601String(),
                        'expires_at' => $currentExpiresAt->toIso8601String(),
                    ],
                ],
            ])->save();

            Sanctum::actingAs($driver);

            $expectedStartAt = $currentExpiresAt->toIso8601String();
            $expectedExpiresAt = $currentExpiresAt->copy()->addDays(30)->toIso8601String();

            $this->postJson('/api/driver/subscription/renew', [
                'plan' => 'monthly',
            ])
                ->assertOk()
                ->assertJsonPath('subscription.plan', 'monthly')
                ->assertJsonPath('subscription.status', 'active')
                ->assertJsonPath('subscription.starts_at', $expectedStartAt)
                ->assertJsonPath('subscription.expires_at', $expectedExpiresAt)
                ->assertJsonPath('subscription.remaining_days', 33)
                ->assertJsonPath('wallet.available_balance_fcfa', 1500);

            $driver->refresh();
            $subscription = $driver->settings['driver_subscription'] ?? [];

            $this->assertSame('monthly', $subscription['plan'] ?? null);
            $this->assertSame($expectedStartAt, $subscription['started_at'] ?? null);
            $this->assertSame($expectedExpiresAt, $subscription['expires_at'] ?? null);
            $this->assertSame(1500, (int) $driver->driver_wallet_balance_fcfa);

            $this->assertDatabaseHas('user_notifications', [
                'user_id' => $driver->id,
                'type' => 'driver_subscription_renewed',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_driver_cannot_renew_subscription_without_enough_wallet_balance(): void
    {
        $driver = $this->createUser('driver', 'subscription-driver-insufficient@example.com');
        $driver->forceFill(['driver_wallet_balance_fcfa' => 400])->save();

        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/subscription/renew', [
            'plan' => 'weekly',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['wallet'])
            ->assertJsonPath(
                'errors.wallet.0',
                'Solde insuffisant. Rechargez votre compte conducteur pour renouveler l abonnement Hebdomadaire.'
            );

        $driver->refresh();
        $this->assertSame(400, (int) $driver->driver_wallet_balance_fcfa);
    }

    public function test_driver_subscription_can_be_reported_as_expired(): void
    {
        $now = Carbon::create(2026, 6, 2, 10, 0, 0, 'UTC');
        Carbon::setTestNow($now);

        try {
            $driver = $this->createUser('driver', 'subscription-driver-expired@example.com');

            $driver->forceFill([
                'settings' => [
                    'driver_subscription' => [
                        'plan' => 'monthly',
                        'started_at' => $now->copy()->subDays(40)->toIso8601String(),
                        'expires_at' => $now->copy()->subDays(10)->toIso8601String(),
                    ],
                ],
            ])->save();

            Sanctum::actingAs($driver);

            $this->getJson('/api/driver/subscription')
                ->assertOk()
                ->assertJsonPath('subscription.plan', 'monthly')
                ->assertJsonPath('subscription.status', 'expired')
                ->assertJsonPath('subscription.is_active', false)
                ->assertJsonPath('subscription.remaining_days', 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function createUser(string $role, string $email): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' User',
            'email' => $email,
            'phone' => '+22990007766',
            'role' => $role,
            'password' => 'password123',
            'vehicle_type' => $role === 'driver' ? 'voiture' : null,
            'vehicle_plate' => $role === 'driver' ? 'AA-0000-BB' : null,
        ]);
    }
}
