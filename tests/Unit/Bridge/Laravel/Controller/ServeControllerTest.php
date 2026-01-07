<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Bridge\Laravel\Controller;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tattali\PresignedUrl\Bridge\Laravel\Http\Controllers\ServeController;
use Tattali\PresignedUrl\Server\FileResponse;
use Tattali\PresignedUrl\Server\FileServerInterface;

final class ServeControllerTest extends TestCase
{
    #[Test]
    public function it_serves_file_with_string_body(): void
    {
        $fileResponse = new FileResponse(
            200,
            ['Content-Type' => 'text/plain', 'Content-Length' => '11'],
            'Hello World'
        );

        $fileServer = $this->createMock(FileServerInterface::class);
        $fileServer->expects($this->once())
            ->method('serve')
            ->with('documents', 'test.txt', 1234567890, 'abc123', 'GET', $this->isType('array'))
            ->willReturn($fileResponse);

        $request = $this->createRequest('GET', [
            'X-Expires' => '1234567890',
            'X-Signature' => 'abc123',
        ]);

        $controller = new ServeController($fileServer);
        $response = $controller($request, 'documents', 'test.txt');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Hello World', $response->getContent());
    }

    #[Test]
    public function it_serves_file_with_stream_body(): void
    {
        $stream = fopen('php://memory', 'rb+');
        fwrite($stream, 'Stream content');
        rewind($stream);

        $fileResponse = new FileResponse(
            200,
            ['Content-Type' => 'application/octet-stream'],
            $stream
        );

        $fileServer = $this->createMock(FileServerInterface::class);
        $fileServer->expects($this->once())
            ->method('serve')
            ->willReturn($fileResponse);

        $request = $this->createRequest('GET', [
            'X-Expires' => '1234567890',
            'X-Signature' => 'abc123',
        ]);

        $controller = new ServeController($fileServer);
        $response = $controller($request, 'bucket', 'file.bin');

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function it_serves_file_without_body(): void
    {
        $fileResponse = new FileResponse(
            204,
            ['Content-Type' => 'text/plain']
        );

        $fileServer = $this->createMock(FileServerInterface::class);
        $fileServer->expects($this->once())
            ->method('serve')
            ->willReturn($fileResponse);

        $request = $this->createRequest('HEAD', [
            'X-Expires' => '1234567890',
            'X-Signature' => 'abc123',
        ]);

        $controller = new ServeController($fileServer);
        $response = $controller($request, 'bucket', 'file.txt');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_403_for_invalid_signature(): void
    {
        $fileResponse = new FileResponse(
            403,
            ['Content-Type' => 'text/plain'],
            'Forbidden'
        );

        $fileServer = $this->createMock(FileServerInterface::class);
        $fileServer->expects($this->once())
            ->method('serve')
            ->willReturn($fileResponse);

        $request = $this->createRequest('GET', [
            'X-Expires' => '1234567890',
            'X-Signature' => 'invalid',
        ]);

        $controller = new ServeController($fileServer);
        $response = $controller($request, 'bucket', 'file.txt');

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_410_for_expired_url(): void
    {
        $fileResponse = new FileResponse(
            410,
            ['Content-Type' => 'text/plain'],
            'Gone'
        );

        $fileServer = $this->createMock(FileServerInterface::class);
        $fileServer->expects($this->once())
            ->method('serve')
            ->willReturn($fileResponse);

        $request = $this->createRequest('GET', [
            'X-Expires' => '1000000000',
            'X-Signature' => 'abc123',
        ]);

        $controller = new ServeController($fileServer);
        $response = $controller($request, 'bucket', 'file.txt');

        self::assertSame(410, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_404_for_missing_file(): void
    {
        $fileResponse = new FileResponse(
            404,
            ['Content-Type' => 'text/plain'],
            'Not Found'
        );

        $fileServer = $this->createMock(FileServerInterface::class);
        $fileServer->expects($this->once())
            ->method('serve')
            ->willReturn($fileResponse);

        $request = $this->createRequest('GET', [
            'X-Expires' => '1234567890',
            'X-Signature' => 'abc123',
        ]);

        $controller = new ServeController($fileServer);
        $response = $controller($request, 'bucket', 'missing.txt');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function it_handles_partial_content_response(): void
    {
        $fileResponse = new FileResponse(
            206,
            [
                'Content-Type' => 'application/octet-stream',
                'Content-Range' => 'bytes 0-100/1000',
            ],
            'partial content'
        );

        $fileServer = $this->createMock(FileServerInterface::class);
        $fileServer->expects($this->once())
            ->method('serve')
            ->willReturn($fileResponse);

        $request = $this->createRequest('GET', [
            'X-Expires' => '1234567890',
            'X-Signature' => 'abc123',
        ], [
            'Range' => 'bytes=0-100',
        ]);

        $controller = new ServeController($fileServer);
        $response = $controller($request, 'bucket', 'large.bin');

        self::assertSame(206, $response->getStatusCode());
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    private function createRequest(string $method, array $query, array $headers = []): Request
    {
        $request = $this->createMock(Request::class);
        $request->query = new InputBag($query);
        $request->headers = new HeaderBag(array_change_key_case($headers, CASE_LOWER));

        $request->expects($this->any())
            ->method('getMethod')
            ->willReturn($method);

        return $request;
    }
}
