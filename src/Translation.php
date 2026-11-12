<?php

declare(strict_types=1);

namespace Survos\Bergamot;

/** A finished translation and how it was made. */
final class Translation
{
    /**
     * @param list<string> $route model ids used, in order: one, or two when pivoting through English
     */
    public function __construct(
        public readonly string $text,
        public readonly array $route,
        public readonly int $sentences,
        public readonly int $tokens,
        public readonly float $elapsedMs,
        public readonly int $modelLoads,
    ) {
    }
}
