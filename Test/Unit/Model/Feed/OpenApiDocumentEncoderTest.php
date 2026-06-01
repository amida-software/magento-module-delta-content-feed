<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\Feed;

use Amida\ProductDeltaFeed\Model\Feed\OpenApiDocumentEncoder;
use Amida\ProductDeltaFeed\Model\Feed\ProtoWriter;
use PHPUnit\Framework\TestCase;

class OpenApiDocumentEncoderTest extends TestCase
{
    public function testCanonicalJsonIsStableAndOrderIndependent(): void
    {
        $encoder = new OpenApiDocumentEncoder(new ProtoWriter());
        $left = ['b' => 2, 'a' => ['y' => 2, 'x' => 1]];
        $right = ['a' => ['x' => 1, 'y' => 2], 'b' => 2];

        self::assertSame($encoder->canonicalJson($left), $encoder->canonicalJson($right));
    }

    public function testEncodesOpenApiDocumentWrapper(): void
    {
        $encoder = new OpenApiDocumentEncoder(new ProtoWriter());
        $payload = ['schema_version' => 1, 'entity' => 'store', 'store' => ['name' => 'Demo']];
        $binary = $encoder->encode($payload, 'store', '/amidafeed/v1/store/key/{key}', '#/components/schemas/StoreMetadata');

        self::assertNotSame('', $binary);
        self::assertStringContainsString('store', $binary);
        self::assertStringContainsString('/amidafeed/v1/store/key/{key}', $binary);
        self::assertStringContainsString(hash('sha256', $encoder->canonicalJson($payload)), $binary);
    }
}
