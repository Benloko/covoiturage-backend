<?php

namespace Tests\Feature;

use App\Models\PhoneVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_and_update_profile(): void
    {
        $user = User::query()->create([
            'name' => 'Driver One',
            'email' => 'driver-profile@example.com',
            'phone' => '0190000111',
            'role' => 'driver',
            'password' => 'password123',
            'vehicle_type' => 'voiture',
            'vehicle_plate' => 'RB-1111-AA',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('user.email', 'driver-profile@example.com');

        $this->putJson('/api/profile', [
            'name' => 'Driver Updated',
            'phone' => '0195551111',
            'vehicle_type' => 'suv',
            'vehicle_plate' => 'RB-2222-CC',
        ])->assertOk()
            ->assertJsonPath('user.name', 'Driver Updated')
            ->assertJsonPath('user.vehicle_type', 'suv');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Driver Updated',
            'phone' => '0195551111',
            'vehicle_type' => 'suv',
            'vehicle_plate' => 'RB-2222-CC',
        ]);
    }

    public function test_profile_update_rejects_invalid_phone_and_plate_formats(): void
    {
        $user = User::query()->create([
            'name' => 'Driver Two',
            'email' => 'driver-profile-2@example.com',
            'phone' => '0190000114',
            'role' => 'driver',
            'password' => 'password123',
            'vehicle_type' => 'voiture',
            'vehicle_plate' => 'RB-3333-DD',
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/profile', [
            'name' => 'Driver Two',
            'phone' => '4133',
            'vehicle_type' => 'voiture',
            'vehicle_plate' => 'AB-1234-XX',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['phone', 'vehicle_plate']);
    }

    public function test_user_can_request_and_confirm_phone_verification_code(): void
    {
        $user = User::query()->create([
            'name' => 'Phone Verify User',
            'email' => 'phone-verify@example.com',
            'phone' => '0146123456',
            'role' => 'passenger',
            'password' => 'password123',
        ]);

        Sanctum::actingAs($user);

        $sendResponse = $this->postJson('/api/profile/phone-verification/send')
            ->assertOk()
            ->assertJsonPath('phone_verification.status', 'unverified')
            ->assertJsonPath('phone_verification.required', true)
            ->assertJsonPath('phone_verification.network', 'mtn_momo');

        $debugCode = (string) $sendResponse->json('debug_code');
        $this->assertNotSame('', $debugCode);

        $this->postJson('/api/profile/phone-verification/confirm', [
            'code' => $debugCode,
        ])
            ->assertOk()
            ->assertJsonPath('phone_verification.status', 'verified')
            ->assertJsonPath('user.phone_verification_status', 'verified')
            ->assertJsonPath('user.phone_verification_required', false);

        $user->refresh();

        $this->assertNotNull($user->phone_verified_at);

        /** @var PhoneVerificationCode $verificationCode */
        $verificationCode = PhoneVerificationCode::query()->latest('id')->firstOrFail();

        $this->assertNotNull($verificationCode->verified_at);
        $this->assertNotNull($verificationCode->consumed_at);
    }

    public function test_phone_verification_rejects_wrong_code(): void
    {
        $user = User::query()->create([
            'name' => 'Wrong Code User',
            'email' => 'wrong-code@example.com',
            'phone' => '0146123499',
            'role' => 'passenger',
            'password' => 'password123',
        ]);

        Sanctum::actingAs($user);

        $sendResponse = $this->postJson('/api/profile/phone-verification/send')
            ->assertOk();

        $debugCode = (string) $sendResponse->json('debug_code');
        $invalidCode = $debugCode === '000000' ? '999999' : '000000';

        $this->postJson('/api/profile/phone-verification/confirm', [
            'code' => $invalidCode,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $user->refresh();
        $this->assertNull($user->phone_verified_at);
    }

    public function test_user_can_upload_and_delete_avatar(): void
    {
        Storage::fake('public');

        $user = User::query()->create([
            'name' => 'Avatar User',
            'email' => 'avatar@example.com',
            'phone' => '0190000112',
            'role' => 'passenger',
            'password' => 'password123',
        ]);

        Sanctum::actingAs($user);

        $uploadResponse = $this->post('/api/profile/avatar', [
            'avatar' => $this->fakePng('avatar.png'),
        ], [
            'Accept' => 'application/json',
        ]);

        $uploadResponse
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $user->refresh();

        $this->assertNotNull($user->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);

        $this->deleteJson('/api/profile/avatar')
            ->assertOk()
            ->assertJsonPath('user.avatar_url', null);

        $user->refresh();
        $this->assertNull($user->avatar_path);
    }

    public function test_user_can_submit_identity_verification_request(): void
    {
        Storage::fake('public');

        $user = User::query()->create([
            'name' => 'Verified User',
            'email' => 'verified@example.com',
            'phone' => '0190000113',
            'role' => 'passenger',
            'password' => 'password123',
        ]);

        Sanctum::actingAs($user);

        $response = $this->post('/api/profile/verification/request', [
            'document_type' => 'national_id',
            'document_number' => 'ID-123456',
            'document_front' => $this->fakePng('front.png'),
            'document_back' => $this->fakePng('back.png'),
            'selfie' => $this->fakePng('selfie.png'),
            'liveness_video' => UploadedFile::fake()->create('liveness.mp4', 2048, 'video/mp4'),
            'auto_liveness_status' => 'passed',
            'auto_liveness_score' => 88.4,
            'auto_face_match_status' => 'passed',
            'auto_face_match_score' => 77.8,
        ], [
            'Accept' => 'application/json',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('verification.document_type', 'national_id');

        $this->assertDatabaseHas('identity_verifications', [
            'user_id' => $user->id,
            'document_type' => 'national_id',
            'status' => 'in_review',
            'liveness_check_status' => 'passed',
            'face_match_status' => 'passed',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'identity_verification_status' => 'pending',
        ]);
    }

    private function fakePng(string $fileName): UploadedFile
    {
        $png1x1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7+5hQAAAAASUVORK5CYII=');

        return UploadedFile::fake()->createWithContent($fileName, $png1x1 ?: '');
    }
}
