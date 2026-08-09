<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\Infrastructure\Persistence\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'banned_users')]
final class BannedUser
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 100)]
    private string $userId;

    #[ORM\Column(name: 'banned_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $bannedAt;

    public function __construct(string $userId, ?DateTimeImmutable $bannedAt = null)
    {
        $this->userId = $userId;
        $this->bannedAt = $bannedAt ?? new DateTimeImmutable();
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function bannedAt(): DateTimeImmutable
    {
        return $this->bannedAt;
    }
}
