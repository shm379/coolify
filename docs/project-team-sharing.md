# Project ownership transfer and cross-team deployment access

This fork provides **two separate, intentionally limited workflows** in **Project → Settings**.

## 1. Share a project with another team's current members

A project owner who also owns another team can select that team and generate invitations for its existing members.

- Invitations must be **accepted by each recipient**. Accepting creates a `member` membership in the *project's owning team*, restricted to the selected project's UUID.
- **Deploy only** grants the existing `deploy` level (and implied `read`) without `write`, `delete`, or `read:sensitive`.
- **View only** grants `read` for that one project, without deployment.
- The recipient **switches to the project's owning team** to use it. The server remains managed by its original team. This is *not* a new cross-team server ownership or server-sharing mechanism.
- The team's SSH, server administration, and terminal routes remain restricted; members never become admins through these invitations.
- Existing memberships/invitations are **not overwritten**. Update their scopes or revoke access in **Team → Members**.
- **Newly joined members of the guest team do not receive access automatically.** Generate invitations again as needed.
- An owner can invite members of teams they do not own individually through the existing Team → Members invitation workflow. Choose the new **Deployer** preset and select the project.
- Links generated in Project Settings must be sent privately to recipients; this workflow does not send emails automatically.
- To change an existing invitation, revoke it in the Team invitations UI and issue a new one.

**Security boundary:** A deploy grant triggers the project's configured build/redeploy, and project read access includes logs. A malicious change in the GitHub repository, an untrusted dependency/build script, or secrets accidentally printed to logs can still compromise data. Protect GitHub branches, credentials, build pipelines, and Docker/volume access. Disabling the terminal is not complete workload isolation.

## 2. Move an empty project to another team

The source-team **owner** must also own the destination team. Enter the exact project name to confirm.

- The project, its environments and settings retain their IDs/UUIDs.
- Project/environment shared variables have their `team_id` updated in a database transaction, preserving encrypted values; team-wide variables remain in the original team.
- Projects with applications, services or databases (including soft-deleted resources) are **rejected**. The change must not orphan server/destination/source credentials or backups.

## Not yet supported

- Moving a project that contains deployed resources between teams while keeping their server, credentials, schedules, data volumes, DNS bindings and backups intact.
- Showing the source team's shared project under the guest team's own team switcher without joining the source team.
- Automatic ongoing synchronization/revocation based on guest-team membership changes.
- Tenant-grade isolation of multiple untrusted teams on a single Docker daemon.

These require a dependency-aware migration with explicit server/destination access grants, audit and rollback, plus workload isolation. Do **not** change `projects.team_id` manually on active workloads.

## Verification

```bash
php artisan test tests/Feature/ProjectTeamSharingAndTransferTest.php
php artisan test tests/Feature/TeamInvitationHandlingTest.php tests/Feature/CrossTeamIdorServerProjectTest.php tests/Unit/Policies/ProjectPolicyTest.php
```

The fork also contained a PHP parse error: `Server::sshEndpointInUse()` was nested in `Server::getIp()`. The accompanying fix moves it to class scope so migrations/tests can execute.
