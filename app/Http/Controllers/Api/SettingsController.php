<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'settings' => $this->resolvedSettings($user),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'notifications' => ['required', 'array'],
            'notifications.push_trips' => ['required', 'boolean'],
            'notifications.push_payments' => ['required', 'boolean'],
            'notifications.newsletter' => ['required', 'boolean'],
            'notifications.sms_alerts' => ['required', 'boolean'],
        ]);

        $settings = is_array($user->settings) ? $user->settings : [];
        $settings['notifications'] = $validated['notifications'];

        $user->forceFill([
            'settings' => $settings,
        ])->save();

        return response()->json([
            'message' => 'Parametres mis a jour avec succes.',
            'settings' => $this->resolvedSettings($user->fresh()),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'max:255', 'confirmed', 'different:current_password'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Le mot de passe actuel est incorrect.'],
            ]);
        }

        $user->forceFill([
            'password' => $validated['new_password'],
        ])->save();

        return response()->json([
            'message' => 'Mot de passe mis a jour avec succes.',
        ]);
    }

    private function resolvedSettings(User $user): array
    {
        $storedSettings = is_array($user->settings) ? $user->settings : [];
        $storedNotifications = is_array($storedSettings['notifications'] ?? null)
            ? $storedSettings['notifications']
            : [];

        return [
            'notifications' => [
                'push_trips' => array_key_exists('push_trips', $storedNotifications)
                    ? (bool) $storedNotifications['push_trips']
                    : true,
                'push_payments' => array_key_exists('push_payments', $storedNotifications)
                    ? (bool) $storedNotifications['push_payments']
                    : true,
                'newsletter' => array_key_exists('newsletter', $storedNotifications)
                    ? (bool) $storedNotifications['newsletter']
                    : false,
                'sms_alerts' => array_key_exists('sms_alerts', $storedNotifications)
                    ? (bool) $storedNotifications['sms_alerts']
                    : false,
            ],
        ];
    }
}
