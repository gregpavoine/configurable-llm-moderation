<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Gsoi\Skeleton\Domain\Comment\BannedUserRepository;

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
