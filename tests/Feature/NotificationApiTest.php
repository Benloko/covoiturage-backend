<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_list_notifications_with_unread_count(): void
    {
        $user = $this->createUser('notifications-list@example.com');

        UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => 'system',
            'title' => 'Bienvenue',
            'message' => 'Votre compte est actif.',
        ]);

        UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => 'booking_confirmed',
            'title' => 'Reservation confirmee',
            'message' => 'Votre reservation est confirmee.',
            'read_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.title', 'Reservation confirmee')
            ->assertJsonPath('data.1.title', 'Bienvenue');
    }

    public function test_user_can_mark_single_notification_as_read(): void
    {
        $user = $this->createUser('notifications-read@example.com');

        $notification = UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => 'system',
            'title' => 'Info',
            'message' => 'Message test',
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/notifications/'.$notification->id.'/read')
            ->assertOk()
            ->assertJsonPath('notification.id', $notification->id)
            ->assertJsonPath('unread_count', 0);

        $this->assertDatabaseMissing('user_notifications', [
            'id' => $notification->id,
            'read_at' => null,
        ]);
    }

    public function test_user_can_mark_all_notifications_as_read_and_clear_them(): void
    {
        $user = $this->createUser('notifications-read-all@example.com');

        UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => 'system',
            'title' => 'A',
            'message' => 'A',
        ]);

        UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => 'system',
            'title' => 'B',
            'message' => 'B',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated', 2)
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, UserNotification::query()->where('user_id', $user->id)->whereNull('read_at')->count());

        $this->deleteJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('deleted', 2)
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, UserNotification::query()->where('user_id', $user->id)->count());
    }

    public function test_user_cannot_mark_notification_of_another_user(): void
    {
        $owner = $this->createUser('notifications-owner@example.com');
        $other = $this->createUser('notifications-other@example.com');

        $notification = UserNotification::query()->create([
            'user_id' => $owner->id,
            'type' => 'system',
            'title' => 'Owner',
            'message' => 'Owner message',
        ]);

        Sanctum::actingAs($other);

        $this->patchJson('/api/notifications/'.$notification->id.'/read')
            ->assertForbidden()
            ->assertJsonPath('message', 'Cette notification ne vous appartient pas.');
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Notif User',
            'email' => $email,
            'phone' => '+22990000188',
            'role' => 'passenger',
            'password' => 'password123',
        ]);
    }
}

