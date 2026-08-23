<?php

namespace ErnestDefoe\Giveaways\Tests;

use ErnestDefoe\Giveaways\Support\SlugHelper;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;

class SlugHelperTest extends TestCase
{
    public function test_unique_returns_base_when_free(): void
    {
        $this->assertSame('new-giveaway', SlugHelper::unique('New Giveaway', fn () => false));
    }

    public function test_unique_suffixes_when_taken(): void
    {
        $taken = ['mega-prize', 'mega-prize-2'];
        $slug = SlugHelper::unique('Mega Prize', fn ($s) => in_array($s, $taken, true));
        $this->assertSame('mega-prize-3', $slug);
    }

    public function test_base_falls_back_to_configured_default(): void
    {
        $this->assertSame('giveaway', SlugHelper::base('!!!'));
        $this->assertSame('category', SlugHelper::base('!!!', 'category'));
    }

    public function test_isDuplicateKey_detects_mysql_1062(): void
    {
        $pdo = new \PDOException('Duplicate entry');
        $pdo->errorInfo = ['23000', 1062, 'Duplicate entry ... for key giveaways_slug_unique'];
        $e = new QueryException('default', 'insert into giveaways ...', [], $pdo);

        $this->assertTrue(SlugHelper::isDuplicateKey($e));
    }

    public function test_isDuplicateKey_detects_sqlite_unique_message(): void
    {
        $pdo = new \PDOException('UNIQUE constraint failed: giveaways.slug');
        $pdo->errorInfo = ['23000', 1555, 'UNIQUE constraint failed: giveaways.slug'];
        $e = new QueryException('default', 'insert into giveaways ...', [], $pdo);

        $this->assertTrue(SlugHelper::isDuplicateKey($e));
    }

    public function test_isDuplicateKey_rejects_other_errors(): void
    {
        $pdo = new \PDOException('SQLSTATE[42000]: Syntax error');
        $pdo->errorInfo = ['42000', 1064, 'Syntax error'];
        $e = new QueryException('default', 'bad sql', [], $pdo);

        $this->assertFalse(SlugHelper::isDuplicateKey($e));
    }

    public function test_saveWithUniqueSlug_retries_then_succeeds(): void
    {
        $pdo = new \PDOException('Duplicate entry');
        $pdo->errorInfo = ['23000', 1062, 'dup'];
        $dup = new QueryException('default', 'insert into giveaways ...', [], $pdo);

        $attempts = 0;
        $slug = 'x';

        SlugHelper::saveWithUniqueSlug(
            function () use (&$attempts, $dup) {
                $attempts++;
                if ($attempts < 3) {
                    throw $dup;
                }
            },
            function () use (&$slug) {
                $slug .= '-2';
            }
        );

        $this->assertSame(3, $attempts);
        $this->assertSame('x-2-2', $slug);
    }

    public function test_saveWithUniqueSlug_rethrows_non_duplicate(): void
    {
        $pdo = new \PDOException('Syntax error');
        $pdo->errorInfo = ['42000', 1064, 'Syntax error'];
        $e = new QueryException('default', 'bad sql', [], $pdo);

        $this->expectException(QueryException::class);

        SlugHelper::saveWithUniqueSlug(
            function () use ($e) {
                throw $e;
            },
            function () {}
        );
    }
}