<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Book;
use App\Entity\Review;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 *
 * @method Review|null find($id, $lockMode = null, $lockVersion = null)
 * @method Review|null findOneBy(array $criteria, array $orderBy = null)
 * @method Review[]    findAll()
 * @method Review[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    public function getAverageRating(Book $book): ?int
    {
        $rating = $this->createQueryBuilder('r')
            ->select('AVG(r.rating)')
            ->where('r.book = :book')->setParameter('book', $book)
            ->getQuery()->getSingleScalarResult()
        ;

        return $rating ? (int) $rating : null;
    }

    public function save(Review $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Review $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findMostReviewedDate(bool $isMonth = false): ?array
{
    $conn = $this->getEntityManager()->getConnection();
    $sql = $isMonth
        ? "SELECT DATE_TRUNC('month', r.publication_date) AS date, COUNT(r.id) AS count"
        : "SELECT DATE_TRUNC('day', r.publication_date) AS date, COUNT(r.id) AS count";

    $sql .= " FROM review r
              GROUP BY date
              ORDER BY count DESC, date DESC
              LIMIT 1";

    $stmt = $conn->prepare($sql);
    $result = $stmt->executeQuery();

    return $result->fetchAssociative() ?: null;
}


}
