<section class="application-settings-section">
    <div class="application-settings-section-header">
        <div>
            <h2>Share project with another team's members</h2>
            <p>Invite current members of another team you own with project-scoped access.</p>
        </div>
    </div>
    <div class="application-settings-section-body">
        @if ($destinationTeams->isEmpty())
            <p class="text-sm text-neutral-500">To invite another team's members, you must own both teams.</p>
        @else
            <form wire:submit="invite" class="grid max-w-xl gap-4">
                <p class="text-sm text-neutral-500">
                    A shared member accepts an invitation and switches to the project's owning team to access only this project.
                    Server management and terminals remain restricted. New members of the invited team
                    do not automatically inherit this access.
                </p>
                <label class="block text-sm font-medium" for="project-share-target-team">Invite members from team</label>
                <select id="project-share-target-team" wire:model="targetTeamId"
                    class="w-full rounded border border-neutral-300 bg-white px-3 py-2 text-neutral-900 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white">
                    <option value="">Select a team</option>
                    @foreach ($destinationTeams as $team)
                        <option value="{{ $team->id }}">{{ $team->name }}</option>
                    @endforeach
                </select>
                @error('targetTeamId') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                <label class="block text-sm font-medium" for="project-share-access">Permission</label>
                <select id="project-share-access" wire:model="accessLevel"
                    class="w-full rounded border border-neutral-300 bg-white px-3 py-2 text-neutral-900 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white">
                    <option value="deploy">Deploy only (view and redeploy; no settings editing or secret-value permission)</option>
                    <option value="read">View only (no deploy or editing)</option>
                </select>
                <p class="text-xs text-amber-600 dark:text-amber-400">
                    Redeploying runs the configured GitHub revision/build on the project server.
                    Protect the repository, build scripts, and deployment credentials separately.
                </p>
                <div><x-forms.button type="submit">Generate scoped invitation links</x-forms.button></div>
            </form>
            @if ($invitations !== [] || $skipped > 0)
                <div class="mt-4 grid gap-2 text-sm">
                    <p>{{ count($invitations) }} invitations ready. {{ $skipped }} existing members or invitations skipped.</p>
                    <p class="text-neutral-500">Send each link privately to the corresponding recipient. They must log in and accept it.</p>
                    @foreach ($invitations as $invitation)
                        <div class="rounded border border-neutral-300 p-3 dark:border-neutral-700">
                            <div class="font-medium">{{ $invitation['email'] }}</div>
                            <a href="{{ $invitation['link'] }}" target="_blank" rel="noopener noreferrer"
                                class="break-all text-coollabs hover:underline">{{ $invitation['link'] }}</a>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</section>
