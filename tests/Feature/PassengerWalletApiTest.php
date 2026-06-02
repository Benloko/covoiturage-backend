<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PassengerWalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_can_view_wallet_and_deposit_history(): void
    {
        $passenger = $this->createUser('passenger', 'wallet-passenger@example.com');
        $passenger->forceFill([
            'passenger_wallet_balance_fcfa' => 2500,
        ])->save();

        Sanctum::actingAs($passenger);

        $this->getJson('/api/passenger/wallet')
            ->assertOk()
            ->assertJsonPath('wallet.available_balance_fcfa', 2500)
            ->assertJsonPath('wallet.total_balance_fcfa', 2500)
            ->assertJsonPath('wallet.recharge_network', 'mtn_momo')
            ->assertJsonPath('wallet.recharge_network_label', 'MTN Mobile Money')
            ->assertJsonPath('wallet.recharge_network_supported', true);

        $this->getJson('/api/passenger/wallet/deposits')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_passenger_can_deposit_and_balance_is_credited(): void
    {
        $passenger = $this->createUser('passenger', 'wallet-deposit-passenger@example.com');

        Sanctum::actingAs($passenger);

        $this->postJson('/api/passenger/wallet/deposit', [
            'amount_fcfa' => 1800,
            'current_password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('wallet.available_balance_fcfa', 1800)
            ->assertJsonPath('deposit.amount_fcfa', 1800)
            ->assertJsonPath('deposit.network', 'mtn_momo')
            ->assertJsonPath('deposit.phone', $passenger->phone)
            ->assertJsonPath('deposit.status', 'completed');

        $this->assertDatabaseHas('users', [
            'id' => $passenger->id,
            'passenger_wallet_balance_fcfa' => 1800,
        ]);

        $this->assertDatabaseHas('passenger_deposits', [
            'user_id' => $passenger->id,
            'amount_fcfa' => 1800,
            'network' => 'mtn_momo',
            'phone' => $passenger->phone,
            'status' => 'completed',
        ]);
    }

    public function test_passenger_deposit_requires_correct_password_and_supported_phone_prefix(): void
    {
        $passenger = $this->createUser('passenger', 'wallet-deposit-password@example.com', '0110665544');

        Sanctum::actingAs($passenger);

        $this->postJson('/api/passenger/wallet/deposit', [
            'amount_fcfa' => 1000,
            'current_password' => 'password123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        $passenger->forceFill([
            'phone' => '0146123456',
        ])->save();

        $this->postJson('/api/passenger/wallet/deposit', [
            'amount_fcfa' => 1000,
            'current_password' => 'wrong-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertDatabaseCount('passenger_deposits', 0);
    }

    public function test_driver_cannot_access_passenger_wallet_endpoints(): void
    {
        $driver = $this->createUser('driver', 'wallet-driver-forbidden@example.com');

        Sanctum::actingAs($driver);

        $this->getJson('/api/passenger/wallet')
            ->assertForbidden()
            ->assertJsonPath('message', 'Acces reserve aux passagers.');

        $this->postJson('/api/passenger/wallet/deposit', [
            'amount_fcfa' => 1000,
            'current_password' => 'password123',
        ])->assertForbidden();
    }

    private function createUser(string $role, string $email, ?string $phone = null): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' User',
            'email' => $email,
            'phone' => $phone ?? '0146123456',
            'role' => $role,
            'password' => 'password123',
            'passenger_wallet_balance_fcfa' => $role === 'passenger' ? 0 : 0,
            'vehicle_type' => $role === 'driver' ? 'voiture' : null,
            'vehicle_plate' => $role === 'driver' ? 'AA-0000-BB' : null,
        ]);
    }
}

