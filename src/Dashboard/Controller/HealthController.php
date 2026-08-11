<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness endpoint for kamal-proxy. Returns 200 without touching the database, so
 * a container is "healthy" before post-deploy migrations create the schema — the
 * app root queries tables that don't exist yet on the very first deploy.
 */
final class HealthController extends AbstractController
{
    #[Route('/up', name: 'health_up', methods: ['GET'])]
    public function up(): Response
    {
        return new Response('OK', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }
}
