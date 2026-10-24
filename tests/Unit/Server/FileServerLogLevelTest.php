<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Server\FileResponse;
use Tattali\PresignedUrl\Server\FileServer;
use Tattali\PresignedUrl\Signer\SignerInterface;
use Tattali\PresignedUrl\Storage\StorageInterface;

final class FileServerLogLevelTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function levels(): array
    {
        return [
            'emergency' => ['emergency'],
            'alert' => ['alert'],
            'critical' => ['critical'],
            'error' => ['error'],
            'warning' => ['warning'],
            'notice' => ['notice'],
            'info' => ['info'],
            'debug' => ['debug'],
        ];
    }

    #[Test]
    #[DataProvider('levels')]
    public function it_logs_through_the_method_named_after_the_level(string $level): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method($level)
            ->with('Message', ['bucket' => 'documents']);
        $logger->expects($this->never())->method('log');

        $response = $this->createServer($logger)->respond($level, 'Message', ['bucket' => 'documents'], 418, 'Body');

        self::assertSame(418, $response->getStatusCode());
        self::assertSame('Body', $response->getBody());
    }

    #[Test]
    #[DataProvider('levels')]
    public function it_responds_without_a_logger(string $level): void
    {
        $response = $this->createServer(null)->respond($level, 'Message', [], 400, 'Bad Request');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['Content-Type' => 'text/plain'], $response->getHeaders());
    }

    private function createServer(?LoggerInterface $logger): LogLevelFileServer
    {
        return new LogLevelFileServer(
            self::createStub(StorageInterface::class),
            self::createStub(SignerInterface::class),
            new Config(secret: 'secret', baseUrl: 'https://cdn.example.com'),
            $logger,
        );
    }
}

readonly class LogLevelFileServer extends FileServer
{
    /**
     * @param array<string, mixed> $context
     */
    public function respond(string $level, string $message, array $context, int $status, string $body): FileResponse
    {
        return $this->logAndRespond($level, $message, $context, $status, $body);
    }
}
