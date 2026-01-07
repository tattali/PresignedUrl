<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Exception;

final class ExpiredUrlException extends PresignedUrlException
{
    public function __construct()
    {
        parent::__construct('URL has expired.');
    }
}
