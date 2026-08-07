<?php

namespace App\Actions\Teams;

use App\Enums\SeatIncreaseRequestStatus;
use App\Models\SeatIncreaseRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateSeatIncreaseRequest
{
    /**
     * Registrar una solicitud de cupos adicionales para una organización.
     */
    public function handle(
        User $actor,
        Team $team,
        int $administratorSeats,
        int $collaboratorSeats,
        string $justification,
    ): SeatIncreaseRequest {
        $validated = validator([
            'administratorSeats' => $administratorSeats,
            'collaboratorSeats' => $collaboratorSeats,
            'justification' => trim($justification),
        ], [
            'administratorSeats' => ['required', 'integer', 'min:0', 'max:100000'],
            'collaboratorSeats' => ['required', 'integer', 'min:0', 'max:100000'],
            'justification' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'administratorSeats.min' => 'Los cupos de administradores no pueden ser negativos.',
            'administratorSeats.max' => 'La cantidad solicitada de administradores es demasiado grande.',
            'collaboratorSeats.min' => 'Los cupos de colaboradores no pueden ser negativos.',
            'collaboratorSeats.max' => 'La cantidad solicitada de colaboradores es demasiado grande.',
            'justification.required' => 'La justificación es obligatoria.',
            'justification.min' => 'La justificación debe tener al menos 10 caracteres.',
            'justification.max' => 'La justificación no puede superar 1000 caracteres.',
        ])->validate();

        if ((int) $validated['administratorSeats'] + (int) $validated['collaboratorSeats'] < 1) {
            throw ValidationException::withMessages([
                'seatIncreaseRequest' => ['Debes solicitar al menos un cupo adicional.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $team, $validated): SeatIncreaseRequest {
            $lockedTeam = Team::query()->lockForUpdate()->findOrFail($team->id);

            Gate::forUser($actor)->authorize('requestSeatIncrease', $lockedTeam);

            if ($lockedTeam->seatIncreaseRequests()
                ->where('status', SeatIncreaseRequestStatus::Pending->value)
                ->exists()) {
                throw ValidationException::withMessages([
                    'seatIncreaseRequest' => ['Esta organización ya tiene una solicitud de cupos pendiente.'],
                ]);
            }

            return $lockedTeam->seatIncreaseRequests()->create([
                'requested_by' => $actor->id,
                'requested_administrator_seats' => $validated['administratorSeats'],
                'requested_collaborator_seats' => $validated['collaboratorSeats'],
                'justification' => $validated['justification'],
                'status' => SeatIncreaseRequestStatus::Pending,
                'pending_marker' => true,
            ]);
        });
    }
}
