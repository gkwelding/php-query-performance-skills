---
title: Eloquent Queries and Batch Processing
tags: laravel, eloquent, chunk, chunkById, lazy, cursor, exists, pagination, whereHas, firstOrCreate
---

## Eloquent Queries and Batch Processing

Checked against Laravel 10.50 and 13.34 source; behaviour of `chunk`, `cursor` and `lazy` measured on SQLite.

### chunk() vs chunkById() When the Callback Writes

`chunk()` pages with `OFFSET`. If the callback changes a column the query filters on, rows shift between pages and some are never visited.

**Incorrect:**

```php
// 10 unpublished posts, chunks of 3: only 6 are published, 4 are skipped
Post::where('published', false)->chunk(3, function ($posts) {
    $posts->each->update(['published' => true]);
});
```

**Correct:**

```php
// Pages with "where id > last id": all 10 are published
Post::where('published', false)->chunkById(3, function ($posts) {
    $posts->each->update(['published' => true]);
});
```

`chunkById()` needs the key in the result. With joins or a non-default key, pass the qualified column and the alias: `chunkById(500, $callback, 'posts.id', 'id')`. It orders by the key itself. Don't combine it with an `orderBy()` on another column: that order stays first, and paging by `id > last id` then revisits some rows and skips others (10 posts ordered by title came back as ids 10, 9, 8, 10, 9). `eachById()` and `lazyById()` are the per-row and lazy forms.

`with()` works with `chunk()` / `chunkById()`: each chunk eager loads its own relations.

### lazy(), lazyById() and cursor()

| Method | Queries | Eager loading | Memory |
|---|---|---|---|
| `lazy(1000)` | One per chunk (`OFFSET`) | Yes, per chunk | One chunk of models at a time |
| `lazyById(1000)` | One per chunk (`id > ?`) | Yes, per chunk | One chunk; safe when the loop writes |
| `cursor()` | One | **No.** `with()` is ignored | One model at a time in PHP |

`cursor()` with a relation read in the loop is an N+1: `Post::with('author')->cursor()` over 10 posts ran 11 queries. Use `lazyById()` when the loop reads relations.

`cursor()` keeps only one model hydrated at a time, but `pdo_mysql` buffers the whole result set by default, so the rows are still in memory. On large MySQL tables prefer `lazyById()`.

### Existence and Counting

**Incorrect:**

```php
if (Post::where('author_id', $id)->count() > 0) { /* ... */ }
if (Post::where('author_id', $id)->get()->isNotEmpty()) { /* ... */ } // loads every row
```

**Correct:**

```php
if (Post::where('author_id', $id)->exists()) { /* ... */ }
```

`$author->posts->isEmpty()` loads the relation; `$author->posts()->exists()` doesn't. In a loop over parents, use `withExists('posts')` instead of either.

### Pagination

`paginate()` runs a count query plus the page query. `simplePaginate()` fetches `perPage + 1` rows and no count. `cursorPaginate()` pages by key instead of `OFFSET`. Switching changes the response (no `total` / `last_page`, different links), so only do it when the user agrees.

### whereHas vs Joins

`whereHas()` compiles to a correlated `where exists (select * from "comments" where "posts"."id" = "comments"."post_id" and ...)`. It returns each parent once and is usually fine with an index on the foreign key and the filtered columns. Rewriting it as a join returns a parent once per matching child unless you add `distinct` or a `groupBy`, and changes the selected columns. Only swap after `EXPLAIN` on the project's driver shows the subquery is the cost (`general/indexes-and-caching.md`).

### firstOrCreate and Races

`firstOrCreate()` selects, then inserts. From Laravel 10.29 the insert goes through `createOrFirst()`, which catches `UniqueConstraintViolationException` and selects the row the other request created. That only protects you if a **unique index** covers the lookup columns; without one, concurrent requests insert duplicates. `updateOrCreate()` is built on `firstOrCreate()`.

`createOrFirst()` (Laravel 10.20+) inserts first and selects only on a violation. It saves a query when creating is the common case, and needs the same unique index.

If the fix needs the unique index, propose the migration; existing duplicates will make it fail, so say so.

### Bulk Writes

`Post::whereIn('id', $ids)->update(['status' => 'archived'])` is one query, but it skips model events, observers, mutators and casts: the values go to the database as given (`updated_at` is still set). Replacing a loop of `$post->save()` with it changes behaviour if any of those exist. Check `booted()`, observers and listeners first.

### Fewer Columns

For large listings and exports, `select()` only the columns used, or `pluck()` / `value()` when you need one column. Keep the primary and foreign keys (`laravel/eager-loading.md`).
