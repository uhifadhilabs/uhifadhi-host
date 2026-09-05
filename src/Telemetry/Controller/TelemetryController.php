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

namespace Uhifadhi\Telemetry\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Uhifadhi\Telemetry\Model\CaptureFilter;
use Uhifadhi\Telemetry\Store\CaptureStore;

/**
 * The diagnostic surface — a Super-Admin-only window onto what the API actually
 * received and returned. It is the whole reason to run the monitor: when a
 * ranger's sync fails with "invalid payload" and the client cannot be
 * intercepted, this is where you read the raw (redacted) payload the server saw.
 *
 * Deliberately spare. It is a dev tool, not a product: a filterable list ordered
 * failures-first, and one capture opened in full. Nothing here mutates anything.
 *
 * Super-Admin-only, twice over: the firewall already requires ROLE_USER for `^/`,
 * and this restates the real bar (ROLE_SUPER_ADMIN — the tier the host's
 * role_hierarchy tops out at) at the controller, so the gate travels with the code.
 */
#[Route('/telemetry')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class TelemetryController extends AbstractController
{
    private const int PAGE_SIZE = 50;

    public function __construct(private readonly CaptureStore $store)
    {
    }

    #[Route('', name: 'telemetry_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $status = $request->query->getInt('status', 0);

        $filter = new CaptureFilter(
            userEmail: self::trimmed($request->query->get('user')),
            status: $status > 0 ? $status : null,
            failuresOnly: $request->query->getBoolean('failures'),
            endpoint: self::trimmed($request->query->get('endpoint')),
            since: self::date($request->query->get('since')),
            until: self::date($request->query->get('until')),
            limit: self::PAGE_SIZE,
            offset: ($page - 1) * self::PAGE_SIZE,
        );

        $result = $this->store->search($filter);

        return $this->render('telemetry/index.html.twig', [
            'result' => $result,
            'page' => $page,
            'filters' => [
                'user' => self::trimmed($request->query->get('user')),
                'status' => $status > 0 ? $status : null,
                'failures' => $request->query->getBoolean('failures'),
                'endpoint' => self::trimmed($request->query->get('endpoint')),
                'since' => self::trimmed($request->query->get('since')),
                'until' => self::trimmed($request->query->get('until')),
            ],
        ]);
    }

    #[Route('/{id}', name: 'telemetry_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        $capture = $this->store->find($id);
        if (null === $capture) {
            throw $this->createNotFoundException('No such capture.');
        }

        return $this->render('telemetry/show.html.twig', ['capture' => $capture]);
    }

    private static function trimmed(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        $trimmed = self::trimmed($value);
        if (null === $trimmed) {
            return null;
        }

        try {
            return new \DateTimeImmutable($trimmed);
        } catch (\Exception) {
            return null;
        }
    }
}
