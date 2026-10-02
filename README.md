# PHP Query Performance Skills

An agent skill for finding and fixing database query problems in Laravel (Eloquent) and Symfony (Doctrine ORM) code, above all N+1 queries. Every fix is proven with a query count taken before and after.

**Measure → Diagnose → Fix → Prove**

## Skills

| Skill | What it does |
|---|---|
| `optimise-queries` | Reads the code path of a controller, page, command, job or handler, measures its queries with a test or script, fixes the N+1s and other problems without changing behaviour, re-measures and reports the before/after counts. Asked only to review, it stops after the diagnosis and changes nothing. |

## What's Covered

**Method (both frameworks):** query-count tests at two data sizes, grouping statements to find the repeated one, fixtures that make an N+1 visible, capturing output to prove behaviour didn't change, a table of fixes that silently change results, `EXPLAIN` on the real driver, index migrations proposed rather than applied, caching only after query fixes and with every invalidation path.

**Laravel / Eloquent:** N+1 through loops, Blade, API Resources, policies and `$appends` accessors; `with()` / `load()` / `loadMissing()`; nested and constrained eager loads; column selection that silently nulls relations; `withCount` / `withSum` / `withExists`; `whenLoaded` / `whenCounted`; `$with` on the model; automatic eager loading (12.8+); `chunk()` vs `chunkById()` when the callback writes, and `chunkById()` with another `orderBy`; `lazy()` / `lazyById()` / `cursor()`; `exists()` vs `count()`; pagination; `whereHas` vs joins; `firstOrCreate` races and unique indexes; bulk updates and model events. Measurement with `expectsDatabaseQueryCount()`, the query log, `DB::listen`, `preventLazyLoading()` (and its one-row blind spot), `handleLazyLoadingViolationUsing()` and `shouldBeStrict()`.

**Symfony / Doctrine:** lazy proxies and the identity map, fetch joins (`addSelect`), joins that aren't fetch joins, filtered fetch joins that truncate collections, `setMaxResults()` vs `Paginator`, `setFetchMode()`, `EXTRA_LAZY`, `fetch: EAGER`, aggregate counts, array and `NEW` DTO hydration, `toIterable()` with `clear()`, detached references after `clear()`, DQL bulk updates, Messenger workers. Measurement with the profiler's `db` collector in web tests, `doctrine.debug_data_holder` in kernel tests, DBAL logging middleware (and the removal of `DebugStack` in DBAL 4).

**Versions:** checked against Laravel 10.50, 11.57, 12.69 and 13.34 (plus older 10.x and 12.x tags to pin when APIs arrived), and two Symfony stacks: 6.4 / DoctrineBundle 2.19 / ORM 2.20 / DBAL 3.10 and 8.1 / DoctrineBundle 3.3 / ORM 3.7 / DBAL 4.5. Query counts quoted in the rules were measured on SQLite.

## Install

### Claude Code plugin

```
/plugin marketplace add gkwelding/php-query-performance-skills
/plugin install php-query-performance-skills@php-query-performance-skills
```

The command becomes `/php-query-performance-skills:optimise-queries <target>`.

### Copy into a project or user skills folder

```
cp -r skills/optimise-queries ~/.claude/skills/
# or per project:
cp -r skills/optimise-queries .claude/skills/
```

### claude.ai

Build the package, then upload `dist/optimise-queries.skill` (Settings → Capabilities → Skills):

```
sh scripts/build-skills.sh
```

The script packages the committed files at `HEAD`; commit edits first.

## Usage

```
/optimise-queries app/Http/Controllers/Api/PostController.php
/optimise-queries "GET /orders"
/optimise-queries src/Command/ExportInvoicesCommand.php
/optimise-queries review src/Controller/ProductController.php   # report only, no changes
/optimise-queries                                               # no target: what changed on this branch
```

## Ground Rules the Skill Enforces

- Measure before and after, the same way on the same data. No number, no fix.
- Never change behaviour: same response, same rows written, same order, same output.
- Fix the query first; cache only afterwards, with explicit invalidation.
- Propose index migrations; never apply them to a database other than the local test one.
- Don't connect to production databases; ask for the slow query log or an `EXPLAIN` instead.

## Layout

```
skills/
└── optimise-queries/
    ├── SKILL.md
    └── rules/
        ├── general/     # measuring before/after, EXPLAIN, indexes, caching
        ├── laravel/     # measuring, eager loading, queries and batches
        └── doctrine/    # measuring, fetching, batch processing
scripts/build-skills.sh  # packages dist/*.skill for claude.ai
evals/                   # with/without-skill comparison on Laravel and Symfony fixtures
```

## Status

First version. The rules were written against the framework sources listed above, and the behaviour they describe (query counts, skipped rows, truncated collections, exceptions) was reproduced in small SQLite scripts. Treat it as a strong starting point.

## Evals

[`evals/`](evals/README.md) compares fixes made with and without the skill on Laravel and Symfony fixtures with planted problems: an N+1 in a controller loop, a nested N+1 in API Resources, a `count()` per row where `withCount` fits, a `chunk()` that skips rows, and lazy Doctrine associations in a Twig loop. Hidden tests, copied in only after Claude has finished, score each run: query count at two data sizes before and after, exact output unchanged (including after a write, so a cache fails), caching added, schema changes proposed vs applied, files changed and cost. A blind side-by-side review compares the two diffs.

First result, one sample (`ArchiveStaleOrders`, default model): both variants caught the `chunk()` trap and kept the output identical. The plain prompt also batched the per-row `UPDATE`s, so it got the command to 3 queries at both data sizes, against 10 / 29 for the skill, at about half the cost ($0.25 vs $0.45). The skill left a query-count test as a regression guard. Possible rule gap: a loop that writes one row at a time inside `chunkById()`.

## Licence

MIT, © Black Pug Ltd. See [LICENSE](LICENSE).
