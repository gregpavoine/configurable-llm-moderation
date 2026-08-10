<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Gsoi\CommentModeration\Domain\Comment\BannedUserRepository;

final readonly class DoctrineBannedUserRepository implements BannedUserRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function isBanned(string $userId): bool
    {
        return null !== $this->entityManager->find(BannedUser::class, $userId);
    }
}
