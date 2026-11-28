# Usage Analytics v1 — development branch

## Scope

Keep the existing four-card Overview layout. Its **AI Usage Cost** card reports total *estimated* SyndaMorph model spending for the selected Today / 7 days / 30 days / All time period, and its caption reports mean estimated cost **per metered job**. No spending-by-agent or spending-by-model UI is introduced.

Model Configuration (under AI Integration) shows the **selected provider's** monthly limit and remaining spending allowance when authorized. The existing provider selection on the Provider Connection tab is reused. There is no second provider picker. The existing Max job budget control is unchanged.

## Local usage source of truth

`GET /wp-json/syndamorph/v1/overview?period=7d` now includes `usageCost`:

- `estimatedTotalUsd`: sum of `agent_complete.metric.estimatedCostUsd` for jobs created in the selected period.
- `estimatedMeanPerMeteredJobUsd`: total / unique jobs with cost receipts.
- `meteredJobs`: unique job count with recorded per-call estimates.
- `isEstimate`: true; the actual provider invoice may differ, particularly with caching and rate adjustments.

The query counts a completed provider call exactly once, regardless of final job status. It does not count resume/replay output as a new paid request, and it does not infer zero costs for jobs without valid receipts. The status counts and filter are unchanged.

## Provider billing

`GET /wp-json/syndamorph/v1/ai/provider-budget?provider=anthropic` is read-only and restricted to administrators.

Claude is the first implemented adapter. It checks the official beta spend-limits endpoint for a monthly organization (or usage-tier) cap. If that succeeds and analytics permission is available, it reads month-to-date organization cost and calculates the **remaining spending allowance** (monthly limit minus provider-reported spend). This value is **not a prepaid credit balance**.

- Use the existing saved Claude credential when it has sufficient scopes.
- Optionally define `SYNDAMORPH_ANTHROPIC_BILLING_KEY` in server-side WordPress configuration if a separately authorized credential is required.
- The credential is never returned to the browser, and provider API snapshots are cached for five minutes (or one minute for unavailable results).
- Anthropic billing API access is scope- and account-dependent; the display stays unavailable rather than substituting SyndaMorph estimates.
- Other providers return an explicit unavailable state until a documented, verified provider-specific adapter exists. Do not mix data from other accounts.

Provider spend limits may differ from prepaid balance, and permission failures do not mean the account is out of funds.

## QA checklist

1. Confirm Overview's Today, 7d, 30d and all-time filters change total and mean estimated usage consistently.
2. Confirm jobs with `agent_complete` receipts are counted even when their final status is failed; unmetered jobs are excluded from mean denominator.
3. Confirm AI Integration reuses its original Provider Connection selector, and budget data loads only upon opening Model Configuration.
4. Confirm unavailable provider data and unauthorized billing access show `—` plus an explanation, not zero.
5. Confirm only a real monthly organization limit is shown; no max-job-budget or credit-balance inference.
6. Confirm administrators-only REST permissions and no credentials in HTML/REST/cache values.
7. Verify PHP syntax, JS syntax and existing GitHub Actions before deploying.

No plugin release or tag is associated with this experimental change.
