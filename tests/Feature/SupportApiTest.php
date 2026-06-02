<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_and_list_support_tickets(): void
    {
        $user = $this->createUser('support-list@example.com');

        Sanctum::actingAs($user);

        $this->postJson('/api/support/tickets', [
            'category' => 'general',
            'message' => 'Bonjour, je souhaite verifier le statut de ma reservation de demain.',
        ])
            ->assertCreated()
            ->assertJsonPath('ticket.category', 'general')
            ->assertJsonPath('ticket.status', 'open');

        $this->getJson('/api/support/tickets')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.category', 'general');

        $this->assertDatabaseHas('support_tickets', [
            'user_id' => $user->id,
            'category' => 'general',
            'status' => 'open',
        ]);
    }

    public function test_support_ticket_requires_valid_payload(): void
    {
        $user = $this->createUser('support-invalid@example.com');

        Sanctum::actingAs($user);

        $this->postJson('/api/support/tickets', [
            'category' => 'unknown',
            'message' => 'Court',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category', 'message']);
    }

    public function test_user_can_only_see_their_own_tickets(): void
    {
        $owner = $this->createUser('support-owner@example.com');
        $other = $this->createUser('support-other@example.com');

        SupportTicket::query()->create([
            'user_id' => $owner->id,
            'role' => 'passenger',
            'category' => 'account',
            'message' => 'Ticket prive propriétaire.',
            'status' => 'open',
        ]);

        SupportTicket::query()->create([
            'user_id' => $other->id,
            'role' => 'passenger',
            'category' => 'payment',
            'message' => 'Ticket prive autre utilisateur.',
            'status' => 'open',
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/support/tickets')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->assertSame($owner->id, SupportTicket::query()->whereKey($response->json('data.0.id'))->value('user_id'));
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Support User',
            'email' => $email,
            'phone' => '+22990000199',
            'role' => 'passenger',
            'password' => 'password123',
        ]);
    }
}