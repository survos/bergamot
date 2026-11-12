# survos/bergamot

Machine translation inside the PHP process. A Bergamot (Marian) model is called through FFI: no HTTP service, no
Python, a sentence in a few milliseconds.

```php
use Survos\Bergamot\{ModelCatalog, ModelManager, Model, Runtime, Translator};

$home = '/var/lib/bergamot';                     // holds native/ and models/
$models = new ModelManager($home);
$models->install($models->catalog()->euronano('base', Model::XX_EN));   // 44 MB, checksum-verified
$models->install($models->catalog()->euronano('base', Model::EN_XX));

$translator = new Translator($models, new Runtime($home));
$translator->translate('Das Museum ist montags geschlossen.', 'de', 'en')->text;   // The museum is closed on Mondays.
$translator->translate('Please reset your password.', 'en', 'fr')->text;           // Veuillez réinitialiser votre mot de passe.
$translator->translate('Das Museum ist geschlossen.', 'de', 'fr')->route;          // [xx-en, en-xx]: pivots through English
$translator->whyNot('en', 'hu');                                                   // cannot write "hu"; languages are ...
```

## What is in it

| Class | Does |
|---|---|
| `Translator` | Text in, text out. Picks the model(s) for a language pair, splits into sentences, translates each, restores the spacing. `whyNot()` says whether a pair can be done here without loading anything. |
| `SentenceSplitter` | ICU sentence rules with per-language abbreviation lists, mended for ordinals ("am 3. Mai"), extra abbreviations and closing quotes. Lossless: the pieces concatenate to the input. |
| `ModelCatalog`, `Model` | The models that can be fetched, pinned to a revision and a SHA-256 per file. |
| `ModelManager` | Installed / install / verify / remove under `<home>/models/<model id>/`. A download is hashed before it is moved into place. |
| `Runtime` | Finds the two native libraries and says what is missing. |
| `Bridge` | The FFI calls. One loaded model. |

## Models

EuroNano ([qvac/TranslatePsy-EuroNano](https://huggingface.co/qvac/TranslatePsy-EuroNano), Apache-2.0): Czech, Dutch,
Finnish, French, German, Italian, Portuguese, Spanish and Swedish, to and from English, one model per direction, in
three sizes (`tiny` 18 MB, `base-memory` 32 MB, `base` 44 MB per direction). Into English the model reads any of its
languages without being told which, so the source may be `auto`. From English each sentence is tagged with the target
(`##DE ...`). Two non-English languages go through English.

Limits that come with the models: a sentence over 120 model tokens is refused, as is output containing unknown-token
markers (`BergamotException`); decoding is greedy; there is no confidence score.

## Native libraries

Under `<home>/native/`:

- `libbergamot_bridge.{dylib,so}`: a small C bridge PHP can call. Source in `bridge/`; build with
  `c++ -std=c++17 -O3 -shared -fPIC bridge/bergamot_bridge.cpp -o libbergamot_bridge.so` (`-dynamiclib` on macOS).
- the Bergamot runtime: the platform's prebuilt library from the npm package `@qvac/translation-nmtcpp` 10.0.0
  (Apache-2.0), copied as `libeuronano-qvac-10.0.0.{dylib,so}`. The package ships macOS, Linux and Windows builds.

Verified on macOS arm64. The Linux build of the bridge has not been tried yet.

PHP needs the FFI extension, and `ffi.enable=1` under a web server (the command line always has FFI). Models load on
first use, about 30 ms each, and stay loaded for the life of the `Translator`: keep it for the life of the process
(a FrankenPHP worker, a queue consumer) and that is one load.
