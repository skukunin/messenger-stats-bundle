<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class NoStoreResponseFactory
{
    private const CACHE_CONTROL = 'Cache-Control';
    private const NO_STORE = 'no-store';

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public function json(array $data, int $status = Response::HTTP_OK, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($data, $status, $headers);
        $response->setEncodingOptions(\JSON_UNESCAPED_SLASHES);

        return $this->withoutStore($response);
    }

    /**
     * @template TResponse of Response
     *
     * @param TResponse $response
     *
     * @return TResponse
     */
    private function withoutStore(Response $response): Response
    {
        $response->headers->set(self::CACHE_CONTROL, self::NO_STORE);

        return $response;
    }

    public function text(string $body, string $contentType, int $status = Response::HTTP_OK): Response
    {
        return $this->withoutStore(new Response($body, $status, ['Content-Type' => $contentType]));
    }

    public function withoutBody(int $status): Response
    {
        return $this->withoutStore(new Response('', $status));
    }
}
