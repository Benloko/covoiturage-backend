<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DriverVehicleDocument;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DriverVehicleDocumentController extends Controller
{
    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private const REQUIRED_DOCUMENTS = [
        'moto' => [
            [
                'type' => 'assurance',
                'label' => 'Assurance moto',
                'description' => 'Attestation d\'assurance de la moto en cours de validite.',
            ],
            [
                'type' => 'carte_grise_moto',
                'label' => 'Carte grise moto',
                'description' => 'Carte grise ou preuve d\'immatriculation de la moto.',
            ],
            [
                'type' => 'permis_a',
                'label' => 'Permis de conduire A',
                'description' => 'Permis valide pour conduire une moto.',
            ],
        ],
        'voiture' => [
            [
                'type' => 'assurance',
                'label' => 'Assurance vehicule',
                'description' => 'Attestation d\'assurance automobile en cours de validite.',
            ],
            [
                'type' => 'carte_grise',
                'label' => 'Carte grise',
                'description' => 'Carte grise du vehicule utilise sur la plateforme.',
            ],
            [
                'type' => 'visite_technique',
                'label' => 'Visite technique',
                'description' => 'Preuve de visite technique valide.',
            ],
            [
                'type' => 'permis_b',
                'label' => 'Permis de conduire B',
                'description' => 'Permis valide correspondant au type de vehicule.',
            ],
        ],
        'minibus' => [
            [
                'type' => 'assurance',
                'label' => 'Assurance vehicule',
                'description' => 'Attestation d\'assurance du minibus en cours de validite.',
            ],
            [
                'type' => 'carte_grise',
                'label' => 'Carte grise',
                'description' => 'Carte grise du minibus.',
            ],
            [
                'type' => 'visite_technique',
                'label' => 'Visite technique',
                'description' => 'Preuve de visite technique valide.',
            ],
            [
                'type' => 'permis_d',
                'label' => 'Permis de conduire D',
                'description' => 'Permis valide pour le transport de passagers.',
            ],
            [
                'type' => 'autorisation_transport',
                'label' => 'Autorisation de transport',
                'description' => 'Autorisation ou licence de transport en cours de validite.',
            ],
        ],
    ];

    public function requirements(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensureDriver($user)) {
            return $response;
        }

        $vehicleType = $this->resolveVehicleType($user);

        return response()->json([
            'vehicle_type' => $vehicleType,
            'requirements' => $this->requirementsForVehicleType($vehicleType),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensureDriver($user)) {
            return $response;
        }

        $vehicleType = $this->resolveVehicleType($user);
        $requirements = $this->requirementsForVehicleType($vehicleType);
        $allowedTypes = collect($requirements)->pluck('type')->all();

        $documents = DriverVehicleDocument::query()
            ->where('user_id', $user->id)
            ->whereIn('document_type', $allowedTypes)
            ->orderBy('document_label')
            ->get()
            ->keyBy('document_type');

        $submitted = 0;
        $expired = 0;
        $items = [];

        foreach ($requirements as $requirement) {
            /** @var DriverVehicleDocument|null $document */
            $document = $documents->get($requirement['type']);

            if ($document) {
                $submitted++;

                if ($this->documentIsExpired($document)) {
                    $expired++;
                }
            }

            $items[] = [
                ...$requirement,
                'document' => $document ? $this->serializeDocument($document) : null,
                'missing' => $document === null,
                'is_expired' => $document ? $this->documentIsExpired($document) : false,
            ];
        }

        $requiredCount = count($requirements);

        return response()->json([
            'vehicle_type' => $vehicleType,
            'requirements' => $items,
            'stats' => [
                'required_count' => $requiredCount,
                'submitted_count' => $submitted,
                'missing_count' => max(0, $requiredCount - $submitted),
                'expired_count' => $expired,
                'valid_count' => max(0, $submitted - $expired),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensureDriver($user)) {
            return $response;
        }

        $vehicleType = $this->resolveVehicleType($user);
        $requirements = $this->requirementsForVehicleType($vehicleType);
        $requirementsByType = collect($requirements)
            ->keyBy('type');
        $allowedTypes = $requirementsByType->keys()->all();

        $validatedType = $request->validate([
            'document_type' => ['required', 'string', Rule::in($allowedTypes)],
        ]);

        $documentType = (string) $validatedType['document_type'];

        /** @var DriverVehicleDocument|null $existing */
        $existing = DriverVehicleDocument::query()
            ->where('user_id', $user->id)
            ->where('document_type', $documentType)
            ->first();

        $validated = $request->validate([
            'document_type' => ['required', 'string', Rule::in($allowedTypes)],
            'document_number' => ['nullable', 'string', 'max:80'],
            'expires_at' => ['required', 'date', 'after_or_equal:today'],
            'document_photo' => [
                $existing ? 'nullable' : 'required',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:10240',
            ],
        ]);

        $documentPhotoPath = $existing?->document_photo_path;

        if ($request->hasFile('document_photo')) {
            $path = $request->file('document_photo')->store(
                sprintf('vehicle-documents/%d/%s/%s', $user->id, $documentType, Str::uuid()->toString()),
                'public'
            );

            if ($existing?->document_photo_path) {
                Storage::disk('public')->delete($existing->document_photo_path);
            }

            $documentPhotoPath = $path;
        }

        if (! $documentPhotoPath) {
            throw ValidationException::withMessages([
                'document_photo' => ['Une photo du document est obligatoire.'],
            ]);
        }

        $requirement = $requirementsByType->get($documentType);
        $statusCode = $existing ? 200 : 201;

        $document = DriverVehicleDocument::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'document_type' => $documentType,
            ],
            [
                'vehicle_type_scope' => $vehicleType,
                'document_label' => (string) ($requirement['label'] ?? $documentType),
                'document_number' => $validated['document_number'] ?? null,
                'expires_at' => $validated['expires_at'],
                'document_photo_path' => $documentPhotoPath,
                'status' => 'pending_review',
                'review_notes' => null,
                'submitted_at' => now(),
            ]
        );

        $this->createUserNotification(
            $user,
            'driver_vehicle_document_submitted',
            'Document vehicule enregistre',
            'Votre document "'.$document->document_label.'" a ete enregistre et sera verifie.',
            [
                'driver_vehicle_document_id' => $document->id,
                'document_type' => $document->document_type,
                'expires_at' => $document->expires_at,
                'status' => $document->status,
            ]
        );

        return response()->json([
            'message' => 'Document vehicule enregistre avec succes.',
            'document' => $this->serializeDocument($document),
        ], $statusCode);
    }

    private function ensureDriver(User $user): ?JsonResponse
    {
        if ($user->role === 'driver') {
            return null;
        }

        return response()->json([
            'message' => 'Acces reserve aux conducteurs.',
        ], 403);
    }

    private function resolveVehicleType(User $user): string
    {
        $value = strtolower(trim((string) ($user->vehicle_type ?? '')));

        return in_array($value, ['moto', 'voiture', 'minibus'], true)
            ? $value
            : 'voiture';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function requirementsForVehicleType(string $vehicleType): array
    {
        return self::REQUIRED_DOCUMENTS[$vehicleType]
            ?? self::REQUIRED_DOCUMENTS['voiture'];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDocument(DriverVehicleDocument $document): array
    {
        return [
            'id' => $document->id,
            'vehicle_type_scope' => $document->vehicle_type_scope,
            'document_type' => $document->document_type,
            'document_label' => $document->document_label,
            'document_number' => $document->document_number,
            'expires_at' => optional($document->expires_at)?->toDateString(),
            'document_photo_url' => $this->storageUrl($document->document_photo_path),
            'status' => $document->status,
            'review_notes' => $document->review_notes,
            'submitted_at' => $document->submitted_at,
            'is_expired' => $this->documentIsExpired($document),
        ];
    }

    private function documentIsExpired(DriverVehicleDocument $document): bool
    {
        $expiresAt = $document->expires_at;

        if (! $expiresAt) {
            return false;
        }

        return $expiresAt->toDateString() < now()->toDateString();
    }

    private function storageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return rtrim(request()->getSchemeAndHttpHost(), '/').'/storage/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createUserNotification(?User $user, string $type, string $title, string $message, array $data = []): void
    {
        if (! $user) {
            return;
        }

        UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }
}

