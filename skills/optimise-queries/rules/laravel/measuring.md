---
title: Measuring Queries in Laravel
tags: laravel, measurement, query-count, expectsDatabaseQueryCount, preventLazyLoading, DB::listen
---

## Measuring Queries in Laravel

Checked against Laravel 10.50, 11.57, 12.69 and 13.34; version differences are noted.

### Query-Count Test

`expectsDatabaseQueryCount()` (in `InteractsWithDatabase`, available in Laravel 10-13) registers a `DB::listen` counter and asserts the total when the application is torn down. It counts **every query on the connection from the moment it's called**, so call it after arranging data, and remember that queries the test runs afterwards (`assertDatabaseHas`, `fresh()`) count too.

**Incorrect:**

```php
$this->expectsDatabaseQueryCount(3);

Author::factory()->count(5)->has(Post::factory()->count(2))->create(); // these inserts are counted
$this->getJson('/api/posts')->assertOk();
```

**Correct:**

```php
<?php

namespace Tests\Feature\Http;

use App\Models\Author;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PostIndexQueryCountTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('authorCounts')]
    public function test_index_runs_same_queries_for_any_number_of_posts(int $authors): void
    {
        Author::factory()->count($authors)->has(Post::factory()->count(2))->create();

        $this->expectsDatabaseQueryCount(2);

        $this->getJson('/api/posts')->assertOk();
    }

    public static function authorCounts(): array
    {
        return [
            '3 authors' => [3],
            '10 authors' => [10],
        ];
    }
}
```

The same count at both sizes is the proof that no query runs per row. Authentication, session and middleware queries are part of the count; they run once per request, so they don't affect the comparison between sizes. Use the project's style (PHPUnit `#[DataProvider]` / docblock providers, or a Pest dataset).

For a job or command: `(new ExportPosts())->handle(...)` or `$this->artisan('posts:export')->assertSuccessful()` after `expectsDatabaseQueryCount()`.

### Grouping Statements

The total says there's a problem; grouping says where. In a test, tinker or a script:

```php
DB::flushQueryLog();
DB::enableQueryLog();

$this->getJson('/api/posts'); // or run the command/job/service

$repeated = collect(DB::getQueryLog())->countBy('query')->sortDesc()->take(10);
DB::disableQueryLog();
```

Each log entry has `query`, `bindings` and `time` (Laravel 13 adds `readWriteType`). Bindings are kept apart from the SQL, so `countBy('query')` groups the per-row statements. Alternatively `DB::listen(fn (QueryExecuted $query) => ...)` with `$query->sql`, `$query->bindings`, `$query->time`. `$query->toRawSql()` on the event exists from Laravel 11; the query builder's `toRawSql()` from later 10.x releases.

The query log keeps every query in memory: turn it off again in long-running code.

### Finding the Line: preventLazyLoading

`Model::preventLazyLoading()` makes a lazy relation load throw `LazyLoadingViolationException`, naming the model and relation. Turn it on in the measurement test's `setUp()`, or for non-production in `AppServiceProvider::boot()`:

```php
Model::preventLazyLoading(! $this->app->isProduction());
```

What it does and doesn't catch:

- It only applies to models hydrated from a result with **more than one row**. `Post::first()->author` and a one-row `get()` never throw.
- It catches relation **property** access (`$post->author`). It doesn't catch relation **method** queries (`$post->comments()->count()`, `$post->author()->first()`), queries inside accessors, or `Post::where(...)` inside a loop. Count; don't rely on the exception alone.

To log instead of throwing (for example in production), register a handler. The lazy load still happens after the handler returns:

```php
Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
    logger()->warning('Lazy loaded '.$model::class.'::'.$relation);
});
```

The callback receives `($model, $relation)` in Laravel 10-12; Laravel 13 passes the `LazyLoadingViolationException` as a third argument.

### shouldBeStrict

`Model::shouldBeStrict()` turns on three things at once: `preventLazyLoading()`, `preventSilentlyDiscardingAttributes()` and `preventAccessingMissingAttributes()`. The last two throw on mass-assigning non-fillable attributes and on reading attributes that weren't selected, which can break code unrelated to the query fix. For this work, turn on `preventLazyLoading()` only, unless the project already uses `shouldBeStrict()`.

### Debugbar and Telescope

Use them only if `composer.json` already has them (`barryvdh/laravel-debugbar` or `fruitcake/laravel-debugbar`, `laravel/telescope`). They help find a page's repeated statements in the browser; the before/after evidence is the test. Don't install them for this.
