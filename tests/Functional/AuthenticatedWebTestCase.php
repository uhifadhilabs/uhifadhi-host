<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Access\Entity\User;
use App\Access\Enum\TeamRoleEnum;
use App\Access\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Base for functional tests of the authenticated app. Every dashboard page now sits behind
 * the `main` firewall (access_control `^/` = ROLE_USER), so a plain client is bounced to
 * /login. These tests exercise features, not the auth gate itself (that is {@see Access\LoginTest}),
 * so they sign in a Manager — a tier that holds every permission by tier — and get on with it.
 */
abstract class AuthenticatedWebTestCase extends WebTestCase
{
    use Factories;

    /**
     * Persist a user at the given tier and sign the client in as them.
     */
    protected function loginAs(KernelBrowser $client, TeamRoleEnum $tier = TeamRoleEnum::Admin): User
    {
        $user = UserFactory::createOne(['teamRole' => $tier]);
        $client->loginUser($user);

        return $user;
    }
}
