<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_can_create_conversation_and_send_message(): void
    {
        $driver = $this->createUser('driver', 'chat-driver-create@example.com');
        $passenger = $this->createUser('passenger', 'chat-passenger-create@example.com');

        $trip = $this->createTrip($driver, [
            'status' => 'scheduled',
            'departure_at' => Carbon::now()->addHours(3),
        ]);

        Booking::query()->create([
            'trip_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'status' => 'upcoming',
            'seats_reserved' => 1,
            'booked_price_fcfa' => 500,
            'booked_at' => now(),
        ]);

        Sanctum::actingAs($driver);

        $createResponse = $this->postJson('/api/chat/conversations', [
            'target_user_id' => $passenger->id,
            'trip_id' => $trip->id,
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('conversation.counterpart.id', $passenger->id)
            ->assertJsonPath('conversation.trip.id', $trip->id);

        $conversationId = $createResponse->json('conversation.id');

        $this->postJson('/api/chat/conversations/'.$conversationId.'/messages', [
            'message' => 'Bonjour, le depart est confirme a 08h30.',
        ])
            ->assertCreated()
            ->assertJsonPath('chat_message.message', 'Bonjour, le depart est confirme a 08h30.');

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversationId,
            'sender_id' => $driver->id,
            'message' => 'Bonjour, le depart est confirme a 08h30.',
        ]);

        $this->getJson('/api/chat/conversations/'.$conversationId)
            ->assertOk()
            ->assertJsonPath('messages.0.is_mine', true)
            ->assertJsonPath('messages.0.sender.id', $driver->id)
            ->assertJsonPath('messages.0.message', 'Bonjour, le depart est confirme a 08h30.');
    }

    public function test_conversation_supports_incremental_fetch_with_after_id(): void
    {
        $driver = $this->createUser('driver', 'chat-driver-incremental@example.com');
        $passenger = $this->createUser('passenger', 'chat-passenger-incremental@example.com');

        $trip = $this->createTrip($driver, [
            'status' => 'scheduled',
            'departure_at' => Carbon::now()->addHours(2),
        ]);

        $conversation = Conversation::query()->create([
            'trip_id' => $trip->id,
            'driver_id' => $driver->id,
            'passenger_id' => $passenger->id,
            'last_message_at' => now(),
            'driver_last_read_at' => now(),
            'passenger_last_read_at' => null,
        ]);

        $messageOne = ConversationMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $driver->id,
            'message' => 'Premier message',
        ]);

        $messageTwo = ConversationMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $passenger->id,
            'message' => 'Deuxieme message',
        ]);

        $messageThree = ConversationMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $driver->id,
            'message' => 'Troisieme message',
        ]);

        Sanctum::actingAs($passenger);

        $this->getJson('/api/chat/conversations/'.$conversation->id.'?after_id='.$messageOne->id)
            ->assertOk()
            ->assertJsonPath('meta.after_id', $messageOne->id)
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('meta.last_message_id', $messageThree->id)
            ->assertJsonPath('messages.0.id', $messageTwo->id)
            ->assertJsonPath('messages.1.id', $messageThree->id);
    }
    public function test_passenger_can_see_unread_count_and_mark_conversation_as_read(): void
    {
        $driver = $this->createUser('driver', 'chat-driver-unread@example.com');
        $passenger = $this->createUser('passenger', 'chat-passenger-unread@example.com');

        $trip = $this->createTrip($driver, [
            'status' => 'scheduled',
            'departure_at' => Carbon::now()->addHours(4),
        ]);

        $conversation = Conversation::query()->create([
            'trip_id' => $trip->id,
            'driver_id' => $driver->id,
            'passenger_id' => $passenger->id,
            'last_message_at' => now(),
            'driver_last_read_at' => now(),
            'passenger_last_read_at' => null,
        ]);

        ConversationMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $driver->id,
            'message' => 'Je suis en route.',
        ]);

        Sanctum::actingAs($passenger);

        $this->getJson('/api/chat/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonPath('data.0.unread_count', 1)
            ->assertJsonPath('meta.unread_total', 1);

        $this->postJson('/api/chat/conversations/'.$conversation->id.'/read')
            ->assertOk()
            ->assertJsonPath('conversation.unread_count', 0);

        $this->getJson('/api/chat/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 0)
            ->assertJsonPath('meta.unread_total', 0);
    }

    public function test_conversation_creation_requires_trip_relationship(): void
    {
        $driver = $this->createUser('driver', 'chat-driver-relation@example.com');
        $passenger = $this->createUser('passenger', 'chat-passenger-relation@example.com');

        $trip = $this->createTrip($driver, [
            'status' => 'scheduled',
        ]);

        Sanctum::actingAs($driver);

        $this->postJson('/api/chat/conversations', [
            'target_user_id' => $passenger->id,
            'trip_id' => $trip->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['trip_id']);
    }

    public function test_non_participant_cannot_access_or_send_messages(): void
    {
        $driver = $this->createUser('driver', 'chat-driver-owner@example.com');
        $passenger = $this->createUser('passenger', 'chat-passenger-owner@example.com');
        $otherPassenger = $this->createUser('passenger', 'chat-passenger-other@example.com');

        $trip = $this->createTrip($driver);

        $conversation = Conversation::query()->create([
            'trip_id' => $trip->id,
            'driver_id' => $driver->id,
            'passenger_id' => $passenger->id,
            'last_message_at' => now(),
        ]);

        ConversationMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $driver->id,
            'message' => 'Message prive.',
        ]);

        Sanctum::actingAs($otherPassenger);

        $this->getJson('/api/chat/conversations/'.$conversation->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'Cette conversation ne vous appartient pas.');

        $this->postJson('/api/chat/conversations/'.$conversation->id.'/messages', [
            'message' => 'Tentative d intrusion',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Cette conversation ne vous appartient pas.');

        $this->postJson('/api/chat/conversations/'.$conversation->id.'/read')
            ->assertForbidden()
            ->assertJsonPath('message', 'Cette conversation ne vous appartient pas.');
    }

    public function test_passenger_can_create_conversation_and_notify_driver_when_sending_message(): void
    {
        $driver = $this->createUser('driver', 'chat-driver-notified@example.com');
        $passenger = $this->createUser('passenger', 'chat-passenger-notified@example.com');

        $trip = $this->createTrip($driver, [
            'status' => 'scheduled',
            'departure_at' => Carbon::now()->addHours(6),
        ]);

        Booking::query()->create([
            'trip_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'status' => 'upcoming',
            'seats_reserved' => 1,
            'booked_price_fcfa' => 500,
            'booked_at' => now(),
        ]);

        Sanctum::actingAs($passenger);

        $conversationResponse = $this->postJson('/api/chat/conversations', [
            'target_user_id' => $driver->id,
            'trip_id' => $trip->id,
        ])
            ->assertCreated()
            ->assertJsonPath('conversation.counterpart.id', $driver->id);

        $conversationId = $conversationResponse->json('conversation.id');

        $this->postJson('/api/chat/conversations/'.$conversationId.'/messages', [
            'message' => 'Bonjour chauffeur, je serai au point de rendez-vous 5 min avant.',
        ])
            ->assertCreated()
            ->assertJsonPath('chat_message.sender.id', $passenger->id);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $driver->id,
            'type' => 'chat_message',
        ]);
    }

    private function createUser(string $role, string $email): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' User',
            'email' => $email,
            'phone' => '+22990112233',
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


