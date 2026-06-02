<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name',
    'email',
    'phone',
    'phone_verified_at',
    'role',
    'vehicle_type',
    'vehicle_plate',
    'avatar_path',
    'identity_verification_status',
    'identity_verification_requested_at',
    'identity_verified_at',
    'settings',
    'driver_wallet_balance_fcfa',
    'passenger_wallet_balance_fcfa',
    'password',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'identity_verification_requested_at' => 'datetime',
            'identity_verified_at' => 'datetime',
            'settings' => 'array',
            'driver_wallet_balance_fcfa' => 'integer',
            'passenger_wallet_balance_fcfa' => 'integer',
            'password' => 'hashed',
        ];
    }

    public function identityVerifications(): HasMany
    {
        return $this->hasMany(IdentityVerification::class);
    }

    public function drivenTrips(): HasMany
    {
        return $this->hasMany(Trip::class, 'driver_id');
    }

    public function passengerBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'passenger_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(UserNotification::class)->orderByDesc('id');
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class)->orderByDesc('id');
    }

    public function driverConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'driver_id')->orderByDesc('last_message_at')->orderByDesc('id');
    }

    public function passengerConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'passenger_id')->orderByDesc('last_message_at')->orderByDesc('id');
    }

    public function sentConversationMessages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class, 'sender_id')->orderByDesc('id');
    }

    public function driverWithdrawals(): HasMany
    {
        return $this->hasMany(DriverWithdrawal::class)->orderByDesc('id');
    }

    public function driverDeposits(): HasMany
    {
        return $this->hasMany(DriverDeposit::class)->orderByDesc('id');
    }

    public function passengerDeposits(): HasMany
    {
        return $this->hasMany(PassengerDeposit::class)->orderByDesc('id');
    }

    public function driverVehicleDocuments(): HasMany
    {
        return $this->hasMany(DriverVehicleDocument::class)->orderByDesc('submitted_at')->orderByDesc('id');
    }

    public function phoneVerificationCodes(): HasMany
    {
        return $this->hasMany(PhoneVerificationCode::class)->orderByDesc('id');
    }
}
