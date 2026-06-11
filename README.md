# The Absence Run

Employees request time off — vacation, sick days, and more. We need a script
that processes the **pending** requests for a period: for each one, check the
employee's remaining entitlement, decide **approve** or **reject**, update their
balance, and post the decision to our HR API.

We've stubbed the repo and seeded a sample period. There are a couple of passing
tests for the basic case. **Make it production-ready.** You may use AI, Google,
and ask us anything.

---

## Setup

You need PHP 8.2+ and Composer. No Docker, no database server — the app uses a
local SQLite file.

```bash
composer install

# Create the database schema (via migrations) and load the sample period
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:fixtures:load --no-interaction
```

Start the mock HR API in a second terminal (it stays running):

```bash
php -S 127.0.0.1:8081 mock-hr-api/server.php
```

Then run the script and the tests:

```bash
php bin/console app:absence:run --date=2025-04-15
php bin/phpunit
```

The sample period is the leave year **2025** with a run date of **2025-04-15**.

### Or, with the Makefile

`make help` lists every target. The common loop (targets pin `APP_ENV=dev`, so they
always hit the working database, not the test one):

```bash
make setup          # composer install + create schema + seed the sample period
make mock           # in a second terminal — the mock HR API (stays running)
make fresh          # reset to a clean "pending" state and clear the HR ledger
make run            # process the run (override the date: make run DATE=2025-05-01)
make test           # the test suite
make sql            # open a SQLite shell on the dev database
```

There is also an extra **edge-case catalogue** (sick-leave matrix, overlaps, an
over-drawn leaver, bad data, …) that you can load and eyeball:

```bash
php bin/console doctrine:fixtures:load --group=scenarios
make run
```

## What's in the box

| Path | What it is |
|------|------------|
| `src/Entity/` | `Employee`, `LeaveRequest`, `LeaveBalance` |
| `src/Service/LeaveRequestProcessor.php` | The processor — a deliberately naive first pass. **This is what you'll work on.** |
| `src/Command/AbsenceRunCommand.php` | The `app:absence:run` entry point |
| `src/Hr/` | The HR API client (`HrApiClientInterface` + HTTP implementation) |
| `src/DataFixtures/AppFixtures.php` | The seeded sample period |
| `mock-hr-api/server.php` | A standalone mock of the HR API (Bearer auth + idempotency) |
| `docs/LEAVE_POLICY.md` | **The leave policy.** Read it carefully — it defines what "correct" means. |
| `tests/` | A base test case + three passing happy-path tests |

## Configuration & secrets

The HR API base URL and token come from the `HR_API_BASE_URL` / `HR_API_TOKEN`
environment variables — `config/services.yaml` binds them with `%env(...)%`. The
dev/mock defaults live in `.env.dev`; they're deliberately **not** in `.env`, so a
non-dev environment that forgets to inject the real token fails loudly rather than
silently using the mock. In **production**, the real `HR_API_TOKEN` (and
`APP_SECRET`, `DATABASE_URL`) is injected via the platform's secret store as a real
environment variable, which overrides the defaults — so no real credential is ever
committed.

## What we'd like from you

Work in phases — the thinking matters as much as the code:

1. **`QUESTIONS.md`** — before writing code, read the brief, the policy, and the
   seeded data, and write down your questions and assumptions. Send them over; we'll
   answer.
2. **`SPEC.md`** — turn that into a short spec: the rules you'll implement, the edge
   cases, what's out of scope, and how you'll test it.
3. **Prototype** — spike whatever you find riskiest to convince yourself it'll work.
   Throwaway code is fine here.
4. **Build** — make the processor production-ready, with tests.

Afterwards we'll sit down for ~an hour, walk through your code, and talk about how
you'd run this thing for real.

## Rules of engagement

- **Use AI, search, whatever you like.** We care about what you ship and whether you
  understand it — we'll ask.
- **Ask questions.** A thin brief is intentional. Good questions are a strong signal.
- It's a backend script, so **tests matter**. Extend the suite as you go.
