<?php

use App\Enums\AccessLevel;
use App\Livewire\Project\MoveToTeam;
use App\Livewire\Project\ShareWithTeam;
use App\Livewire\Team\Member\Access;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\ProjectTeamTransferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->sourceTeam = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->owner->teams()->attach($this->sourceTeam, ['role' => 'owner']);
    $this->owner->teams()->attach($this->otherTeam, ['role' => 'owner']);
    $this->project = Project::factory()->create(['team_id' => $this->sourceTeam->id]);
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->sourceTeam]);
});

test('owner can transfer an empty project and its encrypted scoped variables', function () {
    $environment = $this->project->environments()->firstOrFail();
    $projectVariable = SharedEnvironmentVariable::create([
        'key' => 'PROJECT_SECRET',
        'value' => 'secret-value',
        'type' => 'project',
        'project_id' => $this->project->id,
        'team_id' => $this->sourceTeam->id,
    ]);
    $environmentVariable = SharedEnvironmentVariable::create([
        'key' => 'ENV_SECRET',
        'value' => 'environment-value',
        'type' => 'environment',
        'environment_id' => $environment->id,
        'team_id' => $this->sourceTeam->id,
    ]);
    $sourceTeamVariable = SharedEnvironmentVariable::create([
        'key' => 'SOURCE_TEAM_ONLY',
        'value' => 'not-transferred',
        'type' => 'team',
        'team_id' => $this->sourceTeam->id,
    ]);

    app(ProjectTeamTransferService::class)->transfer($this->project, $this->otherTeam, $this->owner);

    expect($this->project->fresh()->team_id)->toBe($this->otherTeam->id)
        ->and($projectVariable->fresh()->team_id)->toBe($this->otherTeam->id)
        ->and($projectVariable->fresh()->value)->toBe('secret-value')
        ->and($environmentVariable->fresh()->team_id)->toBe($this->otherTeam->id)
        ->and($sourceTeamVariable->fresh()->team_id)->toBe($this->sourceTeam->id);
});

test('moving a project with running resources is blocked without changing ownership', function () {
    $server = Server::factory()->create(['team_id' => $this->sourceTeam->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    Application::factory()->create([
        'environment_id' => $this->project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    expect(fn () => app(ProjectTeamTransferService::class)->transfer($this->project, $this->otherTeam, $this->owner))
        ->toThrow(ValidationException::class);
    expect($this->project->fresh()->team_id)->toBe($this->sourceTeam->id);
});

test('source team administrators cannot transfer project ownership', function () {
    $admin = User::factory()->create();
    $admin->teams()->attach($this->sourceTeam, ['role' => 'admin']);
    $admin->teams()->attach($this->otherTeam, ['role' => 'owner']);

    expect(fn () => app(ProjectTeamTransferService::class)->transfer($this->project, $this->otherTeam, $admin))
        ->toThrow(AuthorizationException::class);
});

test('destination team must also be owned by the actor', function () {
    $unownedTeam = Team::factory()->create();

    expect(fn () => app(ProjectTeamTransferService::class)->transfer($this->project, $unownedTeam, $this->owner))
        ->toThrow(ValidationException::class);
});

test('moving through Livewire requires an exact project name', function () {
    Livewire::test(MoveToTeam::class, ['projectId' => $this->project->id])
        ->set('targetTeamId', $this->otherTeam->id)
        ->set('confirmationName', 'not-'.$this->project->name)
        ->call('transfer')
        ->assertHasErrors(['confirmationName']);

    expect($this->project->fresh()->team_id)->toBe($this->sourceTeam->id);
});

test('sharing with a second team invites its members with deploy-only scopes', function () {
    $invitee = User::factory()->create();
    $invitee->teams()->attach($this->otherTeam, ['role' => 'member']);
    $server = Server::factory()->create(['team_id' => $this->sourceTeam->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $app = Application::factory()->create([
        'environment_id' => $this->project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    Livewire::test(ShareWithTeam::class, ['projectId' => $this->project->id])
        ->set('targetTeamId', $this->otherTeam->id)
        ->set('accessLevel', 'deploy')
        ->call('invite')
        ->assertHasNoErrors();

    $invitation = TeamInvitation::where('team_id', $this->sourceTeam->id)
        ->where('email', $invitee->email)->firstOrFail();

    expect($invitation->role)->toBe('member')
        ->and($invitation->access)->toBe([[
            'scope' => 'project',
            'uuid' => $this->project->uuid,
            'levels' => ['deploy'],
        ]])
        ->and($invitee->teams()->where('teams.id', $this->sourceTeam->id)->exists())->toBeFalse();

    // The new member's abilities, once they accept, exclude Write and ReadSensitive.
    $invitee->teams()->attach($this->sourceTeam, $invitation->membershipAttributes());
    $invitee->unsetRelation('teams');
    $access = app(AccessResolver::class);

    expect($access->allows($invitee, AccessLevel::Deploy, $this->project))->toBeTrue()
        ->and($access->allows($invitee, AccessLevel::Write, $this->project))->toBeFalse()
        ->and($access->allows($invitee, AccessLevel::ReadSensitive, $this->project))->toBeFalse()
        ->and($access->allows($invitee, AccessLevel::Deploy, $app))->toBeTrue()
        ->and($access->allows($invitee, AccessLevel::Write, $app))->toBeFalse()
        ->and(Gate::forUser($invitee)->denies('update', $server))->toBeTrue()
        ->and(Gate::forUser($invitee)->denies('canAccessTerminal'))->toBeTrue()
        ->and($invitee->isAdminOfTeam($this->sourceTeam->id))->toBeFalse();
});

test('share invitation cannot target a team the initiator does not own', function () {
    $unownedTeam = Team::factory()->create();
    $unownedUser = User::factory()->create();
    $unownedUser->teams()->attach($unownedTeam, ['role' => 'member']);

    Livewire::test(ShareWithTeam::class, ['projectId' => $this->project->id])
        ->set('targetTeamId', $unownedTeam->id)
        ->call('invite')
        ->assertForbidden();

    expect(TeamInvitation::where('team_id', $this->sourceTeam->id)->count())->toBe(0);
});

test('share invitation never broadens an existing source team membership', function () {
    $invitee = User::factory()->create();
    $invitee->teams()->attach($this->otherTeam, ['role' => 'member']);
    $invitee->teams()->attach($this->sourceTeam, [
        'role' => 'member',
        'access' => json_encode([]),
    ]);

    Livewire::test(ShareWithTeam::class, ['projectId' => $this->project->id])
        ->set('targetTeamId', $this->otherTeam->id)
        ->call('invite')
        ->assertHasNoErrors();

    expect(TeamInvitation::where('team_id', $this->sourceTeam->id)->where('email', $invitee->email)->exists())->toBeFalse();
    expect($invitee->teams()->where('teams.id', $this->sourceTeam->id)->firstOrFail()->pivot->access)->toBe('[]');
});

test('invited guest must accept before project access is granted', function () {
    $invitee = User::factory()->create();
    $invitee->teams()->attach($this->otherTeam, ['role' => 'member']);

    Livewire::test(ShareWithTeam::class, ['projectId' => $this->project->id])
        ->set('targetTeamId', $this->otherTeam->id)
        ->set('accessLevel', 'read')
        ->call('invite')->assertHasNoErrors();

    $invitation = TeamInvitation::where('team_id', $this->sourceTeam->id)
        ->where('email', $invitee->email)->firstOrFail();

    $this->actingAs($invitee);
    session(['currentTeam' => $this->otherTeam]);
    $this->post(route('team.invitation.accept', ['uuid' => $invitation->uuid]))
        ->assertRedirect(route('team.index'));

    $invitee->unsetRelation('teams');
    expect($invitee->teams()->where('teams.id', $this->sourceTeam->id)->exists())->toBeTrue()
        ->and(app(AccessResolver::class)->allows($invitee, AccessLevel::Read, $this->project))->toBeTrue()
        ->and(app(AccessResolver::class)->allows($invitee, AccessLevel::Deploy, $this->project))->toBeFalse()
        ->and(TeamInvitation::whereKey($invitation->id)->exists())->toBeFalse();
});

test('deployer preset is limited to one project', function () {
    $grants = Access::presetGrants('deployer', $this->project->uuid);

    expect($grants)->toHaveCount(1)
        ->and($grants[0]->scope->value)->toBe('project')
        ->and($grants[0]->uuid)->toBe($this->project->uuid)
        ->and($grants[0]->levels)->toBe([AccessLevel::Deploy])
        ->and(Access::presetGrants('deployer', null))->toBeNull();
});
