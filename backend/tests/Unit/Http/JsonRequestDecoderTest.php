<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\JsonRequestDecoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class JsonRequestDecoderTest extends TestCase
{
    public function testEmptyBodyIsAnEmptyArray(): void
    {
        self::assertSame([], JsonRequestDecoder::decode(new Request()));
    }

    public function testObjectPayloadIsReturned(): void
    {
        $request = Request::create('/', 'POST', [], [], [], [], '{"email":"a@b.test"}');

        self::assertSame(['email' => 'a@b.test'], JsonRequestDecoder::decode($request));
    }

    public function testInvalidJsonIsABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid JSON payload.');

        JsonRequestDecoder::decode(Request::create('/', 'POST', [], [], [], [], '{'));
    }

    public function testAJsonScalarIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('JSON payload must be an object or array.');

        JsonRequestDecoder::decode(Request::create('/', 'POST', [], [], [], [], '"just a string"'));
    }
}
