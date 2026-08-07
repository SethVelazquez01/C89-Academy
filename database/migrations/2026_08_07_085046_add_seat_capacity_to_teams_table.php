<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedInteger('included_administrator_seats')->default(1)->after('is_personal');
            $table->unsignedInteger('additional_administrator_seats')->default(0)->after('included_administrator_seats');
            $table->unsignedInteger('included_collaborator_seats')->default(1)->after('additional_administrator_seats');
            $table->unsignedInteger('additional_collaborator_seats')->default(0)->after('included_collaborator_seats');
        });

        DB::table('teams')
            ->orderBy('id')
            ->eachById(function (stdClass $team): void {
                $administratorMemberships = DB::table('team_members')
                    ->where('team_id', $team->id)
                    ->whereIn('role', ['owner', 'admin'])
                    ->whereIn('status', ['active', 'suspended'])
                    ->count();

                $administratorInvitations = DB::table('team_invitations')
                    ->where('team_id', $team->id)
                    ->whereIn('role', ['owner', 'admin'])
                    ->whereNull('accepted_at')
                    ->where(fn ($query) => $query
                        ->whereNull('expires_at')
                        ->orWhere('expires_at', '>=', now()))
                    ->count();

                $collaboratorMemberships = DB::table('team_members')
                    ->where('team_id', $team->id)
                    ->where('role', 'member')
                    ->whereIn('status', ['active', 'suspended'])
                    ->count();

                $collaboratorInvitations = DB::table('team_invitations')
                    ->where('team_id', $team->id)
                    ->where('role', 'member')
                    ->whereNull('accepted_at')
                    ->where(fn ($query) => $query
                        ->whereNull('expires_at')
                        ->orWhere('expires_at', '>=', now()))
                    ->count();

                DB::table('teams')
                    ->where('id', $team->id)
                    ->update([
                        'included_administrator_seats' => max(
                            1,
                            $administratorMemberships + $administratorInvitations,
                        ),
                        'included_collaborator_seats' => max(
                            1,
                            $collaboratorMemberships + $collaboratorInvitations,
                        ),
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn([
                'included_administrator_seats',
                'additional_administrator_seats',
                'included_collaborator_seats',
                'additional_collaborator_seats',
            ]);
        });
    }
};
