# Coolify v4 REST API: static Next.js app from private GitHub repo

Source: tag **v4.4.6** (latest release, 2026-10-09). `S` = `openapi.yaml@v4.4.6`, `C` = `app/Http/Controllers/Api/ApplicationsController.php@v4.4.6`, `R` = `routes/api.php@v4.4.6`. Base URL `https://<host>/api/v1`, header `Authorization: Bearer <token>`.

## 1. Create an app
**POST `/applications/private-github-app`** (S:698, R:244, ability `write`).
Required per spec (S:711): `project_uuid`, `server_uuid`, `environment_name`, `environment_uuid`, `github_app_uuid`, `git_repository`, `git_branch`, `build_pack`. The description says you need only one of environment_name/uuid. All are strings.
Optional fields used here: `ports_exposes` (string, S:742), `name`, `domains` (S:758), `is_static` (bool, S:774), `is_spa`, `is_auto_deploy_enabled` (S:780), `static_image` (enum `nginx:alpine`, S:789), `install_command` (S:793), `build_command` (S:796), `base_directory` (S:805), `publish_directory` (S:808), `custom_labels` (S:872), `instant_deploy` (bool, S:907), `is_http_basic_auth_enabled`/`http_basic_auth_username`/`http_basic_auth_password` (S:1024), `destination_uuid`, `autogenerate_domain` (default true), `force_domain_override`.
Responses: 201 `{uuid}`, 409 domain conflict `{message, warning, conflicts[]}`, 400, 401.
Public-repo contrast: **POST `/applications/public`** (S:316). Same body, but no `github_app_uuid`.
`git_repository` format for GH App, e.g. `owner/repo` or full URL: **UNCONFIRMED**.

## 2. Build pack for `next build` + `output: 'export'`
Enum: `nixpacks, railpack, static, dockerfile, dockercompose` (S, private-github-app body).
- `static` runs **no build**. It copies the repo into nginx as is (`deploy_static_buildpack` and `build_static_image`, `app/Jobs/ApplicationDeploymentJob.php@v4.4.6`:1214-1237, 4295-4305). It only fits pre-built output.
- **Use `build_pack: "nixpacks"` + `is_static: true` + `publish_directory: "out"`**. Nixpacks builds, then `COPY --from=<build> /app/out` into `static_image` nginx (Job.php:4395-4505). Matches the official docs: "Build strategy nixpacks, Site type Static, Output Directory `out`" (coollabsio/coolify-docs `content/docs/applications/framework-examples/javascript/nextjs.mdx`:18-22). `ports_exposes` "80" for nginx: inferred, **UNCONFIRMED**.
- `railpack` + `is_static` also exists (Job.php:3716-3723).

## 3. Domains, env, basic auth, labels
- `domains`: comma-separated URL string (S:758). PATCH `/applications/{uuid}` also accepts it (S:2256).
- Env: `POST /applications/{uuid}/envs`, `PATCH /applications/{uuid}/envs` (update; `key`+`value` required), `PATCH /applications/{uuid}/envs/bulk` (`{data:[...]}`), `DELETE /applications/{uuid}/envs/{env_uuid}` (S:2699-2935). Spec fields: `key`, `value`, `is_preview`, `is_literal`, `is_multiline`, `is_shown_once`. **Spec gap:** the controller also accepts `is_runtime`, `is_buildtime` (bool, default true) and `comment` (C:4219, 4246-4247, 4282). They are missing from the request schema but present in the `EnvironmentVariable` response schema (S:15326-15328).
- Basic auth: `is_http_basic_auth_enabled`, `http_basic_auth_username`, `http_basic_auth_password` on every create endpoint (S:1024; C:1251, 1261). They appeared in openapi.yaml at **v4.0.0-beta.411**; beta.410 lacks them. PATCH accepts them in code (C:3253, 3265-3268, 3350-3357). They are **missing from the PATCH spec**. Enabling requires both username and password.
- `custom_labels`: string that **must be base64-encoded**, otherwise 422 (C:4913-4932). `is_container_label_escape_enabled` (default true) escapes `$`.

## 4. Deploy and poll
- `POST /deploy?uuid=<app>[&force=true]` (S:6778, R:153, ability `deploy`). Returns `{deployments:[{message, resource_uuid, deployment_uuid}]}`. **Note:** `GET /deploy` now returns post_required (R:152).
- Alternative: `POST /applications/{uuid}/start?force=&instant_deploy=` → `{message, deployment_uuid}` (S:2935, R:290).
- Poll: `GET /deployments/{deployment_uuid}` → `ApplicationDeploymentQueue` with `status` and `logs` (S:6698). `GET /deployments/applications/{uuid}?skip=&take=` (S:6840). `POST /deployments/{uuid}/cancel`.
- Status values: `queued`, `in_progress`, `finished`, `failed`, `cancelled-by-user` (`app/Enums/ApplicationDeploymentStatus.php`).

## 5. Delete
`DELETE /applications/{uuid}` (S:2137, R:251, `write`). Query bools, all default true: `delete_configurations`, `delete_volumes`, `docker_cleanup`, `delete_connected_networks`. The work is asynchronous (`DeleteResourceJob`): 200 `{message:"Application deletion request queued."}` (C:2986-3022).

## 6. Auto-deploy
Field `is_auto_deploy_enabled` (bool, default true; S:780). GitHub App webhooks hit `POST /webhooks/source/github/events` (`routes/webhooks.php`:16). The app is resolved via the `X-GitHub-Hook-Installation-Target-Id` header plus its secret. Matching applications have `repository_project_id` = repo id, `source_id` = the app and `git_branch` = the pushed branch. A deploy runs only if `isDeployable()` is true, meaning `is_auto_deploy_enabled` (`Webhook/Github.php`:389-520; `Models/Application.php`:1335). `watch_paths` filters by path. `[skip ci]`/`[skip cd]` skips the deploy. No per-app webhook setup is needed with a GitHub App.

## 7. Tokens
Abilities: `read`, `read:sensitive`, `write`, `write:sensitive`, `deploy`, `root` (`app/Livewire/Security/ApiTokens.php`:129). `root` bypasses all checks (`app/Http/Middleware/ApiAbility.php`). Create and delete need `write`, deploy and cancel need `deploy`, GETs need `read`. Expiry options: 7, 30, 60, 90 or 365 days, or none. **Team scoping:** `team_id` is set to the session's current team at creation (`app/Models/User.php`:278), and `api.token.team` enforces it. To restrict a token to one team, switch to that team in the UI before creating it. Non-admin members are limited to read (ApiAbility). UI quirk: toggling `deploy` resets the selection to `['deploy']` only (ApiTokens.php:110). You need write+deploy for create+deploy, so add `write` after `deploy`. A token can only be created in the UI. An API endpoint for it is **UNCONFIRMED**.
