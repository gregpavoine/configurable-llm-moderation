<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Domain\Comment;

enum ModerationStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Rejected = 'rejected';
}
