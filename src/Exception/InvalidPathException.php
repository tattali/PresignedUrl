<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Exception;

final class InvalidPathException extends PresignedUrlException
{
    public function __construct(string $path)
    {
        parent::__construct(sprintf('Invalid path "%s". Path traversal detected.', $path));
    }
}
