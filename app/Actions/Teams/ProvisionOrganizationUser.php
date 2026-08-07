<?php

namespace App\Actions\Teams;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationSeatType;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Teams\OrganizationAccountCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProvisionOrganizationUser
{
    public function __construct(private CreateTeam $createTeam) {}

    /**
     * Crear o reincorporar una identidad y asignarle una membresía organizacional.
     */
    public function handle(
        User $actor,
        Team $team,
        string $name,
        string $email,
        string $role,
    ): Membership {
        $validated = validator([
            'name' => trim($name),
            'email' => Str::lower(trim($email)),
            'role' => $role,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in(TeamRole::assignableValues())],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede superar 255 caracteres.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico no tiene un formato válido.',
            'email.max' => 'El correo electrónico no puede superar 255 caracteres.',
            'role.in' => 'El rol seleccionado no es válido.',
        ])->validate();

        $teamRole = TeamRole::from((string) $validated['role']);

        /** @var array{membership: Membership, user: User, was_created: bool} $result */
        $result = DB::transaction(function () use ($actor, $team, $validated, $teamRole): array {
            $lockedTeam = Team::query()->lockForUpdate()->findOrFail($team->id);

            if ($actor->isPlatformOwner()) {
                Gate::forUser($actor)->authorize('provisionAdministratorGlobally', $lockedTeam);

                if ($teamRole !== TeamRole::Admin) {
                    throw ValidationException::withMessages([
                        'role' => ['El Platform Owner solo puede crear administradores organizacionales.'],
                    ]);
                }
            } else {
                Gate::forUser($actor)->authorize('addMember', $lockedTeam);
            }

            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$validated['email']])
                ->lockForUpdate()
                ->first();

            $membership = $user === null
                ? null
                : Membership::query()
                    ->where('team_id', $lockedTeam->id)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

            if ($membership !== null && ! $membership->isRemoved()) {
                throw ValidationException::withMessages([
                    'email' => ['Este usuario ya pertenece actualmente a la organización.'],
                ]);
            }

            $hasPendingInvitation = $lockedTeam->invitations()
                ->whereRaw('LOWER(email) = ?', [$validated['email']])
                ->whereNull('accepted_at')
                ->where(fn ($query) => $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now()))
                ->exists();

            if ($hasPendingInvitation) {
                throw ValidationException::withMessages([
                    'email' => ['Este correo ya tiene una invitación pendiente en la organización.'],
                ]);
            }

            $seatType = OrganizationSeatType::fromRole($teamRole);

            if (! $lockedTeam->hasAvailableSeat($seatType)) {
                throw ValidationException::withMessages([
                    'role' => ["No hay cupos disponibles para {$seatType->label()} en esta organización."],
                ]);
            }

            $wasCreated = false;

            if ($user === null) {
                $user = User::query()->create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => Str::password(64),
                ]);

                $user->forceFill(['email_verified_at' => now()])->save();

                $this->createTeam->handle(
                    $user,
                    $user->name."'s Team",
                    isPersonal: true,
                );

                $wasCreated = true;
            }

            if ($membership !== null) {
                $updates = [
                    'role' => $teamRole,
                    'status' => MembershipStatus::Active,
                    'status_changed_by' => $actor->id,
                    'status_changed_at' => now(),
                ];

                if ($membership->role !== $teamRole) {
                    $updates['role_changed_by'] = $actor->id;
                    $updates['role_changed_at'] = now();
                }

                $membership->update($updates);
            } else {
                $membership = $lockedTeam->memberships()->create([
                    'user_id' => $user->id,
                    'role' => $teamRole,
                    'status' => MembershipStatus::Active,
                    'created_by' => $actor->id,
                ]);
            }

            if ($wasCreated) {
                $user->switchTeam($lockedTeam);
            }

            return [
                'membership' => $membership->refresh(),
                'user' => $user,
                'was_created' => $wasCreated,
            ];
        });

        if ($result['was_created']) {
            $result['user']->notify(new OrganizationAccountCreated(
                Password::broker()->createToken($result['user']),
                $team,
                $teamRole,
            ));
        }

        return $result['membership'];
    }
}
