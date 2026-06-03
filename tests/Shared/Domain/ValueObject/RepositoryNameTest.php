<?php

declare(strict_types=1);

namespace Tests\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\RepositoryName;
use PHPUnit\Framework\TestCase;

final class RepositoryNameTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function validProvider(): iterable
    {
        yield 'golang/go' => ['golang/go', 'golang', 'go'];
        yield 'php/php-src' => ['php/php-src', 'php', 'php-src'];
        yield 'a/b' => ['a/b', 'a', 'b'];
        yield 'a.b/c.d' => ['a.b/c.d', 'a.b', 'c.d'];
        yield 'Owner/Repo' => ['Owner/Repo', 'Owner', 'Repo'];
        yield 'a_b/c-d' => ['a_b/c-d', 'a_b', 'c-d'];
    }

    /**
     * @dataProvider validProvider
     */
    public function testConstructsAndExposesParts(string $input, string $owner, string $repo): void
    {
        $vo = new RepositoryName($input);

        $this->assertSame($input, $vo->value());
        $this->assertSame($owner, $vo->owner());
        $this->assertSame($repo, $vo->repo());
        $this->assertSame($input, (string) $vo);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'no slash' => ['invalid'];
        yield 'empty' => [''];
        yield 'two slashes' => ['a/b/c'];
        yield 'only slash' => ['/'];
        yield 'empty repo' => ['a/'];
        yield 'empty owner' => ['/b'];
        yield 'trailing space' => ['owner/repo '];
    }

    /**
     * @dataProvider invalidProvider
     */
    public function testRejectsInvalidShapes(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RepositoryName($input);
    }

    public function testEquals(): void
    {
        $vo = new RepositoryName('golang/go');

        $this->assertTrue($vo->equals(new RepositoryName('golang/go')));
        $this->assertFalse($vo->equals(new RepositoryName('php/php-src')));
    }
}
