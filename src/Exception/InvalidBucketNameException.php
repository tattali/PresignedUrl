<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Exception;

final class InvalidBucketNameException extends PresignedUrlException
{
    public function __construct(string $bucket, string $reason)
    {
        parent::__construct(sprintf('Invalid bucket name "%s": %s', $bucket, $reason));
    }
}
