---
name: optimise-queries
description: "Find and fix database query performance problems in Laravel (Eloquent) and Symfony (Doctrine ORM) code, above all N+1 queries, and prove each fix with a query count taken before and after. Use when the user asks to optimise, speed up or cut the queries of a controller, route, page, API endpoint, Blade/Twig view, API Resource, serializer, Artisan/console command, job, Messenger handler or repository, including 'fix the N+1', 'this page runs 300 queries', 'why is this endpoint slow', 'add eager loading', 'too many queries', 'this export runs out of memory', and review-only requests such as 'check this controller for N+1s' (report, change nothing). Not for designing a schema from scratch, tuning the database server (my.cnf, postgresql.conf), front-end or HTTP performance, or slowness that doesn't involve the database."
allowed-tools: Read, Write, Edit, Glob, Grep, Bash
---

# Optimise Queries

Find the query problems in a Laravel or Symfony target, fix them without changing behaviour, and prove every fix with a query count measured the same way before and after.

**Target:** $ARGUMENTS

If no target was given (the line above is empty or shows a literal `$ARGUMENTS` placeholder), use what changed: files from `git diff --name-only main...HEAD` (use the repo's default branch) plus `git status --porcelain` that are controllers, models/entities, repositories, resources/normalizers, Blade/Twig templates, commands, jobs or handlers, excluding `vendor/` and migrations. Take the entry points (controller actions, commands, jobs, handlers) one at a time. If there are none, ask for a target: a route, a controller action, a command or a job.

**Review only.** If the user asked to review, audit or find problems without fixing them (or the target starts with `review`), do Steps 1-3 and stop with the report. Change no project files; a measurement test or script you write for the review goes in the report or is deleted afterwards.

## Quality Standards

- No number, no fix. Each change comes with the query count before and after, from the same measurement on the same data.
- Behaviour doesn't change: same response body, same rows written, same order, same command output. This is a refactor.
- Read the whole path before changing anything. The N+1 is usually in a template, resource, serializer, accessor or policy, not where the query is built.
- Fix the query before reaching for a cache.
- Index changes are proposed as migrations, never applied to a database other than the local test one.

---

## Step 1: Detect the Stack and Read the Path

1. **Stack** from `composer.json` / `composer.lock`: `laravel/framework`, or `doctrine/orm`, `doctrine/dbal`, `doctrine/doctrine-bundle` and `symfony/framework-bundle`, with versions (the rules note version differences). Also the debug tools installed (`laravel/telescope`, `barryvdh/laravel-debugbar` or `fruitcake/laravel-debugbar`, `symfony/web-profiler-bundle`) and the database driver (`.env`, `phpunit.xml`, `config/database.php`, `doctrine.yaml`).
2. **The target** and everything it runs that can touch the database: models/entities and their relation mappings (`$with`, `$appends`, accessors, `fetch:` modes), query scopes and repositories, API Resources / serializer groups / normalizers, Blade/Twig templates and components, policies/voters, observers, listeners and subscribers.
3. **Candidates.** List each suspected problem with `file:line` and the rule it matches. They stay hypotheses until Step 2 measures them.

## Step 2: Measure Before

1. **Choose the measurement** (`general/measure-first.md`): an existing feature/web test that covers the target, otherwise a new query-count test, otherwise a script. Laravel: `laravel/measuring.md`. Symfony: `doctrine/measuring.md`.
2. **Seed data that shows the problem**: several parents, several children each, distinct related rows, at two sizes (e.g. 3 and 10 parents).
3. **Record** the total count, the statements grouped by SQL (a statement repeated once per row is the N+1), and peak memory for batch code.
4. **Capture the output** to compare later: response JSON, rendered HTML, rows written, command output.

## Step 3: Diagnose

1. For each repeated statement, find the line that issues it: the lazy-loading exception (Laravel), the profiler backtrace (Doctrine), or by reading the path.
2. Match it to a fix from the rules. Repetition that a rule doesn't cover is still a finding: report it with the evidence.
3. If there's no repetition and the count is already small, the problem is a slow query, not a count. Go to `general/indexes-and-caching.md`.
4. Print the findings table (format under Step 5) with the before counts. **Review only stops here.**

## Step 4: Fix

1. One problem at a time, with the smallest change that removes it, at the query that loads the parents (`with()`, `withCount()`, a fetch join, `setFetchMode()`, `chunkById()`, `toIterable()` + `clear()`).
2. Keep public signatures, response shape, ordering, pagination and writes the same. Check each fix against the behaviour traps in the rule you applied.
3. If the fix needs an index, write the migration (`general/indexes-and-caching.md`) and leave it for the user to run.
4. Caching comes after query fixes, only with explicit invalidation.

## Step 5: Prove

1. Re-run the measurement from Step 2 on the same data. For an N+1 fix the count must drop and be the same at both data sizes.
2. Compare the captured output. If it differs, revert that fix and re-diagnose.
3. Run the target's existing tests and their neighbours.
4. Keep the query-count test as a regression guard, unless the user doesn't want new tests: then say where it is so they can drop it.
5. Report:

```
## Query Report: {target}

| # | Problem | Location | Fix | Before | After |
|---|---|---|---|---|---|
| 1 | N+1 on Post::author in PostResource | app/Http/Resources/PostResource.php:18 | with('author') in PostController@index | 21 / 51 | 2 / 2 |

Measured with: tests/Feature/Http/PostIndexQueryCountTest.php, 10 and 25 authors x 2 posts, SQLite.
Output: response JSON identical before and after.
Proposed, not applied: index on posts (author_id, published_at), database/migrations/2026_10_02_000000_add_author_published_index_to_posts.php
Not fixed: {finding, and why}
```

Before/after columns show the count at each data size.

---

## Troubleshooting

**Can't run anything** (no test database, app won't boot). Review statically, label every count "estimated", and say what's needed to measure. Don't claim a fix works.

**Count doesn't drop.** The eager load isn't reaching the access point: a different relation name, the relation called as a method (`$post->comments()->count()`), a collection loaded in another place, an accessor or policy querying, or (Doctrine) a join without `addSelect`. Re-diagnose from the grouped statements.

**Count drops but output changes.** Revert. Usual causes: a constrained eager load or a filtered fetch join hiding children, an inner join dropping parents, `select()` leaving out a key or a column an accessor reads, `whenLoaded()` omitting a key, `chunkById()` changing the order.

**The query is slow, not repeated.** Get `EXPLAIN` from a database with realistic data and propose an index (`general/indexes-and-caching.md`). Plans from SQLite with ten rows say little about MySQL or Postgres with millions.

**Only slow in production.** Ask for the slow query log or an `EXPLAIN` from there. Don't connect to production databases.

**Lots of queries from packages** (auth, session, permissions, feature flags). Report them separately; fix them only if the target triggers them per row.

---

## Example

```
User: /optimise-queries app/Http/Controllers/Api/PostController.php

Step 1: Laravel 12.30, SQLite tests. index() returns PostResource::collection(Post::latest()->paginate(20)).
        PostResource reads $this->author->name and $this->comments->count(). Post has no $with.
        Candidates: N+1 on author (PostResource:14), comments loaded per post only to count (PostResource:16).

Step 2: No test covers the query count. Writes tests/Feature/Http/PostIndexQueryCountTest.php with
        expectsDatabaseQueryCount and two sizes. Before: 5 authors x 2 posts = 22 queries,
        10 authors x 2 posts = 42 (pagination count + page + one query per post for each relation).
        Saves the JSON response.

Step 3: Repeated: select * from "users" where "id" = ? (once per post),
        select * from "comments" where "post_id" = ? (once per post).

Step 4: Post::with('author')->withCount('comments')->latest()->paginate(20);
        PostResource uses $this->comments_count.

Step 5: 3 queries at both sizes (count, page with comments_count subselect, authors). JSON identical. tests/Feature/Http run: green.
        Report: 22 / 42 -> 3 / 3. Test kept as a regression guard.
```

---

## Rules Reference

Paths are relative to `./rules/`. Read the ones that apply before measuring.

### Always

- `general/measure-first.md` - what to count, data that shows an N+1, same measurement before and after, behaviour checks
- `general/indexes-and-caching.md` - when the problem is one slow query, an index is needed, or caching is on the table

### By Target

| Target | Also read |
|---|---|
| **Laravel**, any target | `laravel/measuring.md` |
| **Laravel** controller, page, Blade view, API Resource, model with `$with` / `$appends` / accessors | `laravel/eager-loading.md` |
| **Laravel** command, job, export, import, batch update, anything with `chunk` / `cursor` / `whereHas` / `firstOrCreate` / pagination | `laravel/queries-and-batches.md` (and `laravel/eager-loading.md` if it loops over relations) |
| **Symfony / Doctrine**, any target | `doctrine/measuring.md` |
| **Symfony** controller, Twig template, serializer/normalizer, repository, entity with `fetch:` modes | `doctrine/fetching.md` |
| **Symfony** console command, Messenger handler, import, export, anything iterating many entities | `doctrine/batch-processing.md` (and `doctrine/fetching.md` if it loops over associations) |
