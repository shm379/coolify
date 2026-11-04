<section class="application-settings-section">
    <div class="application-settings-section-header">
        <div>
            <h2>Move project to another team</h2>
            <p>Transfer ownership to a team you own, without deleting project environments or settings.</p>
        </div>
    </div>
    <div class="application-settings-section-body">
        @if ($destinationTeams->isEmpty())
            <p class="text-sm text-neutral-500">You must own another team to move this project.</p>
        @else
            <form wire:submit="transfer" class="grid max-w-xl gap-4">
                <p class="text-sm text-amber-600 dark:text-amber-400">
                    Only projects without applications, databases or services can be moved.
                    Deployed resources use team-owned servers and credentials; sharing those between teams is not safe.
                    Project and environment variables will be transferred with the project.
                </p>
                <label class="block text-sm font-medium" for="move-project-team">Destination team</label>
                <select id="move-project-team" wire:model="targetTeamId"
                    class="w-full rounded border border-neutral-300 bg-white px-3 py-2 text-neutral-900 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white">
                    <option value="">Select a team</option>
                    @foreach ($destinationTeams as $team)
                        <option value="{{ $team->id }}">{{ $team->name }}</option>
                    @endforeach
                </select>
                @error('targetTeamId') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                <label class="block text-sm font-medium" for="move-project-confirm-name">
                    Confirm by entering the project name: <strong>{{ $project->name }}</strong>
                </label>
                <input id="move-project-confirm-name" type="text" wire:model="confirmationName" autocomplete="off"
                    class="w-full rounded border border-neutral-300 bg-white px-3 py-2 text-neutral-900 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white">
                @error('confirmationName') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                <div>
                    <x-forms.button type="submit">Move project</x-forms.button>
                </div>
            </form>
        @endif
    </div>
</section>
