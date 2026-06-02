<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\BeninPhoneNumber;
use App\Rules\BeninVehiclePlate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:filter', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', new BeninPhoneNumber()],
            'role' => ['required', Rule::in(['driver', 'passenger'])],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'vehicle_type' => ['nullable', 'string', 'max:50', 'required_if:role,driver'],
            'vehicle_plate' => ['nullable', 'string', 'max:50', 'required_if:role,driver', new BeninVehiclePlate()],
        ]);

        $validated['email'] = strtolower(trim((string) $validated['email']));
        $validated['phone'] = BeninPhoneNumber::normalize($validated['phone']) ?? (string) $validated['phone'];

        if (! empty($validated['vehicle_plate'])) {
            $validated['vehicle_plate'] = BeninVehiclePlate::normalize($validated['vehicle_plate']) ?? (string) $validated['vehicle_plate'];
        }

        if ($validated['role'] !== 'driver') {
            $validated['vehicle_type'] = null;
            $validated['vehicle_plate'] = null;
        }

        $validated['phone_verified_at'] = null;

        $user = User::query()->create($validated);

        return response()->json([
            'message' => 'Inscription reussie. Connectez-vous maintenant.',
            'user' => $this->serializeUser($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:filter'],
            'password' => ['required', 'string'],
            'role' => ['nullable', Rule::in(['driver', 'passenger'])],
        ]);

        $email = strtolower(trim((string) $validated['email']));

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Identifiants invalides.',
            ], 422);
        }

        if (isset($validated['role']) && $validated['role'] !== $user->role) {
            return response()->json([
                'message' => 'Ce compte ne correspond pas au role selectionne.',
            ], 422);
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Connexion reussie.',
            'token' => $token,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->serializeUser($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Deconnexion reussie.',
        ]);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'phone_verified_at' => $user->phone_verified_at,
            'phone_verification_status' => $user->phone_verified_at ? 'verified' : 'unverified',
            'phone_verification_required' => $user->phone_verified_at === null,
            'role' => $user->role,
            'vehicle_type' => $user->vehicle_type,
            'vehicle_plate' => $user->vehicle_plate,
            'avatar_url' => $this->avatarUrl($user->avatar_path),
            'identity_verification_status' => $user->identity_verification_status,
            'identity_verification_requested_at' => $user->identity_verification_requested_at,
            'identity_verified_at' => $user->identity_verified_at,
            'created_at' => $user->created_at,
        ];
    }

    private function avatarUrl(?string $avatarPath): ?string
    {
        if (! $avatarPath) {
            return null;
        }

        return rtrim(request()->getSchemeAndHttpHost(), '/').'/storage/'.ltrim($avatarPath, '/');
    }
}
