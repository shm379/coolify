<?php

namespace App\Livewire\Project;

use App\Enums\AccessLevel;
use App\Enums\AccessScope;
use App\Models\Project;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Services\Access\Grant;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ShareWithTeam extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $projectId;

    public ?int $targetTeamId = null;

    public string $accessLevel = 'deploy';

    /** @var list<array{email: string, link: string}> */
    public array $invitations = [];

    public int $skipped = 0;

    public function mount(int $projectId): void
    {
        $project = Project::ownedByCurrentTeam()->findOrFail($projectId);
        $this->authorize('transferTeam', $project);
        $this->projectId = $projectId;
    }

    public function invite(): void
    {
        $this->validate([
            'targetTeamId' => ['required', 'integer', Rule::exists('teams', 'id')],
            'accessLevel' => ['required', Rule::in(['read', 'deploy'])],
        ]);

        $project = Project::ownedByCurrentTeam()->findOrFail($this->projectId);
        $this->authorize('transferTeam', $project);
        $destination = Team::findOrFail($this->targetTeamId);

        // Membership of another team must not be exposed to a source-team admin.
        // The actor has to be owner of both teams to invite the destination members.
        abort_unless(auth()->user()->teams()
            ->where('teams.id', $destination->id)
            ->wherePivot('role', 'owner')->exists(), 403);

        if ($destination->id === $project->team_id) {
            $this->addError('targetTeamId', 'Choose another team.');

            return;
        }

        $members = $destination->members()->limit(51)->get();
        if ($members->count() > 50) {
            $this->addError('targetTeamId', 'A team with more than 50 members must be shared in smaller batches.');

            return;
        }

        $level = AccessLevel::from($this->accessLevel);
        $access = Grant::listToArray([new Grant(AccessScope::Project, $project->uuid, [$level])]);
        $this->invitations = [];
        $this->skipped = 0;

        foreach ($members as $member) {
            // Never replace an existing membership, which may have stronger grants.
            if ($member->teams()->where('teams.id', $project->team_id)->exists()
                || TeamInvitation::query()->where('team_id', $project->team_id)->where('email', $member->email)->exists()) {
                $this->skipped++;

                continue;
            }

            // Invitation acceptance is required: no silent cross-team memberships.
            $uuid = new_public_id(32);
            $link = route('team.invitation.show', ['uuid' => $uuid]);
            TeamInvitation::create([
                'team_id' => $project->team_id,
                'uuid' => $uuid,
                'email' => $member->email,
                'role' => 'member',
                'link' => $link,
                'via' => 'link',
                'access' => $access,
            ]);
            $this->invitations[] = ['email' => $member->email, 'link' => $link];
        }

        auditLog('ui.project.team_share_invitations_created', [
            'team_id' => $project->team_id,
            'project_uuid' => $project->uuid,
            'destination_team_id' => $destination->id,
            'access_level' => $level->value,
            'invitation_count' => count($this->invitations),
            'skipped_count' => $this->skipped,
        ]);

        $this->dispatch('success', count($this->invitations).' scoped invitations generated.');
    }

    public function render()
    {
        $project = Project::ownedByCurrentTeam()->findOrFail($this->projectId);
        $this->authorize('transferTeam', $project);

        return view('livewire.project.share-with-team', [
            'destinationTeams' => auth()->user()->teams()
                ->wherePivot('role', 'owner')
                ->where('teams.id', '!=', $project->team_id)
                ->orderBy('teams.name')->get(['teams.id', 'teams.name']),
        ]);
    }
}
