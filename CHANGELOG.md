# Changelog

## Unreleased

The plugin is renamed from SimpleABTesting to RebelABTesting. It is a new plugin to Matomo and does
not take over data of SimpleABTesting.

### Added

- Each experiment has a page with its result: visits per variant and, for each goal, the conversion
  rate, the difference to the original and the chance that the variant is better.
- Several experiments can run on a website at the same time.
- An experiment can have up to ten variants.
- An experiment can be limited to pages by their address, and to a share of the visitors.
- An experiment can be stopped before its last day.
- A conversion counts for an experiment from the moment the visit is in it, not when it happened
  earlier in the same visit.
- An experiment is judged by a primary measure: a goal, ecommerce orders, revenue per visit, an
  event, bounces or visits that search the website. Goals are shown without a verdict.
- An experiment can have up to two guardrails, measures that must not get worse.
- Admins get a preview link for each variant, which shows it before the experiment starts and
  without counting the visit.
- The list of experiments shows whether an experiment is planned, running or has ended. Users with
  view access see the list and the results.
- The tag hides the page while it fetches the experiments on a visitor's first page, and applies
  variants at once on later pages.
- Matomo deletes the log of the plugin together with the visits, when old logs are deleted and when
  the data of a visitor is deleted.
- The result warns when the visitors are split unevenly between the groups, which means that
  assignment or tracking is broken.
- The form works out how many days an A/B test needs.
- Fake door: an experiment that adds a button or link to something that does not exist yet and
  counts the visits that click it.
- A variant is a list of changes that need no code: change a text, hide an element, add a CSS
  class, set a style, change an image or a link.
- Users with write access to a website can run experiments without code. CSS and JavaScript in a
  variant need admin access.
- Watch an element: an experiment that changes nothing and counts the visits that see and click
  an element the page already has.
- The tag takes the address of Matomo with a path, for a Matomo that is installed in a directory.
- A test website in `tests/site` to try experiments on.
- More to judge an experiment by: the revenue of a goal, total revenue, visits that reach any goal,
  page views per visit and the length of a visit.
- A chart on the page of an experiment shows how the primary measure of each variant developed.
- The segment chosen in Matomo limits the result of an experiment to its visits.
- An experiment can leave out pages, and say how sure to be before a variant is called better or
  worse: 90, 95 or 99%.
- Templates fill in the changes of a variant for the things that are tested most.
- A variant can send the visitor to another page, for a test between two pages.
- Changes to experiments go through Matomo's API events, which RebelAuditLog logs.
- The list of experiments shows what the primary measure of each says so far, and the name of an
  experiment leads to its page.
- The one who adds an experiment can get an email when a variant is found better or worse, and when
  the experiment has ended.
- A variant is compared with the original also when one of them has no visit with the outcome at
  all.
- Swedish translation.

### Security

- The lists and forms of experiments need admin access to the website. Before, a user with view
  access to any website could read the experiments of every website.
- The address the tag reads experiments from only answers for experiments that are running, and
  without their names.
- After a change, the browser is sent to a page of Matomo. The address is no longer taken from the
  request, and neither is the message that is shown.
- A tracking request is only logged for a running experiment of its website, under the name the
  experiment has in Matomo. Before, a request could add rows with made-up names to the reports.
- The first and the last day of an experiment have to be dates, in that order.
- The name of an experiment is unique within its website. Before it was unique across all websites,
  so the error told a user that a name was in use on a website they have no access to.

### Fixed

- Users with view access can open the reports.
- JavaScript and CSS of several lines keep their line breaks when stored through the API.
- Experiments can be stored on MariaDB versions that have the function `TO_DATE`.
- The segments for the variant and the experiment work. Their columns on the visit were never
  created.

### Changed

- One tag runs all experiments of a website. It no longer has an experiment to choose.
- The log has a row for each visit and experiment, not for each tracking request.
- Tracking requests carry the experiments in the parameter `rabt`.
- `insertExperiment` and `updateExperiment` take a list of variants, the share of visitors and the
  pages. `stopExperiment`, `getExperiments` and `getExperimentResult` are new, and
  `getExperimentPublic` and `getExperimentReportData` are removed.
- The segments `experimentId` and `experimentVariant` read the log of the plugin.
- The reports of visits and conversions per period are removed, with `getExperimentData`,
  `getExperimentGoalData` and `getExperimentUniqueVisitorData`. The page of an experiment shows its
  result for the whole run.
- The page "Insert experiment" is removed. The list of experiments tells how to add the tag.
- The list of experiments is called "Manage experiments", and the form to create or change an
  experiment is in steps.
- The database tables are `rebel_ab_testing_experiments` and `rebel_ab_testing_log`.
- The plugin needs PHP 8.2 or newer.
- `insertExperiment` returns the ID of the experiment.
- Texts of an experiment are stored, taken and handed out as they were written, without HTML
  encoding.
- The menu has its own icon, and the icon font is removed.
- The segments for the experiment name and category are removed.
- The name of an experiment can contain any characters.

## [0.1.99] - 2026-11-10

### Fixed

- `Updates/0.1.98.php` crashed `core:update` from 0.1.97 with "Call to a member
  function sql() on null": the class used `$this->migration` without a
  constructor receiving the migration factory. It now takes
  `Piwik\Updater\Migration\Factory`, so the three ALTERs (`idexperiment`,
  `assignment_id`, `idx_visit_time`) run on upgrade.

## [0.1.98] - 2026-10-17

### Added

- Assignment is stored in `localStorage` under `sabt:<idexperiment>` as JSON
  with the variant `v`, an assignment id `a` (32 hex characters) and an expiry
  `exp`, replacing the `sabt_<name>` cookie.
- The tag sends the assignment id `saba` together with `sabt`, `sabi` and
  `sabn`, and sends an exposure `ping` after the variant has been applied, for
  both variants. Exposure is therefore recorded with a timestamp even on
  single-pageview visits.
- `tracker.js` reads the assignment from `localStorage` and sends `sabt`,
  `sabi` and `saba` on every request type the Matomo tracker sends through a
  plugin hook (pageviews, links, goals, events, site search, ecommerce, pings
  and content tracking) while an assignment is active.
- `simple_ab_testing_log` gets the columns `idexperiment` and `assignment_id`
  and the index `idx_visit_time (idvisit, server_time)`, added by
  `Updates/0.1.98.php`. A request-supplied `sabi` is verified against the
  request's site and otherwise resolved by experiment name; malformed `saba`,
  `sabi` and `sabt` values are stored as NULL or ignored.

### Changed

- **Upgrade note:** when `localStorage` is unavailable, the original is shown
  and the visitor is not part of the test.
- Only one assignment is kept per site: when the tag applies an experiment it
  removes every other `sabt:*` entry, so a concluded test's assignment stops
  riding along on the visitor's requests.

### Fixed

- Requests whose UTC day lies outside the experiment's `from_date`–`to_date`
  (for example a returning visitor after the test was concluded and its
  `to_date` moved back) are logged with `idexperiment` NULL, so they no longer
  count for the concluded test.

### Security

- Experiment rows are scoped to their site: `insertExperiment`,
  `updateExperiment`, `getExperiment` and `deleteExperiment` require admin
  access to the given site (not just to some site); `deleteExperiment` now
  takes `idSite` (`deleteExperiment(int $id, int $idSite)`); updates and
  deletes filter on `id` and `idsite`, and an update never changes a row's
  `idsite`. The Controller's write actions check admin access to the site
  before acting.
- `API::getExperimentPublic` now requires view access to the site and returns
  only the public tag fields. The tag endpoint in the Controller reads the row
  directly by id and idsite.

## [0.1.96] - 2026-09-16

### Added

- `Updates/0.1.96.php`: invalidates this plugin's existing archives on
  upgrade. **Upgrade note:** 0.1.95's row shape (nested subtables with summed
  parent-row metrics) only applies to archives computed after this update
  runs — this file makes sure existing installations recompute rather than
  keep serving the old flat shape indefinitely. Invalidation only marks
  archives for recomputation on next access; it does not delete any data.

### Fixed

- **Version numbering.** The archive-invalidation update above was first
  written as `Updates/0.1.95.php`, but `plugin.json` had already been bumped
  to 0.1.95 before that file existed. Matomo only executes an `Updates_x_y_z`
  class for a version it has not already recorded in
  `matomo_option.version_SimpleABTesting`, so on any instance that had seen
  the earlier 0.1.95 build the update would have been skipped without a word.
  Renamed to 0.1.96 — file, class and `plugin.json` together, since Matomo
  matches update files by filename-as-version.
- The invalidation is now derived from the plugin's own data instead of
  sweeping every year from 2010 to the current year for every site. It looks
  up which sites have `simple_ab_testing_log` rows and what date range each
  one covers, and invalidates one day per day in that range, clamped so the
  upper bound never exceeds today. The old sweep expanded to roughly 430
  queued period recomputations per site-year and, because a `year` period
  always spans the whole calendar year, queued future-dated days, weeks and
  months as well. A site with no SimpleABTesting data now correctly has
  nothing invalidated.
- `doUpdate()` skips the invalidation when the plugin is not activated.
  `ArchiveInvalidator::markArchivesAsInvalidated()` throws in that case, and
  `Updater::update()` turns any exception from `doUpdate()` into a warning
  while marking the component successfully updated anyway — so the failure
  would have been silent and the version bumped regardless. The no-op is now
  deliberate.

## [0.1.95] - 2026-09-16

### Added

- `updateExperiment` API/DAO method, so editing an experiment no longer means
  delete-and-reinsert.
- `getExperimentGoalData` API method, exposing the new per-goal conversion
  record.
- `getExperimentUniqueVisitorData` API method, exposing the previously
  unread unique-visitors record (day-only — see `Archiver::recordNamesForMultiPeriod`).
- A concurrent-experiment guard: a new experiment on a site whose date range
  overlaps an existing one on that site is now rejected.
- Experiment names containing a comma are rejected (the Tag Manager tag's
  parameter string is comma-joined and never URL-encodes the name).

### Changed

- `nb_visits` now counts distinct visits (`COUNT(DISTINCT idvisit)`), not
  tracking hits (`COUNT(*)`) — affected both the archiver and the raw
  `getExperimentReportData` method.
- Goal conversions are now deduplicated against the log table before joining
  `log_conversion`, so a visit logged on multiple tracking hits no longer
  inflates its conversion count.
- `insertExperiment`/`deleteExperiment` take `int`, not `bool`, for `idSite`/`id`.
- Report rows now nest variant (and, for goals, the goal id) as subtables
  instead of flat columns — a flat `variant` column was summed into a
  meaningless number by Matomo's own period rollup as soon as an experiment's
  data spanned a week/month archive. The top-level (and, for goals, the
  middle/variant-level) row now also carries the real SUM of its subtable's
  metrics, so the visible row shows real numbers without needing to expand
  it.
- `Controller::getExperimentReport()` — the actual live render path Matomo
  dispatches to — now shows the same corrected columns
  (`label`, `nb_visits`) as `Reports/GetExperimentReport.php`'s own view
  config.
- `Controller::addExperiment()` now redirects with the real validation/
  overlap error message on failure instead of a raw error page.

### Deprecated

### Removed

- Dead, commented-out `trackEvent` calls in the tag's JS (and the
  `originalName` parameter that only fed them).

### Fixed

- Coding standard
- Unique visitors are no longer summed across a multi-day period (a browser
  seen on three different days was counted as three) — moved to its own,
  day-only archive record.

### Security
