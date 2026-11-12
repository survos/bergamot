<?php

declare(strict_types=1);

namespace Survos\Bergamot\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Survos\Bergamot\SentenceSplitter;

final class SentenceSplitterTest extends TestCase
{
    /** @param list<string> $sentences */
    #[DataProvider('texts')]
    #[RequiresPhpExtension('intl')]
    public function testSentences(string $locale, string $text, array $sentences): void
    {
        $pieces = (new SentenceSplitter())->split($text, $locale);

        self::assertSame($sentences, array_map('trim', $pieces));
        self::assertSame($text, implode('', $pieces), 'the pieces put together are the text, whitespace included');
    }

    public static function texts(): iterable
    {
        yield 'titles, an ordinal date, a decimal time, lower-case starts' => ['de',
            'Dr. Müller kam am 3. Mai um 15.30 Uhr an. Er sagte: „Das ist z.B. gut.“ Danach ging er! wirklich? ja.',
            ['Dr. Müller kam am 3. Mai um 15.30 Uhr an.', 'Er sagte: „Das ist z.B. gut.“', 'Danach ging er!', 'wirklich?', 'ja.']];
        yield 'a year at the end of a sentence is not an ordinal' => ['de',
            'Das war 1990. Danach kam nichts.', ['Das war 1990.', 'Danach kam nichts.']];
        yield 'French titles and a street abbreviation' => ['fr',
            'M. Dupont habite au 12 av. Victor Hugo. Il est parti... à 8 h 30.',
            ['M. Dupont habite au 12 av. Victor Hugo.', 'Il est parti... à 8 h 30.']];
        yield 'Spanish titles, inverted marks, a dotted country' => ['es',
            'El Sr. García llegó a las 3 p.m. ¿Qué dijo? ¡Nada! Volvió a EE.UU. el lunes.',
            ['El Sr. García llegó a las 3 p.m.', '¿Qué dijo?', '¡Nada!', 'Volvió a EE.UU. el lunes.']];
        yield 'Dutch abbreviations ICU does not know' => ['nl',
            'Dhr. Jansen kwam o.a. met zijn vrouw. Ze aten thuis.', ['Dhr. Jansen kwam o.a. met zijn vrouw.', 'Ze aten thuis.']];
        yield 'line breaks always end a piece, lists and blank lines survive' => ['auto',
            "Erste Zeile ohne Punkt\nZweite Zeile.  Dritte.\n\n- eins\n- zwei",
            ['Erste Zeile ohne Punkt', 'Zweite Zeile.', 'Dritte.', '- eins', '- zwei']];
        yield 'one sentence' => ['en', 'Log out', ['Log out']];
    }

    public function testAnAbbreviationCanBeAdded(): void
    {
        $text = 'Stuur het t.a.v. Jan de Vries. Dank u.';

        self::assertCount(3, (new SentenceSplitter())->split($text, 'nl'));
        self::assertSame(['Stuur het t.a.v. Jan de Vries. ', 'Dank u.'], (new SentenceSplitter(['nl' => ['t.a.v.']]))->split($text, 'nl'));
    }

    public function testEmptyText(): void
    {
        self::assertSame([], (new SentenceSplitter())->split('', 'de'));
        self::assertSame(["  \n"], (new SentenceSplitter())->split("  \n", 'de'));
    }
}
