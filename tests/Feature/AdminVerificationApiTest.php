<?php

namespace Tests\Feature;

use App\Models\IdentityVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminVerificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access_admin_verification_endpoints(): void
    {
        config()->set('admin.emails', ['admin@shareride.local']);

        $user = User::query()->create([
            'name' => 'Simple User',
            'email' => 'user@example.com',
            'phone' => '+22990001001',
            'role' => 'passenger',
            'password' => 'password123',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/admin/verifications')
            ->assertForbidden();
    }

    public function test_admin_can_approve_verification_and_user_status_is_updated(): void
    {
        config()->set('admin.emails', ['admin@shareride.local']);

        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@shareride.local',
            'phone' => '+22990001002',
            'role' => 'passenger',
            'password' => 'password123',
        ]);

        $candidate = User::query()->create([
            'name' => 'Candidate User',
            'email' => 'candidate@example.com',
            'phone' => '+22990001003',
            'role' => 'driver',
            'password' => 'password123',
            'identity_verification_status' => 'pending',
        ]);

        $verification = IdentityVerification::query()->create([
            'user_id' => $candidate->id,
            'document_type' => 'passport',
            'status' => 'in_review',
            'document_front_path' => 'identity-verifications/front.png',
            'selfie_path' => 'identity-verifications/selfie.png',
            'liveness_video_path' => 'identity-verifications/video.mp4',
            'liveness_check_status' => 'pending',
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/verifications')
            ->assertOk()
            ->assertJsonPath('data.0.id', $verification->id);

        $this->patchJson('/api/admin/verifications/'.$verification->id, [
            'decision' => 'approved',
            'liveness_check_status' => 'passed',
            'liveness_score' => 97.5,
        ])
            ->assertOk()
            ->assertJsonPath('verification.status', 'approved');

        $this->assertDatabaseHas('identity_verifications', [
            'id' => $verification->id,
            'status' => 'approved',
            'liveness_check_status' => 'passed',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $candidate->id,
            'identity_verification_status' => 'verified',
        ]);
    }

    public function test_admin_can_reject_verification_with_review_notes(): void
    {
        config()->set('admin.emails', ['admin@shareride.local']);

        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@shareride.local',
            'phone' => '+22990001004',
            'role' => 'passenger',
            'password' => 'password123',
        ]);

        $candidate = User::query()->create([
            'name' => 'Second Candidate',
            'email' => 'candidate2@example.com',
            'phone' => '+22990001005',
            'role' => 'passenger',
            'password' => 'password123',
            'identity_verification_status' => 'pending',
        ]);

        $verification = IdentityVerification::query()->create([
            'user_id' => $candidate->id,
            'document_type' => 'national_id',
            'status' => 'in_review',
            'document_front_path' => 'identity-verifications/front-2.png',
            'document_back_path' => 'identity-verifications/back-2.png',
            'selfie_path' => 'identity-verifications/selfie-2.png',
            'liveness_video_path' => 'identity-verifications/video-2.mp4',
            'liveness_check_status' => 'pending',
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/admin/verifications/'.$verification->id, [
            'decision' => 'rejected',
            'review_notes' => 'Document flou, merci de renvoyer une photo nette.',
        ])
            ->assertOk()
            ->assertJsonPath('verification.status', 'rejected')
            ->assertJsonPath('verification.review_notes', 'Document flou, merci de renvoyer une photo nette.');

        $this->assertDatabaseHas('users', [
            'id' => $candidate->id,
            'identity_verification_status' => 'rejected',
        ]);
    }
}
