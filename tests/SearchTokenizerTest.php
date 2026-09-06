<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Api\SearchTokenizer;
use PHPUnit\Framework\TestCase;

final class SearchTokenizerTest extends TestCase
{
    public function testSplitsWordsAndDropsStopWords(): void
    {
        $this->assertSame(
            ['sable', 'cascade', 'vector', 'cascad'],
            SearchTokenizer::tokens('Sable cascade vector sable cascad'),
        );
        $this->assertSame(
            ['sable', 'vector'],
            SearchTokenizer::tokens('sable vector'),
        );
    }

    public function testIgnoresEnglishAndRussianPrepositions(): void
    {
        $this->assertSame(
            ['house', 'river'],
            SearchTokenizer::tokens('the house by the river'),
        );
        $this->assertSame(
            ['дом', 'реке'],
            SearchTokenizer::tokens('дом на реке'),
        );
    }

    public function testDedupesCaseInsensitively(): void
    {
        $this->assertSame(['alpha', 'beta'], SearchTokenizer::tokens('Alpha ALPHA beta'));
    }

    public function testKeepsStopWordsWhenQueryIsOnlyStopWords(): void
    {
        $this->assertSame(['the', 'of'], SearchTokenizer::tokens('the of'));
    }

    public function testEmptyInput(): void
    {
        $this->assertSame([], SearchTokenizer::tokens('   '));
    }
}
