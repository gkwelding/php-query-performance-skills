---
title: Slow Queries, Indexes and Caching
tags: explain, indexes, migrations, caching, invalidation
---

## Slow Queries, Indexes and Caching

When the count is already small and one statement is slow, the fix is usually the query or an index. Caching comes last.

### EXPLAIN on the Real Driver

Take the SQL and bindings from the measurement, then run `EXPLAIN` on the project's driver with realistic data. A plan from SQLite with a few rows doesn't predict MySQL or Postgres with millions.

```php
// Laravel: explain() prefixes the SQL with EXPLAIN and returns a Collection of rows
Post::where('author_id', 42)->orderByDesc('published_at')->explain();

// SQLite: plain EXPLAIN returns VM bytecode, not a plan
$query = Post::where('author_id', 42)->orderByDesc('published_at');
DB::select('EXPLAIN QUERY PLAN ' . $query->toSql(), $query->getBindings());
```

```php
// Doctrine: SQL, params and types from the profiler or doctrine.debug_data_holder (doctrine/measuring.md)
$plan = $connection->executeQuery('EXPLAIN ' . $entry['sql'], $entry['params'], $entry['types'])->fetchAllAssociative();
```

The Symfony profiler's Doctrine panel has an "Explain query" link per query that does the same (`EXPLAIN QUERY PLAN` on SQLite).

`EXPLAIN ANALYZE` runs the statement (Postgres; MySQL 8.0.18+). Never use it on an `UPDATE`, `DELETE` or `INSERT` outside a throwaway database.

What points at a missing index: MySQL `type: ALL` with `key: NULL` or `Using filesort` on a large `rows` estimate; Postgres `Seq Scan` on a large table, or a `Sort` feeding a `Limit`.

### Propose Indexes, Don't Apply Them

An index is a schema change with a cost on every write. Write the migration and leave it for the user to run. Running it against the local test database to measure is fine; against anything shared, staging or production is not.

- Index what the slow query filters, joins and sorts on. Composite order: equality columns first, then the range or sort column.
- Postgres doesn't index foreign key columns automatically; MySQL InnoDB does. Laravel's `foreignId()->constrained()` adds the constraint, not an index, so on Postgres eager loads and `whereHas` on `post_id` scan without one.
- Don't add an index the measurement doesn't justify.
- On large tables, index creation can block writes. Postgres `CREATE INDEX CONCURRENTLY` can't run inside a transaction: a Laravel migration needs `public $withinTransaction = false;`, a Doctrine migration needs `isTransactional()` to return `false`. Say so in the report and let the user choose.

**Laravel:**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->index(['author_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['author_id', 'published_at']);
        });
    }
};
```

**Doctrine:** add the index to the mapping and generate the migration with `php bin/console doctrine:migrations:diff` (`doctrine/doctrine-migrations-bundle`). Read the generated SQL: `diff` includes any other drift between the mapping and the database, which doesn't belong in this change.

```php
#[ORM\Entity]
#[ORM\Index(name: 'idx_post_author_published', columns: ['author_id', 'published_at'])]
class Post
```

Use named arguments: `ORM\Index` takes `columns` first in ORM 2 and `name` first in ORM 3.

### Caching Comes Last

Only after the query fixes, when the remaining query is inherently expensive and the data can be stale for a while. Report a cache as a separate item, with its key, lifetime and every invalidation point.

- The key includes everything the result depends on: user or tenant, locale, permissions, filters, page.
- Invalidate on every write path. Eloquent model events (`saved`, `deleted`) don't fire for query-builder `update()` / `delete()` or raw SQL. Doctrine lifecycle callbacks and listeners don't run for DQL `UPDATE` / `DELETE`. Find those paths before relying on events.

**Incorrect:**

```php
// Hides the N+1 for an hour, and the list is stale after every edit
return Cache::remember('posts.index', 3600, fn () => PostResource::collection(Post::all())->resolve());
```

**Correct:**

```php
// Query fixed first; cache only what is still expensive, keyed by its inputs
$key = "authors.{$author->id}.post-stats";
$stats = Cache::remember($key, 600, fn () => $author->posts()->selectRaw('count(*) as total, max(published_at) as latest')->first());

// Invalidation, in Post::booted(); bulk updates elsewhere must forget the key too
static::saved(fn (Post $post) => Cache::forget("authors.{$post->author_id}.post-stats"));
static::deleted(fn (Post $post) => Cache::forget("authors.{$post->author_id}.post-stats"));
```

Symfony: `$cache->get($key, function (ItemInterface $item) { $item->expiresAfter(600); return ...; })` and `$cache->delete($key)`, or tags with `TagAwareCacheInterface::invalidateTags()`.

Doctrine's second-level cache and result cache: use them only if the project already configures them (`second_level_cache` under `doctrine.orm`, `#[ORM\Cache]` on entities). Don't introduce them as part of a query fix.
