# Evals

Checks whether `optimise-queries` produces better query fixes than a plain prompt: fewer queries, the same output, no cache hiding the problem, no schema change applied behind your back. Every score comes from code Claude never sees.

For each target, `run.sh` scaffolds a fresh Laravel or Symfony app on SQLite, adds the fixture code, and scores three runs:

| Variant | What runs |
|---|---|
| `baseline` | Nothing. The untouched fixture, scored the same way, gives the "before" numbers |
| `without` | `claude -p "<target> is slow. Fix its database queries without changing behaviour."` |
| `with` | `claude -p "/optimise-queries <target>"`, with this repo's `skills/` copied into the app's `.claude/skills/` |

After Claude finishes, the run's diff is saved. Only then are the hidden tests (`hidden/<framework>/tests/Eval/`) copied in and run. They hold two kinds of test per target:

- **Behaviour:** the exact response bytes (JSON, HTML, command output) compared against a snapshot taken from the original code. Each controller also gets a second test that requests the page, changes the data, then requests it again: a cached response fails it.
- **Query probe:** one request (or command run) at a small and a large data size, counted with `DB::listen` (Laravel) or the profiler's `db` collector (Symfony). `preventLazyLoading()` isn't used, because it ignores one-row results and relation method calls such as `$order->items()->count()`.

One row per run in `results.csv`:

| Column | Meaning |
|---|---|
| `queries` | Probe count at the small / large size, e.g. `9/29`. The baseline row is "before". The same count at both sizes means nothing runs per row |
| `behaviour` | Hidden behaviour tests passed / run (probe excluded). Anything short of all of them means the fix changed what the code does |
| `caching` | Added non-test lines that cache (`Cache::`, `cache()`, `->remember(`, `CacheInterface`, `ItemInterface`, `enableResultCache`, `#[ORM\Cache`). The skill allows caching only after query fixes; any hit is worth reading |
| `schema_proposed` | New migration files in the diff |
| `schema_applied` | 1 if the app's own database (`database/database.sqlite`, `var/data_dev.db`) has a different schema or migration log after the run. The skill says to propose indexes and leave applying them to the user; test databases don't count |
| `files_changed`, `test_files` | Files in the diff outside / inside `tests/`. The skill keeps its query-count test as a regression guard, so `test_files` is expected there |
| `cost_usd`, `turns`, `minutes` | From `claude -p --output-format json` |

## Blind review

At the end of a run, `judge.sh` asks one tool-less `claude -p` per target to compare the two diffs, labelled A and B in random order, with the original fixture code for context. It picks A, B or tie for `behaviour` (keeps output, ordering and writes), `root_cause` (removes repeated queries at the source rather than caching or moving them), `minimal`, `clarity` and `overall`. Verdicts, mapped back to `with` / `without`, go to `judge.csv` with a one-sentence reason each. `JUDGE=0 evals/run.sh` skips it; `evals/judge.sh evals/.work/results/<timestamp>` re-judges a saved run (`JUDGE_BUDGET_USD`, default 1, caps each comparison).

## Targets

| Target | Planted problems | Baseline `queries` | Good fix |
|---|---|---|---|
| Laravel `OrderController` (`GET /orders`) | N+1 on `$order->customer` in a controller loop; `$order->items()->count()` per row where `withCount` fits | `9/29` (3 / 10 customers) | `2/2` |
| Laravel `CustomerController` (`GET /customers`) | Nested N+1 inside API Resources (`orders`, then each order's `items`), a customer with no orders, an order with no items | `8/25` | `3/3` |
| Laravel `ArchiveStaleOrders` (`orders:archive-stale`) | `chunk()` whose callback changes the column it filters on, so it skips rows; N+1 on `$order->customer` per row | `12/23` (6 / 20 stale orders) | `10/29` |
| Symfony `AuthorController` (`GET /authors`, Twig) | Lazy-loaded `books` and `publisher` associations in a Twig loop; an author with no books (an inner join drops them); `#[ORM\OrderBy]` on `books`, which the fix must keep | `6/13` (3 / 10 authors) | `1/1` |

`ArchiveStaleOrders` is the odd one: the command says it archives *every* stale pending order, and the hidden test holds it to that, so the baseline fails behaviour (it archives 7 of 12). A fix that eager-loads `customer` but keeps `chunk()` still fails. Its query count goes *up* when fixed, because the fixed command visits every row; read it together with `behaviour`.

## Proving the scores can fail

`stub/claude` stands in for the CLI and copies a canned fix over the app, so the plumbing can be checked without API spend. `STUB_FIX` picks the fix:

| `STUB_FIX` | Target | `queries` | `behaviour` | Other |
|---|---|---|---|---|
| `good` | all | `2/2`, `3/3`, `10/29`, `1/1` | all pass | |
| `cache` | `OrderController` wrapped in `Cache::remember` | `9/29` (unchanged) | `1/2` (stale after a write) | `caching` 1 |
| `change` | `OrderController` with the eager loads but no `orderByDesc` | `2/2` | `0/2` | |
| `change` | Symfony fetch join with `join` instead of `leftJoin` | `1/1` | `0/2` (authors without books vanish) | |
| `schema` | adds an index migration and runs `php artisan migrate` | `9/29` | `2/2` | `schema_proposed` 1, `schema_applied` 1 |

```
PATH="$(cygpath -u "$PWD/evals/stub"):$PATH"   # plain "$PWD/evals/stub:$PATH" outside Windows
command -v claude                               # must print .../evals/stub/claude before a stub run
STUB_FIX=cache JUDGE=0 evals/run.sh OrderController
```

## Running

Needs `composer`, `git`, `php` with `pdo_sqlite` and the `claude` CLI.

```
evals/run.sh                    # every target
evals/run.sh laravel            # one framework
evals/run.sh OrderController    # targets whose path contains "OrderController"
MODEL=claude-sonnet-5-5 BUDGET_USD=3 evals/run.sh symfony
```

The first run scaffolds the apps into `evals/.work/` (gitignored, or `WORK=...`) and reuses them afterwards. Fixture edits are copied in on every run; delete a framework's folder to rebuild it after changing its packages. Before every run the app is reset with git and its own database restored from a copy taken at scaffold time, so one run's changes (or applied migration) can't reach the next. Each run writes `results.csv` plus, per run, Claude's JSON and final report (`*.claude.txt`), the diff, the hidden tests' output and JUnit log, and the probe counts to `evals/.work/results/<timestamp>/`.

To change a snapshot after editing a fixture on purpose: copy `hidden/<framework>/.` into the scratch app, run its test with `EVAL_WRITE_SNAPSHOTS=1`, read the new file and copy it back.

**Cost:** 8 `claude -p` runs for `all`, each capped by `BUDGET_USD` (default 5), plus 4 judge calls capped by `JUDGE_BUDGET_USD` (default 1). Claude can only edit files and run `php` (including `artisan`), `vendor/bin/phpunit` / `pest`, `bin/console`, `composer dump-autoload` and read-only `git` in the scratch app.

**Noise:** one run per variant is one sample. Run each a few times before trusting a difference, and compare like with like (same model, same framework versions).

**Not measured:** review mode (`/optimise-queries review <target>`), and whether the before/after numbers in Claude's report match the probe; read `*.claude.txt` for that.

### Windows

Run it from Git Bash. The scripts handle these:

- Git Bash rewrites `/optimise-queries ...` into a file path when passing it to a native exe; `claude` runs with `MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'`.
- Native Windows `php` can't open MSYS paths like `/tmp/...`, so every path given to `php` is relative to the app directory.
- Laravel 13 skeletons ship `laravel/pao`, which switches PHPUnit to JSON output when it detects an AI agent (any run started from Claude Code). The hidden tests run with `PAO_DISABLE=1` and are read from JUnit XML. `claude -p` itself still runs with pao, as in a real project.
- A `C:/...` entry in `PATH` breaks on the colon: add the stub with `$(cygpath -u ...)`, and check `command -v claude` before a stub run.

Current `laravel/laravel` and Symfony skeletons ship a `CLAUDE.md`/`AGENTS.md`. They apply to both variants equally.

## First result, one sample

`evals/run.sh ArchiveStaleOrders`, default model, `BUDGET_USD=3`, Laravel 13.34, 2026-10-02:

| Variant | `queries` | `behaviour` | `caching` | `schema` | `files_changed` | `test_files` | Cost | Turns | Minutes |
|---|---|---|---|---|---|---|---|---|---|
| baseline | `12/23` | 0/1 | | | | | | | |
| without | `3/3` | 1/1 | 0 | none | 1 | 0 | $0.25 | 9 | 2.8 |
| with | `10/29` | 1/1 | 0 | none | 1 | 1 | $0.45 | 12 | 1.2 |

Both variants spotted the `chunk()` trap, switched to `chunkById()` and eager-loaded `customer`, so both archive all 12 orders with the output unchanged. The plain prompt went further: one bulk `whereKey(...)->update()` per chunk instead of one `UPDATE` per order, giving 3 queries at both sizes. The skill kept the per-row `update()`, so its count still grows with the data, but it left a query-count test behind. The blind review (judge $0.24) preferred the plain prompt's fix for `root_cause`, `minimal` and `overall`, and the skill's for `behaviour` (per-row updates keep model events, though `Order` has none) and `clarity` (the test). Total spend: $0.94.

One sample. It says the harness works end to end, not which variant is better.
