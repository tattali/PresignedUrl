<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Bridge\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tattali\PresignedUrl\Server\FileServerInterface;

readonly class ServeController
{
    public function __construct(
        protected FileServerInterface $fileServer,
    ) {}

    public function __invoke(Request $request, string $bucket, string $path): Response|StreamedResponse
    {
        /** @var array<string, string> $query */
        $query = $request->query->all();

        $headers = [];
        foreach ($request->headers->all() as $key => $values) {
            if (is_array($values) && isset($values[0])) {
                $headers[$key] = $values[0];
            } elseif (is_string($values)) {
                $headers[$key] = $values;
            }
        }

        $fileResponse = $this->fileServer->serve(
            bucket: $bucket,
            path: $path,
            expires: isset($query['X-Expires']) ? (int) $query['X-Expires'] : 0,
            signature: $query['X-Signature'] ?? '',
            method: $request->getMethod(),
            headers: $headers,
        );

        $body = $fileResponse->getBody();

        if ($body === null || is_string($body)) {
            return new Response(
                content: $body ?? '',
                status: $fileResponse->getStatusCode(),
                headers: $fileResponse->getHeaders(),
            );
        }

        $stream = $body;

        return new StreamedResponse(
            static function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            $fileResponse->getStatusCode(),
            $fileResponse->getHeaders(),
        );
    }
}
