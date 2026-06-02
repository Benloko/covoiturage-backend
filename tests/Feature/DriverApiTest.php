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

class DriverApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_driver_can_access_driver_endpoints(): void
    {
        $passenger = $this->createUser('passenger', 'driver-api-passenger@example.com');

        Sanctum::actingAs($passenger);

        $this->getJson('/api/driver/home')
            ->assertForbidden()
            ->assertJsonPath('message', 'Acces reserve aux conducteurs.');
    }

    public function test_driver_home_returns_stats_upcoming_trips_and_notifications(): void
    {
        $driver = $this->createUser('driver', 'driver-home@example.com');
        $passengerA = $this->createUser('passenger', 'driver-home-passenger-a@example.com');
        $passengerB = $this->createUser('passenger', 'driver-home-passenger-b@example.com');

        $upcomingTrip = $this->createTrip($driver, [
            'from_city' => 'Calavi',
            'to_city' => 'Cotonou',
            'departure_at' => Carbon::now()->addHours(2),
            'arrival_at' => Carbon::now()->addHours(2)->addMinutes(25),
            'status' => 'scheduled',
            'price_fcfa' => 500,
            'seats_total' => 4,
            'seats_available' => 2,
        ]);

        $completedTripA = $this->createTrip($driver, [
            'from_city' => 'Porto-Novo',
            'to_city' => 'Cotonou',
            'departure_at' => Carbon::now()->subDays(2),
            'arrival_at' => Carbon::now()->subDays(2)->addMinutes(45),
            'status' => 'completed',
            'price_fcfa' => 1200,
        ]);

        $completedTripB = $this->createTrip($driver, [
            'from_city' => 'Abomey',
            'to_city' => 'Bohicon',
            'departure_at' => Carbon::now()->subDay(),
            'arrival_at' => Carbon::now()->subDay()->addMinutes(30),
            'status' => 'completed',
            'price_fcfa' => 900,
        ]);

        $this->createTrip($driver, [
            'status' => 'cancelled',
            'departure_at' => Carbon::now()->addDays(2),
            'arrival_at' => Carbon::now()->addDays(2)->addMinutes(40),
        ]);

        Booking::query()->create([
            'trip_id' => $upcomingTrip->id,
            'passenger_id' => $passengerA->id,
            'status' => 'upcoming',
            'seats_reserved' => 2,
            'booked_price_fcfa' => 1000,
            'booked_at' => now(),
        ]);

        Booking::query()->create([
            'trip_id' => $completedTripA->id,
            'passenger_id' => $passengerA->id,
            'status' => 'completed',
            'seats_reserved' => 3,
            'booked_price_fcfa' => 3600,
            'booked_at' => now()->startOfMonth()->addDays(3),
            'passenger_rating' => 4,
            'rated_at' => now()->subDay(),
        ]);

        Booking::query()->create([
            'trip_id' => $completedTripB->id,
            'passenger_id' => $passengerB->id,
            'status' => 'completed',
            'seats_reserved' => 2,
            'booked_price_fcfa' => 2400,
            'booked_at' => now()->startOfMonth()->addDays(5),
            'passenger_rating' => 5,
            'rated_at' => now()->subDay(),
        ]);

        UserNotification::query()->create([
            'user_id' => $driver->id,
            'type' => 'system',
            'title' => 'A',
            'message' => 'A',
        ]);

        UserNotification::query()->create([
            'user_id' => $driver->id,
            'type' => 'system',
            'title' => 'B',
            'message' => 'B',
            'read_at' => now(),
        ]);

        Sanctum::actingAs($driver);

        $this->getJson('/api/driver/home')
            ->assertOk()
            ->assertJsonPath('stats.trips_published', 4)
            ->assertJsonPath('stats.passengers_transported', 5)
            ->assertJsonPath('stats.average_rating', 4.5)
            ->assertJsonPath('stats.revenue_month_fcfa', 6000)
            ->assertJsonPath('upcoming_trips.0.id', $upcomingTrip->id)
            ->assertJsonPath('upcoming_trips.0.passengers', 2)
            ->assertJsonPath('notifications.unread_count', 1);
    }

    public function test_driver_can_publish_trip(): void
    {
        $driver = $this->createUser('driver', 'driver-publish@example.com');

        Sanctum::actingAs($driver);

        $response = $this->postJson('/api/driver/trips', [
            'from' => 'Calavi, Godomey Carrefour',
            'to' => 'Cotonou, Akpakpa PK3',
            'date' => Carbon::tomorrow()->format('Y-m-d'),
            'time' => '08:30',
            'seats' => 3,
            'price' => 700,
            'vehicle_type' => 'voiture',
            'description' => 'Trajet matinal.',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Trajet publie avec succes.')
            ->assertJsonPath('trip.status', 'upcoming')
            ->assertJsonPath('trip.total', 3);

        $tripId = $response->json('trip.id');

        $this->assertDatabaseHas('trips', [
            'id' => $tripId,
            'driver_id' => $driver->id,
            'from_city' => 'Calavi',
            'from_label' => 'Calavi, Godomey Carrefour',
            'to_city' => 'Cotonou',
            'to_label' => 'Cotonou, Akpakpa PK3',
            'price_fcfa' => 700,
            'seats_total' => 3,
            'seats_available' => 3,
            'status' => 'scheduled',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $driver->id,
            'type' => 'trip_published',
        ]);
    }

    public function test_driver_cannot_publish_trip_in_the_past(): void
    {
        $driver = $this->createUser('driver', 'driver-publish-past@example.com');

        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/trips', [
            'from' => 'Calavi',
            'to' => 'Cotonou',
            'date' => Carbon::yesterday()->format('Y-m-d'),
            'time' => '08:00',
            'seats' => 2,
            'price' => 500,
            'vehicle_type' => 'voiture',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['departure_at']);
    }

    public function test_driver_cannot_publish_moto_trip_with_more_than_one_seat(): void
    {
        $driver = $this->createUser('driver', 'driver-publish-moto-limit@example.com');
        $driver->forceFill([
            'vehicle_type' => 'moto',
        ])->save();

        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/trips', [
            'from' => 'Calavi',
            'to' => 'Cotonou',
            'date' => Carbon::tomorrow()->format('Y-m-d'),
            'time' => '08:15',
            'seats' => 2,
            'price' => 500,
            'vehicle_type' => 'moto',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['seats'])
            ->assertJsonPath('errors.seats.0', 'Pour ce type de vehicule, le nombre de places doit etre egal a 1.');
    }

    public function test_driver_cannot_publish_voiture_trip_with_more_than_three_seats(): void
    {
        $driver = $this->createUser('driver', 'driver-publish-voiture-limit@example.com');

        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/trips', [
            'from' => 'Calavi',
            'to' => 'Cotonou',
            'date' => Carbon::tomorrow()->format('Y-m-d'),
            'time' => '09:00',
            'seats' => 4,
            'price' => 500,
            'vehicle_type' => 'voiture',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['seats'])
            ->assertJsonPath('errors.seats.0', 'Pour ce type de vehicule, le nombre de places doit etre compris entre 1 et 3.');
    }

    public function test_driver_cannot_publish_trip_with_vehicle_type_different_from_profile(): void
    {
        $driver = $this->createUser('driver', 'driver-publish-vehicle-mismatch@example.com');

        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/trips', [
            'from' => 'Calavi',
            'to' => 'Cotonou',
            'date' => Carbon::tomorrow()->format('Y-m-d'),
            'time' => '10:00',
            'seats' => 1,
            'price' => 500,
            'vehicle_type' => 'moto',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vehicle_type'])
            ->assertJsonPath('errors.vehicle_type.0', 'Le type de vehicule du trajet doit correspondre a celui renseigne dans votre profil.');
    }

    public function test_driver_can_filter_his_trips_by_status(): void
    {
        $driver = $this->createUser('driver', 'driver-filter@example.com');
        $otherDriver = $this->createUser('driver', 'driver-filter-other@example.com');
        $passenger = $this->createUser('passenger', 'driver-filter-passenger@example.com');

        $scheduledTrip = $this->createTrip($driver, [
            'status' => 'scheduled',
            'departure_at' => Carbon::now()->addHours(5),
            'arrival_at' => Carbon::now()->addHours(5)->addMinutes(40),
        ]);

        $inProgressTrip = $this->createTrip($driver, [
            'status' => 'in_progress',
            'departure_at' => Carbon::now()->addHour(),
            'arrival_at' => Carbon::now()->addHour()->addMinutes(40),
        ]);

        $completedTrip = $this->createTrip($driver, [
            'status' => 'completed',
            'departure_at' => Carbon::now()->subDay(),
            'arrival_at' => Carbon::now()->subDay()->addMinutes(35),
        ]);

        $this->createTrip($driver, [
            'status' => 'cancelled',
            'departure_at' => Carbon::now()->addDays(2),
            'arrival_at' => Carbon::now()->addDays(2)->addMinutes(20),
        ]);

        $this->createTrip($otherDriver, [
            'status' => 'scheduled',
            'departure_at' => Carbon::now()->addHours(6),
        ]);

        Booking::query()->create([
            'trip_id' => $scheduledTrip->id,
            'passenger_id' => $passenger->id,
            'status' => 'upcoming',
            'seats_reserved' => 2,
            'booked_price_fcfa' => 1000,
            'booked_at' => now(),
        ]);

        Booking::query()->create([
            'trip_id' => $inProgressTrip->id,
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
            'booked_price_fcfa' => 500,
            'booked_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($driver);

        $this->getJson('/api/driver/trips?status=upcoming')
            ->assertOk()
            ->assertJsonPath('meta.status', 'upcoming')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.counts.upcoming', 2)
            ->assertJsonPath('meta.counts.completed', 1)
            ->assertJsonPath('meta.counts.cancelled', 1)
            ->assertJsonFragment(['id' => $scheduledTrip->id, 'status' => 'upcoming'])
            ->assertJsonFragment(['id' => $inProgressTrip->id, 'status' => 'upcoming']);

        $this->getJson('/api/driver/trips?status=completed')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $completedTrip->id)
            ->assertJsonPath('data.0.status', 'completed');

        $this->getJson('/api/driver/trips?status=all')
            ->assertOk()
            ->assertJsonPath('meta.total', 4);
    }

    public function test_driver_can_view_trip_details_with_passengers(): void
    {
        $driver = $this->createUser('driver', 'driver-trip-details@example.com');
        $passengerA = $this->createUser('passenger', 'driver-trip-details-passenger-a@example.com');
        $passengerB = $this->createUser('passenger', 'driver-trip-details-passenger-b@example.com');

        $trip = $this->createTrip($driver, [
            'status' => 'scheduled',
            'seats_total' => 4,
            'seats_available' => 2,
        ]);

        Booking::query()->create([
            'trip_id' => $trip->id,
            'passenger_id' => $passengerA->id,
            'status' => 'upcoming',
            'seats_reserved' => 2,
            'booked_price_fcfa' => 1000,
            'booked_at' => now(),
        ]);

        Booking::query()->create([
            'trip_id' => $trip->id,
            'passenger_id' => $passengerB->id,
            'status' => 'cancelled',
            'seats_reserved' => 1,
            'booked_price_fcfa' => 500,
            'booked_at' => now()->subHour(),
            'cancelled_at' => now()->subMinutes(40),
        ]);

        Sanctum::actingAs($driver);

        $this->getJson('/api/driver/trips/'.$trip->id)
            ->assertOk()
            ->assertJsonPath('trip.id', $trip->id)
            ->assertJsonPath('trip.reserved_seats', 2)
            ->assertJsonPath('trip.passengers_count', 1)
            ->assertJsonFragment(['status' => 'upcoming'])
            ->assertJsonFragment(['status' => 'cancelled'])
            ->assertJsonFragment(['name' => $passengerA->name]);
    }

    public function test_driver_can_update_trip_status_and_complete_upcoming_bookings(): void
    {
        $driver = $this->createUser('driver', 'driver-trip-status@example.com');
        $passenger = $this->createUser('passenger', 'driver-trip-status-passenger@example.com');

        $trip = $this->createTrip($driver, [
            'status' => 'scheduled',
            'departure_at' => Carbon::now()->addHours(2),
            'arrival_at' => Carbon::now()->addHours(2)->addMinutes(30),
        ]);

        $booking = Booking::query()->create([
            'trip_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'status' => 'upcoming',
            'seats_reserved' => 1,
            'booked_price_fcfa' => 500,
            'booked_at' => now(),
        ]);

        Sanctum::actingAs($driver);

        $this->patchJson('/api/driver/trips/'.$trip->id.'/status', [
            'status' => 'in_progress',
        ])
            ->assertOk()
            ->assertJsonPath('trip.status_raw', 'in_progress');

        $this->assertDatabaseHas('trips', [
            'id' => $trip->id,
            'status' => 'in_progress',
        ]);

        $this->patchJson('/api/driver/trips/'.$trip->id.'/status', [
            'status' => 'completed',
        ])
            ->assertOk()
            ->assertJsonPath('trip.status', 'completed')
            ->assertJsonPath('trip.status_raw', 'completed');

        $booking->refresh();

        $this->assertSame('completed', $booking->status);
        $this->assertSame('pending', $booking->passenger_completion_status);
        $this->assertSame('pending_confirmation', $booking->payout_status);
        $this->assertSame(475, (int) $booking->payout_amount_fcfa);
        $this->assertNotNull($booking->driver_completed_at);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'completed',
            'passenger_completion_status' => 'pending',
            'payout_status' => 'pending_confirmation',
            'payout_amount_fcfa' => 475,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $passenger->id,
            'type' => 'trip_started',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $passenger->id,
            'type' => 'trip_completed',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $passenger->id,
            'type' => 'trip_completion_confirmation_required',
        ]);
    }

    public function test_driver_cannot_start_trip_without_any_upcoming_booking(): void
    {
        $driver = $this->createUser('driver', 'driver-trip-no-booking-start@example.com');

        $trip = $this->createTrip($driver, [
            'status' => 'scheduled',
            'departure_at' => Carbon::now()->addHours(2),
            'arrival_at' => Carbon::now()->addHours(2)->addMinutes(30),
        ]);

        Sanctum::actingAs($driver);

        $this->getJson('/api/driver/trips/'.$trip->id)
            ->assertOk()
            ->assertJsonPath('trip.can_start', false);

        $this->patchJson('/api/driver/trips/'.$trip->id.'/status', [
            'status' => 'in_progress',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status'])
            ->assertJsonPath('errors.status.0', 'Impossible de demarrer: aucun passager n\'a encore reserve ce trajet.');
    }

    public function test_driver_can_cancel_passenger_booking(): void
    {
        $driver = $this->createUser('driver', 'driver-booking-cancel@example.com');
        $passenger = $this->createUser('passenger', 'driver-booking-cancel-passenger@example.com');

        $trip = $this->createTrip($driver, [
            'status' => 'scheduled',
            'seats_total' => 4,
            'seats_available' => 2,
        ]);

        $booking = Booking::query()->create([
            'trip_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'status' => 'upcoming',
            'seats_reserved' => 2,
            'booked_price_fcfa' => 1000,
            'booked_at' => now(),
        ]);

        Sanctum::actingAs($driver);

        $this->patchJson('/api/driver/bookings/'.$booking->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('trip.id', $trip->id)
            ->assertJsonPath('trip.seats_available', 4)
            ->assertJsonFragment(['booking_id' => $booking->id, 'status' => 'cancelled']);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'cancelled',
        ]);

        $this->assertDatabaseHas('trips', [
            'id' => $trip->id,
            'seats_available' => 4,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $passenger->id,
            'type' => 'booking_cancelled_driver',
        ]);
    }

    public function test_driver_cannot_manage_trip_or_booking_of_another_driver(): void
    {
        $ownerDriver = $this->createUser('driver', 'driver-owner@example.com');
        $otherDriver = $this->createUser('driver', 'driver-other-manager@example.com');
        $passenger = $this->createUser('passenger', 'driver-owner-passenger@example.com');

        $trip = $this->createTrip($ownerDriver, [
            'status' => 'scheduled',
        ]);

        $booking = Booking::query()->create([
            'trip_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'status' => 'upcoming',
            'seats_reserved' => 1,
            'booked_price_fcfa' => 500,
            'booked_at' => now(),
        ]);

        Sanctum::actingAs($otherDriver);

        $this->getJson('/api/driver/trips/'.$trip->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'Ce trajet ne vous appartient pas.');

        $this->patchJson('/api/driver/trips/'.$trip->id.'/status', [
            'status' => 'in_progress',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Ce trajet ne vous appartient pas.');

        $this->patchJson('/api/driver/bookings/'.$booking->id.'/cancel')
            ->assertForbidden()
            ->assertJsonPath('message', 'Cette reservation ne vous appartient pas.');
    }

    private function createUser(string $role, string $email): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' User',
            'email' => $email,
            'phone' => '+22990008877',
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
