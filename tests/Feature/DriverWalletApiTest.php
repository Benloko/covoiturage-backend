<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\DriverDeposit;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverWalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_wallet_returns_available_and_pending_balances(): void
    {
        $driver = $this->createUser('driver', 'wallet-driver@example.com');
        $driver->forceFill(['driver_wallet_balance_fcfa' => 2500])->save();

        $passenger = $this->createUser('passenger', 'wallet-passenger@example.com');

        $trip = $this->createTrip($driver, [
            'status' => 'completed',
        ]);

        Booking::query()->create([
            'trip_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'status' => 'completed',
            'seats_reserved' => 1,
            'booked_price_fcfa' => 1000,
            'booked_at' => now()->subHour(),
            'driver_completed_at' => now()->subMinutes(20),
            'passenger_completion_status' => 'pending',
            'payout_status' => 'pending_confirmation',
            'payout_amount_fcfa' => 950,
        ]);

        Sanctum::actingAs($driver);

        $this->getJson('/api/driver/wallet')
            ->assertOk()
            ->assertJsonPath('wallet.available_balance_fcfa', 2500)
            ->assertJsonPath('wallet.pending_balance_fcfa', 950)
            ->assertJsonPath('wallet.total_balance_fcfa', 3450)
            ->assertJsonPath('wallet.withdrawal_phone', $driver->phone)
            ->assertJsonPath('wallet.withdrawal_network', 'mtn_momo')
            ->assertJsonPath('wallet.withdrawal_network_label', 'MTN Mobile Money')
            ->assertJsonPath('wallet.withdrawal_network_supported', true)
            ->assertJsonPath('wallet.recharge_phone', $driver->phone)
            ->assertJsonPath('wallet.recharge_network', 'mtn_momo')
            ->assertJsonPath('wallet.recharge_network_label', 'MTN Mobile Money')
            ->assertJsonPath('wallet.recharge_network_supported', true)
            ->assertJsonPath('wallet.minimum_deposit_fcfa', 500);
    }

    public function test_driver_can_view_driver_deposit_history(): void
    {
        $driver = $this->createUser('driver', 'wallet-driver-deposits@example.com');

        DriverDeposit::query()->create([
            'user_id' => $driver->id,
            'amount_fcfa' => 3000,
            'network' => 'mtn_momo',
            'phone' => $driver->phone,
            'status' => 'completed',
            'reference' => 'DD-TEST-123',
            'requested_at' => now()->subMinutes(2),
            'processed_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($driver);

        $this->getJson('/api/driver/wallet/deposits')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.amount_fcfa', 3000)
            ->assertJsonPath('data.0.network', 'mtn_momo')
            ->assertJsonPath('data.0.status', 'completed');
    }

    public function test_driver_can_deposit_on_wallet_with_password_check(): void
    {
        $driver = $this->createUser('driver', 'wallet-driver-deposit@example.com');
        $driver->forceFill(['driver_wallet_balance_fcfa' => 1000])->save();

        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/wallet/deposit', [
            'amount_fcfa' => 2500,
            'current_password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('wallet.available_balance_fcfa', 3500)
            ->assertJsonPath('deposit.amount_fcfa', 2500)
            ->assertJsonPath('deposit.network', 'mtn_momo')
            ->assertJsonPath('deposit.phone', $driver->phone)
            ->assertJsonPath('deposit.status', 'completed');

        $this->assertDatabaseHas('driver_deposits', [
            'user_id' => $driver->id,
            'amount_fcfa' => 2500,
            'network' => 'mtn_momo',
            'phone' => $driver->phone,
            'status' => 'completed',
        ]);
    }

    public function test_driver_can_withdraw_to_profile_phone_with_password_check(): void
    {
        $driver = $this->createUser('driver', 'wallet-withdraw-driver@example.com');
        $driver->forceFill(['driver_wallet_balance_fcfa' => 5000])->save();

        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/wallet/withdraw', [
            'amount_fcfa' => 1500,
            'current_password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('wallet.available_balance_fcfa', 3500)
            ->assertJsonPath('withdrawal.amount_fcfa', 1500)
            ->assertJsonPath('withdrawal.network', 'mtn_momo')
            ->assertJsonPath('withdrawal.phone', $driver->phone)
            ->assertJsonPath('withdrawal.status', 'completed');

        $this->assertDatabaseHas('driver_withdrawals', [
            'user_id' => $driver->id,
            'amount_fcfa' => 1500,
            'network' => 'mtn_momo',
            'phone' => $driver->phone,
            'status' => 'completed',
        ]);
    }

    public function test_driver_cannot_withdraw_pending_balance_before_passenger_confirmation(): void
    {
        $driver = $this->createUser('driver', 'wallet-pending-withdraw-driver@example.com');
        $driver->forceFill(['driver_wallet_balance_fcfa' => 0])->save();

        $passenger = $this->createUser('passenger', 'wallet-pending-withdraw-passenger@example.com');

        $trip = $this->createTrip($driver, [
            'status' => 'completed',
        ]);

        Booking::query()->create([
            'trip_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'status' => 'completed',
            'seats_reserved' => 1,
            'booked_price_fcfa' => 1000,
            'booked_at' => now()->subHour(),
            'driver_completed_at' => now()->subMinutes(20),
            'passenger_completion_status' => 'pending',
            'payout_status' => 'pending_confirmation',
            'payout_amount_fcfa' => 950,
        ]);

        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/wallet/withdraw', [
            'amount_fcfa' => 500,
            'current_password' => 'password123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount_fcfa'])
            ->assertJsonPath(
                'errors.amount_fcfa.0',
                'Solde disponible insuffisant pour ce retrait. 950 FCFA sont en attente de confirmation passager et ne sont pas encore retirables.'
            );

        $this->assertDatabaseCount('driver_withdrawals', 0);
    }

    public function test_driver_cannot_withdraw_with_unsupported_profile_phone_prefix(): void
    {
        $driver = $this->createUser('driver', 'wallet-unsupported-prefix@example.com', '0110665544');
        $driver->forceFill(['driver_wallet_balance_fcfa' => 2000])->save();

        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/wallet/withdraw', [
            'amount_fcfa' => 1000,
            'current_password' => 'password123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone'])
            ->assertJsonPath(
                'errors.phone.0',
                'Le numero de profil ne correspond a aucun reseau mobile money pris en charge au Benin. Mettez ce numero a jour dans votre profil.'
            );

        $this->assertDatabaseCount('driver_withdrawals', 0);
    }

    public function test_driver_cannot_withdraw_with_wrong_password_or_insufficient_balance(): void
    {
        $driver = $this->createUser('driver', 'wallet-withdraw-failure-driver@example.com');
        $driver->forceFill(['driver_wallet_balance_fcfa' => 600])->save();

        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/wallet/withdraw', [
            'amount_fcfa' => 500,
            'current_password' => 'wrong-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->postJson('/api/driver/wallet/withdraw', [
            'amount_fcfa' => 2000,
            'current_password' => 'password123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount_fcfa']);
    }

    public function test_driver_cannot_deposit_with_wrong_password_or_unsupported_profile_phone_prefix(): void
    {
        $driver = $this->createUser('driver', 'wallet-driver-deposit-failure@example.com', '0110665544');

        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/wallet/deposit', [
            'amount_fcfa' => 1000,
            'current_password' => 'wrong-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->postJson('/api/driver/wallet/deposit', [
            'amount_fcfa' => 1000,
            'current_password' => 'password123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone'])
            ->assertJsonPath(
                'errors.phone.0',
                'Le numero de profil ne correspond a aucun reseau mobile money pris en charge au Benin. Mettez ce numero a jour dans votre profil.'
            );

        $this->assertDatabaseCount('driver_deposits', 0);
    }

    private function createUser(string $role, string $email, ?string $phone = null): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' User',
            'email' => $email,
            'phone' => $phone ?? '0146123456',
            'role' => $role,
            'password' => 'password123',
            'vehicle_type' => $role === 'driver' ? 'voiture' : null,
            'vehicle_plate' => $role === 'driver' ? 'AA-0000-BB' : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTrip(User $driver, array $attributes = []): Trip
    {
        return Trip::query()->create(array_merge([
            'driver_id' => $driver->id,
            'from_city' => 'Calavi',
            'from_label' => 'Calavi Centre',
            'to_city' => 'Cotonou',
            'to_label' => 'Cotonou Centre',
            'departure_at' => now()->addHours(3),
            'arrival_at' => now()->addHours(3)->addMinutes(20),
            'price_fcfa' => 500,
            'vehicle_type' => 'voiture',
            'vehicle_model' => 'Toyota',
            'status' => 'scheduled',
            'seats_total' => 4,
            'seats_available' => 4,
        ], $attributes));
    }
}
