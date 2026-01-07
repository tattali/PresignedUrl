<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Exception;

final class InvalidSignatureException extends PresignedUrlException
{
    public function __construct()
    {
        parent::__construct('Invalid signature.');
    }
}
