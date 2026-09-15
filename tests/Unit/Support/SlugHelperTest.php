<?php

namespace Ygpynet\Giveaways\Tests\Unit\Support;

use PDOException;
use PHPUnit\Framework\TestCase;
use Illuminate\Database\QueryException;
use Ygpynet\Giveaways\Support\SlugHelper;

class SlugHelperTest extends TestCase
{
    public function test_base_slugifies_an_ascii_title(): void
    {
        $this->assertSame('hello-world', SlugHelper::base('Hello World', 'giveaway', 'en'));
    }

    public function test_base_falls_back_when_nothing_survives_slugification(): void
    {
        $this->assertSame('giveaway', SlugHelper::base('!!! ???', 'giveaway', 'en'));
        $this->assertSame('prize', SlugHelper::base('!!! ???', 'prize', 'en'));
    }

    public function test_unique_appends_suffixes_until_the_slug_is_free(): void
    {
        $taken = ['hello-world', 'hello-world-2'];

        $slug = SlugHelper::unique('Hello World', fn ($s) => in_array($s, $taken, true));

        $this->assertSame('hello-world-3', $slug);
    }

    public function test_unique_returns_the_base_when_it_is_free(): void
    {
        $slug = SlugHelper::unique('Hello World', fn ($s) => false);

        $this->assertSame('hello-world', $slug);
    }

    public function test_save_with_unique_slug_returns_on_success(): void
    {
        $saved = 0;
        SlugHelper::saveWithUniqueSlug(function () use (&$saved) {
            $saved++;
        }, function () {
            $this->fail('regenerate should not run on a clean save');
        });

        $this->assertSame(1, $saved);
    }

    public function test_save_with_unique_slug_retries_on_duplicate_key(): void
    {
        $attempts = 0;
        $regenerated = 0;

        SlugHelper::saveWithUniqueSlug(
            function () use (&$attempts) {
                $attempts++;
                if ($attempts < 3) {
                    throw $this->duplicateKey();
                }
            },
            function () use (&$regenerated) {
                $regenerated++;
            }
        );

        $this->assertSame(3, $attempts);
        $this->assertSame(2, $regenerated);
    }

    public function test_save_with_unique_slug_reraises_non_duplicate_errors(): void
    {
        $e = new QueryException('mysql', 'SQLSTATE[HY000]: general error', [], new PDOException('boom'));

        $this->expectException(QueryException::class);

        SlugHelper::saveWithUniqueSlug(
            function () use ($e) {
                throw $e;
            },
            function () {
                $this->fail('regenerate should not run for non-duplicate errors');
            }
        );
    }

    protected function duplicateKey(): QueryException
    {
        // MySQL 1062 shape: errorInfo[1] carries the driver code.
        $previous = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        $previous->errorInfo = ['23000', 1062, 'Duplicate entry'];

        return new QueryException(
            'mysql',
            'insert into giveaways ...',
            [],
            $previous
        );
    }
}
