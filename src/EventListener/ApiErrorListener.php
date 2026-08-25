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

namespace Uhifadhi\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Uhifadhi\Api\ApiProblemException;

/**
 * Gives EVERY /api failure the one shape API-CONTRACT.md §10 describes:
 * `{code, message, retryable, details}`. The field app's entire failure policy
 * reads those fields, so a stray Hydra document or an HTML error page from some
 * layer we did not write is a broken contract, not a cosmetic problem.
 *
 * Two passes, deliberately:
 *
 * 1. {@see onException} catches OUR {@see ApiProblemException} — the only errors
 *    that know their own contract code, retryability and details — and answers
 *    immediately.
 * 2. {@see onResponse} is the safety net for everything else: 401s from the
 *    firewall, 404s from routing, 422s from validation, 500s from anywhere.
 *    It runs on the RESPONSE rather than the exception on purpose — by then
 *    Symfony and api-platform have already settled the status code, and the
 *    status is the one thing this pass must not second-guess. It only replaces
 *    the body.
 */
final class ApiErrorListener
{
    /** Marks a body this listener already wrote, so pass 2 leaves it alone. */
    private const string HANDLED_HEADER = 'X-Uhifadhi-Api-Error';

    /**
     * Status → contract code for failures raised outside our own code. The app
     * treats `retryable` as authoritative, so it is derived from the status the
     * same way §10's table does: 429 and 5xx are worth retrying, nothing else is.
     */
    private const array STATUS_CODES = [
        Response::HTTP_BAD_REQUEST => 'invalid_request',
        Response::HTTP_UNAUTHORIZED => 'unauthorized',
        Response::HTTP_FORBIDDEN => 'forbidden',
        Response::HTTP_NOT_FOUND => 'not_found',
        Response::HTTP_METHOD_NOT_ALLOWED => 'method_not_allowed',
        Response::HTTP_NOT_ACCEPTABLE => 'not_acceptable',
        Response::HTTP_CONFLICT => 'conflict',
        Response::HTTP_UNSUPPORTED_MEDIA_TYPE => 'unsupported_media_type',
        Response::HTTP_UNPROCESSABLE_ENTITY => 'invalid_payload',
        Response::HTTP_TOO_MANY_REQUESTS => 'rate_limited',
    ];

    /**
     * Priority 512: ahead of the firewall's exception listener (1), Symfony's
     * ErrorListener (-128) and api-platform's error pipeline, so an
     * ApiProblemException is answered verbatim instead of being reshaped into
     * somebody else's error document.
     */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 512)]
    public function onException(ExceptionEvent $event): void
    {
        if (!self::isApiRequest($event->getRequest())) {
            return;
        }

        $problem = self::findApiProblem($event->getThrowable());
        if (!$problem instanceof ApiProblemException) {
            return;
        }

        $event->setResponse(self::render(
            $problem->getStatusCode(),
            $problem->getProblemCode(),
            $problem->getMessage(),
            $problem->isRetryable(),
            $problem->getDetails(),
        ));

        // Nothing downstream may re-render this: the codes are the contract.
        $event->stopPropagation();
    }

    /**
     * Priority -1024: last, once every other listener has produced whatever
     * error response it wanted, so this rewrites a settled result.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -1024)]
    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();

        if (!self::isApiRequest($event->getRequest())
            || $response->getStatusCode() < 400
            || $response->headers->has(self::HANDLED_HEADER)
        ) {
            return;
        }

        $status = $response->getStatusCode();
        $code = self::STATUS_CODES[$status] ?? ($status >= 500 ? 'server_error' : 'request_failed');

        $event->setResponse(self::render(
            $status,
            $code,
            self::messageFor($response, $status),
            // §10: only 429 and 5xx are worth trying again.
            Response::HTTP_TOO_MANY_REQUESTS === $status || $status >= 500,
        ));
    }

    /**
     * Only the machine API. The docs UI, the profiler and every Twig page keep
     * their own error rendering.
     */
    private static function isApiRequest(Request $request): bool
    {
        $path = $request->getPathInfo();

        return ('/api' === $path || str_starts_with($path, '/api/'))
            && !str_starts_with($path, '/api/docs');
    }

    /**
     * An ApiProblemException thrown inside a service arrives wrapped — by
     * api-platform's own handling, or by Symfony when a listener rethrows — so
     * the cause chain is walked rather than only the outermost throwable.
     */
    private static function findApiProblem(\Throwable $throwable): ?ApiProblemException
    {
        for ($e = $throwable; null !== $e; $e = $e->getPrevious()) {
            if ($e instanceof ApiProblemException) {
                return $e;
            }
        }

        return null;
    }

    /**
     * A human-readable line for the ranger's Sync screen. Symfony's own error
     * responses carry either a JSON document or an HTML page; the HTML is never
     * forwarded — it would put a stack trace in a field app.
     */
    private static function messageFor(Response $response, int $status): string
    {
        $content = $response->getContent();
        if (\is_string($content) && str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            $decoded = json_decode($content, true);
            if (\is_array($decoded)) {
                foreach (['detail', 'description', 'message', 'hydra:description', 'title'] as $key) {
                    if (isset($decoded[$key]) && \is_string($decoded[$key]) && '' !== $decoded[$key]) {
                        return $decoded[$key];
                    }
                }
            }
        }

        return Response::$statusTexts[$status] ?? 'Request failed';
    }

    /** @param array<string, mixed> $details */
    private static function render(int $status, string $code, string $message, bool $retryable, array $details = []): JsonResponse
    {
        $response = new JsonResponse([
            'code' => $code,
            'message' => $message,
            'retryable' => $retryable,
            'details' => (object) $details,
        ], $status);

        $response->headers->set(self::HANDLED_HEADER, '1');

        return $response;
    }
}
