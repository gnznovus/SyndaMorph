# SyndaMorph Operations Overview (development)

## Navigation

The WordPress top-level `SyndaMorph` menu now defaults to `admin.php?page=syndamorph` (Overview). The shared `syndamorph_operations_navigation()` component renders four consistent destinations:

| Workspace | Route |
| --- | --- |
| Overview | `admin.php?page=syndamorph` |
| AI Production Team | `admin.php?page=syndamorph&tab=production` |
| AI Integration | `admin.php?page=syndamorph&tab=ai` |
| Client Access Manager | `admin.php?page=syndamorph&tab=gateway` |

Production History remains inside AI Production Team. Overview recent-job links use `view=history&inspect={database-id}` to open the existing inspector without duplicating its renderer. Older `tools.php?page=syndamorph` links redirect to the Production workspace; their recognized tab or view arguments are preserved.

## Reporting contract

`GET /wp-json/syndamorph/v1/overview?period=7d` is administrator-only, read-only, and makes no provider calls. Supported periods are `today`, `7d`, `30d`, `all`; invalid periods default to seven days. The endpoint reads `sm_jobs`, uses UTC-stored timestamps and the site's configured timezone to establish boundaries, and returns status totals, REF/GEN totals, completion rate and up to eight recent jobs.

- **Completion rate** is `completed / all recorded jobs in selected period`. Running jobs remain in the denominator.
- **Needs attention** counts `failed`, `needs_review`, `uncompleted`, `stopped`, `aborted`, and `paused`.
- **Source modes** are currently the historical categories `REF` and `GEN`. Distinguishing URL-based REF from supplied HTML/PACKAGE requires the proposed Switch scope contract; the dashboard must not infer it from titles.
- **Agent performance, costs and visual QA** are deliberately shown as unavailable. The current endpoint does not aggregate evidence-bound metrics, and it must not invent them.

The page uses the existing Operations styling and scoped JavaScript, not the heavyweight Production Team scripts. This is an experimental UI change on the development branch, not a published plugin release.

## Verification checklist

1. Overview is the default menu destination, and all four sidebar routes preserve active-state highlighting.
2. Production Setup, Production, Results and History tabs continue operating, including direct History inspection links.
3. AI Integration and Client Access Manager retain their own CSS/JS.
4. Today / seven days / thirty days / all time return filtered totals matching the archive; empty periods do not imply a 0% completion rate.
5. Administrator-only REST permissions and escaping remain intact.
6. Legacy Tools bookmarks redirect without duplicating the old menu.
7. Test narrow viewport and keyboard navigation, then run the repository's PHP syntax and UI regressions.
