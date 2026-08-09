<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Gsoi\Skeleton\Domain\Comment\Comment;
use Gsoi\Skeleton\Domain\Comment\CommentId;
use Gsoi\Skeleton\Domain\Comment\CommentRepository;
use Gsoi\Skeleton\Domain\Comment\Exception\CommentNotFoundException;
use Gsoi\Skeleton\Domain\Comment\ModerationStatus;

final readonly class DoctrineCommentRepository implements CommentRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Comment $comment): void
    {
        $this->entityManager->persist($comment);
        $this->entityManager->flush();
    }

    public function get(CommentId $id): Comment
    {
        $comment = $this->entityManager->find(Comment::class, $id->toString());

        if (!$comment instanceof Comment) {
            throw CommentNotFoundException::withId($id);
        }

        return $comment;
    }

    public function search(?string $publisher, ?ModerationStatus $status, int $limit, int $offset): array
    {
        /** @var list<Comment> $comments */
        $comments = $this->filteredQuery($publisher, $status)
            ->select('comment')
            ->orderBy('comment.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $comments;
    }

    public function count(?string $publisher, ?ModerationStatus $status): int
    {
        /** @var int|string $count */
        $count = $this->filteredQuery($publisher, $status)
            ->select('COUNT(comment.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    private function filteredQuery(?string $publisher, ?ModerationStatus $status): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()->from(Comment::class, 'comment');

        if (null !== $publisher) {
            $query->andWhere('comment.publisher = :publisher')->setParameter('publisher', $publisher);
        }

        if (null !== $status) {
            $query->andWhere('comment.status = :status')->setParameter('status', $status->value);
        }

        return $query;
    }
}
