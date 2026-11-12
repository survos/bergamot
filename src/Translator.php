<?php

declare(strict_types=1);

namespace Survos\Bergamot;

/**
 * Translates text between English and the model's languages inside this process.
 *
 *   xx -> en   the "xx-en" model; it reads any of its languages without being told which, so source may be "auto"
 *   en -> xx   the "en-xx" model, each sentence tagged with the target ("##DE ...")
 *   xx -> yy   both, pivoting through English sentence by sentence
 *
 * Text is cut into sentences (SentenceSplitter), each is translated on its own, and the whitespace between them is put
 * back as it was: this is how the models were trained to be used. A sentence over 120 model tokens is refused, as is
 * output with unknown-token markers; the caller decides what to do with text this cannot handle.
 *
 * whyNot() answers "could you translate this pair here?" without loading anything, so a caller can choose another
 * engine up front. Models load on first use and stay loaded for the life of this object.
 */
final class Translator implements TranslatorInterface
{
    public const MAX_CHARS = 20000;
    /** Beyond this many model tokens a sentence is outside what the model was trained on. */
    public const MAX_SENTENCE_TOKENS = 120;

    /** @var array<string, Bridge> by model id */
    private array $bridges = [];

    public function __construct(
        private readonly ModelManager $models,
        private readonly Runtime $runtime,
        private readonly string $variant = 'base',
        private readonly SentenceSplitter $splitter = new SentenceSplitter(),
    ) {
    }

    /** @return list<string> every language this can read or write, English included */
    public function languages(): array
    {
        return ['en', ...$this->models->catalog()->euronano($this->variant, Model::XX_EN)->languages];
    }

    /** Whether this pair is translated here, on this machine, right now. */
    public function supports(string $source, string $target): bool
    {
        return null === $this->whyNot($source, $target);
    }

    /** Why this pair cannot be translated here; null when it can. Loads nothing. */
    public function whyNot(string $source, string $target): ?string
    {
        try {
            $route = $this->route($source, $target);
        } catch (BergamotException $e) {
            return $e->getMessage();
        }
        if (null !== ($why = $this->runtime->unavailableBecause())) {
            return $why;
        }
        foreach ($route as $model) {
            if (!$this->models->isInstalled($model)) {
                return sprintf('model %s is not installed (%.0f MB)', $model->id, $model->bytes() / 1e6);
            }
        }

        return null;
    }

    public function translate(string $text, string $source, string $target): Translation
    {
        if (null !== ($why = $this->whyNot($source, $target))) {
            throw new BergamotException($why);
        }
        if ('' === trim($text) || str_contains($text, "\0")) {
            throw new BergamotException('Supply nonblank plain text without NUL characters');
        }
        if (mb_strlen($text) > self::MAX_CHARS) {
            throw new BergamotException(sprintf('Input exceeds %d characters', self::MAX_CHARS));
        }
        $route = $this->route($source, $target);
        $began = hrtime(true);
        $out = '';
        $tokens = 0;
        $sentences = 0;
        foreach ($this->splitter->split($text, $source) as $piece) {
            $sentence = trim($piece);
            if ('' === $sentence) {
                $out .= $piece; // whitespace only, kept as written
                continue;
            }
            foreach ($route as $model) {
                $result = $this->bridge($model)->translate($model->tagFor($target).$sentence);
                if ($result['tokens'] > self::MAX_SENTENCE_TOKENS) {
                    throw new BergamotException(sprintf('A sentence exceeds %d model tokens', self::MAX_SENTENCE_TOKENS));
                }
                $sentence = trim($result['text']);
                if ('' === $sentence) {
                    throw new BergamotException('Model returned empty text');
                }
                if (str_contains($sentence, '<unk>') || str_contains($sentence, '⁇')) {
                    throw new BergamotException('Model returned unknown-token markers');
                }
                $tokens += $result['tokens'];
            }
            $out .= substr($piece, 0, \strlen($piece) - \strlen(ltrim($piece))).$sentence.substr($piece, \strlen(rtrim($piece)));
            ++$sentences;
        }

        return new Translation($out, array_map(static fn (Model $m): string => $m->id, $route), $sentences, $tokens,
            round((hrtime(true) - $began) / 1e6, 1), array_sum(array_map(static fn (Bridge $b): int => $b->usage()['loads'], $this->bridges)));
    }

    /**
     * The models a pair goes through.
     *
     * @return list<Model>
     */
    private function route(string $source, string $target): array
    {
        $catalog = $this->models->catalog();
        $toEnglish = $catalog->euronano($this->variant, Model::XX_EN);
        $fromEnglish = $catalog->euronano($this->variant, Model::EN_XX);
        $known = $toEnglish->languages;
        $list = implode(', ', ['en', ...$known]);
        if ('en' !== $target && !\in_array($target, $known, true)) {
            throw new BergamotException(sprintf('cannot write "%s"; languages are %s', $target, $list));
        }
        if ('auto' !== $source && 'en' !== $source && !\in_array($source, $known, true)) {
            throw new BergamotException(sprintf('cannot read "%s"; languages are %s', $source, $list));
        }
        if ($source === $target) {
            throw new BergamotException(sprintf('source and target are both "%s"', $source));
        }
        if ('en' === $target) {
            return [$toEnglish];
        }
        if ('en' === $source) {
            return [$fromEnglish];
        }

        return [$toEnglish, $fromEnglish]; // xx -> yy, and "auto" into anything but English
    }

    private function bridge(Model $model): Bridge
    {
        return $this->bridges[$model->id] ??= new Bridge(
            (string) $this->runtime->bridge(),
            (string) $this->runtime->library(),
            (string) $this->models->path($model),
        );
    }
}
