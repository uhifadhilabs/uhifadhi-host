<?php

declare(strict_types=1);

/*
 * This file is part of uhifadhi.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Uhifadhi\Entity\User;
use Uhifadhi\Enum\TeamRoleEnum;
use Uhifadhi\Factory\UserFactory;

/**
 * REGENERATING A MEMBER'S PASSWORD — the other half of a deployment that sends no mail.
 *
 * Creation shows the generated password ONCE. That is correct, and it is also the whole failure
 * mode: an admin who closed the page, or who was handed an account somebody else created, has no
 * way back to it. There is no "forgot password" mail flow to fall back on, so without this the
 * only recovery is a console the client does not have.
 *
 * It is the same act as creation and carries the same rules: Super Admin only (it hands somebody
 * a way in), the app generates the password from the same alphabet, nothing is emailed, and it is
 * shown exactly once on the answer page.
 */
final class TeamMemberPasswordTest extends AuthenticatedWebTestCase
{
    public function testTheRosterOffersRegenerateToASuperAdminOnly(): void
    {
        $client = static::createClient();
        $member = UserFactory::createOne(['teamRole' => TeamRoleEnum::Staff, 'email' => 'askari@authority.go.tz']);

        $this->loginAs($client, TeamRoleEnum::SuperAdmin);
        $client->request('GET', '/team');
        self::assertSelectorExists('form[action="/team/members/'.$member->getUuidString().'/password"]');

        // A Manager administers positions; handing out a password is not that.
        $this->loginAs($client, TeamRoleEnum::Manager);
        $client->request('GET', '/team');
        self::assertSelectorNotExists('form[action="/team/members/'.$member->getUuidString().'/password"]');
    }

    /**
     * @return list<array{TeamRoleEnum}>
     */
    public static function lesserTiers(): array
    {
        return [[TeamRoleEnum::Admin], [TeamRoleEnum::Manager]];
    }

    #[DataProvider('lesserTiers')]
    public function testTiersBelowSuperAdminAreRefusedTheWrite(TeamRoleEnum $tier): void
    {
        $client = static::createClient();
        $member = UserFactory::createOne(['teamRole' => TeamRoleEnum::Staff]);
        $before = $member->getPassword();
        $this->loginAs($client, $tier);

        $client->request('POST', '/team/members/'.$member->getUuidString().'/password');

        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->reload($member)->getPassword(), 'the stored hash is untouched');
    }

    public function testASuperAdminRegeneratesAPasswordAndTheOldOneStopsWorking(): void
    {
        $client = static::createClient();
        $hasher = $this->hasher();
        $old = 'the-password-nobody-wrote-down';
        $member = UserFactory::createOne(['teamRole' => TeamRoleEnum::Staff, 'email' => 'askari@authority.go.tz']);
        $member->setPassword($hasher->hashPassword($member, $old));
        $this->em()->flush();

        $this->loginAs($client, TeamRoleEnum::SuperAdmin);
        $crawler = $client->request('GET', '/team');
        $token = $crawler->filter('form[action="/team/members/'.$member->getUuidString().'/password"] input[name="_token"]')->attr('value');

        $crawler = $client->request('POST', '/team/members/'.$member->getUuidString().'/password', ['_token' => $token]);

        // Answered by RENDERING, for the same reason creation is: a redirect would throw the one
        // copy of the password away.
        self::assertResponseIsSuccessful();
        $password = trim($crawler->filter('[data-generated-password]')->text());
        self::assertMatchesRegularExpression('/^\S{16,}$/', $password);
        self::assertNotSame($old, $password);

        $fresh = $this->reload($member);
        self::assertTrue($hasher->isPasswordValid($fresh, $password), 'the shown password is the real one');
        self::assertFalse($hasher->isPasswordValid($fresh, $old), 'the old password stops working');

        // Shown once: the roster does not carry it afterwards.
        $crawler = $client->request('GET', '/team');
        self::assertCount(0, $crawler->filter('[data-generated-password]'));
    }

    public function testTheWriteIsRefusedWithoutItsCsrfToken(): void
    {
        $client = static::createClient();
        $member = UserFactory::createOne(['teamRole' => TeamRoleEnum::Staff]);
        $before = $member->getPassword();
        $this->loginAs($client, TeamRoleEnum::SuperAdmin);

        $client->request('POST', '/team/members/'.$member->getUuidString().'/password', ['_token' => 'not-the-token']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->reload($member)->getPassword());
    }

    private function hasher(): UserPasswordHasherInterface
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        return $hasher;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function reload(User $user): User
    {
        $em = $this->em();
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($user->getId());
        \assert($reloaded instanceof User);

        return $reloaded;
    }
}
