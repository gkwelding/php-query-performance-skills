---
title: Measuring Queries with Doctrine
tags: symfony, doctrine, dbal, measurement, query-count, profiler, middleware
---

## Measuring Queries with Doctrine

Checked against two stacks: Symfony 6.4 / DoctrineBundle 2.19 / ORM 2.20 / DBAL 3.10, and Symfony 8.1 / DoctrineBundle 3.3 / ORM 3.7 / DBAL 4.5. Everything below works on both unless noted.

### DebugStack Is Gone

`Doctrine\DBAL\Logging\DebugStack`, the `SQLLogger` interface and `Configuration::setSQLLogger()` are deprecated in DBAL 3 and removed in DBAL 4. Don't write new code with them. Use one of the options below; all of them are built on DBAL middlewares.

### Web Test: the Profiler's db Collector

The profiler must be enabled in the test environment. The `symfony/web-profiler-bundle` recipe does this with `when@test: framework: profiler: { collect: false }`; `collect: false` means nothing is collected until the test asks for it. `doctrine.dbal.profiling` defaults to `%kernel.debug%`, which is `true` in tests.

```php
<?php

namespace App\Tests\Controller;

use App\Entity\Author;
use App\Entity\Post;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PostListQueryCountTest extends WebTestCase
{
    #[DataProvider('authorCounts')]
    public function testListRunsSameQueriesForAnyNumberOfAuthors(int $authors): void
    {
        $client = static::createClient();
        $this->createAuthorsWithTwoPostsEach($authors);

        $client->enableProfiler();
        $client->request('GET', '/posts');

        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $client->getProfile()->getCollector('db')->getQueryCount());
    }

    public static function authorCounts(): array
    {
        return [
            '3 authors' => [3],
            '10 authors' => [10],
        ];
    }

    private function createAuthorsWithTwoPostsEach(int $authors): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        foreach (range(1, $authors) as $i) {
            $author = new Author("Author {$i}");
            $em->persist($author);
            $em->persist(new Post($author, "First post by {$i}"));
            $em->persist(new Post($author, "Second post by {$i}"));
        }

        $em->flush();
        $em->clear();
    }
}
```

- `enableProfiler()` applies to the next request only. Without it, `getProfile()` returns `null` when `collect` is `false`, and `false` if there is no profiler service at all.
- `getQueryCount()` sums all connections. `getQueries()` returns them per connection (`sql`, `params`, `types`, `executionMS`) for grouping.
- **Clear the entity manager after seeding.** The first request in a test uses the same container, so entities left in the identity map are served from memory and the N+1 doesn't show.
- Use the project's fixtures, Foundry factories or helpers to seed if it has them; the helper above stands in for those.

### Kernel Test: doctrine.debug_data_holder

For handlers, commands, services and repositories, read the same data the profiler uses. The service is private but available through `static::getContainer()` in tests:

```php
$holder = static::getContainer()->get('doctrine.debug_data_holder');
$holder->reset();

$handler(new ExportPosts());

$this->assertCount(3, $holder->getData()['default']);
```

`getData()` is keyed by connection name, each entry with `sql`, `params`, `types` and `executionMS`. It's only fed when `doctrine.dbal.profiling` is on; a count of zero for code that clearly queries means profiling is off in that environment. Arrange data first, then `reset()`, then act.

### Finding the Line

With `profiling_collect_backtrace: true` under the connection config in `doctrine.yaml` (`when@dev` or `when@test`), the profiler records a backtrace per query. Use it to find which template line, normalizer or getter triggered the repeated statement. Only for local diagnosis: it adds overhead to every query.

### Scripts and Commands Outside Tests

`doctrine.dbal.logging` (defaults to `%kernel.debug%`) adds DBAL's logging middleware, which logs every statement to the `doctrine` Monolog channel at debug level as `Executing query: {sql}` / `Executing statement: {sql} (parameters: ...)`. Run the command with `-vvv` or send the channel to a file, then group the lines by SQL.

For plain DBAL/ORM code without the bundle, add the middleware yourself:

```php
$config = (new \Doctrine\DBAL\Configuration())
    ->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($logger)]); // any PSR-3 logger
```

`Symfony\Bridge\Doctrine\Middleware\Debug\Middleware` with a `DebugDataHolder` gives the same structured data as the profiler, if `symfony/doctrine-bridge` is installed.
