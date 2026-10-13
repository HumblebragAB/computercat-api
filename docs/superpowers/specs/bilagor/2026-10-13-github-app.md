# GitHub App (org-owned, server-side) — create repos from template

Research date: 2026-10-13. Source: docs.github.com only. No API calls were made.
UNCONFIRMED = docs do not state it; verify empirically (read-only) or with GitHub support.

## 1. Create repo from template

`POST /repos/{template_owner}/{template_repo}/generate` (API version header `X-GitHub-Api-Version: 2022-11-28`)
Source: https://docs.github.com/en/rest/repos/repos#create-a-repository-using-a-template

Body:
| field | type | required | notes |
|---|---|---|---|
| `owner` | string | no | "The organization or person who will own the new repository." Docs: "To create a new repository in an organization, the authenticated user must be a member of the specified organization." |
| `name` | string | **yes** | name of new repo |
| `description` | string | no | |
| `include_all_branches` | boolean | no, default `false` | "include the directory structure and files from all branches" |
| `private` | boolean | no, default **`false`** | default creates a PUBLIC repo. Always send `private: true` explicitly. |

- Response: `201 Created` (only documented code; errors not enumerated).
- Template must have `is_template: true` (check with `GET /repos/{owner}/{repo}`).
- If the template is not public, "the authenticated user must own or be a member of an organization that owns the repository".
- Classic/OAuth scopes (not relevant for an App): `public_repo` or `repo`; `repo` for private.

GitHub App permissions (https://docs.github.com/en/rest/authentication/permissions-required-for-github-apps):
- Repository permission **Administration: write** — listed for `/generate`, token types "UAT, IAT" (user access token, installation access token).
- Repository permission **Contents: read** — also listed for `/generate`, "UAT, IAT".
- Both rows are marked "additional permissions required" (✓); the page does not spell out how they combine. Reasonable reading: Administration write (to create) + Contents read (on the template). UNCONFIRMED whether Contents read is needed only on the template repo or broadly.
- **Installation access token: yes, supported** ("IAT" listed).

UNCONFIRMED:
- How the "authenticated user must be a member of the org" rule applies to an installation token (the App acts as itself, not a user). The permissions table says IAT works, so presumably the installation on the org satisfies it.
- Whether a token scoped via `repositories: [template]` can create a new repo (the new repo isn't in the token's list at issue time). Safest: request the token for the creation call with no `repositories` restriction, or test.
- Whether org setting "members can create repositories" restricts Apps.
- Alternative endpoint `POST /orgs/{org}/repos` (Administration: write, UAT/IAT) is not from template.

## 2. Authentication as an installation

JWT (https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/generating-a-json-web-token-jwt-for-a-github-app):
- Algorithm **RS256**, signed with the App's private key (.pem).
- `iat`: recommended 60 seconds in the past ("To protect against clock drift").
- `exp`: "no more than 10 minutes into the future".
- `iss`: "The client ID or application ID of your GitHub App" — "Use of the client ID is recommended."

Installation token:
- `POST /app/installations/{installation_id}/access_tokens`, authenticated with `Authorization: Bearer <JWT>` ("You must use a JWT to access this endpoint").
  Source: https://docs.github.com/en/rest/apps/apps#create-an-installation-access-token-for-an-app
- Body (all optional): `repositories` (string[] names), `repository_ids` (int[]), `permissions` (object, e.g. `{"administration":"write","contents":"write"}`). Up to 500 repositories.
- Omit `repositories` → token covers all repos the installation can access. Omit `permissions` → all permissions granted to the App. Cannot exceed what the installation has.
- Response 201: `token`, `expires_at`, `permissions`, `repository_selection` (`all`/`selected`), `repositories`.
- Lifetime: **1 hour** (https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/generating-an-installation-access-token-for-a-github-app).
- Finding installation id: `GET /orgs/{org}/installation` (JWT), or `GET /repos/{owner}/{repo}/installation`, `GET /app/installations`, or from webhook payload. Can be stored as config since it is stable per installation.
- UNCONFIRMED: whether an already-issued token without `repositories` restriction automatically covers a repo created after issuance. Simplest: mint a fresh token per operation (cheap; tokens are short-lived anyway).

Laravel note: use an existing JWT lib (e.g. firebase/php-jwt or lcobucci/jwt) — standard RS256; private key from secret store/env, never committed.

## 3. Minimal permissions and blast radius

Minimal App permission set (repository permissions):
- **Administration: write** (required for `/generate`)
- **Contents: write** (read covers the template requirement; write needed to push/modify files afterwards)
- **Metadata: read** (GitHub makes this mandatory for any repository permission — standard behaviour; not re-verified in this pass, UNCONFIRMED wording)
- No organization permissions needed for these operations.

Blast radius of Administration: write (same permissions page):
- `DELETE /repos/{owner}/{repo}` — **Administration: write, UAT/IAT** → **yes, the App can delete repositories** it has access to.
- `PATCH /repos/{owner}/{repo}` (incl. `archived`, `private`/visibility, name, default branch) — Administration: write.
- Plus everything else under Administration (collaborators, branch protection, webhooks, deploy keys, etc. — see the Administration section of the permissions page). There is no finer-grained "create only" permission.

Avoiding deletion:
- Archive = `PATCH /repos/{owner}/{repo}` with `{"archived": true}` (https://docs.github.com/en/rest/repos/repos#update-a-repository). Same permission as delete, so archiving does not reduce the App's capability; it only means our code never calls DELETE.
- Code-level mitigation: wrap the GitHub client so only `generate`, contents endpoints and (optionally) PATCH archived are callable; never implement DELETE.
- Token-level mitigation: mint tokens with `permissions` narrowed per call — e.g. `{"contents":"write"}` for content work, and request `administration: write` only for the creation call, scoped where possible.
- Org-level: Delete docs say 403 is returned when "an organization owner has prevented members from deleting organization-owned repositories". UNCONFIRMED whether that org setting also blocks GitHub Apps/installation tokens.
- Installation scoping: install the App with "Only select repositories" (template only). Docs: "If the GitHub App creates any repositories later, the app will automatically be granted access to those repositories as well." So the App can only ever touch the template + repos it created — limits delete blast radius to those. (https://docs.github.com/en/apps/using-github-apps/reviewing-and-modifying-installed-github-apps)
- Private key is the crown jewel: anyone holding it can mint tokens with full installed permissions.

## 4. Rate limits

https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api
- Primary, installation tokens: min 5,000 req/h; +50/h per repo over 20 repos and +50/h per org user over 20 members; cap 12,500/h; Enterprise Cloud org: 15,000/h.
- JWT-authenticated requests: no limit stated (UNCONFIRMED).
- Secondary: ≤100 concurrent requests; ≤900 points/min for REST (GET=1, POST/PATCH/PUT/DELETE=5); ≤90 s CPU per 60 s; **content creation ≤80/min and ≤500/hour** ("Some endpoints have lower limits"; repo-generation-specific limit not stated — UNCONFIRMED); ≤2,000 token requests/hour for Apps.
- Handling: honour `retry-after`; if `x-ratelimit-remaining: 0` wait until `x-ratelimit-reset` (epoch s); otherwise wait ≥1 min, then exponential backoff with a cap. Continuing while limited "may result in the banning of your integration."
- Occasional repo creation is far below all limits.
- Note: `/generate` is async in practice — repo contents may not be ready immediately after 201. UNCONFIRMED in docs fetched here; poll before writing contents.

## 5. Other Apps with "All repositories" (e.g. Claude GitHub App)

- Docs fetched do **not** explicitly say that "All repositories" includes repos created later. Pages checked: reviewing-and-modifying-installed-github-apps, installing-a-github-app-from-a-third-party, installing-your-own-github-app. They only state the "Only select repositories" rule: an App gets access to repos *it itself* creates.
- **UNCONFIRMED** (docs): whether a repo created by our App is automatically covered by another App's "All repositories" installation. The name and the `repository_selection: "all"` model strongly suggest yes (access is evaluated against the whole account rather than a list), but this is not stated verbatim. Verify by creating a test repo and checking `GET /repos/{owner}/{repo}/installation` for the other App, or checking the other App's installation settings in the org UI.
- If the other App is on "Only select repositories", new repos are **not** added automatically (the auto-grant applies only to the creating App); an org owner or repo admin must add them.
