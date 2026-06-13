# Questions & Assumptions

**Fill this in before you write code.** Read the brief (`README.md`), the leave
policy (`docs/LEAVE_POLICY.md`), and the seeded data (`src/DataFixtures/AppFixtures.php`),
then capture what you'd want to clarify with the "product owner" (us) and the
assumptions you're making in the meantime.

Send it over — we'll answer your questions. **Good questions are a strong signal**,
so don't hold back. There's no upper limit; the empty bullets below are just a
starting shape, not a quota. We'd expect a senior submission to have ~5–15 across
the three sections.

## Questions

_Things you'd want a real product owner to clarify before you commit to a design._

1. **Part-time day counting (Bjarne).** He works 3 days/week, but no field says
   *which* 3 days. For a Mon–Fri request, does it consume 5 working days or only
   his 3 contracted days? Confirm the rule, or add a working-pattern field.

2. **Is `submittedAt` order the right fairness rule** when two requests compete for
   scarce remaining balance (first-submitted wins), or should priority differ?

3. **Sick-during-vacation ordering (§9).** A sick note submitted *before* the
   vacation it overlaps would be seen first — vacation still pending → no credit.
   How should this be sequenced so the credit isn't missed?

4. **Carryover lapse — keyed to run date or leave date? (§6)** Taken literally, §6
   lapses carryover by **run date**, so a run *before* 31 March could approve a
   *July* vacation drawing on carryover that will have lapsed by July. Intended, or
   should lapse be judged against the leave dates?

5. **Leavers (§2).** §2 covers employees who *leave* mid-year, and `Employee` has
   `employmentEndDate`, but the seed has no leaver. Should I implement leaver
   pro-rata now? And how should a resulting **over-drawn (negative) balance** be
   handled — clawback, clamp to zero, or just flag it?

6. **Run-date eligibility and year boundaries.** Do I process *all* pending
   requests regardless of their dates (including ones already in the past, like
   Dilan's)? And for a request spanning the year boundary (Dec→Jan), which year's
   balance applies?

7. **Null carryover expiry (§6).** §6 says carried-over days *always* lapse on
   31 March, but `carryoverExpiresOn` is nullable (and null for everyone with zero
   carryover in the seed). Does a null expiry mean "non-expiring carryover" or
   simply "no carryover"? And what should the run do if it ever sees
   `carriedOverDays > 0` with a null expiry — count it indefinitely, or flag it as
   a data error?

8. **When is UNPAID rejected?** §8 specifies its *balance effect* (no vacation
   consumption) but not its *approval criteria*. Should UNPAID ever be rejected
   (e.g. discretionary employer denial, dates outside employment), or always
   recorded as approved? (SICK is now settled — see the assumption below: a medical
   certificate is required once the absence exceeds 3 calendar days.)

9. **Single-day request with both half-days.** If a one-day request (`startDate ==
   endDate`) has both `halfDayStart` and `halfDayEnd` set, should it count as one
   full day or half a day? The §4 formula would give `1 − 0.5 − 0.5 = 0`, which is
   clearly wrong; I read the two flags as describing the same day as a half-day, so
   I'd count **0.5**. Confirm?

10. **Should a cancellation be posted to HR as its own decision?** When a
    previously-approved request is cancelled, we credit the balance back — should we
    also post a `cancelled` decision to HR so its ledger matches (it already recorded
    the approval), or is HR told about cancellations through another channel? The
    answer decides whether the idempotency key needs the decision qualifier.

11. **Sick certificate threshold — what counts as the "period"?** EntgFG §5 requires
    a certificate once incapacity exceeds 3 calendar days, but applied per request it
    lets back-to-back 3-day notes slip through. I now measure it over the *continuous*
    incapacity (merging overlapping/adjacent sick periods). Two sub-questions: (a) does
    a non-working gap break continuity — i.e. is "sick Fri, then sick Mon" one period
    or two? I currently treat any calendar gap (including a weekend) as a break; and
    (b) for *frequent but non-contiguous* short absences, German employers can require
    a certificate from day one — is there such a rule and threshold, or is that an
    abuse-detection concern outside this run?

## Assumptions I'm making (until told otherwise)

_The defaults you're picking so you can keep moving. State each one — if we disagree we'll tell you, and if we don't, your code shows your reasoning._

- **HR decisions are an append-only log with key-based idempotency** (from
  `mock-hr-api/server.php`). The server dedupes *only* on the `Idempotency-Key`
  header — it never inspects `requestId` or the body — so an identical key replays
  the original record and any new key creates a new one. Nothing is immutable at the
  server; a corrected or follow-up decision reaches HR purely by carrying a new key.
- **The HR payload is stored verbatim, unvalidated** (same source): the server only
  checks the body is JSON. So a negative `days` and an added `type` field are both
  accepted. I'll therefore post `days` = **change to the vacation balance** (0 for
  UNPAID/SPECIAL, the negative credited amount for a §9 SICK) and include a `type`
  field so the record is self-describing. There's no real downstream consumer in the
  exercise to constrain the semantics; a real HR API spec would confirm them.
- **Idempotency key is deterministic, `{requestId}:{decision}`** — e.g. `7:approved`,
  `7:cancelled`. In the normal flow a request is decided exactly once
  (`PENDING → APPROVED|REJECTED`), so `requestId` alone would suffice; the qualifier
  matters only for the §12 case, where a previously-approved request that is now
  `CANCELLED` produces a *second* HR post, and `requestId` alone would dedupe it
  against the original approval. Not the starter's `random_bytes`, which duplicates
  on every re-run. Re-runs replay each decision by its stable key.
- **`usedDays` is the authoritative opening balance.** It is *not* re-derived
  from request history — for most seeded employees the opening used days have no
  backing request rows (and re-summing Dilan's would double-count). The run starts
  from `usedDays` and applies the changes it makes.
- **Schema changes / new persistence are in scope** (confirmed). The solution isn't
  confined to `LeaveRequestProcessor.php`; I can add entities and migrations —
  notably persisting `Decision` — rather than squeezing cross-run state into existing
  fields.
- **Every balance change is recorded as a persisted `Decision`, idempotently.**
  `Decision` (`src/Service/Decision.php`) is today a transient value object — created
  during the run and discarded, so its `consumedDays` doesn't survive between runs.
  I'll promote it to a persisted entity (a decision/audit log) keyed to the request:
  each row stores `consumedDays`, the idempotency key, and the HR reference, and its
  existence guards both the HR post and the balance mutation — so re-runs and the
  §9/§12 reversals never double-count. This reuses the existing `Decision` concept
  rather than adding a field to `LeaveRequest` (a deliberate schema extension either
  way).
- **Part-time scales the entitlement, not the per-request deduction** (Q1). With no
  working-pattern field, a request consumes every Mon–Fri working day in range
  (minus holidays/half-days), and the 3/5 factor only shrinks the annual entitlement.
- **SICK credit-back is conditional, not automatic** (Q3). §9 credits days back only
  when a SICK request has `medicalCertificate = true` *and* overlaps an
  already-`APPROVED` VACATION — and only the overlapping working days. Every other
  SICK request (no certificate, or no overlap) is recorded with zero balance impact
  and no credit.
- **SICK approval follows the certificate rule, measured over the continuous
  incapacity** (Q11). With `medicalCertificate = true` → accepted (and §9 credit-back
  if it overlaps an approved vacation). Without one, the threshold applies to the
  whole continuous period — a no-certificate request is accepted only if the
  contiguous span of claimed sick days (this request merged with overlapping/adjacent
  ones) is **≤ 3 calendar days**; beyond that → rejected (EntgFG §5). This stops
  back-to-back 3-day notes from slipping through. **UNPAID** is recorded as approved
  with zero impact (Q8), until told what should make it rejectable.
- **Partial mid-run failure → per-request commit after a successful HR post.** The
  policy already requires re-runnability (no duplicate posts, no double-deduction);
  the mechanism is ours, not an open question. A failed post skips that one request
  (log + continue), and the deterministic key + recorded per-request impact keep the
  run safe to re-run.
- **Processing order:** `submittedAt` ascending, then `id` — first-come-first-served
  (Q2).
- **Two-phase processing** (Q3): all vacation decisions first, then §9 sick credits,
  so the "already-approved" precondition holds.
- **Carryover lapse is keyed to the run date** — the literal §6 reading (Q4).
- **This batch reconciles cancellations:** it reverses the days a
  previously-approved-then-cancelled request consumed, once, via the recorded
  per-request impact. (§12 is a correctness rule and nothing in the brief describes
  another owner, so the run does it.)
- **A cancellation is also posted to HR as its own decision** (Q10) — `decision =
  cancelled`, `days` = the negative credit — so HR's ledger stays consistent with
  the credited-back balance. This second post is what makes the `{requestId}:{decision}`
  idempotency key necessary.
- **Leaver pro-rata is implemented per §2** (Q5); an over-drawn (negative) balance
  is flagged rather than clawed back.
- **`contractualLeaveDays` is trusted as-is, not clamped.** §1 frames "at least the
  statutory minimum" as a guaranteed invariant, so clamping would only fire on data
  that violates it — and silently rewriting a payroll figure is worse than surfacing
  it. (A below-floor value would be a data error to flag.) Malformed data —
  `workingDaysPerWeek ∉ [1,6]`, reversed date ranges, dates outside employment — is
  rejected with a reason, not silently corrected.
- **Cross-year (Dec→Jan) requests are rejected** with a "spans two leave years —
  please split" reason (Q6); the proper split needs a 2026 `LeaveBalance` row the
  seed doesn't provide. Conceptually, a leave day belongs to the calendar year it
  falls in.
- **A null `carryoverExpiresOn` is treated as "never lapses"** (Q7) — the literal
  §6 logic has no date to be "after"; but `carriedOverDays > 0` with a null expiry
  is flagged as a data error.
- **A half-day flag on a weekend/holiday contributes 0** — the §4 formula's −0.5
  only applies when the flagged boundary is itself a working day. **A single-day
  request flagged both `halfDayStart` and `halfDayEnd` counts as 0.5** (Q9): the two
  flags describe the same day as a half-day, not two separate halves.
- **Year-end carryover rollover is out of scope — a separate batch.** This run
  *consumes* the opening carryover and lapses it per §6; it never *produces* next
  year's carryover.

## Things in the data that look surprising

_Anything that smells off, contradicts the policy, or seems to be a missing value._

- **Opening `usedDays` is represented inconsistently across employees.** Dilan's
  `usedDays = 10` is backed by an approved March request (the comment says the 10
  "comes from" it), but Eva's `5`, Anna's `24`, and Bjarne's `14` have no backing
  request rows at all. So `usedDays` can't be re-derived from history — for most
  people it's an opening figure with nothing behind it, and re-summing Dilan's
  would double-count. This is why the run has to treat `usedDays` as authoritative
  rather than derive it. (Whether the seeded figures are themselves *correct* — e.g.
  Eva's — is the kind of thing we'd want you to confirm; see the Eva note.)
- **Eva — a cancelled February booking sitting next to an unexplained `usedDays = 5`.**
  The cancelled request was never approved, so it consumed nothing and the 5 looks
  unrelated. Flagging in case the 5 *was* meant to come from that cancellation, in
  which case §12 reconciliation would be in play for the seed.
- **Anna — carryover (6 days) has already lapsed at the run date** (run 2025-04-15
  is after the 2025-03-31 expiry) yet still sits in the balance row. §6 says treat
  it as zero; the stale value looks like a trap for code that counts it blindly.
- **Bjarne — part-time with no working-pattern field** (see Q1): a missing value —
  there's no way to know *which* days he works, so his Mon–Fri request is ambiguous.
- **No leaver and no six-day-week employee**, although §2 (leavers) and §1 (24-day
  statutory on a six-day week) both describe them — coverage the seed leaves untested
  (see Q5).
