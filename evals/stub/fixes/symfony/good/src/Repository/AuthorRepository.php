<?php

namespace App\Repository;

use App\Entity\Author;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Author> */
class AuthorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Author::class);
    }

    /** @return list<Author> */
    public function findAllWithPublisherAndBooks(): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.publisher', 'p')->addSelect('p')
            ->leftJoin('a.books', 'b')->addSelect('b')
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
