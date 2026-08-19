<?php

declare(strict_types=1);

namespace Uhifadhi\Tests\Functional\Access;

use Uhifadhi\Access\Entity\User;
use Uhifadhi\Access\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The form_login firewall end to end: the survey-plate login page renders, good
 * credentials land on the dashboard, bad ones bounce back with an error, and repeated
 * failures are throttled. Each test uses a distinct email so the per-username login
 * throttler (whose cache DAMA does not roll back) never leaks between tests.
 */
final class LoginTest extends WebTestCase
{
    use Factories;

    public function testTheLoginPageRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="_username"]');
        self::assertSelectorExists('input[name="_password"]');
        self::assertSelectorExists('input[name="_csrf_token"]');
    }

    public function testAnAuthenticatedUserVisitingLoginIsSentToTheDashboard(): void
    {
        $client = static::createClient();
        $user = $this->userWithPassword($client, 'in@authority.go.tz', 'correct horse');
        $client->loginUser($user);

        $client->request('GET', '/login');

        self::assertResponseRedirects('/');
    }

    public function testGoodCredentialsSignInAndLandOnTheDashboard(): void
    {
        $client = static::createClient();
        $this->userWithPassword($client, 'good@authority.go.tz', 'correct horse');

        $client->request('GET', '/login');
        $client->submitForm('Sign in', [
            '_username' => 'good@authority.go.tz',
            '_password' => 'correct horse',
        ]);

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testBadCredentialsBounceBackWithAnError(): void
    {
        $client = static::createClient();
        $this->userWithPassword($client, 'bad@authority.go.tz', 'correct horse');

        $client->request('GET', '/login');
        $client->submitForm('Sign in', [
            '_username' => 'bad@authority.go.tz',
            '_password' => 'wrong password',
        ]);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorExists('.auth-error');
    }

    public function testRepeatedBadCredentialsAreThrottled(): void
    {
        $client = static::createClient();
        $this->userWithPassword($client, 'throttle@authority.go.tz', 'correct horse');

        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $client->request('GET', '/login');
            $client->submitForm('Sign in', [
                '_username' => 'throttle@authority.go.tz',
                '_password' => 'wrong password',
            ]);
            $client->followRedirect();
        }

        self::assertSelectorTextContains('.auth-error', 'Too many');
    }

    /**
     * Create a persisted, verified user whose password is a real hash of $plain — the
     * factory default is a placeholder, so anything that signs in re-hashes here.
     */
    private function userWithPassword(KernelBrowser $client, string $email, string $plain): User
    {
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        $user = UserFactory::createOne(['email' => $email]);
        $user->setPassword($hasher->hashPassword($user, $plain));

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->flush();

        return $user;
    }
}
