---
title: Doctrine Batch Processing
tags: symfony, doctrine, batch, toIterable, clear, memory, commands, messenger
---

## Doctrine Batch Processing

Commands, Messenger handlers, imports and exports that touch many entities run out of memory or slow down because every hydrated entity stays in the unit of work. Checked against ORM 2.20 and 3.7.

### Iterate, Don't Load Everything

**Incorrect:**

```php
foreach ($em->getRepository(Post::class)->findAll() as $post) { // every post hydrated and tracked at once
    $exporter->write($post);
}
```

**Correct:**

```php
$query = $em->createQuery('SELECT p FROM App\Entity\Post p ORDER BY p.id');

$processed = 0;
foreach ($query->toIterable() as $post) {
    $exporter->write($post);

    if (++$processed % 500 === 0) {
        $em->clear(); // detach what has been processed so far
    }
}
$em->clear();
```

- Count batches yourself, as above: don't rely on the iterator's keys.
- `toIterable()` hydrates one row at a time, but each entity is still managed until you `clear()`. Without the `clear()` memory grows as with `getResult()`.
- `toIterable()` throws `QueryException` ("Iterate with fetch join ... not allowed") for a fetch-joined **collection**. Fetch-joined to-one associations are allowed.
- `Query::iterate()` is gone in ORM 3; use `toIterable()`.
- For pure exports, `getArrayResult()` or `toIterable([], AbstractQuery::HYDRATE_ARRAY)` skip the unit of work entirely.
- Lazy associations read in the loop are an N+1 per batch: fetch join the to-one associations (`doctrine/fetching.md`).

Measure with `memory_get_peak_usage(true)` and the query count on a few thousand rows, before and after.

### clear() Detaches Everything You Hold

After `$em->clear()`, every entity in a variable is detached, including ones loaded before the loop. Persisting a new entity that references one throws `ORMInvalidArgumentException` ("A new entity was found through the relationship ... that was not configured to cascade persist").

**Incorrect:**

```php
$author = $em->find(Author::class, $authorId);

foreach ($rows as $i => $row) {
    $em->persist(new Post($author, $row['title']));

    if ($i % 500 === 499) {
        $em->flush();
        $em->clear(); // $author is now detached; the next flush throws
    }
}
```

**Correct:**

```php
foreach ($rows as $i => $row) {
    $em->persist(new Post($em->getReference(Author::class, $authorId), $row['title']));

    if ($i % 500 === 499) {
        $em->flush();
        $em->clear();
    }
}
$em->flush();
$em->clear();
```

`getReference()` returns a managed proxy without a `SELECT`. Re-fetch with `find()` instead when the loop reads the entity's fields.

- ORM 3's `clear()` takes no arguments. ORM 2's `clear(Post::class)` is deprecated.
- `$em->detach($entity)` (both versions) detaches one entity, for when only one type grows.
- Flush once per batch, not per row. A `flush()` inside the loop is one transaction per row.

### Bulk Changes with DQL

```php
$em->createQuery('UPDATE App\Entity\Post p SET p.archived = true WHERE p.publishedAt < :cutoff')
    ->setParameter('cutoff', $cutoff)
    ->execute(); // 1 statement
```

One statement instead of load, change and flush per row, but lifecycle callbacks, entity listeners and Doctrine event subscribers don't run, and entities already in the unit of work keep their old values. Replacing a loop of setters with it changes behaviour if any of those exist: check the entity's `#[ORM\HasLifecycleCallbacks]`, entity listeners and subscribers first, and `clear()` afterwards.

### Messenger Workers

A long-running worker keeps the same entity manager between messages. When `symfony/messenger` is installed, DoctrineBundle registers `DoctrineClearEntityManagerWorkerSubscriber`, which clears the entity managers after each handled or failed message. A handler that processes thousands of entities in one message still needs the batch pattern above.
