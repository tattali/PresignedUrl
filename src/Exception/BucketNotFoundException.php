<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Exception;

final class BucketNotFoundException extends PresignedUrlException
{
    public function __construct(string $bucket)
    {
        parent::__construct(sprintf('Bucket "%s" not found.', $bucket));
    }
}
