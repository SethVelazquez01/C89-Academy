<?php

namespace App\Http\Responses\Concerns;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

trait RedirectsToCurrentTeam
{
    protected function redirectPathForCurrentTeam(Request $request, string $redirect): string
    {
        $team = $this->currentTeam($request);

        $request->session()->forget('url.intended');

        URL::defaults(['current_team' => $team->slug]);

        return "/{$team->slug}{$redirect}";
    }

    protected function currentTeam(Request $request): Team
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $team = $user->currentTeam;

        if ($team && $user->belongsToTeam($team)) {
            return $team;
        }

        $team = $user->fallbackTeam();

        abort_if(! $team, 403);

        $user->switchTeam($team);

        return $team;
    }
}
