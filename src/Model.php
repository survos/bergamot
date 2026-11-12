<?php

declare(strict_types=1);

namespace Survos\Bergamot;

/**
 * One translation model as published: where its files are, what they must hash to, and what it translates.
 *
 * A model is one direction. "xx-en" reads any of the listed languages (it is not told which) and writes English;
 * "en-xx" reads English and writes whichever language its input is tagged with.
 */
final class Model
{
    public const XX_EN = 'xx-en';
    public const EN_XX = 'en-xx';

    /**
     * @param string                                                       $id        e.g. "euronano/base/xx-en"; also its directory under <home>/models/
     * @param list<string>                                                 $languages the non-English languages it covers
     * @param array<string, array{url: string, sha256: string, size: int}> $files     by file name
     */
    public function __construct(
        public readonly string $id,
        public readonly string $direction,
        public readonly string $variant,
        public readonly array $languages,
        public readonly array $files,
        public readonly string $source,
        public readonly string $revision,
        public readonly string $license,
    ) {
    }

    public function bytes(): int
    {
        return array_sum(array_column($this->files, 'size'));
    }

    /** What to put in front of a sentence so the model writes $target; empty for models that only write English. */
    public function tagFor(string $target): string
    {
        return self::EN_XX === $this->direction ? '##'.strtoupper($target).' ' : '';
    }
}
