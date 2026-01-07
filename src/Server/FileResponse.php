<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Server;

final class FileResponse
{
    /** @var resource|string|null */
    private $body;

    /**
     * @param array<string, string> $headers
     * @param resource|string|null $body
     */
    public function __construct(
        private readonly int $statusCode,
        private array $headers = [],
        $body = null,
    ) {
        $this->body = $body;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        $normalized = strtolower($name);

        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $normalized) {
                return $value;
            }
        }

        return null;
    }

    public function hasHeader(string $name): bool
    {
        return $this->getHeader($name) !== null;
    }

    public function withHeader(string $name, string $value): self
    {
        $response = clone $this;
        $response->headers[$name] = $value;

        return $response;
    }

    /**
     * @return resource|string|null
     */
    public function getBody()
    {
        return $this->body;
    }

    public function hasBody(): bool
    {
        return $this->body !== null;
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function isNotModified(): bool
    {
        return $this->statusCode === 304;
    }

    public function isPartialContent(): bool
    {
        return $this->statusCode === 206;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header(sprintf('%s: %s', $name, $value));
        }

        if ($this->body === null) {
            return;
        }

        if (is_resource($this->body)) {
            fpassthru($this->body);
            fclose($this->body);
        } else {
            echo $this->body;
        }
    }
}
