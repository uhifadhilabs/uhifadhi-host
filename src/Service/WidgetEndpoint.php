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

namespace Uhifadhi\Service;

use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\User;
use Uhifadhi\Exception\InvalidWidgetPreferenceException;
use Uhifadhi\Model\WidgetCatalog;
use Uhifadhi\Model\WidgetDom;

/**
 * The two write endpoints every widget library needs, once: save a layout, throw
 * one away. A controller that owns a dashboard surface hands its catalogue in
 * and returns what comes back — it writes no CSRF check, no user lookup and no
 * status code of its own.
 *
 * A SERVICE rather than a trait or an abstract controller on purpose: module
 * bundles register plain controller classes with no base class (a reusable
 * bundle must not inherit the host's AbstractController), and a trait would have
 * to reach for properties the including class happens to have. Injected, this
 * works identically in a host controller and in a bundle's.
 *
 * No permission beyond "signed in" is asked: arranging your own dashboard is not
 * a privilege, and the data on show is the dashboard's, which the caller has
 * already authorised the person to read.
 */
final readonly class WidgetEndpoint
{
    public function __construct(
        private WidgetService $widgets,
        private TokenStorageInterface $tokenStorage,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    /**
     * The token the library page renders into {@see WidgetDom::CSRF_TOKEN}.
     * Handed to the template by the controller rather than fetched there with
     * Twig's csrf_token(): that function only exists where the twig-bridge CSRF
     * extension is wired, and a module's screen must not depend on it.
     */
    public function csrfToken(WidgetCatalog $catalog, ?Uuid $areaUuid = null): string
    {
        return $this->csrfTokenManager->getToken(self::csrfTokenId($catalog->surface, $areaUuid))->getValue();
    }

    /**
     * The whole layout in one JSON body — the library posts its complete state
     * after every toggle, width change and drop, so a partial write can never
     * leave a half-applied layout behind.
     *
     * 204 on success, 422 for a body that is not JSON or a layout the catalogue
     * does not recognise (unprocessable rather than malformed, as the recording
     * screens answer a rejected form), 403 for a bad token.
     */
    public function save(Request $request, WidgetCatalog $catalog, ?Uuid $areaUuid = null): Response
    {
        $userId = $this->userId();
        $this->denyUnlessCsrfValid($request, $catalog, $areaUuid);

        try {
            /** @var array<string, mixed> $payload */
            $payload = $request->toArray();
            $this->widgets->save($catalog, $userId, $payload, $areaUuid);
        } catch (InvalidWidgetPreferenceException|JsonException $invalid) {
            return new Response($invalid->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /** Back to the catalogue's defaults; the row is deleted. 204, or 403 for a bad token. */
    public function reset(Request $request, WidgetCatalog $catalog, ?Uuid $areaUuid = null): Response
    {
        $userId = $this->userId();
        $this->denyUnlessCsrfValid($request, $catalog, $areaUuid);
        $this->widgets->reset($catalog, $userId, $areaUuid);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /** A layout belongs to a person; without one there is nothing to read or write. */
    public function userId(): int
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        $id = $user instanceof User ? $user->getId() : null;
        if (null === $id) {
            throw new AccessDeniedException('Widget preferences belong to a signed-in user.');
        }

        return $id;
    }

    /**
     * Scoped per surface AND per area: a token minted for one dashboard's library
     * cannot rearrange another's, and on an area-scoped surface a token minted
     * for one area cannot rearrange another area's.
     */
    public static function csrfTokenId(string $surface, ?Uuid $areaUuid = null): string
    {
        return 'widgets_'.$surface.(null !== $areaUuid ? '_'.$areaUuid->toRfc4122() : '');
    }

    /**
     * Both writes are state-changing, so both carry the token. Read from the
     * header the library sends, falling back to the conventional "_token"
     * parameter so a plain form post can reach these endpoints too.
     */
    private function denyUnlessCsrfValid(Request $request, WidgetCatalog $catalog, ?Uuid $areaUuid): void
    {
        $submitted = $request->headers->get(WidgetDom::CSRF_HEADER)
            ?? $request->request->getString('_token');

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::csrfTokenId($catalog->surface, $areaUuid), $submitted))) {
            throw new AccessDeniedException('Invalid CSRF token for the widget library.');
        }
    }
}
