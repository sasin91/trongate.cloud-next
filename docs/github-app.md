# The trongate.cloud GitHub App

One GitHub App handles everything `trongate.cloud` needs from GitHub:

- **Sign-in, sign-up & repository picker (`modules/github_auth`).** Its OAuth
  client powers "Continue with GitHub" on `/login` and "Connect GitHub" in
  `/apps/create`. Signed out, an unlinked GitHub account signs up automatically
  with its verified primary email (passwordless, with `apps.create` permission,
  refusing to take over an existing password account) and lands directly in the
  GitHub App install flow and `/apps/create` to deploy an app. When that user
  launches an app, they receive maintainer permissions on it automatically. The
  user access token is stored encrypted in `credentials` (`github-user-{id}`)
  solely to list the repositories the user can pick.
- **Repository access & private builds.** Users install the App on the
  repositories they want to deploy. Builds mint a short-lived installation token
  scoped to one repository with read-only contents (`Github_app::repository_token`),
  so private repositories and background builds never depend on any user session.
- **Automatic deploys on git push (`modules/webhooks`).** The App's webhook
  (`POST /webhooks/github`) delivers every push on every installation. Every app
  configured for that repository and branch (for example `main`) with
  **Deploy on push** enabled automatically queues a build and zero-downtime
  blue/green rollout. There is nothing for customers to set up per repository.

No user ever pastes a personal access token.

## Register the GitHub App

1. Generate a webhook secret: `openssl rand -hex 32`.
2. Create a GitHub App at `https://github.com/settings/apps/new` with these settings:

| Setting | Value |
| --- | --- |
| GitHub App name | `trongate.cloud` (any free name; the slug follows it) |
| Homepage URL | `https://trongate.cloud/` |
| Callback URL | `https://trongate.cloud/auth/github/callback` |
| Expire user authorization tokens | on (renewed automatically via refresh token) |
| Request user authorization (OAuth) during installation | **off** |
| Enable Device Flow | off |
| Setup URL | `https://trongate.cloud/auth/github/installed` |
| Redirect on update | on |
| Webhook | Active, URL `https://trongate.cloud/webhooks/github`, secret from step 1 |
| Repository permissions | Contents: **Read-only**, Metadata: **Read-only** |
| Account permissions | Email addresses: **Read-only** |
| Subscribe to events | **Push** |
| Where can this GitHub App be installed? | **Any account** |

"Request user authorization (OAuth) during installation" stays off on purpose:
users sign in or connect GitHub first, then install the App; the Setup URL
(`/auth/github/installed`) returns them to the page they started from.

3. Note the **Client ID** and **slug** (`github.com/apps/<slug>`), generate a
   **Client secret**, and generate a **Private key** (`.pem`).

## Configure `tc_panel` on FreeBSD

Five environment variables configure both `tc_panel_fpm` and `tc_worker`:

| Variable | Source |
| --- | --- |
| `GITHUB_APP_SLUG` | Public URL slug (`github.com/apps/<slug>`) |
| `GITHUB_APP_CLIENT_ID` | App page (`Iv...`) |
| `GITHUB_APP_CLIENT_SECRET` | "Generate a new client secret" |
| `GITHUB_APP_WEBHOOK_SECRET` | Random 32-byte hex secret from step 1 |
| `GITHUB_APP_PRIVATE_KEY` | PEM contents (single line with literal `\n`), or set `GITHUB_APP_PRIVATE_KEY_FILE` to a `0600` `.pem` file owned by `www` |

Store them in `/var/db/tc/panel/github.env` (mode `0600`, owned by `www:www`):

```sh
GITHUB_APP_SLUG="trongate-cloud"
GITHUB_APP_CLIENT_ID="Iv23li..."
GITHUB_APP_CLIENT_SECRET="..."
GITHUB_APP_WEBHOOK_SECRET="..."
GITHUB_APP_PRIVATE_KEY_FILE="/var/db/tc/panel/github-app.pem"
```

Then re-run `infra/scripts/platform-jails.sh` (or add the `env[GITHUB_APP_*]`
lines to `/zroot/tc/jails/tc_panel/etc/tc/php-fpm.conf`) and restart panel
services:

```sh
jexec tc_panel service tc_panel_fpm restart
jexec tc_panel service tc_worker restart
```

Without these variables, the login page omits the GitHub button,
`POST /webhooks/github` answers `503 not_configured`, and public GitHub
repositories continue to deploy manually as before.

## How the pieces fit

- `modules/github_auth/services/Github_app.php`: OAuth URL/exchange/refresh and
  RS256 App JWT + per-repository installation tokens, using `engine/Http_client.php`.
- `modules/github_auth/services/Github_client.php`: REST API client for user
  profile, installation repositories, branches, commits, and contents.
- `modules/github_auth/Github_auth.php`: `/auth/github`, `/auth/github/callback`,
  `/auth/github/installed`, and `POST /auth/github/disconnect`. OAuth state is
  HMAC-SHA256 signed with `installation_key()` and bound to a `SameSite=Lax`
  `HttpOnly` cookie nonce.
- `modules/webhooks/Webhooks.php`: `POST /webhooks/github` validates
  `X-Hub-Signature-256` in constant time and queues builds for every matching app
  on the pushed repository and branch with `auto_deploy` enabled, deduplicating
  repeated deliveries for the same commit.
- `modules/apps/Apps.php` + `infra/runtime/builder.php`: `_build()` mints a
  short-lived read-only installation token and passes it over the pinned SSH
  `tc-runtime` stdin JSON; `Builder` writes an ephemeral `0600` git `include.path`
  config scoped strictly to `https://github.com/` and deletes it immediately after
  `git fetch`, never persisting tokens in `.git/config`, job payloads, or logs.
