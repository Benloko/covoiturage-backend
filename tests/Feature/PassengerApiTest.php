<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PassengerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_passenger_can_access_passenger_endpoints(): void
    {
        $driver = $this->createUser('driver', 'driver-only-passenger-api@example.com');

        Sanctum::actingAs($driver);

        $this->getJson('/api/passenger/home')
            ->assertForbidden()
            ->assertJsonPath('message', 'Acces reserve aux passagers.');
    }

    public function test_passenger_home_returns_stats_upcoming_and_popular_routes(): void
    {
        $passenger = $this->createUser('passenger', 'home-passenger@example.com');
        $driverA = $this->createUser('driver', 'home-driver-a@example.com');
        $driverB = $this->createUser('driver', 'home-driver-b@example.com');

        $upcomingTrip = $this->createTrip($driverA, [
            'from_city' => 'Calavi',
            'to_city' => 'Cotonou',
            'departure_at' => Carbon::now()->addHours(3),
            'arrival_at' => Carbon::now()->addHours(3)->addMinutes(25),
            'price_fcfa' => 500,
            'vehicle_type' => 'voiture',
            'vehicle_model' => 'Toyota Corolla',
        ]);

        $completedTrip = $this->createTrip($driverB, [
            'from_city' => 'Porto-Novo',
            'to_city' => 'Cotonou',
            'departure_at' => Carbon::now()->subDay(),
            'arrival_at' => Carbon::now()->subDay()->addMinutes(45),
            'price_fcfa' => 1200,
            'vehicle_type' => 'voiture',
            'vehicle_model' => 'Honda Accord',
        ]);

        $this->createTrip($driverA, [
            'from_city' => 'Calavi',
            'to_city' => 'Cotonou',
            'departure_at' => Carbon::now()->addHours(5),
            'arrival_at' => Carbon::now()->addHours(5)->addMinutes(20),
            'price_fcfa' => 600,
        ]);

        Booking::query()->create([
            'trip_id' => $upcomingTrip->id,
            'passenger_id' => $passenger->id,
            'status' => 'upcoming',
            'seats_reserved' => 1,
            'booked_price_fcfa' => 500,
            'booked_at' => now(),
        ]);

        Booking::query()->create([
            'trip_id' => $completedTrip->id,
            'passenger_id' => $passenger->id,
            'status' => 'completed',
            'seats_reserved' => 1,
            'booked_price_fcfa' => 1200,
            'booked_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($passenger);

        $this->getJson('/api/passenger/home')
            ->assertOk()
            ->assertJsonPath('stats.trips_completed', 1)
            ->assertJsonPath('stats.saved_amount_fcfa', 180)
            ->assertJsonPath('stats.upcoming_count', 1)
            ->assertJsonPath('upcoming_bookings.0.trip_id', $upcomingTrip->id)
            ->assertJsonPath('popular_routes.0.from', 'Calavi')
            ->assertJsonPath('popular_routes.0.to', 'Cotonou');
    }

    public function test_passenger_can_search_trips_with_filters(): void
    {
        $passenger = $this->createUser('passenger', 'search-passenger@example.com');
        $driver = $this->createUser('driver', 'search-driver@example.com');

        $matchingTrip = $this->createTrip($driver, [
            'from_city' => 'Calavi',
            'to_city' => 'Cotonou',
            'departure_at' => Carbon::tomorrow()->setTime(8, 0),
            'arrival_at' => Carbon::tomorrow()->setTime(8, 25),
            'price_fcfa' => 550,
            'seats_available' => 2,
        ]);

        $higherPriceTrip = $this->createTrip($driver, [
            'from_city' => 'Calavi',
            'to_city' => 'Cotonou',
            'departure_at' => Carbon::tomorrow()->setTime(10, 15),
            'arrival_at' => Carbon::tomorrow()->setTime(10, 45),
            'price_fcfa' => 900,
            'seats_available' => 1,
        ]);

        $this->createTrip($driver, [
            'from_city' => 'Calavi',
            'to_city' => 'Cotonou',
            'departure_at' => Carbon::tomorrow()->setTime(9, 0),
            'arrival_at' => Carbon::tomorrow()->setTime(9, 25),
            'price_fcfa' => 650,
            'seats_available' => 0,
        ]);

        $this->createTrip($driver, [
            'from_city' => 'Parakou',
            'from_label' => 'Parakou Centre',
            'to_city' => 'Dassa',
            'to_label' => 'Dassa Gare Routiere',
            'departure_at' => Carbon::tomorrow()->setTime(8, 30),
            'arrival_at' => Carbon::tomorrow()->setTime(9, 40),
            'price_fcfa' => 2500,
            'seats_available' => 3,
        ]);

        Sanctum::actingAs($passenger);

        $this->getJson('/api/passenger/trips?from=Calavi&to=Cotonou&date='.Carbon::tomorrow()->format('Y-m-d').'&sort=price_desc')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.filters.sort', 'price_desc')
            ->assertJsonPath('data.0.id', $higherPriceTrip->id)
            ->assertJsonPath('data.1.id', $matchingTrip->id);
    }

    public function test_passenger_can_view_trip_details_and_booking_state(): void
    {
        $passenger = $this->createUser('passenger', 'trip-details-passenger@example.com');
        $driver = $this->createUser('driver', 'trip-details-driver@example.com');

        $trip = $this->createTrip($driver, [
            'from_city' => 'Calavi',
            'from_label' => 'Godomey Carrefour',
            'to_city' => 'Cotonou',
            'to_label' => "Etoile Rouge",
            'departure_at' => Carbon::now()->addHours(2),
            'arrival_at' => Carbon::now()->addHours(2)->addMinutes(25),
            'price_fcfa' => 700,
            'seats_total' => 4,
            'seats_available' => 3,
            'vehicle_type' => 'voiture',
            'vehicle_model' => 'Toyota Corolla',
        ]);

        Sanctum::actingAs($passenger);

        $this->getJson('/api/passenger/trips/'.$trip->id)
            ->assertOk()
            ->assertJsonPath('trip.id', $trip->id)
            ->assertJsonPath('trip.driver.name', $driver->name)
            ->assertJsonPath('trip.vehicle.model', 'Toyota Corolla')
            ->assertJsonPath('trip.can_book', true)
            ->assertJsonPath('trip.passenger_booking', null);

        Booking::query()->create([
            'trip_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'status' => 'upcoming',
            'seats_reserved' => 1,
            'booked_price_fcfa' => 700,
            'booked_at' => now(),
        ]);

        $this->getJson('/api/passenger/trips/'.$trip->id)
            ->assertOk()
            ->assertJsonPath('trip.can_book', false)
            ->assertJsonPath('trip.passenger_booking.status', 'upcoming');
    }

    public function test_passenger_can_book_trip_and_cancel_booking(): void
    {
        $passenger = $this->createUser('passenger', 'booking-passenger@example.com');
        $driver = $this->createUser('driver', 'booking-driver@example.com');

        $trip = $this->createTrip($driver, [
            'from_city' => 'Calavi',
            'to_city' => 'Cotonou',
            'departure_at' => Carbon::now()->addHours(2),
            'arrival_at' => Carbon::now()->addHours(2)->addMinutes(30),
            'price_fcfa' => 500,
            'seats_total' => 4,
            'seats_available' => 2,
        ]);

        Sanctum::actingAs($passenger);

        $bookingResponse = $this->postJson('/api/passenger/bookings', [
            'trip_id' => $trip->id,
            'seats_reserved' => 2,
        ]);

        $bookingResponse
            ->assertCreated()
            ->assertJsonPath('booking.trip_id', $trip->id)
            ->assertJsonPath('booking.status', 'upcoming')
            ->assertJsonPath('booking.price', '1000');

        $trip->refresh();
        $this->assertSame(0, $trip->seats_available);

        $passenger->refresh();
        $this->assertSame(9000, (int) $passenger->passenger_wallet_balance_fcfa);

        $bookingId = $bookingResponse->json('booking.id');

        $this->patchJson('/api/passenger/bookings/'.$bookingId.'/cancel')
            ->assertOk()
            ->assertJsonPath('booking.status', 'cancelled')
            ->assertJsonPath('booking.can_cancel', false);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $passenger->id,
            'type' => 'booking_confirmed',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $passenger->id,
            'type' => 'booking_cancelled',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $driver->id,
            'type' => 'new_booking',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $driver->id,
            'type' => 'booking_cancelled_passenger',
        ]);

        $trip->refresh();
        $this->assertSame(2, $trip->seats_available);

        $passenger->refresh();
        $this->assertSame(10000, (int) $passenger->passenger_wallet_balance_fcfa);
    }

    public function test_passenger_cannot_book_trip_without_enough_wallet_balance(): void
    {
        $passenger = $this->createUser('passenger', 'booking-no-balance-passenger@example.com');
        $passenger->forceFill([
            'passenger_wallet_balance_fcfa' => 200,
        ])->save();

        $driver = $this->createUser('driver', 'booking-no-balance-driver@example.com');

        $trip = $this->createTrip($driver, [
            'departure_at' => Carbon::now()->addHours(2),
            'arrival_at' => Carbon::now()->addHours(2)->addMinutes(30),
            'price_fcfa' => 500,
            'seats_total' => 4,
            'seats_available' => 2,
        ]);

        Sanctum::actingAs($passenger);

        $this->postJson('/api/passenger/bookings', [
            'trip_id' => $trip->id,
            'seats_reserved' => 1,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['balance'])
            ->assertJsonPath('errors.balance.0', 'Solde insuffisant. Rechargez votre compte passager pour confirmer cette reservation.');

        $passenger->refresh();
        $trip->refresh();

        $this->assertSame(200, (int) $passenger->passenger_wallet_balance_fcfa);
        $this->assertSame(2, (int) $trip->seats_available);
    }

    public function test_passenger_cannot_book_when_not_enough_seats_are_available(): void
    {
        $passenger = $this->createUser('passenger', 'capacity-passenger@example.com');
        $driver = $this->createUser('driver', 'capacity-driver@example.com');

        $trip = $this->createTrip($driver, [
            'departure_at' => Carbon::now()->addHours(4),
            'arrival_at' => Carbon::now()->addHours(4)->addMinutes(25),
            'seats_available' => 1,
        ]);

        Sanctum::actingAs($passenger);

        $this->postJson('/api/passenger/bookings', [
            'trip_id' => $trip->id,
            'seats_reserved' => 2,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['seats_reserved']);
    }


    public function test_passenger_can_rate_completed_booking(): void
    {
        $passenger = $this->createUser('passenger', 'rate-passenger@example.com');
        $driver = $this->createUser('driver', 'rate-driver@example.com');

        $trip = $this->createTrip($driver, [
            'departure_at' => Carbon::now()->subHours(4),
            'arrival_at' => Carbon::now()->subHours(3)->subMinutes(25),
            'status' => 'completed',
            'seats_available' => 3,
        ]);

        $booking = Booking::query()->create([
            'trip_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'status' => 'completed',
            'seats_reserved' => 1,
            'booked_price_fcfa' => 500,
            'booked_at' => now()->subHours(5),
        ]);

        Sanctum::actingAs($passenger);

        $this->patchJson('/api/passenger/bookings/'.$booking->id.'/rating', [
            'rating' => 4,
            'comment' => 'Conducteur ponctuel et trajet agreable.',
        ])
            ->assertOk()
            ->assertJsonPath('booking.id', $booking->id)
            ->assertJsonPath('booking.passenger_rating', 4)
            ->assertJsonPath('booking.passenger_review', 'Conducteur ponctuel et trajet agreable.')
            ->assertJsonPath('booking.can_rate', true);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'passenger_rating' => 4,
            'passenger_review' => 'Conducteur ponctuel et trajet agreable.',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $driver->id,
            'type' => 'rating',
        ]);
    }

    public function test_passenger_cannot_rate_non_completed_booking(): void
    {
        $passenger = $this->createUser('passenger', 'rate-blocked-passenger@example.com');
        $driver = $this->createUser('driver', 'rate-blocked-driver@example.com');

        $trip = $this->createTrip($driver, [
            'departure_at' => Carbon::now()->addHours(3),
            'arrival_at' => Carbon::now()->addHours(3)->addMinutes(25),
            'status' => 'scheduled',
            'seats_available' => 2,
        ]);

        $booking = Booking::query()->create([
            'trip_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'status' => 'upcoming',
            'seats_reserved' => 1,
            'booked_price_fcfa' => 500,
            'booked_at' => now(),
        ]);

        Sanctum::actingAs($passenger);

        $this->patchJson('/api/passenger/bookings/'.$booking->id.'/rating', [
            'rating' => 5,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['booking']);
    }

    public function test_passenger_can_confirm_trip_completion_and_credit_driver_wallet(): void
    {
        $passenger = $this->createUser('passenger', 'completion-confirm-passenger@example.com');
        $driver = $this->createUser('driver', 'completion-confirm-driver@example.com');

        $trip = $this->createTrip($driver, [
            'status' => 'completed',
            'departure_at' => Carbon::now()->subHours(2),
            'arrival_at' => Carbon::now()->subHours(1)->subMinutes(20),
        ]);

        $booking = Booking::query()->create([
            'trip_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'status' => 'completed',
            'seats_reserved' => 1,
            'booked_price_fcfa' => 1000,
            'booked_at' => now()->subHours(3),
            'driver_completed_at' => now()->subHour(),
            'passenger_completion_status' => 'pending',
            'payout_status' => 'pending_confirmation',
            'payout_amount_fcfa' => 950,
        ]);

        Sanctum::actingAs($passenger);

        $this->patchJson('/api/passenger/bookings/'.$booking->id.'/completion-confirmation', [
            'decision' => 'yes',
        ])
            ->assertOk()
            ->assertJsonPath('booking.completion_confirmation_status', 'confirmed')
            ->assertJsonPath('booking.payout_status', 'credited')
            ->assertJsonPath('booking.payout_amount_fcfa', 950)
            ->assertJsonPath('booking.can_confirm_completion', false);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'passenger_completion_status' => 'confirmed',
            'payout_status' => 'credited',
            'payout_amount_fcfa' => 950,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $driver->id,
            'driver_wallet_balance_fcfa' => 950,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $driver->id,
            'type' => 'trip_completion_confirmed_credit',
        ]);
    }

    public function test_passenger_can_reject_trip_completion_and_block_credit(): void
    {
        $passenger = $this->createUser('passenger', 'completion-reject-passenger@example.com');
        $driver = $this->createUser('driver', 'completion-reject-driver@example.com');

        $trip = $this->createTrip($driver, [
            'status' => 'completed',
            'departure_at' => Carbon::now()->subHours(2),
            'arrival_at' => Carbon::now()->subHours(1)->subMinutes(20),
        ]);

        $booking = Booking::query()->create([
            'trip_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'status' => 'completed',
            'seats_reserved' => 1,
            'booked_price_fcfa' => 1200,
            'booked_at' => now()->subHours(3),
            'driver_completed_at' => now()->subHour(),
            'passenger_completion_status' => 'pending',
            'payout_status' => 'pending_confirmation',
            'payout_amount_fcfa' => 1140,
        ]);

        Sanctum::actingAs($passenger);

        $this->patchJson('/api/passenger/bookings/'.$booking->id.'/completion-confirmation', [
            'decision' => 'no',
        ])
            ->assertOk()
            ->assertJsonPath('booking.completion_confirmation_status', 'rejected')
            ->assertJsonPath('booking.payout_status', 'blocked')
            ->assertJsonPath('booking.can_confirm_completion', false);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'passenger_completion_status' => 'rejected',
            'payout_status' => 'blocked',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $driver->id,
            'driver_wallet_balance_fcfa' => 0,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $driver->id,
            'type' => 'trip_completion_disputed',
        ]);
    }

    private function createUser(string $role, string $email): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' User',
            'email' => $email,
            'phone' => '+22990009999',
            'role' => $role,
            'password' => 'password123',
            'passenger_wallet_balance_fcfa' => $role === 'passenger' ? 10000 : 0,
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


