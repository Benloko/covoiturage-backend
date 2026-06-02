<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\IdentityVerification;
use App\Models\PhoneVerificationCode;
use App\Models\User;
use App\Models\UserNotification;
use App\Rules\BeninPhoneNumber;
use App\Rules\BeninVehiclePlate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    private const PHONE_VERIFICATION_EXPIRES_MINUTES = 10;

    private const PHONE_VERIFICATION_MAX_ATTEMPTS = 5;

    private const PHONE_VERIFICATION_RESEND_COOLDOWN_SECONDS = 45;

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->serializeUser($user),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', new BeninPhoneNumber()],
            'vehicle_type' => ['nullable', 'string', 'max:50'],
            'vehicle_plate' => ['nullable', 'string', 'max:50', new BeninVehiclePlate()],
        ]);

        $validated['name'] = trim((string) $validated['name']);
        $normalizedPhone = BeninPhoneNumber::normalize($validated['phone']);
        $validated['phone'] = $normalizedPhone ?? (string) $validated['phone'];
        $currentNormalizedPhone = BeninPhoneNumber::normalizeAny((string) $user->phone);
        $phoneChanged = $currentNormalizedPhone !== $validated['phone'];

        if (! empty($validated['vehicle_plate'])) {
            $validated['vehicle_plate'] = BeninVehiclePlate::normalize($validated['vehicle_plate']) ?? (string) $validated['vehicle_plate'];
        }

        if ($user->role !== 'driver') {
            $validated['vehicle_type'] = null;
            $validated['vehicle_plate'] = null;
        }

        if ($phoneChanged) {
            $validated['phone_verified_at'] = null;
        }

        DB::transaction(function () use ($user, $validated, $phoneChanged): void {
            $user->fill($validated);
            $user->save();

            if ($phoneChanged) {
                PhoneVerificationCode::query()
                    ->where('user_id', $user->id)
                    ->whereNull('consumed_at')
                    ->update([
                        'consumed_at' => now(),
                    ]);
            }
        });

        return response()->json([
            'message' => 'Profil mis a jour avec succes.',
            'user' => $this->serializeUser($user->fresh()),
        ]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $avatarPath = $request->file('avatar')->store("avatars/{$user->id}", 'public');

        $user->forceFill([
            'avatar_path' => $avatarPath,
        ])->save();

        return response()->json([
            'message' => 'Photo de profil mise a jour.',
            'user' => $this->serializeUser($user->fresh()),
        ]);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $user->forceFill([
            'avatar_path' => null,
        ])->save();

        return response()->json([
            'message' => 'Photo de profil supprimee.',
            'user' => $this->serializeUser($user->fresh()),
        ]);
    }

    public function verificationStatus(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $verification = $this->latestVerification($user);

        return response()->json([
            'status' => $user->identity_verification_status ?? 'not_started',
            'verification' => $verification ? $this->serializeVerification($verification) : null,
        ]);
    }

    public function requestVerification(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (in_array($user->identity_verification_status, ['pending'], true)) {
            throw ValidationException::withMessages([
                'verification' => ['Une demande de verification est deja en cours.'],
            ]);
        }

        $validated = $request->validate([
            'document_type' => ['required', 'in:national_id,passport,driver_license'],
            'document_number' => ['nullable', 'string', 'max:80'],
            'document_front' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'document_back' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'selfie' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'liveness_video' => ['required', 'file', 'mimes:mp4,mov,avi,webm', 'max:51200'],
            'auto_liveness_status' => ['nullable', 'in:pending,passed,failed,manual_review'],
            'auto_liveness_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'auto_face_match_status' => ['nullable', 'in:pending,passed,failed,manual_review'],
            'auto_face_match_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        if (in_array($validated['document_type'], ['national_id', 'driver_license'], true) && ! $request->hasFile('document_back')) {
            throw ValidationException::withMessages([
                'document_back' => ['Le verso de la piece est obligatoire pour ce type de document.'],
            ]);
        }

        $baseDirectory = sprintf('identity-verifications/%d/%s', $user->id, Str::uuid()->toString());

        $documentFrontPath = $request->file('document_front')->store($baseDirectory, 'public');
        $documentBackPath = $request->hasFile('document_back')
            ? $request->file('document_back')->store($baseDirectory, 'public')
            : null;
        $selfiePath = $request->file('selfie')->store($baseDirectory, 'public');
        $livenessVideoPath = $request->file('liveness_video')->store($baseDirectory, 'public');

        $livenessStatus = in_array($validated['auto_liveness_status'] ?? 'pending', ['passed', 'failed', 'manual_review'], true)
            ? $validated['auto_liveness_status']
            : 'pending';

        $faceMatchStatus = in_array($validated['auto_face_match_status'] ?? 'pending', ['passed', 'failed', 'manual_review'], true)
            ? $validated['auto_face_match_status']
            : 'pending';

        $verification = IdentityVerification::query()->create([
            'user_id' => $user->id,
            'document_type' => $validated['document_type'],
            'document_number' => $validated['document_number'] ?? null,
            'status' => 'in_review',
            'document_front_path' => $documentFrontPath,
            'document_back_path' => $documentBackPath,
            'selfie_path' => $selfiePath,
            'liveness_video_path' => $livenessVideoPath,
            'liveness_check_status' => $livenessStatus,
            'liveness_score' => $validated['auto_liveness_score'] ?? null,
            'face_match_status' => $faceMatchStatus,
            'face_match_score' => $validated['auto_face_match_score'] ?? null,
            'submitted_at' => now(),
        ]);

        $user->forceFill([
            'identity_verification_status' => 'pending',
            'identity_verification_requested_at' => now(),
            'identity_verified_at' => null,
        ])->save();

        return response()->json([
            'message' => 'Demande de verification envoyee avec succes.',
            'status' => 'pending',
            'verification' => $this->serializeVerification($verification),
            'user' => $this->serializeUser($user->fresh()),
        ], 201);
    }

    public function sendPhoneVerificationCode(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->phone_verified_at !== null) {
            return response()->json([
                'message' => 'Ce numero est deja verifie.',
                'phone_verification' => $this->serializePhoneVerificationState($user),
            ]);
        }

        $recentActiveCode = PhoneVerificationCode::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($recentActiveCode?->sent_at && $recentActiveCode->sent_at->diffInSeconds(now()) < self::PHONE_VERIFICATION_RESEND_COOLDOWN_SECONDS) {
            throw ValidationException::withMessages([
                'phone' => ['Veuillez patienter quelques secondes avant de demander un nouveau code.'],
            ]);
        }

        $plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        /** @var array{user: User, code: PhoneVerificationCode} $result */
        $result = DB::transaction(function () use ($user, $plainCode): array {
            /** @var User $lockedUser */
            $lockedUser = User::query()
                ->lockForUpdate()
                ->whereKey($user->id)
                ->firstOrFail();

            $normalizedPhone = BeninPhoneNumber::normalizeAny((string) $lockedUser->phone);

            if (! $normalizedPhone || ! BeninPhoneNumber::detectNetwork($normalizedPhone)) {
                throw ValidationException::withMessages([
                    'phone' => ['Le numero de profil doit contenir 10 chiffres et commencer par un prefixe mobile valide au Benin.'],
                ]);
            }

            $phoneChangedByNormalization = $lockedUser->phone !== $normalizedPhone;

            if ($phoneChangedByNormalization) {
                $lockedUser->forceFill([
                    'phone' => $normalizedPhone,
                    'phone_verified_at' => null,
                ])->save();
            }

            PhoneVerificationCode::query()
                ->where('user_id', $lockedUser->id)
                ->whereNull('consumed_at')
                ->update([
                    'consumed_at' => now(),
                ]);

            $verificationCode = PhoneVerificationCode::query()->create([
                'user_id' => $lockedUser->id,
                'phone' => $normalizedPhone,
                'code_hash' => Hash::make($plainCode),
                'attempts' => 0,
                'max_attempts' => self::PHONE_VERIFICATION_MAX_ATTEMPTS,
                'expires_at' => now()->addMinutes(self::PHONE_VERIFICATION_EXPIRES_MINUTES),
                'sent_at' => now(),
            ]);

            $network = BeninPhoneNumber::detectNetwork($normalizedPhone);

            $this->createUserNotification(
                $lockedUser,
                'phone_verification_code_sent',
                'Code de verification envoye',
                'Un code de verification a ete envoye sur votre numero '.$normalizedPhone.'.',
                [
                    'phone' => $normalizedPhone,
                    'network' => $network,
                    'network_label' => $network ? BeninPhoneNumber::networkLabel($network) : null,
                    'expires_at' => $verificationCode->expires_at,
                ]
            );

            return [
                'user' => $lockedUser->fresh(),
                'code' => $verificationCode,
            ];
        });

        logger()->info('Phone verification code generated', [
            'user_id' => $result['user']->id,
            'phone' => $result['code']->phone,
        ]);

        $response = [
            'message' => 'Code de verification envoye avec succes.',
            'phone_verification' => [
                ...$this->serializePhoneVerificationState($result['user']),
                'expires_at' => $result['code']->expires_at,
            ],
        ];

        if (config('app.debug')) {
            $response['debug_code'] = $plainCode;
        }

        return response()->json($response);
    }

    public function confirmPhoneVerificationCode(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $submittedCode = (string) $validated['code'];

        $updatedUser = DB::transaction(function () use ($user, $submittedCode): User {
            /** @var User $lockedUser */
            $lockedUser = User::query()
                ->lockForUpdate()
                ->whereKey($user->id)
                ->firstOrFail();

            if ($lockedUser->phone_verified_at !== null) {
                return $lockedUser;
            }

            $normalizedPhone = BeninPhoneNumber::normalizeAny((string) $lockedUser->phone);

            if (! $normalizedPhone || ! BeninPhoneNumber::detectNetwork($normalizedPhone)) {
                throw ValidationException::withMessages([
                    'phone' => ['Le numero de profil doit contenir 10 chiffres et commencer par un prefixe mobile valide au Benin.'],
                ]);
            }

            if ($lockedUser->phone !== $normalizedPhone) {
                $lockedUser->forceFill([
                    'phone' => $normalizedPhone,
                ])->save();
            }

            /** @var PhoneVerificationCode|null $verificationCode */
            $verificationCode = PhoneVerificationCode::query()
                ->lockForUpdate()
                ->where('user_id', $lockedUser->id)
                ->where('phone', $normalizedPhone)
                ->whereNull('consumed_at')
                ->latest('id')
                ->first();

            if (! $verificationCode) {
                throw ValidationException::withMessages([
                    'code' => ['Aucun code actif trouve. Demandez un nouveau code.'],
                ]);
            }

            if ($verificationCode->expires_at !== null && $verificationCode->expires_at->isPast()) {
                $verificationCode->forceFill([
                    'consumed_at' => now(),
                ])->save();

                throw ValidationException::withMessages([
                    'code' => ['Le code a expire. Demandez un nouveau code.'],
                ]);
            }

            if ((int) $verificationCode->attempts >= (int) $verificationCode->max_attempts) {
                $verificationCode->forceFill([
                    'consumed_at' => $verificationCode->consumed_at ?? now(),
                ])->save();

                throw ValidationException::withMessages([
                    'code' => ['Le nombre maximal de tentatives est atteint. Demandez un nouveau code.'],
                ]);
            }

            if (! Hash::check($submittedCode, (string) $verificationCode->code_hash)) {
                $attempts = (int) $verificationCode->attempts + 1;

                $verificationCode->forceFill([
                    'attempts' => $attempts,
                    'consumed_at' => $attempts >= (int) $verificationCode->max_attempts ? now() : null,
                ])->save();

                throw ValidationException::withMessages([
                    'code' => ['Code incorrect.'],
                ]);
            }

            $verificationCode->forceFill([
                'attempts' => (int) $verificationCode->attempts + 1,
                'verified_at' => now(),
                'consumed_at' => now(),
            ])->save();

            $lockedUser->forceFill([
                'phone_verified_at' => now(),
            ])->save();

            $this->createUserNotification(
                $lockedUser,
                'phone_verified',
                'Numero verifie',
                'Votre numero de telephone a ete verifie avec succes.',
                [
                    'phone' => $normalizedPhone,
                ]
            );

            return $lockedUser->fresh();
        });

        return response()->json([
            'message' => 'Numero verifie avec succes.',
            'phone_verification' => $this->serializePhoneVerificationState($updatedUser),
            'user' => $this->serializeUser($updatedUser),
        ]);
    }

    private function serializeUser(User $user): array
    {
        $verification = $this->latestVerification($user);
        $phoneNetwork = BeninPhoneNumber::detectNetwork((string) $user->phone, true);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'phone_verified_at' => $user->phone_verified_at,
            'phone_verification_status' => $user->phone_verified_at ? 'verified' : 'unverified',
            'phone_verification_required' => $user->phone_verified_at === null,
            'phone_network' => $phoneNetwork,
            'phone_network_label' => $phoneNetwork
                ? BeninPhoneNumber::networkLabel($phoneNetwork)
                : null,
            'role' => $user->role,
            'vehicle_type' => $user->vehicle_type,
            'vehicle_plate' => $user->vehicle_plate,
            'avatar_url' => $this->avatarUrl($user->avatar_path),
            'identity_verification_status' => $user->identity_verification_status,
            'identity_verification_requested_at' => $user->identity_verification_requested_at,
            'identity_verified_at' => $user->identity_verified_at,
            'wallet' => $this->serializeWallet($user),
            'phone_verification' => $this->serializePhoneVerificationState($user),
            'latest_verification' => $verification ? $this->serializeVerification($verification) : null,
            'created_at' => $user->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePhoneVerificationState(User $user): array
    {
        $network = BeninPhoneNumber::detectNetwork((string) $user->phone, true);

        return [
            'phone' => $user->phone,
            'status' => $user->phone_verified_at ? 'verified' : 'unverified',
            'verified_at' => $user->phone_verified_at,
            'required' => $user->phone_verified_at === null,
            'network' => $network,
            'network_label' => $network ? BeninPhoneNumber::networkLabel($network) : null,
        ];
    }

    private function serializeWallet(User $user): ?array
    {
        if ($user->role === 'driver') {
            $available = (int) ($user->driver_wallet_balance_fcfa ?? 0);
            $pending = (int) Booking::query()
                ->join('trips', 'trips.id', '=', 'bookings.trip_id')
                ->where('trips.driver_id', $user->id)
                ->where('bookings.payout_status', 'pending_confirmation')
                ->sum('bookings.payout_amount_fcfa');

            return [
                'available_balance_fcfa' => $available,
                'pending_balance_fcfa' => $pending,
                'total_balance_fcfa' => $available + $pending,
                'commission_rate_percent' => 5,
                'withdrawal_phone' => $user->phone,
            ];
        }

        if ($user->role === 'passenger') {
            $available = (int) ($user->passenger_wallet_balance_fcfa ?? 0);
            $network = BeninPhoneNumber::detectNetwork((string) $user->phone, true);

            return [
                'available_balance_fcfa' => $available,
                'pending_balance_fcfa' => 0,
                'total_balance_fcfa' => $available,
                'minimum_deposit_fcfa' => 500,
                'recharge_phone' => $user->phone,
                'recharge_network' => $network,
                'recharge_network_label' => $network ? BeninPhoneNumber::networkLabel($network) : null,
                'recharge_network_supported' => $network !== null,
                'can_book_when_balance_sufficient' => true,
            ];
        }

        return null;
    }

    private function serializeVerification(IdentityVerification $verification): array
    {
        return [
            'id' => $verification->id,
            'document_type' => $verification->document_type,
            'status' => $verification->status,
            'liveness_check_status' => $verification->liveness_check_status,
            'liveness_score' => $verification->liveness_score,
            'face_match_status' => $verification->face_match_status,
            'face_match_score' => $verification->face_match_score,
            'review_notes' => $verification->review_notes,
            'submitted_at' => $verification->submitted_at,
            'reviewed_at' => $verification->reviewed_at,
        ];
    }

    private function avatarUrl(?string $avatarPath): ?string
    {
        if (! $avatarPath) {
            return null;
        }

        return rtrim(request()->getSchemeAndHttpHost(), '/').'/storage/'.ltrim($avatarPath, '/');
    }

    private function latestVerification(User $user): ?IdentityVerification
    {
        return $user->identityVerifications()->latest('id')->first();
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
