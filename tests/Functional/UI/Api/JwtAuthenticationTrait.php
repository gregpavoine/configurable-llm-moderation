<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Functional\UI\Api;

use Lexik\Bundle\JWTAuthenticationBundle\Security\User\JWTUser;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

trait JwtAuthenticationTrait
{
    /** @param list<string> $roles */
    private function bearerHeader(array $roles = ['ROLE_MODERATOR']): array
    {
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $manager);
        $user = JWTUser::createFromPayload('functional-test', ['roles' => $roles]);

        return ['HTTP_AUTHORIZATION' => 'Bearer '.$manager->create($user)];
    }
}
