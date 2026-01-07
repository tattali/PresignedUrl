<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Storage;

use Tattali\PresignedUrl\Exception\InvalidBucketNameException;

class BucketNameValidator
{
    private const MIN_LENGTH = 3;
    private const MAX_LENGTH = 63;
    private const PATTERN = '/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/';

    public static function validate(string $name): void
    {
        $length = strlen($name);

        if ($length < self::MIN_LENGTH) {
            throw new InvalidBucketNameException($name, sprintf('must be at least %d characters long', self::MIN_LENGTH));
        }

        if ($length > self::MAX_LENGTH) {
            throw new InvalidBucketNameException($name, sprintf('must be at most %d characters long', self::MAX_LENGTH));
        }

        if (!preg_match(self::PATTERN, $name)) {
            throw new InvalidBucketNameException($name, 'must contain only lowercase letters, numbers, and hyphens, and must start and end with a letter or number');
        }

        if (str_contains($name, '--')) {
            throw new InvalidBucketNameException($name, 'must not contain consecutive hyphens');
        }
    }

    public static function isValid(string $name): bool
    {
        try {
            self::validate($name);

            return true;
        } catch (InvalidBucketNameException) {
            return false;
        }
    }
}
