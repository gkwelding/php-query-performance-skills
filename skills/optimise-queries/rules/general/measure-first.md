---
title: Measure Before and After
tags: measurement, query-count, n+1, method, behaviour
---

## Measure Before and After

Query work without a number is guesswork. Each fix is stated as a count before and after, measured the same way on the same data.

**Incorrect:**

```
Added with('author') to PostController@index, which should fix the N+1.
```

**Correct:**

```
PostController@index: 21 -> 2 queries with 20 posts by 10 authors, 51 -> 2 with 50 posts by 25 authors.
Measured with tests/Feature/Http/PostIndexQueryCountTest.php. Response JSON identical.
```

### Choose the Measurement

In order of preference:

1. **An existing test** that drives the target (feature, web or kernel test). Add a query-count assertion to a copy of it, or to the test itself if the project wants the guard.
2. **A new query-count test** for the target (`laravel/measuring.md`, `doctrine/measuring.md`). It doubles as the regression guard.
3. **A script** (tinker, a console command, a throwaway PHP file) that boots the app, seeds or reads local data, runs the target and prints the count. Delete it afterwards.

Debugbar, Telescope and the Symfony profiler toolbar are fine for finding a problem in the browser. They aren't the before/after evidence: the data behind a page changes between visits.

### Data That Shows the Problem

An N+1 over one parent runs two queries, the same as the fix. The fixture has to make it visible:

- Several parents, each with several children, and **distinct** related rows. Doctrine's identity map loads each related entity once, so 10 posts by the same author look like 1 + 1 queries; 10 posts by 5 authors are 1 + 5.
- **Two sizes** (e.g. 3 and 10 parents). A count that grows with the data is the N+1. After the fix it must be the same at both sizes.
- Laravel's `preventLazyLoading()` only flags models hydrated from a result of more than one row. A one-row fixture never throws.
- Seed through the project's factories, fixtures or Foundry stories so the rows look like real ones (states, soft deletes, tenancy columns).

### What to Record

- Total query count.
- Statements grouped by SQL text with bindings left out, most frequent first. A statement repeated once per row is the N+1; the table name and `where "post_id" = ?` point at the relation.
- For commands, jobs, imports and exports: peak memory (`memory_get_peak_usage(true)`) and the count per batch.
- Time only as a secondary signal. Timings on SQLite with a few dozen rows say little about production.

### Same Measurement, Same Data

Before and after use the same test or script, the same seed, the same database driver and the same user/permissions. A Debugbar count before and a test count after isn't a comparison.

### Behaviour Stays the Same

Capture the output before the first change: response JSON, rendered HTML, rows written, command output, dispatched jobs. Compare after each fix. Query fixes change behaviour in predictable ways:

| Change | What can silently differ |
|---|---|
| Constrained eager load / filtered fetch join | The relation now holds only the matching children, everywhere it's read later |
| Inner join instead of a lazy relation | Parents with no related row disappear |
| Eloquent `join()` to a to-many table | Parents repeated once per child, totals and pagination off |
| Doctrine fetch join of a collection with `setMaxResults()` | The limit applies to SQL rows, so parents and collections come back truncated (use `Paginator`) |
| `select()` with fewer columns | Missing foreign key gives a `null` relation; accessors read missing attributes |
| `whenLoaded()` in a resource | The key is omitted when the controller doesn't load the relation |
| `chunk()` to `chunkById()`, `lazy()` to `lazyById()` | Processing order becomes primary-key order |
| Per-model `save()` to a bulk `update()` | Model events, observers, mutators and Doctrine lifecycle callbacks no longer run |
| Entities to arrays or DTOs | Callers that expect entities or models break |

If the output differs, revert that fix and choose another.

### Keep the Guard

A query-count test fails the build when someone removes the eager load or adds a relation to the resource. Keep it, named for what it protects (`test_index_runs_same_queries_for_any_number_of_posts`), unless the user doesn't want new tests.
