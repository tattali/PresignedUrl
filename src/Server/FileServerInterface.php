<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Server;

interface FileServerInterface
{
    /**
     * @param array<string, string> $headers Request headers
     */
    public function serve(
        string $bucket,
        string $path,
        int $expires,
        string $signature,
        string $method = 'GET',
        array $headers = [],
    ): FileResponse;

    /**
     * @param array<string, string> $query Query parameters
     * @param array<string, string> $headers Request headers
     */
    public function serveFromRequest(
        string $uri,
        array $query = [],
        string $method = 'GET',
        array $headers = [],
    ): FileResponse;
}
