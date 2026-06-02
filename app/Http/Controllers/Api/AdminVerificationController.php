<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IdentityVerification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminVerificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'status' => ['nullable', 'in:in_review,approved,rejected'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = IdentityVerification::query()
            ->with('user')
            ->latest('submitted_at');

        $status = $validated['status'] ?? 'in_review';
        $query->where('status', $status);

        $perPage = $validated['per_page'] ?? 15;
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (IdentityVerification $verification): array => $this->serializeListItem($verification))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'status' => $status,
            ],
        ]);
    }

    public function show(Request $request, IdentityVerification $verification): JsonResponse
    {
        $this->ensureAdmin($request);

        $verification->load('user');

        return response()->json([
            'verification' => $this->serializeDetail($verification),
        ]);
    }

    public function review(Request $request, IdentityVerification $verification): JsonResponse
    {
        $this->ensureAdmin($request);

        if ($verification->status !== 'in_review') {
            throw ValidationException::withMessages([
                'verification' => ['Cette demande a deja ete traitee.'],
            ]);
        }

        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'review_notes' => ['nullable', 'string', 'max:2000', 'required_if:decision,rejected'],
            'liveness_check_status' => ['nullable', 'in:passed,failed,manual_review'],
            'liveness_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'face_match_status' => ['nullable', 'in:pending,passed,failed,manual_review'],
            'face_match_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $decision = $validated['decision'];
        $livenessStatus = $validated['liveness_check_status'] ?? ($decision === 'approved' ? 'passed' : 'failed');

        $verification->forceFill([
            'status' => $decision,
            'liveness_check_status' => $livenessStatus,
            'liveness_score' => $validated['liveness_score'] ?? $verification->liveness_score,
            'face_match_status' => $validated['face_match_status'] ?? $verification->face_match_status,
            'face_match_score' => $validated['face_match_score'] ?? $verification->face_match_score,
            'review_notes' => $validated['review_notes'] ?? null,
            'reviewed_at' => now(),
        ])->save();

        $verification->load('user');

        /** @var User|null $user */
        $user = $verification->user;

        if ($user) {
            $user->forceFill([
                'identity_verification_status' => $decision === 'approved' ? 'verified' : 'rejected',
                'identity_verified_at' => $decision === 'approved' ? now() : null,
            ])->save();
        }

        return response()->json([
            'message' => $decision === 'approved'
                ? 'Verification approuvee avec succes.'
                : 'Verification rejetee.',
            'verification' => $this->serializeDetail($verification->fresh(['user'])),
        ]);
    }

    private function serializeListItem(IdentityVerification $verification): array
    {
        $user = $verification->user;

        return [
            'id' => $verification->id,
            'document_type' => $verification->document_type,
            'status' => $verification->status,
            'liveness_check_status' => $verification->liveness_check_status,
            'liveness_score' => $verification->liveness_score,
            'face_match_status' => $verification->face_match_status,
            'face_match_score' => $verification->face_match_score,
            'submitted_at' => $verification->submitted_at,
            'reviewed_at' => $verification->reviewed_at,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'identity_verification_status' => $user->identity_verification_status,
            ] : null,
        ];
    }

    private function serializeDetail(IdentityVerification $verification): array
    {
        $item = $this->serializeListItem($verification);

        return [
            ...$item,
            'document_number' => $verification->document_number,
            'review_notes' => $verification->review_notes,
            'document_front_url' => $this->storageUrl($verification->document_front_path),
            'document_back_url' => $this->storageUrl($verification->document_back_path),
            'selfie_url' => $this->storageUrl($verification->selfie_path),
            'liveness_video_url' => $this->storageUrl($verification->liveness_video_path),
        ];
    }

    private function ensureAdmin(Request $request): void
    {
        /** @var User|null $user */
        $user = $request->user();

        $email = strtolower((string) $user?->email);

        if (! in_array($email, config('admin.emails', []), true)) {
            abort(403, 'Acces reserve aux administrateurs.');
        }
    }

    private function storageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return rtrim(request()->getSchemeAndHttpHost(), '/').'/storage/'.ltrim($path, '/');
    }
}
