<?php

use App\Enums\TeamRole;
use App\Models\Exam;
use App\Models\Team;
use App\Models\User;

test('organization administrators receive the secure control dashboard', function () {
    $administrator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $administrator->switchTeam($team);
    Exam::factory()->for($team)->create(['title' => 'Evaluación operativa']);

    $this->actingAs($administrator)
        ->get(route('dashboard', ['current_team' => $team]))
        ->assertOk()
        ->assertSeeHtml('data-test="admin-dashboard"')
        ->assertSeeHtml('data-test="admin-security-warning"')
        ->assertSee('Centro de control')
        ->assertSee('Evaluación operativa')
        ->assertSee('Equipo')
        ->assertDontSee('No hay cursos publicados');
});

test('two factor administrators see their account as protected', function () {
    $administrator = User::factory()->withTwoFactor()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $administrator->switchTeam($team);

    $this->actingAs($administrator)
        ->get(route('dashboard', ['current_team' => $team]))
        ->assertOk()
        ->assertSee('2FA activo')
        ->assertSee('Protegido con 2FA')
        ->assertDontSeeHtml('data-test="admin-security-warning"');
});

test('team management is a main page outside settings', function () {
    $administrator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $administrator->switchTeam($team);

    expect(route('teams.edit', ['team' => $team], absolute: false))->toBe('/teams/'.$team->slug);

    $this->actingAs($administrator)
        ->get(route('teams.edit', ['team' => $team]))
        ->assertOk()
        ->assertSeeHtml('data-test="team-main-page"')
        ->assertSee('Administra usuarios, roles, cupos y accesos')
        ->assertDontSee('Perfil');
});
