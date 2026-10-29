# Admin Setup & Navigation — Phase 8

> **Status: Implemented (2026-10-29).** How to create, verify, and revoke administrator access, what the admin area contains, and where every admin page lives. Authorization model: [COST_ACCOUNTING.md](COST_ACCOUNTING.md) §4 and [DECISIONS.md](DECISIONS.md) D-030. **No billing, credit deduction, or customer charging exists.**

## 1. Creating the first administrator

There is **no default admin account, no hard-coded credential, and no public admin registration** (`/register` returns 404 by design). The only supported path is a dedicated Artisan command that prompts for the password with hidden input (so it never lands in shell history, logs, or process listings):

```sh
php artisan admin:create --email=you@example.com
# Password (input hidden, min 8 characters): ••••••••
# Confirm password: ••••••••
```

- `--name="Display Name"` optionally sets the display name (prompted when omitted).
- The account is created with `is_admin = true`; the password is stored only as a bcrypt digest (the model's `hashed` cast; the plaintext is never printed or persisted).
- The admin logs in through the **ordinary login page** (`/login`) — there is no separate admin login.

### Promoting an existing user

Existing users are **never promoted silently** — including the first registered user:

```sh
php artisan admin:create --email=existing.user@example.com --promote
# Grant ADMIN privileges to the existing user #5 (...) This exposes platform-wide
# cost analytics? (yes/no) [no]:
```

Without `--promote` the command refuses and exits non-zero; with `--promote` it still requires an interactive confirmation. Re-running it for an account that is already an admin is an idempotent no-op.

### Verifying admin access

List the administrators (no secrets printed):

```sh
php artisan tinker --execute="App\Models\User::where('is_admin', true)->get(['id','email'])->each(fn($u) => print($u->id.' '.$u->email.PHP_EOL));"
```

Then log in as that user: the sidebar gains the **Administration** section and `/admin` (Admin overview) returns 200. Ordinary users get **403** on every `/admin/*` route and never see the admin section. Guests are redirected to `/login`.

### Revoking admin access

```sh
php artisan admin:revoke --email=you@example.com
# Revoke ADMIN privileges from user #1 (...)? (yes/no) [no]:
```

The user account itself is kept. Revoking requires confirmation; revoking the **last** administrator warns that the admin area becomes unreachable until another admin is created. Re-running for a non-admin is an idempotent no-op.

## 2. Admin area map (sidebar: Administration section)

| Page | Route (name) | Purpose |
|---|---|---|
| Admin overview | `GET /admin` (`admin.dashboard`) | Landing page: measured cost exposure, per-video stats with sample-size labels, pricing-simulation status, infrastructure gaps. Links out to the detailed pages. |
| Cost analytics | `GET /admin/costs` (`admin.costs.index`) | Full summary: actual / estimated-only / unconfirmed / rejected / retries / missing-usage totals; cost by provider, model, operation, status, user; per-video table with per-operation cost breakdown; measured infrastructure usage. |
| Detailed cost reports | `GET /admin/costs/calls` (`admin.costs.calls`) | The per-operation ledger with server-side pagination and filters: date range, provider, model, operation, request status, cost classification, charge status, user, project, render/job, missing-usage-only, retries-only, and `unclassified` legacy rows. |
| Pricing simulation | `GET /admin/pricing-simulation` (`admin.pricing.simulation`) | Read-only reuse of the Phase 7 `PricingSimulator` service (identical output to `php artisan pricing:simulate --json`): 9 duration×profile scenarios, candidate Starter/Creator/Pro economics, credit packs, margin targets, shock grid, subscriber mixture, infrastructure shock, effective verified rates, raw assumptions with evidence labels, data sample + generation timestamp. |

Authorization: every `/admin/*` route sits behind the `admin` Gate (`users.is_admin`, D-030) via `can:admin`. Ordinary users get 403; guests are redirected to login. Hiding the sidebar section for non-admins is discoverability only — the gate is the boundary (tested).

## 3. Ordinary sidebar

- **Workspace** — Dashboard; when a project is in scope (story editor, storyboard, characters, audio, render pages pass the `project` prop), the contextual project sections: Story, Storyboard, Characters, Audio, Render.
- **Settings** — AI providers (`/settings/providers`), Account password (`/settings/password`).
- **Administration** — admins only, as above.
- Logout is preserved in the sidebar footer (desktop) and the mobile top bar; mobile gets the same sections through a drawer (`hamburger` toggle).

Admins see **both** the product navigation and the Administration section.

## 4. Measured pilot instrumentation (Phase 8)

- `assets.download_count` / `assets.downloaded_bytes` (additive migration `2026_10_29_000004`) are incremented by the authorized `?download=1` endpoint only (inline previews are cacheable and are deliberately not counted). Feeds the analytics infrastructure section (`download_count`, `download_bytes`, `bandwidth_cost` at `config/costs.php` `bandwidth.per_gb_usd` — default 0.0 = price undecided, not a zero-cost claim).
- Render duration (`generation_jobs.started_at → finished_at`), artifact sizes, TTS estimate-only rows, and unclassified legacy rows were already measured/flagged by the Phase 7 ledger — see [COST_ACCOUNTING.md](COST_ACCOUNTING.md) for the audit trail and remaining gaps (§5).
- TTS actual cost remains **unmeasurable through the Speech API** (no usage payload); rows stay classified `estimate`. Reconcile against the provider usage dashboard after the first billing cycle — do not invent a conversion factor.

## 5. Verification (2026-10-29)

- New tests: `AdminAccountSetupTest` (13), `AdminDashboardTest` (4), `PricingSimulationTest` (5), `NavigationVisibilityTest` (5), `CostCallsReportTest` (8), `PerVideoAttributionTest` (2), `DownloadMetricsTest` (5).
- Full suite: **525 tests / 3507 assertions / 0 failures**. `npm run build` ✓ · `vendor/bin/pint --test` ✓ · `git diff --check` ✓.
- No live billable provider calls were made; no billing or credit-deduction code exists.
