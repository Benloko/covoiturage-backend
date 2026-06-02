<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_without_being_logged_in(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '0190000001',
            'role' => 'passenger',
            'password' => 'password123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.email', 'jane@example.com')
            ->assertJsonMissingPath('token')
            ->assertJsonStructure([
                'message',
                'user' => ['id', 'name', 'email', 'phone', 'role'],
            ]);
    }

    public function test_registration_rejects_invalid_email_phone_and_vehicle_plate_formats(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Invalid Driver',
            'email' => 'bertin@gmailcom',
            'phone' => '4133',
            'role' => 'driver',
            'password' => 'password123',
            'vehicle_type' => 'voiture',
            'vehicle_plate' => 'AB-1234-XX',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phone', 'vehicle_plate']);
    }

    public function test_registration_rejects_phone_with_unknown_prefix(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Unknown Prefix',
            'email' => 'unknown-prefix@example.com',
            'phone' => '0110000000',
            'role' => 'passenger',
            'password' => 'password123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_user_can_login_and_access_profile_then_logout(): void
    {
        $user = User::query()->create([
            'name' => 'Driver One',
            'email' => 'driver@example.com',
            'phone' => '0190000002',
            'role' => 'driver',
            'password' => 'password123',
            'vehicle_type' => 'voiture',
            'vehicle_plate' => 'RB-1234-AA',
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'driver@example.com',
            'password' => 'password123',
            'role' => 'driver',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['token', 'user']);

        $token = $loginResponse->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'driver@example.com');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Deconnexion reussie.');
    }
}
