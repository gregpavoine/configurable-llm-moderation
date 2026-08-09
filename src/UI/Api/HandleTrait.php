<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\UI\Api;

use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

trait HandleTrait
{
    private function handle(MessageBusInterface $messageBus, object $message): mixed
    {
        $handled = $messageBus->dispatch($message)->last(HandledStamp::class);
        if (!$handled instanceof HandledStamp) {
            throw new LogicException(sprintf('Message "%s" was not handled synchronously.', $message::class));
        }

        return $handled->getResult();
    }
}
