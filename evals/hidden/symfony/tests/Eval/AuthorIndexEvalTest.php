<?php

namespace App\Tests\Eval;

use App\Entity\Author;
use App\Entity\Book;
use App\Entity\Publisher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

// Copied into the scratch app by evals/run.sh only after Claude has finished, so neither variant sees it.
final class AuthorIndexEvalTest extends WebTestCase
{
    private const NAMES = ['Zara', 'Adam', 'Mia', 'Bo', 'Ivy', 'Eli', 'Noor', 'Cal', 'Uma', 'Gus'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->em();
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        (new SchemaTool($em))->dropSchema($metadata);
        (new SchemaTool($em))->createSchema($metadata);
    }

    public function testPageIsUnchanged(): void
    {
        $this->seedAuthors(5);

        $this->client->request('GET', '/authors');

        $this->assertResponseIsSuccessful();
        $this->assertSnapshot('authors.html', $this->client->getResponse()->getContent());
    }

    public function testPageReflectsWrites(): void
    {
        $this->seedAuthors(5);
        $this->client->request('GET', '/authors');

        $em = $this->em();
        $authors = $em->getRepository(Author::class);
        $authors->findOneBy(['name' => 'Adam'])->setName('Adam Renamed');
        $em->persist(new Book($authors->findOneBy(['name' => 'Mia']), 'Book 0 by Mia'));
        $em->flush();
        $this->client->request('GET', '/authors');

        $this->assertResponseIsSuccessful();
        $this->assertSnapshot('authors-after-write.html', $this->client->getResponse()->getContent());
    }

    public static function sizes(): array
    {
        return ['small' => [3], 'large' => [10]];
    }

    // Counted with the profiler's db collector, one request. Read by evals/score.php.
    #[DataProvider('sizes')]
    public function testQueryProbe(int $authors): void
    {
        $this->seedAuthors($authors);
        // The collector reads this holder, which has every query since the container booted
        // (schema setup and seeding included) until it's reset.
        static::getContainer()->get('doctrine.debug_data_holder')->reset();

        $this->client->enableProfiler();
        $this->client->request('GET', '/authors');

        $this->assertResponseIsSuccessful();
        $queries = $this->client->getProfile()->getCollector('db')->getQueryCount();
        file_put_contents('eval-probe.txt', "{$authors} {$queries}\n", FILE_APPEND);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    // Two publishers, every third author without books, books persisted in reverse title order.
    private function seedAuthors(int $authors): void
    {
        $em = $this->em();
        $publishers = [new Publisher('Penguin'), new Publisher('Faber')];
        foreach ($publishers as $publisher) {
            $em->persist($publisher);
        }
        foreach (array_slice(self::NAMES, 0, $authors) as $i => $name) {
            $author = new Author($name, $publishers[$i % 2]);
            $em->persist($author);
            for ($b = $i % 3; $b >= 1; $b--) {
                $em->persist(new Book($author, 'Book '.chr(64 + $b)." by {$name}"));
            }
        }
        $em->flush();
        // Otherwise the request is served from the identity map and the N+1 doesn't show.
        $em->clear();
    }

    // Exact bytes. EVAL_WRITE_SNAPSHOTS=1 rewrites the snapshot (only for maintaining the eval).
    private function assertSnapshot(string $name, string $actual): void
    {
        $path = __DIR__."/snapshots/{$name}";
        if (getenv('EVAL_WRITE_SNAPSHOTS')) {
            file_put_contents($path, $actual);
        }
        $this->assertSame(file_get_contents($path), $actual, "Output differs from snapshot {$name}");
    }
}
