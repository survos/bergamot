<?php

declare(strict_types=1);

namespace Survos\Bergamot;

/** Text-level translation inside this process; see Translator. */
interface TranslatorInterface
{
    /** @return list<string> */
    public function languages(): array;

    /** Why this pair cannot be translated here; null when it can. Must not load a model. */
    public function whyNot(string $source, string $target): ?string;

    /** @throws BergamotException when the pair or the text cannot be handled */
    public function translate(string $text, string $source, string $target): Translation;
}
