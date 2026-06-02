<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverVehicleDocumentsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_can_view_requirements_based_on_vehicle_type(): void
    {
        $driver = $this->createDriver('voiture', 'driver-docs-voiture@example.com');

        Sanctum::actingAs($driver);

        $this->getJson('/api/driver/vehicle-documents/requirements')
            ->assertOk()
            ->assertJsonPath('vehicle_type', 'voiture')
            ->assertJsonPath('requirements.0.type', 'assurance')
            ->assertJsonPath('requirements.2.type', 'visite_technique');

        $driverMoto = $this->createDriver('moto', 'driver-docs-moto@example.com');
        Sanctum::actingAs($driverMoto);

        $this->getJson('/api/driver/vehicle-documents/requirements')
            ->assertOk()
            ->assertJsonPath('vehicle_type', 'moto')
            ->assertJsonPath('requirements.0.type', 'assurance')
            ->assertJsonPath('requirements.1.type', 'carte_grise_moto');
    }

    public function test_driver_can_submit_vehicle_document_with_expiration_and_photo(): void
    {
        Storage::fake('public');

        $driver = $this->createDriver('voiture', 'driver-docs-submit@example.com');

        Sanctum::actingAs($driver);

        $this->post('/api/driver/vehicle-documents', [
            'document_type' => 'assurance',
            'document_number' => 'ASS-2026-001',
            'expires_at' => now()->addMonths(10)->toDateString(),
            'document_photo' => $this->fakePng('assurance.png'),
        ], [
            'Accept' => 'application/json',
        ])
            ->assertCreated()
            ->assertJsonPath('document.document_type', 'assurance')
            ->assertJsonPath('document.document_label', 'Assurance vehicule')
            ->assertJsonPath('document.is_expired', false);

        $this->assertDatabaseHas('driver_vehicle_documents', [
            'user_id' => $driver->id,
            'document_type' => 'assurance',
            'document_label' => 'Assurance vehicule',
            'status' => 'pending_review',
        ]);

        $this->getJson('/api/driver/vehicle-documents')
            ->assertOk()
            ->assertJsonPath('stats.required_count', 4)
            ->assertJsonPath('stats.submitted_count', 1)
            ->assertJsonPath('stats.missing_count', 3)
            ->assertJsonPath('requirements.0.type', 'assurance')
            ->assertJsonPath('requirements.0.document.document_type', 'assurance');
    }

    public function test_passenger_cannot_access_driver_vehicle_documents_endpoints(): void
    {
        $passenger = User::query()->create([
            'name' => 'Passenger User',
            'email' => 'passenger-docs@example.com',
            'phone' => '0146123400',
            'role' => 'passenger',
            'password' => 'password123',
        ]);

        Sanctum::actingAs($passenger);

        $this->getJson('/api/driver/vehicle-documents')
            ->assertForbidden()
            ->assertJsonPath('message', 'Acces reserve aux conducteurs.');

        $this->postJson('/api/driver/vehicle-documents', [
            'document_type' => 'assurance',
            'expires_at' => now()->addMonth()->toDateString(),
        ])->assertForbidden();
    }

    public function test_submission_validation_depends_on_vehicle_type_and_requires_photo_for_new_document(): void
    {
        $driverMoto = $this->createDriver('moto', 'driver-docs-validation@example.com');

        Sanctum::actingAs($driverMoto);

        $this->postJson('/api/driver/vehicle-documents', [
            'document_type' => 'visite_technique',
            'expires_at' => now()->addMonth()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document_type']);

        $this->postJson('/api/driver/vehicle-documents', [
            'document_type' => 'assurance',
            'expires_at' => now()->addMonth()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document_photo']);
    }

    private function createDriver(string $vehicleType, string $email): User
    {
        return User::query()->create([
            'name' => 'Driver User',
            'email' => $email,
            'phone' => '0146123456',
            'role' => 'driver',
            'password' => 'password123',
            'vehicle_type' => $vehicleType,
            'vehicle_plate' => 'RB-1234-AA',
        ]);
    }

    private function fakePng(string $fileName): UploadedFile
    {
        $png1x1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7+5hQAAAAASUVORK5CYII=');

        return UploadedFile::fake()->createWithContent($fileName, $png1x1 ?: '');
    }
}

