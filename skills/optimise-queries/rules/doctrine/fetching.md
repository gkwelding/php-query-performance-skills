---
title: Doctrine Fetching and N+1
tags: symfony, doctrine, n+1, proxies, fetch-join, extra-lazy, eager, paginator, hydration
---

## Doctrine Fetching and N+1

Counts below were measured on SQLite with ORM 2.20 / DBAL 3.10 and ORM 3.7 / DBAL 4.5; both gave the same numbers.

### Lazy Associations in a Loop

A lazy `ManyToOne` is a proxy that loads on first access to anything but its identifier. The identity map loads each related entity once, so the N+1 is one query per **distinct** related entity.

**Incorrect:**

```php
$posts = $postRepository->findAll();

foreach ($posts as $post) {
    $post->getAuthor()->getName(); // 10 posts by 5 authors: 1 + 5 queries
}
```

**Correct:**

```php
$posts = $postRepository->createQueryBuilder('p')
    ->join('p.author', 'a')
    ->addSelect('a')
    ->getQuery()
    ->getResult(); // 1 query
```

`$post->getAuthor()->getId()` doesn't load the proxy. Twig (`{{ post.author.name }}`), serializer groups that include the association, normalizers and voters trigger the load just like PHP code.

### A Join Is Not a Fetch Join

Without `addSelect('a')` (or `SELECT p, a` in DQL) the join only filters; the association is still lazy and the loop still runs 1 + 5 queries.

Use `leftJoin` when the association is nullable or the collection may be empty. An inner fetch join drops parents with no related row: 6 authors, one with no posts, came back as 5 with `JOIN` and 6 with `LEFT JOIN`.

### Fetch-Joining Collections

```php
$authors = $em->createQuery('SELECT a, p FROM App\Entity\Author a LEFT JOIN a.posts p')->getResult(); // 1 query
```

- **Filtering the joined alias filters the collection.** `... JOIN a.posts p WHERE p.published = true` hydrates `$author->getPosts()` with only the published posts, and later reads of that author in the same request (even `find()`) get the filtered collection. `JOIN a.posts p WITH p.published = true` filters it the same way. To filter parents by their children and still get full collections, filter on a second, non-selected alias: `SELECT a, p FROM App\Entity\Author a JOIN a.posts p JOIN a.posts f WHERE f.published = true`.
- **`setMaxResults()` limits SQL rows, not entities.** With 2 posts per author, `setMaxResults(2)` returned 1 author; `setMaxResults(3)` returned 2 authors, the second with 1 of its 2 posts. Use `Doctrine\ORM\Tools\Pagination\Paginator` (its default `fetchJoinCollection: true`): 2 queries for the page, plus 1 for `count()`, with full collections.
- `toIterable()` refuses fetch-joined collections (`doctrine/batch-processing.md`).

### setFetchMode() on One Query

When adding a join is awkward (a repository method used in several places), change the fetch mode for one DQL query:

```php
$query = $em->createQuery('SELECT p FROM App\Entity\Post p')
    ->setFetchMode(Post::class, 'author', ClassMetadata::FETCH_EAGER); // 2 queries: posts, then authors WHERE id IN (...)
```

### EXTRA_LAZY Collections

With `fetch: 'EXTRA_LAZY'` on a `OneToMany` / `ManyToMany`, `count()`, `contains()`, `slice()` and `isEmpty()` (plus `containsKey()` / `get()` with `indexBy`) run a targeted query instead of loading the collection. It helps one large collection on one entity. It doesn't fix a list: `count($author->getPosts())` for 5 authors is still 1 + 5 queries, just smaller ones.

For counts across a list, aggregate in one query:

```php
$authors = $em->createQuery('SELECT a FROM App\Entity\Author a')->getResult();

$counts = $em->createQuery(
    'SELECT IDENTITY(p.author) AS authorId, COUNT(p.id) AS postCount
     FROM App\Entity\Post p WHERE p.author IN (:authors) GROUP BY p.author'
)->setParameter('authors', $authors)->getResult(); // 2 queries in total, for any number of authors
```

Or `SELECT a, COUNT(p.id) AS postCount FROM App\Entity\Author a LEFT JOIN a.posts p GROUP BY a.id`, which returns rows of `[0 => Author, 'postCount' => n]`. That changes the result shape for callers; run it on the project's driver, since `GROUP BY` rules differ.

### fetch: EAGER

`fetch: 'EAGER'` loads the association every time the entity is loaded, by every query, whether or not the code reads it.

- `ManyToOne` EAGER: joined into `find()` / `findBy()` / `findAll()` (1 query); with DQL that doesn't join it, one extra batched query.
- `OneToMany` EAGER: one extra batched query (`WHERE shop_id IN (...)`) per hydration, on both ORM 2.20 and 3.7.

So EAGER isn't an N+1, but it's a cost on every page that loads the entity, and removing it from the mapping changes every one of them. To skip it for one DQL query: `setFetchMode(Shop::class, 'products', ClassMetadata::FETCH_LAZY)` (2 queries down to 1 in the measurement). Prefer explicit fetch joins at the call sites that need the association over adding EAGER to the mapping.

### Read-Only Lists: Arrays and DTOs

For lists, exports and reports that only read, skip entity hydration:

```php
$rows = $em->createQuery(
    'SELECT NEW App\Dto\PostRow(p.id, p.title, a.name) FROM App\Entity\Post p JOIN p.author a'
)->getResult(); // 1 query, no proxies, nothing tracked by the unit of work

$rows = $em->createQuery('SELECT p, a FROM App\Entity\Post p JOIN p.author a')->getArrayResult();
```

Only inside the target: changing a repository method from entities to arrays or DTOs breaks its other callers. DQL `PARTIAL` still parses in ORM 2.20 and 3.7, but it returns managed entities with missing fields; use `NEW` or arrays instead.
