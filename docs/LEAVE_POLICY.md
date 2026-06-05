# Leave Policy

This document defines how leave is calculated. It is the source of truth for what
"correct" means. Read it in full before deciding what to build.

All figures are in **working days**. The leave year is the calendar year.

---

## 1. Annual entitlement

Each employee has a **contractual annual leave entitlement** (`Employee.contractualLeaveDays`),
expressed in working days for a full year on a five-day week.

The statutory minimum under the German Federal Leave Act (Bundesurlaubsgesetz, BUrlG §3)
is **20 working days** on a five-day week (24 on a six-day week). Contractual entitlement
is always at least the statutory minimum.

## 2. Pro-rata entitlement (joiners and leavers)

An employee who joins or leaves part-way through the leave year earns leave in
proportion to the time employed:

```
entitlement = contractualLeaveDays × (full months employed in the year) / 12
```

Round the result **up to the nearest half-day** (BUrlG §5).

A "full month employed" is any calendar month the employee is employed for its
entirety. Example: someone who joins on 1 March is employed for March–December = 10
full months.

## 3. Part-time employees

For employees who work fewer than five days a week, entitlement is scaled by their
contracted working days:

```
entitlement = contractualLeaveDays × workingDaysPerWeek / 5
```

Round **up to the nearest half-day**. (If an employee is both part-time and a
mid-year joiner, apply both factors.)

## 4. How leave days are counted

A leave day is a **working day**. When counting the days a request consumes:

- **Weekends never count.** Saturdays and Sundays are not leave days.
- **Public holidays do not count** if they fall on a working day (see §5). Use the
  employee's federal state (`Employee.federalState`).
- **Half-days** (`halfDayStart` / `halfDayEnd`) count as **0.5**.

So a request consumes: the working days in `[startDate, endDate]`, minus public
holidays, minus 0.5 for each half-day flagged.

## 5. Public holidays 2025

Only the two federal states used in the sample data are listed. A holiday that falls
on a Saturday or Sunday has no effect on leave counting.

### Bavaria (`BY`)

| Date | Holiday | Weekday |
|------|---------|---------|
| 2025-01-01 | New Year's Day | Wed |
| 2025-01-06 | Epiphany | Mon |
| 2025-04-18 | Good Friday | Fri |
| 2025-04-21 | Easter Monday | Mon |
| 2025-05-01 | Labour Day | Thu |
| 2025-05-29 | Ascension | Thu |
| 2025-06-09 | Whit Monday | Mon |
| 2025-06-19 | Corpus Christi | Thu |
| 2025-08-15 | Assumption Day | Fri |
| 2025-10-03 | German Unity Day | Fri |
| 2025-11-01 | All Saints' Day | Sat |
| 2025-12-25 | Christmas Day | Thu |
| 2025-12-26 | St Stephen's Day | Fri |

### Berlin (`BE`)

| Date | Holiday | Weekday |
|------|---------|---------|
| 2025-01-01 | New Year's Day | Wed |
| 2025-03-08 | International Women's Day | Sat |
| 2025-04-18 | Good Friday | Fri |
| 2025-04-21 | Easter Monday | Mon |
| 2025-05-01 | Labour Day | Thu |
| 2025-05-29 | Ascension | Thu |
| 2025-06-09 | Whit Monday | Mon |
| 2025-10-03 | German Unity Day | Fri |
| 2025-12-25 | Christmas Day | Thu |
| 2025-12-26 | St Stephen's Day | Fri |

## 6. Carryover and expiry

Unused entitlement carries into the following year, but carried-over days **lapse on
31 March** (`LeaveBalance.carryoverExpiresOn`). They must be used by that date.

If the run date is **after** the carryover expiry date, the carried-over days have
lapsed: treat them as zero.

## 7. Order of depletion

When approving a request, draw first from any **still-valid** carried-over days, then
from the current-year entitlement.

## 8. Absence types

| Type | Effect on the vacation balance |
|------|-------------------------------|
| `VACATION` | Consumes the vacation balance (subject to all rules above). |
| `SICK` | **Never** consumes the vacation balance. See §9. |
| `UNPAID` | **Never** consumes the vacation balance. Recorded, no balance impact. |
| `SPECIAL` | Drawn from a separate special-leave allotment (e.g. marriage, bereavement). Does **not** touch the vacation balance; treat as always approved for this exercise. |

## 9. Sick leave during vacation (BUrlG §9)

If an employee falls ill **during approved vacation** and provides a medical
certificate, the sick days are not counted as vacation and are **credited back** to
the vacation balance.

In the data this is a `SICK` request with `medicalCertificate = true` whose dates
overlap an already-`APPROVED` `VACATION`. Credit back the **working days** that
overlap.

## 10. Overlapping requests

An employee may not hold two overlapping approved leave periods. If a pending request
overlaps another period that is already approved (including one approved earlier in
the same run), **reject** it.

## 11. Insufficient balance

If the working days a request would consume exceed the employee's remaining balance,
**reject the whole request**. Do not partially approve.

## 12. Cancellations

A request with status `CANCELLED` is not active and consumes nothing. If a previously
approved request is cancelled, the days it consumed should no longer count against the
balance.

---

## HR API contract

Decisions are posted to the HR system. Credentials and base URL are in
`config/services.yaml`; a local mock lives in `mock-hr-api/server.php`.

### `POST /v1/leave-decisions`

- **Auth:** `Authorization: Bearer <token>` (401 if missing/invalid).
- **`Idempotency-Key` header is required** (400 if missing). Posting the same key
  again returns the **original** record with `"replayed": true` and does **not**
  create a duplicate.
- **Body** (JSON): the decision. The starter code sends:
  ```json
  { "employeeId": 1, "requestId": 1, "decision": "approved", "days": 5, "reason": "within balance" }
  ```
- **Responses:** `201` with `{ "id": "...", "replayed": false }` for a new decision;
  `200` with `"replayed": true` for a repeated key.

### `GET /v1/leave-decisions`

Lists everything recorded so far. Useful for checking what your run posted.

### `POST /v1/_reset`

Wipes recorded state. Handy between runs.

## Re-running the script

The run may be executed more than once (retries, partial failures, a second pay
period close). Re-running must not post duplicate decisions to the HR system, and must
not deduct the same days from a balance twice.
