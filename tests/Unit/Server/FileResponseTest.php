<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Server\FileResponse;

final class FileResponseTest extends TestCase
{
    #[Test]
    public function it_returns_status_code(): void
    {
        $response = new FileResponse(200);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_headers(): void
    {
        $headers = ['Content-Type' => 'text/plain', 'X-Custom' => 'value'];
        $response = new FileResponse(200, $headers);

        self::assertSame($headers, $response->getHeaders());
    }

    #[Test]
    public function it_returns_header_by_name(): void
    {
        $response = new FileResponse(200, ['Content-Type' => 'application/json']);

        self::assertSame('application/json', $response->getHeader('Content-Type'));
    }

    #[Test]
    public function it_returns_header_case_insensitive(): void
    {
        $response = new FileResponse(200, ['Content-Type' => 'text/html']);

        self::assertSame('text/html', $response->getHeader('content-type'));
        self::assertSame('text/html', $response->getHeader('CONTENT-TYPE'));
    }

    #[Test]
    public function it_returns_null_for_missing_header(): void
    {
        $response = new FileResponse(200);

        self::assertNull($response->getHeader('X-Missing'));
    }

    #[Test]
    public function it_checks_if_header_exists(): void
    {
        $response = new FileResponse(200, ['Content-Type' => 'text/plain']);

        self::assertTrue($response->hasHeader('Content-Type'));
        self::assertTrue($response->hasHeader('content-type'));
        self::assertFalse($response->hasHeader('X-Missing'));
    }

    #[Test]
    public function it_returns_body(): void
    {
        $response = new FileResponse(200, [], 'Hello World');

        self::assertSame('Hello World', $response->getBody());
    }

    #[Test]
    public function it_returns_stream_body(): void
    {
        $stream = fopen('php://memory', 'rb+');
        fwrite($stream, 'Stream content');
        rewind($stream);

        $response = new FileResponse(200, [], $stream);

        self::assertIsResource($response->getBody());

        fclose($stream);
    }

    #[Test]
    public function it_returns_null_body(): void
    {
        $response = new FileResponse(200);

        self::assertNull($response->getBody());
    }

    #[Test]
    public function it_checks_if_has_body(): void
    {
        $responseWithBody = new FileResponse(200, [], 'content');
        $responseWithoutBody = new FileResponse(200);

        self::assertTrue($responseWithBody->hasBody());
        self::assertFalse($responseWithoutBody->hasBody());
    }

    #[Test]
    public function it_creates_immutable_copy_with_header(): void
    {
        $response = new FileResponse(200, ['Content-Type' => 'text/plain']);
        $newResponse = $response->withHeader('X-Custom', 'value');

        self::assertNotSame($response, $newResponse);
        self::assertNull($response->getHeader('X-Custom'));
        self::assertSame('value', $newResponse->getHeader('X-Custom'));
        self::assertSame('text/plain', $newResponse->getHeader('Content-Type'));
    }

    #[Test]
    public function it_identifies_success_status(): void
    {
        self::assertTrue((new FileResponse(200))->isSuccess());
        self::assertTrue((new FileResponse(201))->isSuccess());
        self::assertTrue((new FileResponse(204))->isSuccess());
        self::assertTrue((new FileResponse(206))->isSuccess());
        self::assertTrue((new FileResponse(299))->isSuccess());

        self::assertFalse((new FileResponse(199))->isSuccess());
        self::assertFalse((new FileResponse(300))->isSuccess());
        self::assertFalse((new FileResponse(400))->isSuccess());
        self::assertFalse((new FileResponse(404))->isSuccess());
        self::assertFalse((new FileResponse(500))->isSuccess());
    }

    #[Test]
    public function it_identifies_not_modified_status(): void
    {
        self::assertTrue((new FileResponse(304))->isNotModified());

        self::assertFalse((new FileResponse(200))->isNotModified());
        self::assertFalse((new FileResponse(303))->isNotModified());
        self::assertFalse((new FileResponse(305))->isNotModified());
    }

    #[Test]
    public function it_identifies_partial_content_status(): void
    {
        self::assertTrue((new FileResponse(206))->isPartialContent());

        self::assertFalse((new FileResponse(200))->isPartialContent());
        self::assertFalse((new FileResponse(205))->isPartialContent());
        self::assertFalse((new FileResponse(207))->isPartialContent());
    }

    #[Test]
    public function it_sends_response_with_string_body(): void
    {
        $response = new FileResponse(
            200,
            ['Content-Type' => 'text/plain'],
            'Hello World'
        );

        ob_start();
        $response->send();
        $output = ob_get_clean();

        self::assertSame('Hello World', $output);
        self::assertSame(200, http_response_code());
    }

    #[Test]
    public function it_sends_response_with_stream_body(): void
    {
        $stream = fopen('php://memory', 'rb+');
        fwrite($stream, 'Stream content');
        rewind($stream);

        $response = new FileResponse(200, [], $stream);

        ob_start();
        $response->send();
        $output = ob_get_clean();

        self::assertSame('Stream content', $output);
    }

    #[Test]
    public function it_sends_response_without_body(): void
    {
        $response = new FileResponse(204, ['Content-Type' => 'text/plain']);

        ob_start();
        $response->send();
        $output = ob_get_clean();

        self::assertSame('', $output);
        self::assertSame(204, http_response_code());
    }
}
