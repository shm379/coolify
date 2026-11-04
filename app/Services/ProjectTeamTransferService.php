<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\StandaloneSqlite;
use App\Models\Team;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Transfer ownership of an undeployed project without sharing Docker access across teams.
 *
 * Deployed resources cannot move by changing project.team_id: their server, destination,
 * SSH key, source, and backup permissions belong to the original team. Reject those moves
 * until a separate, dependency-aware migration workflow is implemented.
 */
final class ProjectTeamTransferService
{
    public function transfer(Project $project, Team $destination, User $actor): void
    {
        DB::transaction(function () use ($project, $destination, $actor) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $sourceTeamId = $project->team_id;

            // Do not depend on the currently selected team or cached Livewire properties.
            Gate::forUser($actor)->authorize('transferTeam', $project);

            if ($sourceTeamId === $destination->id) {
                throw ValidationException::withMessages(['targetTeamId' => 'Choose a different team.']);
            }

            $ownsDestination = $actor->teams()
                ->where('teams.id', $destination->id)
                ->wherePivot('role', 'owner')
                ->exists();

            if (! $ownsDestination) {
                throw ValidationException::withMessages(['targetTeamId' => 'You must be the owner of both teams.']);
            }

            $environmentIds = Environment::query()->where('project_id', $project->id)->pluck('id');

            // Inspect trashed records as well: an undeployed project must not contain
            // resources which could later be restored under an unrelated team's ownership.
            foreach ([
                Application::class,
                Service::class,
                StandalonePostgresql::class,
                StandaloneRedis::class,
                StandaloneKeydb::class,
                StandaloneDragonfly::class,
                StandaloneClickhouse::class,
                StandaloneSqlite::class,
                StandaloneMongodb::class,
                StandaloneMysql::class,
                StandaloneMariadb::class,
            ] as $resourceClass) {
                $query = $resourceClass::query()->whereIn('environment_id', $environmentIds);
                if (in_array(SoftDeletes::class, class_uses_recursive($resourceClass), true)) {
                    $query->withTrashed();
                }
                if ($query->exists()) {
                    throw ValidationException::withMessages([
                        'targetTeamId' => 'This project contains resources. Move their servers, credentials and backups through a dedicated migration before changing teams.',
                    ]);
                }
            }

            $variables = SharedEnvironmentVariable::query()->where(function ($query) use ($project, $environmentIds) {
                $query->where('project_id', $project->id)->orWhereIn('environment_id', $environmentIds);
            });

            if ((clone $variables)->where('team_id', '!=', $sourceTeamId)->exists()) {
                throw ValidationException::withMessages([
                    'targetTeamId' => 'Some project variables belong to another team. Resolve this before moving the project.',
                ]);
            }

            // Keep project- and environment-scoped variables visible to the new owner.
            // Never change variables belonging to other projects or the original team.
            $variables->update(['team_id' => $destination->id]);
            $project->team_id = $destination->id;
            $project->save();
        });

        app(AccessResolver::class)->flush();
    }
}
