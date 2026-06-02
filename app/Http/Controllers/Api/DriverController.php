<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Trip;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DriverController extends Controller
{
    private const DRIVER_COMMISSION_PERCENT = 5;

    /**
     * @var array<string, array{min:int, max:int}>
     */
    private const VEHICLE_SEAT_LIMITS = [
        'moto' => ['min' => 1, 'max' => 1],
        'voiture' => ['min' => 1, 'max' => 3],
    ];

    public function home(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensureDriver($user)) {
            return $response;
        }

        $driverBookingsQuery = Booking::query()
            ->join('trips', 'trips.id', '=', 'bookings.trip_id')
            ->where('trips.driver_id', $user->id);

        $tripsPublished = Trip::query()
            ->where('driver_id', $user->id)
            ->count();

        $passengersTransported = (int) (clone $driverBookingsQuery)
            ->where('bookings.status', 'completed')
            ->sum('bookings.seats_reserved');

        $averageRating = (clone $driverBookingsQuery)
            ->whereNotNull('bookings.passenger_rating')
            ->avg('bookings.passenger_rating');

        $revenueThisMonth = (int) (clone $driverBookingsQuery)
            ->where('bookings.status', 'completed')
            ->whereBetween('bookings.booked_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('bookings.booked_price_fcfa');

        $upcomingTrips = Trip::query()
            ->where('driver_id', $user->id)
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->where('departure_at', '>=', now()->subMinutes(30))
            ->orderBy('departure_at')
            ->limit(5)
            ->get();

        $upcomingPassengerCounts = $this->passengerCountsByTripIds($upcomingTrips->pluck('id'));

        $unreadNotifications = UserNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'stats' => [
                'trips_published' => $tripsPublished,
                'passengers_transported' => $passengersTransported,
                'average_rating' => $averageRating !== null ? round((float) $averageRating, 1) : null,
                'revenue_month_fcfa' => $revenueThisMonth,
            ],
            'upcoming_trips' => $upcomingTrips
                ->map(fn (Trip $trip): array => $this->serializeDriverTripSummary($trip, (int) ($upcomingPassengerCounts[$trip->id] ?? 0)))
                ->all(),
            'notifications' => [
                'unread_count' => $unreadNotifications,
            ],
        ]);
    }

    public function trips(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensureDriver($user)) {
            return $response;
        }

        $validated = $request->validate([
            'status' => ['nullable', 'in:all,upcoming,completed,cancelled'],
        ]);

        $status = $validated['status'] ?? 'all';

        $query = Trip::query()
            ->where('driver_id', $user->id);

        if ($status === 'upcoming') {
            $query->whereIn('status', ['scheduled', 'in_progress']);
        } elseif ($status === 'completed') {
            $query->where('status', 'completed');
        } elseif ($status === 'cancelled') {
            $query->where('status', 'cancelled');
        }

        $trips = $query
            ->orderByDesc('departure_at')
            ->orderByDesc('id')
            ->get();

        $passengerCounts = $this->passengerCountsByTripIds($trips->pluck('id'));

        $countsByStatus = Trip::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->where('driver_id', $user->id)
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => $trips
                ->map(fn (Trip $trip): array => $this->serializeDriverTripCard($trip, (int) ($passengerCounts[$trip->id] ?? 0)))
                ->all(),
            'meta' => [
                'status' => $status,
                'total' => $trips->count(),
                'counts' => [
                    'all' => (int) $countsByStatus->sum(),
                    'upcoming' => (int) ($countsByStatus['scheduled'] ?? 0) + (int) ($countsByStatus['in_progress'] ?? 0),
                    'completed' => (int) ($countsByStatus['completed'] ?? 0),
                    'cancelled' => (int) ($countsByStatus['cancelled'] ?? 0),
                ],
            ],
        ]);
    }

    public function trip(Request $request, Trip $trip): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensureDriver($user)) {
            return $response;
        }

        if ($trip->driver_id !== $user->id) {
            return response()->json([
                'message' => 'Ce trajet ne vous appartient pas.',
            ], 403);
        }

        $payload = $this->buildDriverTripPayload($trip->fresh());

        return response()->json($payload);
    }

    public function publish(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensureDriver($user)) {
            return $response;
        }

        $validated = $request->validate([
            'from' => ['required', 'string', 'max:255'],
            'to' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'seats' => ['required', 'integer', 'min:1', 'max:10'],
            'price' => ['required', 'integer', 'min:100', 'max:200000'],
            'vehicle_type' => ['nullable', 'in:moto,voiture,minibus'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $driverVehicleType = $this->normalizedDriverVehicleType($user);

        if (! $driverVehicleType) {
            throw ValidationException::withMessages([
                'vehicle_type' => ['Le type de vehicule du profil conducteur est invalide ou manquant.'],
            ]);
        }

        if (
            isset($validated['vehicle_type'])
            && $validated['vehicle_type'] !== null
            && $validated['vehicle_type'] !== $driverVehicleType
        ) {
            throw ValidationException::withMessages([
                'vehicle_type' => ['Le type de vehicule du trajet doit correspondre a celui renseigne dans votre profil.'],
            ]);
        }

        $seats = (int) $validated['seats'];

        if ($this->seatsOutOfVehicleLimits($seats, $driverVehicleType)) {
            $limits = self::VEHICLE_SEAT_LIMITS[$driverVehicleType];

            $message = $limits['min'] === $limits['max']
                ? 'Pour ce type de vehicule, le nombre de places doit etre egal a '.$limits['min'].'.'
                : 'Pour ce type de vehicule, le nombre de places doit etre compris entre '.$limits['min'].' et '.$limits['max'].'.';

            throw ValidationException::withMessages([
                'seats' => [$message],
            ]);
        }

        $departureAt = Carbon::createFromFormat(
            'Y-m-d H:i',
            $validated['date'].' '.$validated['time'],
            config('app.timezone')
        );

        if ($departureAt === false || ! $departureAt->isFuture()) {
            throw ValidationException::withMessages([
                'departure_at' => ['La date de depart doit etre dans le futur.'],
            ]);
        }

        $fromLabel = trim((string) $validated['from']);
        $toLabel = trim((string) $validated['to']);

        $trip = Trip::query()->create([
            'driver_id' => $user->id,
            'from_city' => $this->extractCity($fromLabel),
            'from_label' => $fromLabel,
            'to_city' => $this->extractCity($toLabel),
            'to_label' => $toLabel,
            'departure_at' => $departureAt,
            'arrival_at' => (clone $departureAt)->addMinutes(45),
            'price_fcfa' => (int) $validated['price'],
            'vehicle_type' => $driverVehicleType,
            'vehicle_model' => null,
            'status' => 'scheduled',
            'seats_total' => $seats,
            'seats_available' => $seats,
        ]);

        $routeLabel = $trip->from_city.' -> '.$trip->to_city;

        $this->createUserNotification(
            $user,
            'trip_published',
            'Trajet publie',
            'Votre trajet '.$routeLabel.' a ete publie avec succes.',
            [
                'trip_id' => $trip->id,
            ]
        );

        return response()->json([
            'message' => 'Trajet publie avec succes.',
            'trip' => $this->serializeDriverTripCard($trip->fresh(), 0),
        ], 201);
    }

    public function updateTripStatus(Request $request, Trip $trip): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensureDriver($user)) {
            return $response;
        }

        if ($trip->driver_id !== $user->id) {
            return response()->json([
                'message' => 'Ce trajet ne vous appartient pas.',
            ], 403);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:in_progress,completed,cancelled'],
        ]);

        $targetStatus = (string) $validated['status'];

        $updatedTrip = DB::transaction(function () use ($trip, $targetStatus): Trip {
            /** @var Trip $lockedTrip */
            $lockedTrip = Trip::query()
                ->lockForUpdate()
                ->whereKey($trip->id)
                ->firstOrFail();

            $currentStatus = (string) $lockedTrip->status;

            if ($currentStatus === $targetStatus) {
                throw ValidationException::withMessages([
                    'status' => ['Ce trajet est deja dans cet etat.'],
                ]);
            }

            if (in_array($currentStatus, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Ce trajet ne peut plus etre modifie.'],
                ]);
            }

            if ($targetStatus === 'in_progress' && $currentStatus !== 'scheduled') {
                throw ValidationException::withMessages([
                    'status' => ['Seul un trajet programme peut etre demarre.'],
                ]);
            }

            if ($targetStatus === 'completed' && ! in_array($currentStatus, ['scheduled', 'in_progress'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Ce trajet ne peut pas etre termine.'],
                ]);
            }

            if ($targetStatus === 'cancelled' && ! in_array($currentStatus, ['scheduled', 'in_progress'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Ce trajet ne peut pas etre annule.'],
                ]);
            }

            if ($targetStatus === 'in_progress') {
                $hasUpcomingBooking = Booking::query()
                    ->where('trip_id', $lockedTrip->id)
                    ->where('status', 'upcoming')
                    ->exists();

                if (! $hasUpcomingBooking) {
                    throw ValidationException::withMessages([
                        'status' => ['Impossible de demarrer: aucun passager n\'a encore reserve ce trajet.'],
                    ]);
                }
            }

            $routeLabel = ($lockedTrip->from_city ?: 'Depart').' -> '.($lockedTrip->to_city ?: 'Destination');

            if ($targetStatus === 'completed') {
                $bookingsToComplete = Booking::query()
                    ->with('passenger')
                    ->lockForUpdate()
                    ->where('trip_id', $lockedTrip->id)
                    ->where('status', 'upcoming')
                    ->get();

                foreach ($bookingsToComplete as $booking) {
                    $payoutAmount = $this->driverNetPayout((int) $booking->booked_price_fcfa);

                    $booking->forceFill([
                        'status' => 'completed',
                        'driver_completed_at' => now(),
                        'passenger_completion_status' => 'pending',
                        'passenger_completion_confirmed_at' => null,
                        'payout_status' => 'pending_confirmation',
                        'payout_amount_fcfa' => $payoutAmount,
                        'payout_credited_at' => null,
                    ])->save();

                    $this->createUserNotification(
                        $booking->passenger,
                        'trip_completed',
                        'Trajet termine',
                        'Votre trajet '.$routeLabel.' est termine.',
                        [
                            'trip_id' => $lockedTrip->id,
                            'booking_id' => $booking->id,
                        ]
                    );

                    $this->createUserNotification(
                        $booking->passenger,
                        'trip_completion_confirmation_required',
                        'Confirmation de fin de course',
                        'Confirmez si le trajet '.$routeLabel.' est bien termine (Oui/Non).',
                        [
                            'trip_id' => $lockedTrip->id,
                            'booking_id' => $booking->id,
                            'action' => 'passenger_completion_confirmation',
                        ]
                    );
                }
            } elseif ($targetStatus === 'cancelled') {
                $bookingsToCancel = Booking::query()
                    ->with('passenger')
                    ->lockForUpdate()
                    ->where('trip_id', $lockedTrip->id)
                    ->where('status', 'upcoming')
                    ->get();

                foreach ($bookingsToCancel as $booking) {
                    $booking->forceFill([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                    ])->save();

                    /** @var User|null $lockedPassenger */
                    $lockedPassenger = User::query()
                        ->lockForUpdate()
                        ->whereKey($booking->passenger_id)
                        ->first();

                    if ($lockedPassenger) {
                        $lockedPassenger->forceFill([
                            'passenger_wallet_balance_fcfa' => (int) ($lockedPassenger->passenger_wallet_balance_fcfa ?? 0) + (int) $booking->booked_price_fcfa,
                        ])->save();
                    }

                    $this->createUserNotification(
                        $lockedPassenger ?? $booking->passenger,
                        'trip_cancelled_driver',
                        'Trajet annule',
                        'Le conducteur a annule le trajet '.$routeLabel.'. Votre paiement de '.(int) $booking->booked_price_fcfa.' FCFA a ete rembourse.',
                        [
                            'trip_id' => $lockedTrip->id,
                            'booking_id' => $booking->id,
                            'refund_amount_fcfa' => (int) $booking->booked_price_fcfa,
                        ]
                    );
                }
            } elseif ($targetStatus === 'in_progress') {
                $bookingsToNotify = Booking::query()
                    ->with('passenger')
                    ->where('trip_id', $lockedTrip->id)
                    ->where('status', 'upcoming')
                    ->get();

                foreach ($bookingsToNotify as $booking) {
                    $this->createUserNotification(
                        $booking->passenger,
                        'trip_started',
                        'Trajet demarre',
                        'Votre trajet '.$routeLabel.' a demarre.',
                        [
                            'trip_id' => $lockedTrip->id,
                            'booking_id' => $booking->id,
                        ]
                    );
                }
            }

            $tripAttributes = [
                'status' => $targetStatus,
            ];

            if ($targetStatus === 'cancelled') {
                $tripAttributes['seats_available'] = $lockedTrip->seats_total;
            }

            $lockedTrip->forceFill($tripAttributes)->save();

            return $lockedTrip->fresh();
        });

        $message = $targetStatus === 'in_progress'
            ? 'Trajet demarre avec succes.'
            : ($targetStatus === 'completed' ? 'Trajet termine avec succes.' : 'Trajet annule avec succes.');

        return response()->json([
            'message' => $message,
            ...$this->buildDriverTripPayload($updatedTrip),
        ]);
    }

    public function cancelBooking(Request $request, Booking $booking): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensureDriver($user)) {
            return $response;
        }

        $booking->loadMissing('trip');

        if (! $booking->trip || $booking->trip->driver_id !== $user->id) {
            return response()->json([
                'message' => 'Cette reservation ne vous appartient pas.',
            ], 403);
        }

        $updatedTrip = DB::transaction(function () use ($booking): Trip {
            /** @var Booking $lockedBooking */
            $lockedBooking = Booking::query()
                ->with(['trip', 'passenger'])
                ->lockForUpdate()
                ->whereKey($booking->id)
                ->firstOrFail();

            if ($lockedBooking->status !== 'upcoming') {
                throw ValidationException::withMessages([
                    'booking' => ['Seules les reservations a venir peuvent etre annulees.'],
                ]);
            }

            /** @var Trip $trip */
            $trip = Trip::query()
                ->lockForUpdate()
                ->whereKey($lockedBooking->trip_id)
                ->firstOrFail();

            if (in_array((string) $trip->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'booking' => ['Le trajet ne permet plus l annulation de reservation.'],
                ]);
            }

            $lockedBooking->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ])->save();

            /** @var User|null $lockedPassenger */
            $lockedPassenger = User::query()
                ->lockForUpdate()
                ->whereKey($lockedBooking->passenger_id)
                ->first();

            if ($lockedPassenger) {
                $lockedPassenger->forceFill([
                    'passenger_wallet_balance_fcfa' => (int) ($lockedPassenger->passenger_wallet_balance_fcfa ?? 0) + (int) $lockedBooking->booked_price_fcfa,
                ])->save();
            }

            $trip->forceFill([
                'seats_available' => min($trip->seats_total, $trip->seats_available + $lockedBooking->seats_reserved),
            ])->save();

            $routeLabel = ($trip->from_city ?: 'Depart').' -> '.($trip->to_city ?: 'Destination');

            $this->createUserNotification(
                $lockedPassenger ?? $lockedBooking->passenger,
                'booking_cancelled_driver',
                'Reservation annulee',
                'Le conducteur a annule votre reservation pour '.$routeLabel.'. Votre paiement de '.(int) $lockedBooking->booked_price_fcfa.' FCFA a ete rembourse.',
                [
                    'trip_id' => $trip->id,
                    'booking_id' => $lockedBooking->id,
                    'refund_amount_fcfa' => (int) $lockedBooking->booked_price_fcfa,
                ]
            );

            return $trip->fresh();
        });

        return response()->json([
            'message' => 'Reservation annulee avec succes.',
            ...$this->buildDriverTripPayload($updatedTrip),
        ]);
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

    private function buildDriverTripPayload(Trip $trip): array
    {
        $trip = $trip->fresh();

        $bookings = Booking::query()
            ->with('passenger')
            ->where('trip_id', $trip->id)
            ->orderByDesc('booked_at')
            ->orderByDesc('id')
            ->get();

        $activeBookings = $bookings->filter(
            static fn (Booking $booking): bool => in_array((string) $booking->status, ['upcoming', 'completed'], true)
        );

        $reservedSeats = (int) $activeBookings->sum('seats_reserved');

        return [
            'trip' => $this->serializeDriverTripDetails($trip, $reservedSeats, $activeBookings->count()),
            'passengers' => $bookings
                ->map(fn (Booking $booking): array => $this->serializeDriverPassengerBooking($booking, (string) $trip->status))
                ->all(),
        ];
    }

    private function serializeDriverTripSummary(Trip $trip, int $passengers): array
    {
        return [
            'id' => $trip->id,
            'from' => $trip->from_label ?: $trip->from_city,
            'to' => $trip->to_label ?: $trip->to_city,
            'date' => $this->humanDate($trip->departure_at),
            'time' => $trip->departure_at?->format('H:i') ?? '--:--',
            'passengers' => $passengers,
            'available' => $trip->seats_available,
            'total' => $trip->seats_total,
            'price' => (string) $trip->price_fcfa,
            'status' => $this->driverStatus($trip->status),
            'status_raw' => $trip->status,
        ];
    }

    private function serializeDriverTripCard(Trip $trip, int $passengers): array
    {
        return [
            'id' => $trip->id,
            'from' => $trip->from_label ?: $trip->from_city,
            'to' => $trip->to_label ?: $trip->to_city,
            'date' => $trip->departure_at?->format('Y-m-d') ?? 'Non defini',
            'time' => $trip->departure_at?->format('H:i') ?? '--:--',
            'passengers' => $passengers,
            'total' => $trip->seats_total,
            'available' => $trip->seats_available,
            'price' => (string) $trip->price_fcfa,
            'status' => $this->driverStatus($trip->status),
            'status_raw' => $trip->status,
            'status_label' => $this->driverStatusLabel($trip->status),
            'vehicle_type' => $trip->vehicle_type,
            'vehicle_model' => $trip->vehicle_model,
        ];
    }

    private function serializeDriverTripDetails(Trip $trip, int $reservedSeats, int $passengersCount): array
    {
        $durationMinutes = null;

        if ($trip->departure_at !== null && $trip->arrival_at !== null) {
            $durationMinutes = max(0, $trip->departure_at->diffInMinutes($trip->arrival_at, false));
        }

        return [
            'id' => $trip->id,
            'from' => $trip->from_label ?: $trip->from_city,
            'to' => $trip->to_label ?: $trip->to_city,
            'from_city' => $trip->from_city,
            'to_city' => $trip->to_city,
            'from_label' => $trip->from_label,
            'to_label' => $trip->to_label,
            'date' => $this->humanDate($trip->departure_at),
            'departure_date' => $trip->departure_at?->format('Y-m-d'),
            'departure_time' => $trip->departure_at?->format('H:i') ?? '--:--',
            'arrival_time' => $trip->arrival_at?->format('H:i') ?? '--:--',
            'duration_minutes' => $durationMinutes,
            'price' => (string) $trip->price_fcfa,
            'seats_total' => $trip->seats_total,
            'seats_available' => $trip->seats_available,
            'reserved_seats' => $reservedSeats,
            'passengers_count' => $passengersCount,
            'status' => $this->driverStatus($trip->status),
            'status_raw' => $trip->status,
            'status_label' => $this->driverStatusLabel($trip->status),
            'vehicle_type' => $trip->vehicle_type,
            'vehicle_model' => $trip->vehicle_model,
            'can_start' => $trip->status === 'scheduled' && $passengersCount > 0,
            'can_complete' => in_array((string) $trip->status, ['scheduled', 'in_progress'], true),
            'can_cancel' => in_array((string) $trip->status, ['scheduled', 'in_progress'], true),
        ];
    }

    private function serializeDriverPassengerBooking(Booking $booking, string $tripStatus): array
    {
        $passenger = $booking->passenger;

        return [
            'booking_id' => $booking->id,
            'status' => $booking->status,
            'status_label' => $this->bookingStatusLabel((string) $booking->status),
            'seats_reserved' => $booking->seats_reserved,
            'booked_at' => $booking->booked_at,
            'cancelled_at' => $booking->cancelled_at,
            'completion_confirmation_status' => $booking->passenger_completion_status,
            'completion_confirmed_at' => $booking->passenger_completion_confirmed_at,
            'payout_status' => $booking->payout_status,
            'payout_amount_fcfa' => $booking->payout_amount_fcfa !== null ? (int) $booking->payout_amount_fcfa : null,
            'payout_credited_at' => $booking->payout_credited_at,
            'passenger' => [
                'id' => $passenger?->id,
                'name' => $passenger?->name ?? 'Passager',
                'phone' => $passenger?->phone,
                'email' => $passenger?->email,
                'avatar' => $this->initialFromName($passenger?->name),
                'avatar_url' => $this->avatarUrl($passenger?->avatar_path),
            ],
            'can_cancel' => $booking->status === 'upcoming' && ! in_array($tripStatus, ['completed', 'cancelled'], true),
        ];
    }

    private function humanDate($dateTime): string
    {
        if (! $dateTime) {
            return 'Non defini';
        }

        if ($dateTime->isToday()) {
            return "Aujourd'hui";
        }

        if ($dateTime->isTomorrow()) {
            return 'Demain';
        }

        return $dateTime->format('d/m/Y');
    }

    private function extractCity(string $label): string
    {
        $parts = preg_split('/[,;\|-]/', $label);
        $city = trim((string) ($parts[0] ?? $label));

        return $city !== '' ? $city : $label;
    }

    private function driverStatus(string $status): string
    {
        if (in_array($status, ['scheduled', 'in_progress'], true)) {
            return 'upcoming';
        }

        if ($status === 'completed') {
            return 'completed';
        }

        if ($status === 'cancelled') {
            return 'cancelled';
        }

        return 'upcoming';
    }

    private function driverStatusLabel(string $status): string
    {
        return match ($status) {
            'completed' => 'Termine',
            'cancelled' => 'Annule',
            'in_progress' => 'En cours',
            default => 'A venir',
        };
    }

    private function bookingStatusLabel(string $status): string
    {
        return match ($status) {
            'completed' => 'Terminee',
            'cancelled' => 'Annulee',
            default => 'Confirmee',
        };
    }

    private function initialFromName(?string $name): string
    {
        if (! $name) {
            return 'P';
        }

        $trimmed = trim($name);

        return $trimmed !== '' ? strtoupper(substr($trimmed, 0, 1)) : 'P';
    }

    private function avatarUrl(?string $avatarPath): ?string
    {
        if (! $avatarPath) {
            return null;
        }

        return rtrim(request()->getSchemeAndHttpHost(), '/').'/storage/'.ltrim($avatarPath, '/');
    }

    private function passengerCountsByTripIds(Collection $tripIds): array
    {
        $ids = $tripIds
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Booking::query()
            ->select('trip_id', DB::raw('SUM(seats_reserved) as total_reserved'))
            ->whereIn('trip_id', $ids)
            ->whereIn('status', ['upcoming', 'completed'])
            ->groupBy('trip_id')
            ->pluck('total_reserved', 'trip_id')
            ->map(fn ($value): int => (int) $value)
            ->all();
    }

    private function driverNetPayout(int $grossAmount): int
    {
        $sanitized = max(0, $grossAmount);
        $netRate = (100 - self::DRIVER_COMMISSION_PERCENT) / 100;

        return (int) floor($sanitized * $netRate);
    }

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

    private function normalizedDriverVehicleType(User $user): ?string
    {
        $vehicleType = strtolower(trim((string) ($user->vehicle_type ?? '')));

        return in_array($vehicleType, ['moto', 'voiture', 'minibus'], true)
            ? $vehicleType
            : null;
    }

    private function seatsOutOfVehicleLimits(int $seats, string $vehicleType): bool
    {
        $limits = self::VEHICLE_SEAT_LIMITS[$vehicleType] ?? null;

        if (! $limits) {
            return false;
        }

        return $seats < $limits['min'] || $seats > $limits['max'];
    }
}
