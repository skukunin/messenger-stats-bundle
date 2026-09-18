<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

final class TokenRequestListener
{
    public const ROUTE_PREFIX = 'messenger_stats_';
    public const PRIORITY = 8;

    /**
     * @param list<string> $allowedIps
     */
    public function __construct(
        private readonly ?string $token,
        private readonly array $allowedIps,
        private readonly NoStoreResponseFactory $responses,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->isStatsRoute($request)) {
            return;
        }

        $rejection = $this->rejectionOf($request);
        if (null !== $rejection) {
            $event->setResponse($rejection);
        }
    }

    private function isStatsRoute(Request $request): bool
    {
        $route = $request->attributes->get('_route');

        return \is_string($route) && str_starts_with($route, self::ROUTE_PREFIX);
    }

    private function rejectionOf(Request $request): ?Response
    {
        if (null === $this->token || '' === $this->token) {
            return $this->responses->withoutBody(Response::HTTP_NOT_FOUND);
        }

        if (!$this->hasValidToken($request)) {
            return $this->rejection($request, 'unauthorized', Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer']);
        }

        if (!$this->hasAllowedIp($request)) {
            return $this->rejection($request, 'forbidden', Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function hasValidToken(Request $request): bool
    {
        $authorization = (string) $request->headers->get('Authorization', '');
        if (1 !== preg_match('/^Bearer\s+(?<token>\S+)$/i', $authorization, $matches)) {
            return false;
        }

        return hash_equals((string) $this->token, $matches['token']);
    }

    /**
     * @param array<string, string> $headers
     */
    private function rejection(Request $request, string $error, int $status, array $headers = []): Response
    {
        $this->logger?->warning('Messenger stats request rejected as {error} for client {ip}.', [
            'error' => $error,
            'ip' => $request->getClientIp(),
            'route' => $request->attributes->get('_route'),
        ]);

        return $this->responses->json(['error' => $error], $status, $headers);
    }

    private function hasAllowedIp(Request $request): bool
    {
        $clientIp = $request->getClientIp();

        return [] === $this->allowedIps || (null !== $clientIp && IpUtils::checkIp($clientIp, $this->allowedIps));
    }
}
