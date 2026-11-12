<?php

declare(strict_types=1);

namespace Survos\Bergamot\Tests;

use PHPUnit\Framework\TestCase;
use Survos\Bergamot\BergamotException;
use Survos\Bergamot\ModelManager;
use Survos\Bergamot\Runtime;
use Survos\Bergamot\Translator;

/**
 * Real models, in this process. Needs the native libraries and both "base" models under BERGAMOT_HOME (by default the
 * ai-tools checkout beside the monorepo); the tests that translate are skipped where they are missing.
 */
final class TranslatorTest extends TestCase
{
    public function testIntoEnglishWithoutBeingToldTheLanguage(): void
    {
        $translator = $this->translator('de', 'en');

        $done = $translator->translate("Guten Morgen.\nDas Museum ist geschlossen.", 'de', 'en');
        self::assertSame("Good morning.\nThe museum is closed.", $done->text);
        self::assertSame(['euronano/base/xx-en'], $done->route);
        self::assertSame(2, $done->sentences);

        $auto = $translator->translate('Le musée ferme le lundi.', 'auto', 'en');
        self::assertStringContainsStringIgnoringCase('museum', $auto->text);
    }

    public function testFromEnglishIntoATaggedLanguage(): void
    {
        $translator = $this->translator('en', 'de');

        self::assertSame('Guten Morgen.', $translator->translate('Good morning.', 'en', 'de')->text);
        $french = $translator->translate('Please reset your password.', 'en', 'fr');
        self::assertStringContainsString('mot de passe', $french->text);
        self::assertSame(['euronano/base/en-xx'], $french->route);
    }

    public function testBetweenTwoLanguagesThroughEnglish(): void
    {
        $translator = $this->translator('de', 'fr');

        $done = $translator->translate('Das Museum ist geschlossen.', 'de', 'fr');

        self::assertStringContainsStringIgnoringCase('musée', $done->text);
        self::assertSame(['euronano/base/xx-en', 'euronano/base/en-xx'], $done->route);
    }

    public function testModelsAreLoadedOnceHoweverMuchIsTranslated(): void
    {
        $translator = $this->translator('de', 'fr');

        foreach (['Guten Morgen.', 'Gute Nacht.', 'Danke.'] as $text) {
            $loads = $translator->translate($text, 'de', 'fr')->modelLoads;
        }

        self::assertSame(2, $loads, 'one load per model on the route, not per call');
    }

    public function testWhatItCannotDoIsSaidWithoutLoadingAnything(): void
    {
        $translator = new Translator(new ModelManager('/nonexistent'), new Runtime('/nonexistent'));

        self::assertSame('cannot write "hu"; languages are en, cs, de, es, fi, fr, it, nl, pt, sv', $translator->whyNot('en', 'hu'));
        self::assertStringContainsString('cannot read "ru"', (string) $translator->whyNot('ru', 'en'));
        self::assertStringContainsString('both "de"', (string) $translator->whyNot('de', 'de'));
        self::assertStringContainsString('missing /nonexistent/native/libbergamot_bridge', (string) $translator->whyNot('de', 'en'));
        self::assertFalse($translator->supports('de', 'en'));

        $this->expectException(BergamotException::class);
        $translator->translate('Guten Morgen.', 'de', 'en');
    }

    private function translator(string $source, string $target): Translator
    {
        $home = getenv('BERGAMOT_HOME') ?: \dirname(__DIR__, 4).'/ai-tools/var';
        $translator = new Translator(new ModelManager($home), new Runtime($home));
        if (null !== ($why = $translator->whyNot($source, $target))) {
            self::markTestSkipped("cannot translate $source to $target here: $why");
        }

        return $translator;
    }
}
