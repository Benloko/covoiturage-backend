<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_fetch_default_settings(): void
    {
        $user = User::query()->create([
            'name' => 'Settings User',
            'email' => 'settings-default@example.com',
            'phone' => '+22990000144',
            'role' => 'passenger',
            'password' => 'password123',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('settings.notifications.push_trips', true)
            ->assertJsonPath('settings.notifications.push_payments', true)
            ->assertJsonPath('settings.notifications.newsletter', false)
            ->assertJsonPath('settings.notifications.sms_alerts', false);
    }

    public function test_authenticated_user_can_update_settings(): void
    {
        $user = User::query()->create([
            'name' => 'Settings User 2',
            'email' => 'settings-update@example.com',
            'phone' => '+22990000145',
            'role' => 'passenger',
            'password' => 'password123',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/settings', [
            'notifications' => [
                'push_trips' => false,
                'push_payments' => true,
                'newsletter' => true,
                'sms_alerts' => true,
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('settings.notifications.push_trips', false)
            ->assertJsonPath('settings.notifications.push_payments', true)
            ->assertJsonPath('settings.notifications.newsletter', true)
            ->assertJsonPath('settings.notifications.sms_alerts', true);

        $user->refresh();

        $this->assertSame([
            'push_trips' => false,
            'push_payments' => true,
            'newsletter' => true,
            'sms_alerts' => true,
        ], $user->settings['notifications'] ?? []);
    }

    public function test_authenticated_user_can_change_password_with_current_password(): void
    {
        $user = User::query()->create([
            'name' => 'Settings User 3',
            'email' => 'settings-password@example.com',
            'phone' => '+22990000146',
            'role' => 'passenger',
            'password' => 'password123',
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/settings/password', [
            'current_password' => 'password123',
            'new_password' => 'new-password-123',
            'new_password_confirmation' => 'new-password-123',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Mot de passe mis a jour avec succes.');

        $user->refresh();

        $this->assertTrue(Hash::check('new-password-123', $user->password));
        $this->assertFalse(Hash::check('password123', $user->password));
    }

    public function test_change_password_fails_when_current_password_is_invalid(): void
    {
        $user = User::query()->create([
            'name' => 'Settings User 4',
            'email' => 'settings-password-invalid@example.com',
            'phone' => '+22990000147',
            'role' => 'passenger',
            'password' => 'password123',
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/settings/password', [
            'current_password' => 'wrong-password',
            'new_password' => 'another-password-123',
            'new_password_confirmation' => 'another-password-123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }
}
