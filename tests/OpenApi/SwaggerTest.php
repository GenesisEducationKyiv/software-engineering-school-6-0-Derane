<?php

declare(strict_types=1);

namespace Tests\OpenApi;

use PHPUnit\Framework\TestCase;

final class SwaggerTest extends TestCase
{
    public function testSwaggerDeclaresTopLevelSecurityArray(): void
    {
        $schema = file_get_contents(dirname(__DIR__, 2) . '/swagger.yaml');

        self::assertNotFalse($schema);
        self::assertMatchesRegularExpression('/^security:\\s*(\\[\\]|\\n)/m', $schema);
    }
}
