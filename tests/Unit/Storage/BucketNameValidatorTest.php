<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Exception\InvalidBucketNameException;
use Tattali\PresignedUrl\Storage\BucketNameValidator;

final class BucketNameValidatorTest extends TestCase
{
    #[DataProvider('validBucketNamesProvider')]
    public function testValidBucketNames(string $name): void
    {
        BucketNameValidator::validate($name);
        $this->assertTrue(BucketNameValidator::isValid($name));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validBucketNamesProvider(): iterable
    {
        yield 'simple name' => ['mybucket'];
        yield 'with numbers' => ['bucket123'];
        yield 'with hyphens' => ['my-bucket-name'];
        yield 'min length' => ['abc'];
        yield 'max length' => [str_repeat('a', 63)];
        yield 'numbers only' => ['123'];
        yield 'start with number' => ['1bucket'];
        yield 'end with number' => ['bucket1'];
    }

    #[DataProvider('invalidBucketNamesProvider')]
    public function testInvalidBucketNames(string $name, string $expectedMessagePart): void
    {
        $this->expectException(InvalidBucketNameException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedMessagePart, '/') . '/');

        BucketNameValidator::validate($name);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidBucketNamesProvider(): iterable
    {
        yield 'too short' => ['ab', 'at least 3 characters'];
        yield 'too long' => [str_repeat('a', 64), 'at most 63 characters'];
        yield 'uppercase' => ['MyBucket', 'lowercase'];
        yield 'underscore' => ['my_bucket', 'lowercase'];
        yield 'start with hyphen' => ['-bucket', 'start and end'];
        yield 'end with hyphen' => ['bucket-', 'start and end'];
        yield 'consecutive hyphens' => ['my--bucket', 'consecutive hyphens'];
        yield 'space' => ['my bucket', 'lowercase'];
        yield 'special chars' => ['my@bucket', 'lowercase'];
    }

    public function testIsValidReturnsFalseForInvalidName(): void
    {
        $this->assertFalse(BucketNameValidator::isValid('ab'));
        $this->assertFalse(BucketNameValidator::isValid('MyBucket'));
        $this->assertFalse(BucketNameValidator::isValid('my--bucket'));
    }
}
