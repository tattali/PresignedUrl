<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Exception;

final class FileNotFoundException extends PresignedUrlException
{
    public function __construct(string $path)
    {
        parent::__construct(sprintf('File "%s" not found.', $path));
    }
}
