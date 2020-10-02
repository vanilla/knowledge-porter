<?php


namespace Vanilla\KnowledgePorter\Tests;

use PHPUnit\Framework\TestCase;
use Vanilla\KnowledgePorter\Utils\Helpers;

class HelpersTest extends TestCase
{
    public function testDot(): void
    {
        $this->assertSame(
            Helpers::dot('first', 'second', ['third', 'fourth'], ['fifth', 'sixth']),
            'first.second.third.fourth.fifth.sixth'
        );

        $this->assertSame(
            Helpers::dot('a', 'b', ['c', 'd'], [['e', 'f'], ['g', ['h', 'i']]]),
            'a.b.c.d.e.f.g.h.i'
        );
    }

    public function testPath(): void
    {
        $this->assertSame(DIRECTORY_SEPARATOR, '/');

        $this->assertSame(
            Helpers::path('path/to/dir', 'path/to/subdir', 'path/to/file.txt'),
            'path/to/dir/path/to/subdir/path/to/file.txt'
        );

        $this->assertSame(
            Helpers::path('path/to/dir/', 'path/to/subdir', 'path/to/file.txt'),
            'path/to/dir/path/to/subdir/path/to/file.txt'
        );

        $this->assertSame(
            Helpers::path('/path/to/dir/', '/path/to/subdir/', '/path/to/file.txt'),
            '/path/to/dir/path/to/subdir/path/to/file.txt'
        );
    }

    public function testRtruncate(): void
    {
        $this->assertSame(
            'efghijklmnopqrstuvwxyz1234567890',
            Helpers::rtruncate('abcdefghijklmnopqrstuvwxyz1234567890', 32)
        );

        $this->assertSame(
            '567890',
            Helpers::rtruncate('abcdefghijklmnopqrstuvwxyz1234567890', 6)
        );
    }

    public function testDateFormat(): void
    {
        $this->assertSame(
            '2020-09-29T16:12:37-04:00',
            Helpers::dateFormat(1601410357)
        );
    }
}
