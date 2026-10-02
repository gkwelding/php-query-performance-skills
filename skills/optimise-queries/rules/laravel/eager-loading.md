---
title: Eloquent Eager Loading
tags: laravel, eloquent, n+1, eager-loading, with, withCount, resources, blade, accessors
---

## Eloquent Eager Loading

Eloquent has no identity map: every lazy relation access on a different model is a new query, even for the same related row. Counts below were measured on SQLite with Laravel 10.50 and 13.34.

### Relation Property in a Loop

**Incorrect:**

```php
$posts = Post::latest()->get();

foreach ($posts as $post) {
    echo $post->author->name; // 1 query per post: 10 posts = 11 queries
}
```

**Correct:**

```php
$posts = Post::with('author')->latest()->get(); // 2 queries for any number of posts
```

Eager load at the query that loads the parents, not inside the loop or the view.

### Where the Loop Hides

The access is often far from the query:

- **Blade:** `@foreach ($posts as $post) {{ $post->author->name }}`, components receiving a model, `@can('update', $post)` with a policy that reads `$post->team`.
- **API Resources:** `$this->author->name` or `CommentResource::collection($this->comments)` in `toArray()`.
- **Serialisation:** `$appends` accessors that query, run on every `toArray()` / `toJson()`.
- **Notifications, mailables, exports and jobs** looping over a collection passed in.

Trace each repeated statement back to the line that reads the relation, then load it where the parents are queried.

### API Resources: whenLoaded, whenCounted, whenAggregated

Make the resource read only what the controller loaded, and load it in the controller:

```php
// Controller
return PostResource::collection(Post::with('author')->withCount('comments')->paginate(20));

// PostResource::toArray()
'author' => new AuthorResource($this->whenLoaded('author')),
'comments_count' => $this->whenCounted('comments'),
```

`whenLoaded()` and `whenCounted()` omit the key when the relation or count wasn't loaded. Switching `$this->author` to `whenLoaded('author')` without adding the eager load changes the response: the key disappears. Add both together and compare the output. `whenAggregated()` (later 10.x releases onwards) does the same for `withSum` / `withMax` etc.

### with(), load(), loadMissing()

| Method | Use |
|---|---|
| `Post::with('author')` | On the query, before `get()` / `paginate()` |
| `$posts->load('author')` | On a collection or model already loaded. Always runs the query, even if the relation is loaded |
| `$posts->loadMissing('author')` | Loads only where the relation isn't loaded yet. Use it in code that receives models from several callers |

Nested relations take one query per level: `Author::with('posts.comments')` runs 3 queries for any number of authors.

### Constrained Eager Loads Replace the Relation

```php
Post::with(['comments' => fn ($query) => $query->where('approved', true)])->get();
```

`$post->comments` now holds only approved comments, everywhere it's read afterwards. If other code on the path reads `$post->comments` expecting all of them, that's a behaviour change. Use a dedicated relation (`approvedComments()`) instead of constraining the shared one.

`withWhereHas('comments', fn ($query) => ...)` filters the parents and loads the same constrained relation in one call.

### Selecting Columns Keeps the Keys

The parent query must select the foreign key, and a column list on the eager load must include the related key. Without them the relation is silently `null`, with no error:

**Incorrect:**

```php
Post::select('id', 'title')->with('author')->get();   // no author_id: $post->author is null
Post::with('author:name')->get();                     // no authors.id: $post->author is null
```

**Correct:**

```php
Post::select('id', 'title', 'author_id')->with('author:id,name')->get();
```

Accessors and resources that read a column you left out get `null` (or throw under `preventAccessingMissingAttributes()`). Check the output.

### Counts and Aggregates

**Incorrect:**

```php
foreach ($authors as $author) {
    $author->posts->count();     // loads every post of every author to count them
    $author->posts()->count();   // one COUNT query per author
}
```

**Correct:**

```php
$authors = Author::withCount('posts')->get(); // 1 query; read $author->posts_count
```

`withCount`, `withExists`, `withSum`, `withMin`, `withMax`, `withAvg` add correlated subqueries to the parent select. The attribute is `{relation}_count`, `{relation}_exists`, `{relation}_{function}_{column}` (`posts_sum_amount`). On a loaded collection use `loadCount()`, `loadExists()` or `loadSum()`.

### Accessors and $appends

An appended accessor that queries runs once per model on every serialisation: 10 posts with `protected $appends = ['comment_total']` and `return $this->comments()->count();` is 11 queries for `toArray()`. `preventLazyLoading()` doesn't catch it, because it calls the relation method, not the property.

Fix: compute it in the query (`withCount('comments')`) and have the resource read `comments_count`, or read an eager-loaded relation in the accessor and load it at every call site. Removing the attribute from `$appends` changes the JSON of every endpoint that serialises the model; only do it if every one of them is covered.

### $with on the Model

`protected $with = ['author'];` eager loads on **every** query for that model, including `find()` (2 queries instead of 1) and code that never reads the relation, and it chains through any `$with` on the related models. Removing it is a behaviour change for every call site that relies on it. At a hot call site that doesn't need the relation, use `Post::without('author')` or `Post::withOnly(['tags'])` instead.

### Automatic Eager Loading (Laravel 12.8+)

`Model::automaticallyEagerLoadRelationships()` (global) and `$posts->withRelationshipAutoloading()` (one collection) load a relation for the whole collection the first time one model accesses it: 10 posts reading `->author` take 2 queries instead of 11. Not available before 12.8.

Prefer an explicit `with()` for a targeted fix: it's visible at the query and covered by the count test. Don't turn on the global switch as part of fixing one target; it changes every query in the application. If the project already uses it, account for it when reading the counts.
