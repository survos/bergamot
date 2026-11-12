<?php

declare(strict_types=1);

namespace Survos\Bergamot;

/**
 * Cuts text into sentences for a model that translates one sentence at a time.
 *
 * Uses ICU's sentence rules with its per-language abbreviation list (the locale keyword `ss=standard`), so "Dr. Müller",
 * "M. Dupont" or "El Sr. García" stay in one piece. ICU still breaks in two places that matter here, and both are
 * mended afterwards:
 *   - after an ordinal number in the languages that write ordinals with a dot ("am 3. Mai"),
 *   - after abbreviations its list does not have (a short supplement per language, extendable).
 * The mending only ever joins two pieces. Joining too much hands the model two sentences at once, which it copes with;
 * cutting inside a sentence garbles both halves.
 *
 * The pieces are returned with their whitespace, so implode('', split($text)) is always the text again: a caller
 * translates the trimmed core of each piece and puts the spacing back as it was. Line breaks always end a piece.
 *
 * Without the intl extension it falls back to a plain rule: line breaks, and sentence-ending punctuation followed by a
 * capital letter.
 */
final class SentenceSplitter
{
    /** Languages that write ordinals as a number and a dot ("3. Mai", "1. ledna"). */
    private const ORDINAL_DOT = ['cs', 'da', 'de', 'et', 'fi', 'hr', 'hu', 'is', 'lv', 'nb', 'no', 'pl', 'sk', 'sl', 'sr', 'tr'];

    /** Abbreviations ICU's lists lack, lower case, each ending in its dot. */
    private const ABBREVIATIONS = [
        'nl' => ['dhr.', 'mevr.', 'mw.', 'o.a.', 'd.w.z.', 'bijv.', 'bv.', 'm.b.t.', 'i.p.v.', 'ca.', 'nr.'],
        'de' => ['bzw.', 'ggf.', 'u.a.', 'z.b.', 'd.h.', 'ca.', 'nr.', 'str.', 'evtl.'],
        'fr' => ['av.', 'bd.', 'env.', 'cf.', 'p.ex.', 'n°.'],
        'es' => ['ee.uu.', 'avda.', 'núm.', 'pág.', 'p.ej.'],
        'it' => ['sig.', 'sig.ra', 'dott.', 'prof.', 'ecc.', 'pag.', 'n.'],
        'pt' => ['sr.', 'sra.', 'dr.', 'dra.', 'av.', 'pág.', 'n.º'],
        'sv' => ['bl.a.', 't.ex.', 'd.v.s.', 'o.s.v.', 'ca.', 'nr.'],
    ];

    private const FALLBACK = '/(\r?\n+|(?<=[.!?])[^\S\r\n]+(?=[A-ZÀ-ÖØ-Ý¿¡]))/u';

    /**
     * @param array<string, list<string>> $abbreviations more abbreviations per language, e.g. ['nl' => ['t.a.v.']]
     */
    public function __construct(private readonly array $abbreviations = [])
    {
    }

    /**
     * @param string $locale the language of the text ("de", "pt_BR"), or "auto" when it is not known
     *
     * @return list<string> pieces whose concatenation is exactly $text
     */
    public function split(string $text, string $locale = 'auto'): array
    {
        if ('' === $text) {
            return [];
        }
        $language = strtolower(substr($locale, 0, 2));
        if ('auto' === $locale || !preg_match('/^[a-z]{2}$/', $language)) {
            $language = '';
        }

        return $this->mend($this->cut($text, $language), $language);
    }

    /** @return list<string> */
    private function cut(string $text, string $language): array
    {
        if (!class_exists(\IntlBreakIterator::class)) {
            return array_values(array_filter(preg_split(self::FALLBACK, $text, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [$text], static fn (string $p): bool => '' !== $p));
        }
        $iterator = \IntlBreakIterator::createSentenceInstance('' === $language ? 'root' : $language.'@ss=standard');
        $iterator->setText($text);
        $pieces = [];
        foreach ($iterator->getPartsIterator() as $piece) {
            $pieces[] = $piece;
        }

        return $pieces;
    }

    /**
     * Joins a piece to the next one where the break was an ordinal or an abbreviation, and attaches whitespace-only
     * pieces to their neighbour so that every piece but possibly the first carries text.
     *
     * @param list<string> $pieces
     *
     * @return list<string>
     */
    private function mend(array $pieces, string $language): array
    {
        $abbreviations = array_merge(self::ABBREVIATIONS[$language] ?? [], $this->abbreviations[$language] ?? []);
        $ordinals = \in_array($language, self::ORDINAL_DOT, true);
        $out = [];
        $joinNext = false;
        foreach ($pieces as $piece) {
            if ($joinNext || ([] !== $out && '' === trim($piece))) {
                $out[\count($out) - 1] .= $piece;
            } else {
                $out[] = $piece;
            }
            // ICU leaves a closing quote on the wrong side of the break (« C'est bien. | » Puis ...): hand it back.
            if (\count($out) > 1 && preg_match('/^([»”’"\'\)\]]+)(\s+)(?=\S)/u', $out[\count($out) - 1], $m) && !preg_match('/\R\s*$/u', $out[\count($out) - 2])) {
                $out[\count($out) - 2] .= $m[1].$m[2];
                $out[\count($out) - 1] = substr($out[\count($out) - 1], \strlen($m[0]));
            }
            $last = $out[\count($out) - 1];
            // A line break is always a real break; otherwise look at how the piece ends.
            $joinNext = !preg_match('/\R\s*$/u', $last) && $this->endsMidSentence(rtrim($last), $abbreviations, $ordinals);
        }

        return $out;
    }

    /** @param list<string> $abbreviations */
    private function endsMidSentence(string $piece, array $abbreviations, bool $ordinals): bool
    {
        if ('' === $piece) {
            return false;
        }
        // "am 3." or "den 21.": a day or a small ordinal, not a year or a quantity ending a sentence.
        if ($ordinals && preg_match('/(?<![\d.,])\d{1,2}\.$/u', $piece)) {
            return true;
        }
        $lower = mb_strtolower($piece);
        foreach ($abbreviations as $abbreviation) {
            if (str_ends_with($lower, $abbreviation)) {
                $before = mb_substr($lower, 0, -mb_strlen($abbreviation));
                if ('' === $before || !preg_match('/[\p{L}\p{N}]$/u', $before)) {
                    return true; // the abbreviation is a word of its own, not the tail of a longer one
                }
            }
        }

        return false;
    }
}
