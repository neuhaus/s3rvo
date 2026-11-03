<?php

namespace S3Gateway\Tests\Http;

use PHPUnit\Framework\TestCase;
use S3Gateway\Auth\Authenticator;
use S3Gateway\Exception\S3Exception;
use S3Gateway\Http\Request;
use S3Gateway\Tests\ResetsConfig;

final class DirectUploadTest extends TestCase
{
    use ResetsConfig;
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->resetConfig(sys_get_temp_dir() . '/unused-direct-upload');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    private function request(string $wire, array $headers = []): array
    {
        $_SERVER = ['REQUEST_METHOD' => 'PUT', 'REQUEST_URI' => '/bucket/key', 'QUERY_STRING' => ''];
        foreach ($headers as $name => $value) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $input = fopen('php://memory', 'w+b');
        fwrite($input, $wire);
        rewind($input);
        return [new Request($input), $input];
    }

    public function testOpeningAndCheckingLimitsDoesNotConsumeTheBody(): void
    {
        [$request, $input] = $this->request('abc', ['Content-Length' => '3']);
        $this->setConfigValue('MAX_UPLOAD_SIZE', '10');
        (new Authenticator($request))->checkRequestSize('key', deferBodyRead: true);
        $request->armPayloadVerification(hash('sha256', 'abc'));
        $stream = $request->getStreamingBodyStream();
        self::assertSame(0, ftell($input));
        self::assertNull($request->spoolSize());
        self::assertSame(3, $request->uploadLength());
        self::assertSame('abc', stream_get_contents($stream));
        self::assertNull($request->spoolSize());
    }

    public function testPayloadHashMismatchIsRaisedByReading(): void
    {
        [$request] = $this->request('abc', ['Content-Length' => '3']);
        $request->armPayloadVerification(hash('sha256', 'different'));
        $stream = $request->getStreamingBodyStream();
        $this->expectException(S3Exception::class);
        stream_get_contents($stream);
    }

    public function testLimitAlsoAppliesWhileReadingWithoutContentLength(): void
    {
        [$request] = $this->request(str_repeat('x', 1025));
        $this->setConfigValue('MAX_UPLOAD_SIZE', '1');
        $this->expectException(S3Exception::class);
        stream_get_contents($request->getStreamingBodyStream());
    }

    public function testSuppliedContentMd5IsVerifiedIndependentlyOfEtags(): void
    {
        [$request] = $this->request('abc', ['Content-Length' => '3', 'Content-MD5' => base64_encode(md5('abc', true))]);
        self::assertSame('abc', stream_get_contents($request->getStreamingBodyStream()));
        [$bad] = $this->request('abc', ['Content-Length' => '3', 'Content-MD5' => base64_encode(md5('wrong', true))]);
        $this->expectException(S3Exception::class);
        stream_get_contents($bad->getStreamingBodyStream());
    }

    public function testAwsChunkedDecodingAndChecksumTrailerNeedNoSpool(): void
    {
        $checksum = base64_encode(hash('crc32b', 'abc', true));
        [$request] = $this->request("3\r\nabc\r\n0\r\nx-amz-checksum-crc32:{$checksum}\r\n\r\n", [
            'Content-Encoding' => 'aws-chunked', 'X-Amz-Decoded-Content-Length' => '3', 'X-Amz-Trailer' => 'x-amz-checksum-crc32',
        ]);
        self::assertSame(3, $request->uploadLength());
        self::assertSame('abc', stream_get_contents($request->getStreamingBodyStream()));
        self::assertSame($checksum, $request->getTrailers()['x-amz-checksum-crc32']);
        self::assertNull($request->spoolSize());
    }

    public function testBadChecksumTrailerIsRejectedAtEof(): void
    {
        [$request] = $this->request("3\r\nabc\r\n0\r\nx-amz-checksum-crc32:AAAAAA==\r\n\r\n", [
            'Content-Encoding' => 'aws-chunked', 'X-Amz-Decoded-Content-Length' => '3', 'X-Amz-Trailer' => 'x-amz-checksum-crc32',
        ]);
        $this->expectException(S3Exception::class);
        stream_get_contents($request->getStreamingBodyStream());
    }

    public function testTruncatedFramingAndWrongDecodedLengthAreRejected(): void
    {
        [$request] = $this->request("3\r\nabc\r\n", ['Content-Encoding' => 'aws-chunked', 'X-Amz-Decoded-Content-Length' => '3']);
        $this->expectException(S3Exception::class);
        stream_get_contents($request->getStreamingBodyStream());
    }

    public function testSignedChunkChainIsCheckedWithoutStaging(): void
    {
        $date = '20261103T000000Z';
        $key = hash_hmac('sha256', '20261103', 'AWS4secret', true);
        foreach (['us-east-1', 's3', 'aws4_request'] as $part) {
            $key = hash_hmac('sha256', $part, $key, true);
        }
        $previous = str_repeat('0', 64);
        $wire = '';
        foreach (['abc', ''] as $body) {
            $signature = hash_hmac('sha256', implode("\n", ['AWS4-HMAC-SHA256-PAYLOAD', $date,
                '20261103/us-east-1/s3/aws4_request', $previous, hash('sha256', ''), hash('sha256', $body)]), $key);
            $wire .= dechex(strlen($body)) . ';chunk-signature=' . $signature . "\r\n" . $body . "\r\n";
            $previous = $signature;
        }
        [$request] = $this->request($wire, ['Content-Encoding' => 'aws-chunked', 'X-Amz-Decoded-Content-Length' => '3']);
        $context = ['secret' => 'secret', 'amzDate' => $date, 'region' => 'us-east-1', 'service' => 's3', 'signature' => str_repeat('0', 64)];
        $request->armPayloadVerification('STREAMING-AWS4-HMAC-SHA256-PAYLOAD', $context);
        self::assertSame('abc', stream_get_contents($request->getStreamingBodyStream()));
        [$bad] = $this->request(str_replace('abc', 'abX', $wire), ['Content-Encoding' => 'aws-chunked', 'X-Amz-Decoded-Content-Length' => '3']);
        $bad->armPayloadVerification('STREAMING-AWS4-HMAC-SHA256-PAYLOAD', $context);
        $this->expectException(S3Exception::class);
        stream_get_contents($bad->getStreamingBodyStream());
    }
}
