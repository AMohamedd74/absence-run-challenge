# Spec — The Absence Run

A short, living document. Keep it scoped and testable — it doesn't need to be long.
Built from the assumptions in `QUESTIONS.md`; anything still genuinely open is in
[Open questions](#open-questions).

## Problem

A batch command (`app:absence:run --date=Y-m-d`) processes every **PENDING** leave
request for the leave year: it decides **approve** or **reject** per the leave
policy, updates the employee's vacation balance, and posts each decision to the HR
API. It must be **safe to re-run** — no duplicate HR posts, no double-counted days.

## Rules I'm implementing

_From the policy + clarified assumptions. Grouped; each bullet is a test case._

**Entitlement (annual, working days on a 5-day week)**
- Base entitlement = `contractualLeaveDays`, **trusted as-is**: §1 guarantees it is
  already ≥ the statutory floor, so the value is not clamped (clamping would only
  fire on data violating §1, and silently rewriting a payroll figure is worse than
  surfacing it as a data error).
- **Pro-rata** for mid-year joiners/leavers: `contractual × full-months-employed / 12`,
  rounded **up to the nearest half-day** (§2). A "full month" = a calendar month
  employed in its entirety.
- **Part-time**: `× workingDaysPerWeek / 5`, rounded **up to the nearest half-day**
  (§3). Pro-rata and part-time compose; round **once**, at the end.

**Balance & carryover**
- Remaining = entitlement + *valid* carryover − `usedDays` (opening figure, trusted).
- Carryover **lapses** when `runDate > carryoverExpiresOn` → treat as 0 (§6). A
  **null** expiry never lapses.
- **Depletion order**: draw from valid carryover first, then current-year
  entitlement (§7).

**Counting the days a request consumes (§4)**
- Working days in `[startDate, endDate]`, **excluding weekends**, **excluding public
  holidays** for the employee's `federalState` (BY/BE tables, §5).
- **−0.5 per half-day flag**, applied only when the flagged boundary day is itself a
  working day. A single-day request with both half-day flags = **0.5**.

**Per-type decisions (§8)**
- **VACATION** — consumes the balance per the counting rules. If consumed >
  remaining → **reject the whole request** (no partial approval, §11).
- **Overlap (§10)** — reject a VACATION that overlaps an **already-APPROVED**
  leave period (including one approved earlier in this run). `CANCELLED`/`REJECTED`
  periods don't block. SICK-vs-VACATION overlap is the §9 path, *not* a rejection.
- **SICK** — never consumes the vacation balance.
  - With `medicalCertificate = true` → **accept**; if it overlaps an already-APPROVED
    VACATION, **credit back** the overlapping working days (§9).
  - Without a certificate: **≤ 3 calendar days → accept** (recorded, zero impact);
    **> 3 calendar days → reject** (certificate required). *(German EntgFG §5: a
    certificate is required once incapacity exceeds 3 calendar days.)*
- **UNPAID** — never consumes the balance; recorded as approved, zero impact.
- **SPECIAL** — always approved; separate allotment; no vacation impact.
- **CANCELLED** — consumes nothing; a request that was approved-then-cancelled has
  its consumed days **credited back once** (§12), and a `cancelled` decision is
  posted to HR.

**Processing & posting**
- **Two-phase**: decide all VACATION (with overlap) first, then process SICK §9
  credits — so the "already-approved" precondition holds regardless of `submittedAt`.
- **Order within a phase**: `submittedAt` ascending, then `id`.
- Each decision is a **persisted `Decision`** (storing `consumedDays`, idempotency
  key, HR reference). HR post uses key **`{requestId}:{decision}`**; the balance is
  mutated once, guarded by the `Decision` row's existence.
- **Per-request commit** after a successful HR post → the run is crash-safe and
  re-runnable.

**Validation (defensive guards)**
- Reject + log, never silently correct: `workingDaysPerWeek ∉ [1,6]`,
  `startDate > endDate`, dates outside the employment window.
- **Cross-year (Dec→Jan) requests** are rejected with a "spans two leave years —
  please split" reason.

## Edge cases

| Case | Decision |
|------|----------|
| Carryover present but `runDate` after `carryoverExpiresOn` | Carryover = 0 (lapsed) |
| `carryoverExpiresOn` is null | Never lapses; full carryover valid |
| Half-day flag on a weekend/holiday | Contributes 0 (no full day to halve) |
| Single-day request, both half-day flags | 0.5 |
| Public holiday inside the range (state-specific) | Excluded from the count |
| Two pending VACATIONs overlap each other | First by `submittedAt` approved; later rejected (overlap) |
| Two VACATIONs sharing only a weekend/holiday | Not an overlap (overlap is measured in working days, not calendar dates) |
| Pending VACATION overlaps a `CANCELLED`/`REJECTED` period | Not blocked |
| SICK + certificate overlapping an approved VACATION | Accept; credit back the vacation's *consumption* over the overlap (≤ what it deducted, half-days included) |
| SICK without certificate, ≤ 3 calendar days | Accept, zero impact |
| SICK without certificate, > 3 calendar days | Reject (certificate required) |
| Consumed days exceed remaining balance | Reject whole request (no partial) |
| Request approved in a prior run, now `CANCELLED` | Credit days back once; post `cancelled` to HR |
| Cancelled VACATION that had a §9 sick credit | Reverse the vacation **and** revoke the dependent credit (balance returns to 0, not negative) |
| Cancelled zero-impact approval (UNPAID/SPECIAL) | No balance change, but a `cancelled` decision is still posted to HR |
| A decided request re-opened to PENDING | Unsupported — recorded as a skip, never silently re-applied |
| Same run executed twice | No duplicate HR posts; balance unchanged |
| Cross-year (Dec→Jan) request | Reject ("please split") |
| `workingDaysPerWeek` of 7, or `startDate > endDate` | Reject + log |

## Out of scope

- **Year-end carryover rollover** — a separate batch; this run consumes/lapses
  carryover but never produces next year's.
- **True multi-year requests** — rejected and split rather than apportioned across
  two balances (the seed has none, and a 2026 balance row isn't provided).
- **States beyond BY/BE** and the **six-day-week** statutory minimum (formula
  supports it; no sample data).
- **Concurrency / locking, employee notifications, secrets management.**
- Over-drawn (negative) leaver balances are **flagged, not clawed back**.

## Test plan

**Unit (pure calculators)**
- Entitlement: full-time, pro-rata joiner, part-time, joiner × part-time, statutory clamp, half-day rounding.
- Working-day counter: weekends, BY vs BE holidays, half-day flags, half-day on a non-working day.
- Carryover: valid, lapsed (run date after expiry), null expiry, depletion order.

**Per-rule scenarios**
- Sick without certificate ≤ 3 days (accept) and > 3 days (reject).
- Sick with certificate overlapping approved vacation (credit-back).
- Overlap rejection; insufficient-balance rejection; UNPAID/SPECIAL zero impact.
- Cancellation reversal; and re-run after it (no double credit-back).

**Integration — the seed as a golden oracle** (`--date=2025-04-15`)

| Request | Decision | Vacation days | Why |
|---------|----------|---------------|-----|
| Dilan SICK 03-24→26 (cert, overlaps approved vac) | credited | **−3** | §9 credit-back |
| Eva UNPAID 05-05→09 | recorded | 0 | UNPAID no impact |
| Eva VACATION 06-05→11 ½-start | approve | 3.5 | Whit Mon (BE) excluded, ½-day |
| Felix VACATION 05-26→30 | approve | 4 | Ascension (BE) excluded |
| Felix VACATION 05-28→06-03 | **reject** | 0 | overlaps approved 05-26→30 |
| Carla VACATION 07-07→11 | **reject** | 0 | joiner entitlement 25, used 21 → only 4 left |
| Bjarne VACATION 07-07→11 | **reject** | 0 | part-time entitlement 17, used 14 → only 3 left |
| Anna VACATION 05-19→23 | **reject** | 0 | carryover lapsed → only 4 left |
| Anna VACATION 04-28→30 ½-start | approve | 2.5 | ½-day start |
| Anna SPECIAL 06-02 | approve | 0 | special, no impact |

Final balances asserted per employee; HR posts asserted via `GET /v1/leave-decisions`.

**Re-run** — run twice, assert the HR decision count and every balance are unchanged.

## Operational notes

- **Re-run / retries** — idempotent via deterministic `{requestId}:{decision}` keys
  and the persisted `Decision` rows; replays don't duplicate posts or re-deduct.
- **Partial failure** — per-request commit after a successful post; a failed post
  skips that request (log + continue) and is picked up on the next run.
- **Bad data** — rejected and logged, never posted to HR as a real decision.
- **Monitor** — counts of approved / rejected / skipped, HR post failures, requests
  rejected for validation, and any **negative balances** flagged.

## Open questions

Tracked in `QUESTIONS.md`; the ones that would change the build if answered
differently. (Schema changes are confirmed in scope, so the persisted-`Decision`
idempotency/reversal design is settled.)

- **Q1** — are the opening `usedDays` figures trustworthy.
- **Q3 / Q5** — part-time day counting (5 vs 3) and the two-phase sick ordering.
- **Q6 / Q7** — carryover lapse keyed to run date vs leave date; null-expiry meaning.
- **Q10** — whether cancellations are posted to HR (decides the key qualifier).
- **Q8** — UNPAID rejection criteria (SICK now settled by the ≤3/>3-day rule above).