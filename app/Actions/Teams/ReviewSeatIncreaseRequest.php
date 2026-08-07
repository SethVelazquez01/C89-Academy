<?php

namespace App\Actions\Teams;

use App\Enums\SeatIncreaseRequestStatus;
use App\Models\SeatIncreaseRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReviewSeatIncreaseRequest
{
    /**
     * Aprobar o rechazar una solicitud como Platform Owner.
     */
    public function handle(
        User $actor,
        SeatIncreaseRequest $request,
        string $decision,
        ?string $reviewNotes = null,
    ): SeatIncreaseRequest {
        $validated = validator([
            'decision' => $decision,
            'reviewNotes' => $reviewNotes === null ? null : trim($reviewNotes),
        ], [
            'decision' => [
                'required',
                'string',
                Rule::in([
                    SeatIncreaseRequestStatus::Approved->value,
                    SeatIncreaseRequestStatus::Rejected->value,
                ]),
            ],
            'reviewNotes' => ['nullable', 'string', 'max:1000'],
        ], [
            'decision.in' => 'La decisión debe ser aprobar o rechazar.',
            'reviewNotes.max' => 'Las notas de revisión no pueden superar 1000 caracteres.',
        ])->validate();

        $status = SeatIncreaseRequestStatus::from((string) $validated['decision']);
        $notes = $validated['reviewNotes'];

        if ($status === SeatIncreaseRequestStatus::Rejected && blank($notes)) {
            throw ValidationException::withMessages([
                'reviewNotes' => ['Debes indicar el motivo del rechazo.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $request, $status, $notes): SeatIncreaseRequest {
            $lockedTeam = Team::query()->lockForUpdate()->findOrFail($request->team_id);

            Gate::forUser($actor)->authorize('reviewSeatIncreaseGlobally', $lockedTeam);

            $lockedRequest = SeatIncreaseRequest::query()
                ->whereKey($request->id)
                ->where('team_id', $lockedTeam->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedRequest->status->isPending() || $lockedRequest->pending_marker !== true) {
                throw ValidationException::withMessages([
                    'seatIncreaseRequest' => ['Esta solicitud ya fue revisada.'],
                ]);
            }

            if ($status === SeatIncreaseRequestStatus::Approved) {
                $lockedTeam->update([
                    'additional_administrator_seats' => $lockedTeam->additional_administrator_seats
                        + $lockedRequest->requested_administrator_seats,
                    'additional_collaborator_seats' => $lockedTeam->additional_collaborator_seats
                        + $lockedRequest->requested_collaborator_seats,
                ]);
            }

            $lockedRequest->update([
                'status' => $status,
                'pending_marker' => null,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            return $lockedRequest->refresh();
        });
    }
}
