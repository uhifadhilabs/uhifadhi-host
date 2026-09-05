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

namespace Uhifadhi\Telemetry\Capture;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Uhifadhi\Entity\User;

/**
 * The one host-coupled line of the capture: how THIS application names a user.
 * A {@see User} yields its numeric id and email; any other authenticated
 * principal yields just its identifier; nobody signed in yields nulls.
 */
final readonly class SecurityUserResolver implements CapturedUserResolver
{
    public function __construct(private Security $security)
    {
    }

    public function resolve(): array
    {
        $user = $this->security->getUser();

        if ($user instanceof User) {
            return [$user->getId(), $user->getEmail()];
        }
        if ($user instanceof UserInterface) {
            return [null, $user->getUserIdentifier()];
        }

        return [null, null];
    }
}
