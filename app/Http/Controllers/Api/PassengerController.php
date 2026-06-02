<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Trip;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PassengerController extends Controller
{
    private const DRIVER_COMMISSION_PERCENT = 5;

    public function home(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensurePassenger($user)) {
            return $response;
        }

        $completedBookingsQuery = Booking::query()
            ->where('passenger_id', $user->id)
            ->where('status', 'completed');

        $completedTrips = (clone $completedBookingsQuery)->count();
        $completedAmount = (int) (clone $completedBookingsQuery)->sum('booked_price_fcfa');
        $savedAmount = (int) round($completedAmount * 0.15);
        $averageRating = (clone $completedBookingsQuery)
            ->whereNotNull('passenger_rating')
            ->avg('passenger_rating');

        $upcomingBookingsCollection = Booking::query()
            ->with(['trip.driver'])
            ->where('passenger_id', $user->id)
            ->where('status', 'upcoming')
            ->whereHas('trip', static function ($query): void {
                $query->whereIn('status', ['scheduled', 'in_progress']);
            })
            ->get()
            ->filter(static fn (Booking $booking): bool => $booking->trip !== null)
            ->sortBy(static fn (Booking $booking): int => $booking->trip?->departure_at?->getTimestamp() ?? PHP_INT_MAX)
            ->take(3)
            ->values();

        $upcomingDriverRatings = $this->driverRatingsByIds($upcomingBookingsCollection->pluck('trip.driver_id'));

        $upcomingBookings = $upcomingBookingsCollection
            ->map(function (Booking $booking) use ($upcomingDriverRatings): array {
                $driverId = (string) ($booking->trip?->driver_id ?? '');

                return $this->serializeBookingSummary($booking, $upcomingDriverRatings[$driverId] ?? null);
            })
            ->all();

        $upcomingCount = Booking::query()
            ->where('passenger_id', $user->id)
            ->where('status', 'upcoming')
            ->count();

        $unreadNotifications = UserNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'stats' => [
                'trips_completed' => $completedTrips,
                'saved_amount_fcfa' => $savedAmount,
                'average_rating' => $averageRating !== null ? round((float) $averageRating, 1) : null,
                'upcoming_count' => $upcomingCount,
            ],
            'upcoming_bookings' => $upcomingBookings,
            'popular_routes' => $this->popularRoutes(),
            'notifications' => [
                'unread_count' => $unreadNotifications,
            ],
        ]);
    }

    public function trips(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensurePassenger($user)) {
            return $response;
        }

        $validated = $request->validate([
            'from' => ['nullable', 'string', 'max:120'],
            'to' => ['nullable', 'string', 'max:120'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', 'in:soonest,latest,price_asc,price_desc,seats_desc'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Trip::query()
            ->with(['driver'])
            ->where('status', 'scheduled')
            ->where('seats_available', '>', 0)
            ->where('departure_at', '>=', now()->subMinutes(30));

        if (! empty($validated['from'])) {
            $from = trim((string) $validated['from']);

            $query->where(static function ($tripQuery) use ($from): void {
                $tripQuery
                    ->where('from_city', 'like', "%{$from}%")
                    ->orWhere('from_label', 'like', "%{$from}%");
            });
        }

        if (! empty($validated['to'])) {
            $to = trim((string) $validated['to']);

            $query->where(static function ($tripQuery) use ($to): void {
                $tripQuery
                    ->where('to_city', 'like', "%{$to}%")
                    ->orWhere('to_label', 'like', "%{$to}%");
            });
        }

        if (! empty($validated['date'])) {
            $query->whereDate('departure_at', $validated['date']);
        }

        $sort = $validated['sort'] ?? 'soonest';

        if ($sort === 'latest') {
            $query->orderByDesc('departure_at');
        } elseif ($sort === 'price_asc') {
            $query->orderBy('price_fcfa')->orderBy('departure_at');
        } elseif ($sort === 'price_desc') {
            $query->orderByDesc('price_fcfa')->orderBy('departure_at');
        } elseif ($sort === 'seats_desc') {
            $query->orderByDesc('seats_available')->orderBy('departure_at');
        } else {
            $query->orderBy('departure_at');
        }

        $limit = (int) ($validated['limit'] ?? 30);

        $trips = $query
            ->limit($limit)
            ->get();

        $driverIds = $trips->pluck('driver_id')->filter()->unique()->values();

        $driverTripCounts = Trip::query()
            ->select('driver_id', DB::raw('COUNT(*) as total'))
            ->whereIn('driver_id', $driverIds)
            ->groupBy('driver_id')
            ->pluck('total', 'driver_id');

        $driverRatings = $this->driverRatingsByIds($driverIds);

        $serializedTrips = $trips
            ->map(function (Trip $trip) use ($driverTripCounts, $driverRatings): array {
                $driverId = (string) $trip->driver_id;
                $driverTrips = (int) ($driverTripCounts[$driverId] ?? 0);

                return $this->serializeTripCard($trip, $driverTrips, $driverRatings[$driverId] ?? null);
            })
            ->values()
            ->all();

        return response()->json([
            'data' => $serializedTrips,
            'meta' => [
                'total' => count($serializedTrips),
                'filters' => [
                    'from' => $validated['from'] ?? null,
                    'to' => $validated['to'] ?? null,
                    'date' => $validated['date'] ?? null,
                    'sort' => $sort,
                ],
            ],
        ]);
    }

    public function trip(Request $request, Trip $trip): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensurePassenger($user)) {
            return $response;
        }

        $trip->loadMissing('driver');

        $driverTripCount = Trip::query()
            ->where('driver_id', $trip->driver_id)
            ->count();

        $driverRating = $this->driverAverageRating($trip->driver_id);

        $passengerBooking = Booking::query()
            ->where('trip_id', $trip->id)
            ->where('passenger_id', $user->id)
            ->first();

        $isAlreadyBooked = $passengerBooking !== null && $passengerBooking->status !== 'cancelled';
        $hasEnoughWalletBalance = (int) ($user->passenger_wallet_balance_fcfa ?? 0) >= (int) $trip->price_fcfa;
        $isBookable = $trip->status === 'scheduled'
            && $trip->seats_available > 0
            && ($trip->departure_at === null || $trip->departure_at->isFuture())
            && $trip->driver_id !== $user->id
            && ! $isAlreadyBooked
            && $hasEnoughWalletBalance;

        return response()->json([
            'trip' => $this->serializeTripDetails($trip, $driverTripCount, $passengerBooking, $isBookable, $driverRating),
        ]);
    }

    public function bookings(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensurePassenger($user)) {
            return $response;
        }

        $validated = $request->validate([
            'status' => ['nullable', 'in:all,upcoming,completed,cancelled'],
        ]);

        $status = $validated['status'] ?? 'all';

        $query = Booking::query()
            ->with(['trip.driver'])
            ->where('passenger_id', $user->id);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $bookings = $query
            ->orderByDesc('booked_at')
            ->orderByDesc('id')
            ->get()
            ->filter(static fn (Booking $booking): bool => $booking->trip !== null)
            ->values();

        $driverRatings = $this->driverRatingsByIds($bookings->pluck('trip.driver_id'));

        $countsByStatus = Booking::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->where('passenger_id', $user->id)
            ->groupBy('status')
            ->pluck('total', 'status');

        $allCount = (int) $countsByStatus->sum();

        return response()->json([
            'data' => $bookings->map(function (Booking $booking) use ($driverRatings): array {
                $driverId = (string) ($booking->trip?->driver_id ?? '');

                return $this->serializeBookingCard($booking, $driverRatings[$driverId] ?? null);
            })->all(),
            'meta' => [
                'status' => $status,
                'total' => $bookings->count(),
                'counts' => [
                    'all' => $allCount,
                    'upcoming' => (int) ($countsByStatus['upcoming'] ?? 0),
                    'completed' => (int) ($countsByStatus['completed'] ?? 0),
                    'cancelled' => (int) ($countsByStatus['cancelled'] ?? 0),
                ],
            ],
        ]);
    }

    public function book(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensurePassenger($user)) {
            return $response;
        }

        $validated = $request->validate([
            'trip_id' => ['required', 'integer', 'exists:trips,id'],
            'seats_reserved' => ['nullable', 'integer', 'min:1', 'max:6'],
        ]);

        $booking = DB::transaction(function () use ($validated, $user): Booking {
            $trip = Trip::query()
                ->lockForUpdate()
                ->whereKey($validated['trip_id'])
                ->firstOrFail();

            /** @var User $lockedPassenger */
            $lockedPassenger = User::query()
                ->lockForUpdate()
                ->whereKey($user->id)
                ->firstOrFail();

            $seatsReserved = (int) ($validated['seats_reserved'] ?? 1);
            $bookingAmount = (int) $trip->price_fcfa * $seatsReserved;

            if ($trip->status !== 'scheduled') {
                throw ValidationException::withMessages([
                    'trip_id' => ['Ce trajet ne peut plus etre reserve.'],
                ]);
            }

            if ($trip->departure_at !== null && $trip->departure_at->isPast()) {
                throw ValidationException::withMessages([
                    'trip_id' => ['Le depart de ce trajet est deja passe.'],
                ]);
            }

            if ($trip->driver_id === $user->id) {
                throw ValidationException::withMessages([
                    'trip_id' => ['Vous ne pouvez pas reserver votre propre trajet.'],
                ]);
            }

            if ($trip->seats_available < $seatsReserved) {
                throw ValidationException::withMessages([
                    'seats_reserved' => ['Pas assez de places disponibles.'],
                ]);
            }

            $currentWalletBalance = (int) ($lockedPassenger->passenger_wallet_balance_fcfa ?? 0);

            if ($currentWalletBalance < $bookingAmount) {
                throw ValidationException::withMessages([
                    'balance' => ['Solde insuffisant. Rechargez votre compte passager pour confirmer cette reservation.'],
                ]);
            }

            $existingBooking = Booking::query()
                ->lockForUpdate()
                ->where('trip_id', $trip->id)
                ->where('passenger_id', $user->id)
                ->first();

            if ($existingBooking && $existingBooking->status !== 'cancelled') {
                throw ValidationException::withMessages([
                    'trip_id' => ['Vous avez deja une reservation active sur ce trajet.'],
                ]);
            }

            if ($existingBooking) {
                $existingBooking->forceFill([
                    'status' => 'upcoming',
                    'seats_reserved' => $seatsReserved,
                    'booked_price_fcfa' => $bookingAmount,
                    'booked_at' => now(),
                    'cancelled_at' => null,
                    'driver_completed_at' => null,
                    'passenger_completion_status' => null,
                    'passenger_completion_confirmed_at' => null,
                    'payout_status' => 'not_ready',
                    'payout_amount_fcfa' => null,
                    'payout_credited_at' => null,
                ])->save();

                $booking = $existingBooking;
            } else {
                $booking = Booking::query()->create([
                    'trip_id' => $trip->id,
                    'passenger_id' => $user->id,
                    'status' => 'upcoming',
                    'seats_reserved' => $seatsReserved,
                    'booked_price_fcfa' => $bookingAmount,
                    'booked_at' => now(),
                ]);
            }

            $lockedPassenger->forceFill([
                'passenger_wallet_balance_fcfa' => max(0, $currentWalletBalance - $bookingAmount),
            ])->save();

            $trip->forceFill([
                'seats_available' => max(0, $trip->seats_available - $seatsReserved),
            ])->save();

            $routeLabel = ($trip->from_city ?: 'Depart').' -> '.($trip->to_city ?: 'Destination');

            $this->createUserNotification(
                $user,
                'booking_confirmed',
                'Reservation confirmee',
                "Votre reservation pour {$routeLabel} est confirmee.",
                [
                    'booking_id' => $booking->id,
                    'trip_id' => $trip->id,
                ]
            );

            $this->createUserNotification(
                $lockedPassenger,
                'booking_wallet_debited',
                'Paiement de reservation valide',
                'Votre compte passager a ete debite de '.$bookingAmount.' FCFA pour la reservation '.$routeLabel.'.',
                [
                    'booking_id' => $booking->id,
                    'trip_id' => $trip->id,
                    'amount_fcfa' => $bookingAmount,
                    'wallet_balance_fcfa' => (int) ($lockedPassenger->passenger_wallet_balance_fcfa ?? 0),
                ]
            );

            if ($trip->driver_id !== $user->id) {
                $this->createUserNotification(
                    $trip->driver,
                    'new_booking',
                    'Nouvelle reservation',
                    $user->name.' a reserve '.$seatsReserved.' place(s) sur votre trajet '.$routeLabel.'.',
                    [
                        'booking_id' => $booking->id,
                        'trip_id' => $trip->id,
                        'passenger_id' => $user->id,
                    ]
                );
            }

            return $booking->fresh(['trip.driver']);
        });

        return response()->json([
            'message' => 'Reservation enregistree avec succes.',
            'booking' => $this->serializeBookingCard($booking, $this->driverAverageRating($booking->trip?->driver_id)),
        ], 201);
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensurePassenger($user)) {
            return $response;
        }

        if ($booking->passenger_id !== $user->id) {
            return response()->json([
                'message' => 'Cette reservation ne vous appartient pas.',
            ], 403);
        }

        $booking = DB::transaction(function () use ($booking): Booking {
            /** @var Booking $lockedBooking */
            $lockedBooking = Booking::query()
                ->lockForUpdate()
                ->whereKey($booking->id)
                ->firstOrFail();

            if ($lockedBooking->status !== 'upcoming') {
                throw ValidationException::withMessages([
                    'booking' => ['Seules les reservations a venir peuvent etre annulees.'],
                ]);
            }

            $trip = Trip::query()
                ->lockForUpdate()
                ->whereKey($lockedBooking->trip_id)
                ->firstOrFail();

            $updatedSeats = min($trip->seats_total, $trip->seats_available + $lockedBooking->seats_reserved);

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
                'seats_available' => $updatedSeats,
            ])->save();

            $routeLabel = ($trip->from_city ?: 'Depart').' -> '.($trip->to_city ?: 'Destination');

            $this->createUserNotification(
                $lockedBooking->passenger,
                'booking_cancelled',
                'Reservation annulee',
                'Votre reservation pour '.$routeLabel.' a bien ete annulee.',
                [
                    'booking_id' => $lockedBooking->id,
                    'trip_id' => $trip->id,
                    'refund_amount_fcfa' => (int) $lockedBooking->booked_price_fcfa,
                ]
            );

            if ($lockedPassenger) {
                $this->createUserNotification(
                    $lockedPassenger,
                    'booking_refund_passenger_cancel',
                    'Remboursement de reservation',
                    'Le montant de '.(int) $lockedBooking->booked_price_fcfa.' FCFA a ete rembourse dans votre compte passager.',
                    [
                        'booking_id' => $lockedBooking->id,
                        'trip_id' => $trip->id,
                        'refund_amount_fcfa' => (int) $lockedBooking->booked_price_fcfa,
                        'wallet_balance_fcfa' => (int) ($lockedPassenger->passenger_wallet_balance_fcfa ?? 0),
                    ]
                );
            }

            if ($trip->driver_id !== $lockedBooking->passenger_id) {
                $this->createUserNotification(
                    $trip->driver,
                    'booking_cancelled_passenger',
                    'Reservation annulee',
                    ($lockedBooking->passenger?->name ?? 'Un passager').' a annule sa reservation pour '.$routeLabel.'.',
                    [
                        'booking_id' => $lockedBooking->id,
                        'trip_id' => $trip->id,
                        'passenger_id' => $lockedBooking->passenger_id,
                    ]
                );
            }

            return $lockedBooking->fresh(['trip.driver']);
        });

        return response()->json([
            'message' => 'Reservation annulee avec succes.',
            'booking' => $this->serializeBookingCard($booking, $this->driverAverageRating($booking->trip?->driver_id)),
        ]);
    }

    public function rate(Request $request, Booking $booking): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensurePassenger($user)) {
            return $response;
        }

        if ($booking->passenger_id !== $user->id) {
            return response()->json([
                'message' => 'Cette reservation ne vous appartient pas.',
            ], 403);
        }

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $booking = DB::transaction(function () use ($booking, $validated, $user): Booking {
            /** @var Booking $lockedBooking */
            $lockedBooking = Booking::query()
                ->with(['trip.driver', 'passenger'])
                ->lockForUpdate()
                ->whereKey($booking->id)
                ->firstOrFail();

            if ($lockedBooking->status !== 'completed') {
                throw ValidationException::withMessages([
                    'booking' => ['Vous pouvez noter uniquement un trajet termine.'],
                ]);
            }

            $comment = isset($validated['comment']) ? trim((string) $validated['comment']) : null;

            $lockedBooking->forceFill([
                'passenger_rating' => (int) $validated['rating'],
                'passenger_review' => $comment !== '' ? $comment : null,
                'rated_at' => now(),
            ])->save();

            $trip = $lockedBooking->trip;
            if ($trip && $trip->driver_id !== $user->id) {
                $routeLabel = ($trip->from_city ?: 'Depart').' -> '.($trip->to_city ?: 'Destination');

                $this->createUserNotification(
                    $trip->driver,
                    'rating',
                    'Nouvelle note recue',
                    ($lockedBooking->passenger?->name ?? 'Un passager').' vous a attribue '.(int) $validated['rating'].'/5 pour le trajet '.$routeLabel.'.',
                    [
                        'booking_id' => $lockedBooking->id,
                        'trip_id' => $trip->id,
                        'rating' => (int) $validated['rating'],
                    ]
                );
            }

            return $lockedBooking->fresh(['trip.driver']);
        });

        return response()->json([
            'message' => 'Note enregistree avec succes.',
            'booking' => $this->serializeBookingCard($booking, $this->driverAverageRating($booking->trip?->driver_id)),
        ]);
    }

    public function confirmCompletion(Request $request, Booking $booking): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensurePassenger($user)) {
            return $response;
        }

        if ($booking->passenger_id !== $user->id) {
            return response()->json([
                'message' => 'Cette reservation ne vous appartient pas.',
            ], 403);
        }

        $validated = $request->validate([
            'decision' => ['required', 'in:yes,no'],
        ]);

        $decision = (string) $validated['decision'];

        $updatedBooking = DB::transaction(function () use ($booking, $user, $decision): Booking {
            /** @var Booking $lockedBooking */
            $lockedBooking = Booking::query()
                ->with(['trip.driver', 'passenger'])
                ->lockForUpdate()
                ->whereKey($booking->id)
                ->firstOrFail();

            if ($lockedBooking->status !== 'completed') {
                throw ValidationException::withMessages([
                    'booking' => ['Vous pouvez confirmer uniquement un trajet termine.'],
                ]);
            }

            if (($lockedBooking->passenger_completion_status ?? 'pending') !== 'pending') {
                throw ValidationException::withMessages([
                    'booking' => ['La confirmation de fin de course a deja ete traitee.'],
                ]);
            }

            $trip = $lockedBooking->trip;
            if (! $trip) {
                throw ValidationException::withMessages([
                    'booking' => ['Trajet introuvable pour cette reservation.'],
                ]);
            }

            $routeLabel = ($trip->from_city ?: 'Depart').' -> '.($trip->to_city ?: 'Destination');

            if ($decision === 'yes') {
                $payoutAmount = (int) ($lockedBooking->payout_amount_fcfa ?? $this->driverNetPayout((int) $lockedBooking->booked_price_fcfa));

                $lockedBooking->forceFill([
                    'passenger_completion_status' => 'confirmed',
                    'passenger_completion_confirmed_at' => now(),
                    'payout_status' => 'credited',
                    'payout_amount_fcfa' => $payoutAmount,
                    'payout_credited_at' => now(),
                ])->save();

                /** @var User|null $driver */
                $driver = User::query()
                    ->lockForUpdate()
                    ->whereKey($trip->driver_id)
                    ->first();

                if ($driver) {
                    $driver->forceFill([
                        'driver_wallet_balance_fcfa' => (int) ($driver->driver_wallet_balance_fcfa ?? 0) + $payoutAmount,
                    ])->save();

                    $this->createUserNotification(
                        $driver,
                        'trip_completion_confirmed_credit',
                        'Course confirmee',
                        'Le passager a confirme la fin de course '.$routeLabel.'. '.$payoutAmount.' FCFA ont ete credites sur votre solde.',
                        [
                            'trip_id' => $trip->id,
                            'booking_id' => $lockedBooking->id,
                            'payout_amount_fcfa' => $payoutAmount,
                        ]
                    );
                }

                $this->createUserNotification(
                    $user,
                    'trip_completion_confirmed',
                    'Confirmation enregistree',
                    'Merci. Vous avez confirme la fin de course '.$routeLabel.'.',
                    [
                        'trip_id' => $trip->id,
                        'booking_id' => $lockedBooking->id,
                        'decision' => 'yes',
                    ]
                );
            } else {
                $lockedBooking->forceFill([
                    'passenger_completion_status' => 'rejected',
                    'passenger_completion_confirmed_at' => now(),
                    'payout_status' => 'blocked',
                    'payout_credited_at' => null,
                ])->save();

                $this->createUserNotification(
                    $trip->driver,
                    'trip_completion_disputed',
                    'Fin de course contestee',
                    'Le passager a indique que la course '.$routeLabel.' n est pas terminee.',
                    [
                        'trip_id' => $trip->id,
                        'booking_id' => $lockedBooking->id,
                        'decision' => 'no',
                    ]
                );

                $this->createUserNotification(
                    $user,
                    'trip_completion_rejected',
                    'Signalement enregistre',
                    'Votre retour a ete transmis. Le credit conducteur est bloque en attendant verification.',
                    [
                        'trip_id' => $trip->id,
                        'booking_id' => $lockedBooking->id,
                        'decision' => 'no',
                    ]
                );
            }

            return $lockedBooking->fresh(['trip.driver']);
        });

        return response()->json([
            'message' => $decision === 'yes'
                ? 'Confirmation enregistree. Le conducteur a ete credite.'
                : 'Signalement enregistre. Le credit conducteur reste bloque.',
            'booking' => $this->serializeBookingCard($updatedBooking, $this->driverAverageRating($updatedBooking->trip?->driver_id)),
        ]);
    }

    private function ensurePassenger(User $user): ?JsonResponse
    {
        if ($user->role === 'passenger') {
            return null;
        }

        return response()->json([
            'message' => 'Acces reserve aux passagers.',
        ], 403);
    }

    private function serializeTripCard(Trip $trip, int $driverTrips, ?float $driverRating = null): array
    {
        $driver = $trip->driver;
        $departure = $trip->departure_at;
        $arrival = $trip->arrival_at;

        $durationMinutes = null;

        if ($departure !== null && $arrival !== null) {
            $durationMinutes = max(0, $departure->diffInMinutes($arrival, false));
        }

        return [
            'id' => $trip->id,
            'driver' => [
                'name' => $driver?->name ?? 'Conducteur',
                'rating' => $driverRating,
                'trips' => $driverTrips,
                'avatar' => $this->initialFromName($driver?->name),
                'avatar_url' => $this->avatarUrl($driver?->avatar_path),
            ],
            'from' => $trip->from_label ?: $trip->from_city,
            'to' => $trip->to_label ?: $trip->to_city,
            'departureTime' => $departure?->format('H:i') ?? '--:--',
            'arrivalTime' => $arrival?->format('H:i') ?? '--:--',
            'date' => $this->humanDate($departure),
            'price' => (string) $trip->price_fcfa,
            'seatsAvailable' => $trip->seats_available,
            'vehicleType' => $trip->vehicle_type ? ucfirst($trip->vehicle_type) : 'Vehicule',
            'vehicleModel' => $trip->vehicle_model ?: ($trip->vehicle_type ? ucfirst($trip->vehicle_type) : 'Vehicule'),
            'amenities' => [],
            'durationMinutes' => $durationMinutes,
        ];
    }

    private function serializeTripDetails(Trip $trip, int $driverTripCount, ?Booking $passengerBooking, bool $isBookable, ?float $driverRating = null): array
    {
        $driver = $trip->driver;
        $departure = $trip->departure_at;
        $arrival = $trip->arrival_at;
        $durationMinutes = null;

        if ($departure !== null && $arrival !== null) {
            $durationMinutes = max(0, $departure->diffInMinutes($arrival, false));
        }

        $driverMemberSince = $driver?->created_at ? $driver->created_at->format('Y') : null;

        return [
            'id' => $trip->id,
            'driver' => [
                'id' => $driver?->id,
                'name' => $driver?->name ?? 'Conducteur',
                'rating' => $driverRating,
                'totalTrips' => $driverTripCount,
                'memberSince' => $driverMemberSince,
                'avatar' => $this->initialFromName($driver?->name),
                'avatar_url' => $this->avatarUrl($driver?->avatar_path),
                'isVerified' => $driver?->identity_verification_status === 'verified',
            ],
            'from' => [
                'location' => $trip->from_city,
                'address' => $trip->from_label ?: $trip->from_city,
                'time' => $departure?->format('H:i') ?? '--:--',
            ],
            'to' => [
                'location' => $trip->to_city,
                'address' => $trip->to_label ?: $trip->to_city,
                'time' => $arrival?->format('H:i') ?? '--:--',
            ],
            'date' => $departure
                ? $departure->locale('fr')->translatedFormat('l j F Y')
                : 'Non defini',
            'price' => (string) $trip->price_fcfa,
            'seatsAvailable' => $trip->seats_available,
            'totalSeats' => $trip->seats_total,
            'status' => $trip->status,
            'durationMinutes' => $durationMinutes,
            'vehicle' => [
                'type' => $trip->vehicle_type ? ucfirst($trip->vehicle_type) : 'Vehicule',
                'model' => $trip->vehicle_model ?: ($trip->vehicle_type ? ucfirst($trip->vehicle_type) : 'Vehicule'),
                'color' => 'Non precise',
                'plate' => $driver?->vehicle_plate,
            ],
            'amenities' => [],
            'description' => 'Trajet '.$trip->from_city.' -> '.$trip->to_city.'.',
            'bookingFee' => 300,
            'can_book' => $isBookable,
            'passenger_booking' => $passengerBooking ? [
                'id' => $passengerBooking->id,
                'status' => $passengerBooking->status,
                'seats_reserved' => $passengerBooking->seats_reserved,
                'booked_at' => $passengerBooking->booked_at,
                'completion_confirmation_status' => $passengerBooking->passenger_completion_status,
                'can_confirm_completion' => $passengerBooking->status === 'completed' && ($passengerBooking->passenger_completion_status ?? 'pending') === 'pending',
            ] : null,
        ];
    }

    private function serializeBookingSummary(Booking $booking, ?float $driverRating = null): array
    {
        $serialized = $this->serializeBookingCard($booking, $driverRating);

        $trip = $booking->trip;
        if ($trip?->departure_at) {
            $serialized['date'] = $this->humanDate($trip->departure_at);
        }

        return $serialized;
    }

    private function serializeBookingCard(Booking $booking, ?float $driverRating = null): array
    {
        $trip = $booking->trip;
        $driver = $trip?->driver;

        $vehicle = trim(implode(' ', array_filter([
            $trip?->vehicle_type ? ucfirst((string) $trip->vehicle_type) : null,
            $trip?->vehicle_model,
        ])));

        return [
            'id' => $booking->id,
            'trip_id' => $booking->trip_id,
            'driver' => [
                'name' => $driver?->name ?? 'Conducteur',
                'rating' => $driverRating,
                'avatar' => $this->initialFromName($driver?->name),
                'avatar_url' => $this->avatarUrl($driver?->avatar_path),
            ],
            'from' => $trip?->from_label ?: $trip?->from_city,
            'to' => $trip?->to_label ?: $trip?->to_city,
            'date' => $trip?->departure_at?->format('Y-m-d') ?? 'Non defini',
            'time' => $trip?->departure_at?->format('H:i') ?? '--:--',
            'price' => (string) $booking->booked_price_fcfa,
            'status' => $booking->status,
            'vehicle' => $vehicle !== '' ? $vehicle : 'Vehicule',
            'seats_reserved' => $booking->seats_reserved,
            'booked_at' => $booking->booked_at,
            'cancelled_at' => $booking->cancelled_at,
            'passenger_rating' => $booking->passenger_rating !== null ? (int) $booking->passenger_rating : null,
            'passenger_review' => $booking->passenger_review,
            'rated_at' => $booking->rated_at,
            'completion_confirmation_status' => $booking->passenger_completion_status,
            'completion_confirmed_at' => $booking->passenger_completion_confirmed_at,
            'can_confirm_completion' => $booking->status === 'completed' && ($booking->passenger_completion_status ?? 'pending') === 'pending',
            'payout_status' => $booking->payout_status,
            'payout_amount_fcfa' => $booking->payout_amount_fcfa !== null ? (int) $booking->payout_amount_fcfa : null,
            'payout_credited_at' => $booking->payout_credited_at,
            'can_cancel' => $booking->status === 'upcoming',
            'can_rate' => $booking->status === 'completed',
        ];
    }

    private function driverNetPayout(int $grossAmount): int
    {
        $sanitized = max(0, $grossAmount);
        $netRate = (100 - self::DRIVER_COMMISSION_PERCENT) / 100;

        return (int) floor($sanitized * $netRate);
    }

    private function popularRoutes(): array
    {
        /** @var EloquentCollection<int, Trip> $trips */
        $trips = Trip::query()
            ->where('status', 'scheduled')
            ->where('seats_available', '>', 0)
            ->where('departure_at', '>=', now()->subDay())
            ->orderBy('departure_at')
            ->limit(300)
            ->get();

        if ($trips->isEmpty()) {
            return [];
        }

        /** @var Collection<int, array<string, mixed>> $routes */
        $routes = $trips
            ->groupBy(static fn (Trip $trip): string => strtolower(trim($trip->from_city)).'|'.strtolower(trim($trip->to_city)))
            ->map(function (EloquentCollection $group): array {
                /** @var Trip $first */
                $first = $group->first();

                $prices = $group
                    ->pluck('price_fcfa')
                    ->map(static fn ($value): int => (int) $value)
                    ->filter(static fn (int $value): bool => $value > 0)
                    ->values();

                $durations = $group
                    ->map(static function (Trip $trip): ?int {
                        if ($trip->departure_at === null || $trip->arrival_at === null) {
                            return null;
                        }

                        return max(0, $trip->departure_at->diffInMinutes($trip->arrival_at, false));
                    })
                    ->filter(static fn (?int $minutes): bool => $minutes !== null)
                    ->values();

                $priceLabel = 'N/A';
                if ($prices->isNotEmpty()) {
                    $minPrice = $prices->min();
                    $maxPrice = $prices->max();
                    $priceLabel = $minPrice === $maxPrice
                        ? (string) $minPrice
                        : $minPrice.'-'.$maxPrice;
                }

                $timeLabel = $durations->isNotEmpty()
                    ? '~'.(int) round((float) $durations->avg()).'min'
                    : 'Horaire variable';

                return [
                    'from' => $first->from_city,
                    'to' => $first->to_city,
                    'trips' => $group->count(),
                    'price' => $priceLabel,
                    'time' => $timeLabel,
                ];
            })
            ->sortByDesc('trips')
            ->take(5)
            ->values();

        return $routes->all();
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

    private function initialFromName(?string $name): string
    {
        if (! $name) {
            return 'C';
        }

        $trimmed = trim($name);

        return $trimmed !== '' ? strtoupper(substr($trimmed, 0, 1)) : 'C';
    }

    private function avatarUrl(?string $avatarPath): ?string
    {
        if (! $avatarPath) {
            return null;
        }

        return rtrim(request()->getSchemeAndHttpHost(), '/').'/storage/'.ltrim($avatarPath, '/');
    }

    private function driverAverageRating($driverId): ?float
    {
        if (! $driverId) {
            return null;
        }

        $average = Booking::query()
            ->join('trips', 'trips.id', '=', 'bookings.trip_id')
            ->where('trips.driver_id', $driverId)
            ->whereNotNull('bookings.passenger_rating')
            ->avg('bookings.passenger_rating');

        return $average !== null ? round((float) $average, 1) : null;
    }

    private function driverRatingsByIds(Collection $driverIds): array
    {
        $ids = $driverIds
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $averages = Booking::query()
            ->select('trips.driver_id', DB::raw('AVG(bookings.passenger_rating) as avg_rating'))
            ->join('trips', 'trips.id', '=', 'bookings.trip_id')
            ->whereIn('trips.driver_id', $ids)
            ->whereNotNull('bookings.passenger_rating')
            ->groupBy('trips.driver_id')
            ->pluck('avg_rating', 'trips.driver_id');

        $ratings = [];

        foreach ($averages as $driverId => $average) {
            $ratings[(string) $driverId] = round((float) $average, 1);
        }

        return $ratings;
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
}
