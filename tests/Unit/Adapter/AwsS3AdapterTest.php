<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Adapter;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Adapter\AwsS3Adapter;

final class AwsS3AdapterTest extends TestCase
{
    #[Test]
    public function it_supports_native_presigned_urls(): void
    {
        $adapter = AwsS3Adapter::fromConfig([
            'key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'us-east-1',
            'bucket' => 'my-bucket',
        ]);

        self::assertTrue($adapter->supportsNativePresignedUrl());
    }

    #[Test]
    public function it_creates_from_config(): void
    {
        $adapter = AwsS3Adapter::fromConfig([
            'key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'us-east-1',
            'bucket' => 'my-bucket',
        ]);

        self::assertInstanceOf(AwsS3Adapter::class, $adapter);
    }

    #[Test]
    public function it_creates_from_config_with_endpoint(): void
    {
        $adapter = AwsS3Adapter::fromConfig([
            'key' => 'minioadmin',
            'secret' => 'minioadmin',
            'region' => 'us-east-1',
            'bucket' => 'my-bucket',
            'endpoint' => 'http://localhost:9000',
            'use_path_style_endpoint' => true,
        ]);

        self::assertInstanceOf(AwsS3Adapter::class, $adapter);
    }

    #[Test]
    public function it_creates_from_config_with_custom_version(): void
    {
        $adapter = AwsS3Adapter::fromConfig([
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'eu-west-1',
            'bucket' => 'test-bucket',
            'version' => '2006-03-01',
        ]);

        self::assertInstanceOf(AwsS3Adapter::class, $adapter);
    }

    #[Test]
    public function it_generates_native_presigned_url(): void
    {
        $adapter = AwsS3Adapter::fromConfig([
            'key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'us-east-1',
            'bucket' => 'my-bucket',
        ]);

        $expires = time() + 3600;
        $url = $adapter->nativePresignedUrl('test/file.pdf', $expires);

        self::assertNotNull($url);
        self::assertStringContainsString('my-bucket', $url);
        self::assertStringContainsString('test/file.pdf', $url);
        self::assertStringContainsString('X-Amz-Signature', $url);
    }

    #[Test]
    public function it_reports_an_existing_object(): void
    {
        $handler = new MockHandler([new Result([])]);
        $adapter = new AwsS3Adapter($this->createClient($handler), 'my-bucket');

        self::assertTrue($adapter->exists('file.txt'));

        $command = $handler->getLastCommand();
        self::assertSame('HeadObject', $command->getName());
        self::assertSame('my-bucket', $command['Bucket']);
        self::assertSame('file.txt', $command['Key']);
    }

    #[Test]
    public function it_reports_a_missing_object(): void
    {
        $adapter = new AwsS3Adapter($this->createClient(new MockHandler([$this->s3Error('NotFound', 404)])), 'my-bucket');

        self::assertFalse($adapter->exists('missing.txt'));
    }

    #[Test]
    public function it_reports_an_object_as_existing_when_access_is_denied(): void
    {
        $adapter = new AwsS3Adapter($this->createClient(new MockHandler([$this->s3Error('AccessDenied', 403)])), 'my-bucket');

        self::assertTrue($adapter->exists('private.txt'));
    }

    #[Test]
    public function it_reports_a_missing_object_on_other_client_errors(): void
    {
        $adapter = new AwsS3Adapter($this->createClient(new MockHandler([$this->s3Error('BadRequest', 400)])), 'my-bucket');

        self::assertFalse($adapter->exists('file.txt'));
    }

    #[Test]
    public function it_rethrows_server_errors_when_checking_existence(): void
    {
        $adapter = new AwsS3Adapter($this->createClient(new MockHandler([$this->s3Error('InternalError', 500)])), 'my-bucket');

        $this->expectException(S3Exception::class);

        $adapter->exists('file.txt');
    }

    private function createClient(MockHandler $handler): S3Client
    {
        return new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'handler' => $handler,
            'retries' => 0,
        ]);
    }

    private function s3Error(string $code, int $status): callable
    {
        return static fn(CommandInterface $command): S3Exception => new S3Exception($code, $command, [
            'code' => $code,
            'response' => new Response($status),
        ]);
    }
}
