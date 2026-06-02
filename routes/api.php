<?php

use App\Http\Controllers\Api\AdminVerificationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\DriverVehicleDocumentController;
use App\Http\Controllers\Api\DriverSubscriptionController;
use App\Http\Controllers\Api\DriverWalletController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PassengerController;
use App\Http\Controllers\Api\PassengerWalletController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SupportController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->prefix('profile')->group(function (): void {
    Route::get('/', [ProfileController::class, 'show']);
    Route::put('/', [ProfileController::class, 'update']);

    Route::post('/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::delete('/avatar', [ProfileController::class, 'deleteAvatar']);

    Route::get('/verification/status', [ProfileController::class, 'verificationStatus']);
    Route::post('/verification/request', [ProfileController::class, 'requestVerification']);

    Route::post('/phone-verification/send', [ProfileController::class, 'sendPhoneVerificationCode']);
    Route::post('/phone-verification/confirm', [ProfileController::class, 'confirmPhoneVerificationCode']);
});

Route::middleware('auth:sanctum')->prefix('settings')->group(function (): void {
    Route::get('/', [SettingsController::class, 'show']);
    Route::put('/', [SettingsController::class, 'update']);
    Route::put('/password', [SettingsController::class, 'updatePassword']);
});

Route::middleware('auth:sanctum')->prefix('passenger')->group(function (): void {
    Route::get('/home', [PassengerController::class, 'home']);
    Route::get('/trips', [PassengerController::class, 'trips']);
    Route::get('/trips/{trip}', [PassengerController::class, 'trip']);
    Route::get('/bookings', [PassengerController::class, 'bookings']);
    Route::post('/bookings', [PassengerController::class, 'book']);
    Route::patch('/bookings/{booking}/cancel', [PassengerController::class, 'cancel']);
    Route::patch('/bookings/{booking}/rating', [PassengerController::class, 'rate']);
    Route::patch('/bookings/{booking}/completion-confirmation', [PassengerController::class, 'confirmCompletion']);

    Route::get('/wallet', [PassengerWalletController::class, 'show']);
    Route::get('/wallet/deposits', [PassengerWalletController::class, 'deposits']);
    Route::post('/wallet/deposit', [PassengerWalletController::class, 'deposit']);
});

Route::middleware('auth:sanctum')->prefix('driver')->group(function (): void {
    Route::get('/home', [DriverController::class, 'home']);
    Route::get('/trips', [DriverController::class, 'trips']);
    Route::get('/trips/{trip}', [DriverController::class, 'trip']);
    Route::post('/trips', [DriverController::class, 'publish']);
    Route::patch('/trips/{trip}/status', [DriverController::class, 'updateTripStatus']);
    Route::patch('/bookings/{booking}/cancel', [DriverController::class, 'cancelBooking']);

    Route::get('/subscription', [DriverSubscriptionController::class, 'show']);
    Route::post('/subscription/renew', [DriverSubscriptionController::class, 'renew']);

    Route::get('/wallet', [DriverWalletController::class, 'show']);
    Route::get('/wallet/deposits', [DriverWalletController::class, 'deposits']);
    Route::get('/wallet/withdrawals', [DriverWalletController::class, 'withdrawals']);
    Route::post('/wallet/deposit', [DriverWalletController::class, 'deposit']);
    Route::post('/wallet/withdraw', [DriverWalletController::class, 'withdraw']);

    Route::get('/vehicle-documents', [DriverVehicleDocumentController::class, 'index']);
    Route::get('/vehicle-documents/requirements', [DriverVehicleDocumentController::class, 'requirements']);
    Route::post('/vehicle-documents', [DriverVehicleDocumentController::class, 'store']);
});

Route::middleware('auth:sanctum')->prefix('support')->group(function (): void {
    Route::get('/tickets', [SupportController::class, 'index']);
    Route::post('/tickets', [SupportController::class, 'store']);
});

Route::middleware('auth:sanctum')->prefix('notifications')->group(function (): void {
    Route::get('/', [NotificationController::class, 'index']);
    Route::patch('/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('/read-all', [NotificationController::class, 'markAllRead']);
    Route::delete('/', [NotificationController::class, 'clear']);
});

Route::middleware('auth:sanctum')->prefix('chat')->group(function (): void {
    Route::get('/conversations', [ChatController::class, 'index']);
    Route::post('/conversations', [ChatController::class, 'create']);
    Route::get('/conversations/{conversation}', [ChatController::class, 'show']);
    Route::post('/conversations/{conversation}/messages', [ChatController::class, 'sendMessage']);
    Route::post('/conversations/{conversation}/read', [ChatController::class, 'markRead']);
});

Route::middleware('auth:sanctum')->prefix('admin/verifications')->group(function (): void {
    Route::get('/', [AdminVerificationController::class, 'index']);
    Route::get('/{verification}', [AdminVerificationController::class, 'show']);
    Route::patch('/{verification}', [AdminVerificationController::class, 'review']);
});

