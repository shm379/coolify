<?php

namespace App\Livewire\Project;

use App\Models\Project;
use App\Models\Team;
use App\Services\ProjectTeamTransferService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class MoveToTeam extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $projectId;

    public ?int $targetTeamId = null;

    public string $confirmationName = '';

    public function mount(int $projectId): void
    {
        $project = Project::ownedByCurrentTeam()->findOrFail($projectId);
        $this->authorize('transferTeam', $project);
        $this->projectId = $projectId;
    }

    public function transfer(ProjectTeamTransferService $service)
    {
        $this->validate([
            'targetTeamId' => ['required', 'integer', Rule::exists('teams', 'id')],
            'confirmationName' => ['required', 'string'],
        ]);

        $project = Project::ownedByCurrentTeam()->findOrFail($this->projectId);
        $this->authorize('transferTeam', $project);

        if (! hash_equals($project->name, $this->confirmationName)) {
            $this->addError('confirmationName', 'Enter the exact project name to confirm.');

            return;
        }

        $destination = Team::findOrFail($this->targetTeamId);
        $service->transfer($project, $destination, auth()->user());

        session()->flash('success', "Project moved to {$destination->name}. Switch to that team to manage it.");

        return redirect()->route('project.index');
    }

    public function render()
    {
        $project = Project::ownedByCurrentTeam()->findOrFail($this->projectId);
        $this->authorize('transferTeam', $project);

        return view('livewire.project.move-to-team', [
            'project' => $project,
            'destinationTeams' => auth()->user()->teams()
                ->wherePivot('role', 'owner')
                ->where('teams.id', '!=', $project->team_id)
                ->orderBy('teams.name')
                ->get(['teams.id', 'teams.name']),
        ]);
    }
}
