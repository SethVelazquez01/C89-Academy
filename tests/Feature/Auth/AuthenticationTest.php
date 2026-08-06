<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('collaborators ignore a stale intended admin url when logging in', function () {
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);
    $collaborator->switchTeam($team);

    $response = $this
        ->withSession([
            'url.intended' => route('admin.courses.index', ['current_team' => $team]),
        ])
        ->post(route('login.store'), [
            'email' => $collaborator->email,
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('url.intended')
        ->assertRedirect(route('dashboard', ['current_team' => $team], absolute: false));

    $this->assertAuthenticatedAs($collaborator);
});

test('login repairs a current organization that no longer grants access', function () {
    $collaborator = User::factory()->create();
    $availableTeam = Team::factory()->create(['name' => 'A Academy']);
    $unavailableTeam = Team::factory()->create(['name' => 'Unavailable Academy']);

    $availableTeam->members()->attach($collaborator, ['role' => TeamRole::Member->value]);
    $collaborator->forceFill(['current_team_id' => $unavailableTeam->id])->save();

    $response = $this->post(route('login.store'), [
        'email' => $collaborator->email,
        'password' => 'password',
    ]);

    $collaborator->refresh();
    $currentTeam = $collaborator->currentTeam;

    assert($currentTeam instanceof Team);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', ['current_team' => $currentTeam], absolute: false));

    expect($collaborator->belongsToTeam($currentTeam))->toBeTrue()
        ->and($collaborator->current_team_id)->not->toBe($unavailableTeam->id);
});

test('passkey login response redirects to the current team dashboard', function () {
    $user = User::factory()->create();

    $request = Request::create(route('login', absolute: false), 'GET', server: [
        'HTTP_ACCEPT' => 'application/json',
    ]);
    $request->setLaravelSession($this->app['session.store']);
    $request->setUserResolver(fn () => $user);

    $jsonResponse = app(PasskeyLoginResponse::class)->toResponse($request);

    expect($jsonResponse->getData()->redirect)->toBe(route('dashboard', ['current_team' => $user->personalTeam()->slug]));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrorsIn('email');

    $this->assertGuest();
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    if (! Features::canManageTwoFactorAuthentication()) {
        $this->markTestSkipped('Two-factor authentication is not enabled.');
    }

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});
