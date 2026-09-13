---
paths:
  - 'app/Domain/Reports/**'
  - 'app/Livewire/Reports/**'
  - 'resources/views/livewire/reports/**'
  - 'resources/views/components/report-*.blade.php'
  - 'resources/views/components/chart/**'
  - 'app/Jobs/SendScheduledReport.php'
  - 'app/Mail/ScheduledReportMail.php'
---

# Reports

## The registry is the security boundary
`ReportSources` declares every source, dimension, measure, join and filter a
report may use. A `ReportDefinition` carries **keys only** — never a column, a
table or a function — and `ReportRunner` drops any key the source does not
declare. That is the whole reason a report builder here is not an
arbitrary-SQL console with a nice front end.

It holds for stored reports too: the `reports.definition` column is normalised
through the DTO on the way in, so a row edited by hand still cannot make the
runner select something it was never offered. The tests assert this directly —
see "a dimension the source does not declare is dropped".

Every source's base query goes through the module's own `visibleTo()`, and
`applyScopes()` runs before `getQuery()` so the soft-delete scope survives. An
aggregate is the easiest kind of leak to miss, because it does not look like the
rows behind it.

## A report is run as its reader, never as its author
`ReportShow`, the KPI dashboard and a scheduled attachment all run the
definition for the **current** user. Two people opening the same shared report
see different numbers, and that is correct rather than a bug to paper over.

`Report::runnableBy()` is a separate question from `ReportPolicy::view()`: a
shared report about deals is **listed** for everybody and **refuses to run** for
somebody with no `deals.view`. Hiding it entirely would be worse — a report that
appears for one colleague and not another looks like a fault.

## Three different nothings
`ReportResult` distinguishes **refused** (you may not see this source), **not
runnable** (no measure chosen yet) and a real run that matched nothing. The
table and chart components say a different thing for each. Collapsing them would
have a screen telling somebody there is no data when the truth is they cannot
see it.

A measure's empty value follows the aggregate: a count of no rows is nought, an
average of no rows is **null**. Printing nought for an average is a claim nobody
made.

## Totals are their own query
`ReportRunner::totals()` re-runs the measures ungrouped rather than summing the
rows. A total of averages is not the average, and a truncated row list would
produce a total that did not match the data. `MAX_ROWS` truncation is reported in
the result so a screen can say the list was cut.

## Charts are server-side SVG, and that is not a style preference
No JavaScript charting library. Three reasons, the third decisive:

- it adds no dependency to a stack that is fixed;
- a chart in a Livewire page needs no re-initialisation after an update, which
  is the usual source of blank charts;
- **a scheduled report is emailed as a PDF, and dompdf cannot run JavaScript.**
  A JS chart would be missing from every one of them.

`ChartData` owns the geometry so it can be tested; the Blade files only place
what it hands them. Two traps it exists to hold: a single pie slice is drawn as
a **circle**, because an arc of exactly 360° starts and ends at the same point
and SVG draws nothing; and a funnel's bands are measured against the **widest**
band rather than the first, because records created part-way down make a later
stage larger and against the first that band would be wider than the chart.

## Standard reports are definitions, not special-cased SQL
`StandardReports` declares the eight built-ins as `ReportDefinition`s, so a
built-in opens in the builder, can be duplicated, and goes through the same
runner with the same scoping. A hand-written query would be a second engine to
keep in step, answering with numbers the builder could not reproduce.

`install()` creates what is missing and **never overwrites**: a company may have
edited a built-in to suit itself, and a seeder that undid that on every deploy is
one nobody dares run. A standard report cannot be deleted; duplicating one gives
you an ordinary report of your own.

## Key lists are constants, because building a source reads the database
`ReportSources::KEYS` and `StandardReports::SLUGS` are plain arrays rather than
`array_keys(all())`. Building a source calls `DealFields::stageOptions()`, which
queries the pipelines — and a Pest dataset closure is resolved **before the test
database exists**. See .ai/rules/tests.md; this is the same trap
`CustomFieldTest` records.

## Dashboard widgets are rows, not a JSON blob
One row per widget, per user. A drag updates one position; a blob would rewrite
the whole layout on every drag and lose a concurrent change from another tab. The
unique `(user_id, report_id)` index is what stops a double-click adding a
duplicate nobody notices until they remove one and the other stays.

A reorder arriving from the browser is filtered against what the person actually
owns, and anything the drag did not mention keeps its place at the end rather
than collapsing to nought and jumping to the front.

## The forecast shows both numbers and never blends them
Pipeline-weighted knows about the deals that exist and is optimistic in the way
salespeople are; historical knows nothing about the pipeline and is the number
that turns out to be right. The **gap** between them is the most useful thing on
the screen, and one blended figure would hide which to believe.

The historical window excludes the current month — it is half over, and averaging
a part-month in with whole ones drags every figure down and makes every forecast
look optimistic. Weighting uses the same fallback `Deal::weightedValue()` uses,
so the forecast and the deal page cannot disagree.

## A schedule belongs to one person, and advances before it sends
`ReportSchedulePolicy` has **no administrator branch**. A schedule sends its
owner's view of the data to addresses they chose; somebody else editing it would
redirect their figures without their knowing.

`DispatchScheduledReportsAction` advances `next_run_at` **before** queuing the
job, and from *now* rather than from the old due time. Advancing afterwards would
queue the same report again on the next tick while the first was still rendering;
advancing from the old time would make a scheduler that was down for a day send
twenty-four copies.

The attachment is rendered inside the queued job, not carried through the queue
as bytes — the same reasoning as `QuoteMail`. A morning's scheduled reports would
otherwise sit in Redis as megabytes of PDF.

## `ScheduleFrequency::next()` takes the day of the *week* third
`next($after, $hour, $dayOfWeek, $dayOfMonth)`. A day of the month passed
positionally lands in `$dayOfWeek` and is silently ignored, and every monthly
schedule then fires on the 1st. Call it with **named arguments**; the tests do.
