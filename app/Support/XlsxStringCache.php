<?php

namespace App\Support;

use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\CachingStrategyFactory;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\CachingStrategyFactoryInterface;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\CachingStrategyInterface;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\FileBasedStrategy;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\InMemoryStrategy;

/**
 * Excel stores cell text in one shared table. OpenSpout's default keeps that table on disk
 * whenever memory_limit is -1 and there are >10k strings, which makes reading crawl
 * (one temp-file load per cell). Keep it in memory up to MAX_IN_MEMORY strings (~75 bytes each).
 */
final class XlsxStringCache implements CachingStrategyFactoryInterface
{
    public const MAX_IN_MEMORY = 3_000_000;

    public function createBestCachingStrategy(?int $sharedStringsUniqueCount, string $tempFolder): CachingStrategyInterface
    {
        if ($sharedStringsUniqueCount !== null && $sharedStringsUniqueCount <= self::MAX_IN_MEMORY) {
            return new InMemoryStrategy($sharedStringsUniqueCount);
        }

        return new FileBasedStrategy($tempFolder, CachingStrategyFactory::MAX_NUM_STRINGS_PER_TEMP_FILE);
    }
}
