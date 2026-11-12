<?php

declare(strict_types=1);

namespace Survos\Bergamot;

/**
 * The models this library knows how to fetch, pinned to a revision and to the SHA-256 of every file.
 *
 * Today that is EuroNano (qvac/TranslatePsy-EuroNano on Hugging Face, Apache-2.0): nine European languages and English,
 * one model per direction, in three sizes. Between two non-English languages the translator pivots through English.
 */
final class ModelCatalog
{
    public const EURONANO = 'qvac/TranslatePsy-EuroNano';
    public const EURONANO_REVISION = '3a5e1e4e2f7001ff5cfd79785bef03cf19281b73';
    public const EURONANO_LANGUAGES = ['cs', 'de', 'es', 'fi', 'fr', 'it', 'nl', 'pt', 'sv'];

    /** variant => [directory in the repository, size note]. */
    public const VARIANTS = ['tiny' => 'Tiny', 'base' => 'Base', 'base-memory' => 'BaseMemory'];

    /** "<direction>/<Variant>" => [model bytes, model sha256, vocab bytes, vocab sha256]. */
    private const EURONANO_FILES = [
        'en-xx/Base' => [42993131, '45f8472add63cc9e00950b4711afb37ac3c6b2bc81f1daff592ab497a4cbb61d', 805236, '167fb634e5e65a0beeef3d1961d6f6d8d2c64b0ac6c6db2017fa4bae63e08239'],
        'en-xx/BaseMemory' => [31561963, '6532976b0900332294e3e8e9c16ccd19282aeedac23fcbfce73437e64702b2c3', 805243, '44d03c7e8fbd64771e7ab88c47199b95634555350e859ac7fdcd9de1390a578a'],
        'en-xx/Tiny' => [17141227, '646c87844fd9917e929feed627ec7f21b245f7bfded5442b61dc8b4b1b1e68dd', 805231, 'f76ce75a335872f27c812c7d122051f923565b5111adc36f207a15be4cbc9a51'],
        'xx-en/Base' => [42993131, '50f86c092f4c55055eab7c9052e3c952d38ef601d906d375e6245c0ba129fc2b', 797560, '772276ad54e95628b7ded9a835b6a9eaf08e76b8e754bc57b99bf1cd3d99c138'],
        'xx-en/BaseMemory' => [31561963, 'd47039eec08617973972416ca82f46d6d53bb487a961475d6baf9a80e67fbe35', 797567, '3b776103596574e80910de6cb0f4126f08d00549c7e92dd2eba043909c297932'],
        'xx-en/Tiny' => [17141227, 'f71fe9824c5652e5915d372c2cbbc01e1a48e05e3028bd0331a13a84be886ff1', 797560, 'c40c2a0ea584da6eacc3e643da1255adb156611cdf5932b50b7455594ea6f97b'],
    ];

    /** @return list<Model> */
    public function all(): array
    {
        $models = [];
        foreach (self::VARIANTS as $variant => $directory) {
            foreach ([Model::XX_EN, Model::EN_XX] as $direction) {
                $models[] = $this->euronano($variant, $direction);
            }
        }

        return $models;
    }

    public function euronano(string $variant, string $direction): Model
    {
        $directory = self::VARIANTS[$variant] ?? throw new \InvalidArgumentException(sprintf('Unknown variant "%s"; one of %s', $variant, implode(', ', array_keys(self::VARIANTS))));
        [$modelBytes, $modelSha, $vocabBytes, $vocabSha] = self::EURONANO_FILES["$direction/$directory"]
            ?? throw new \InvalidArgumentException(sprintf('Unknown direction "%s"; "%s" or "%s"', $direction, Model::XX_EN, Model::EN_XX));
        $base = sprintf('https://huggingface.co/%s/resolve/%s/%s/%s/intgemm/', self::EURONANO, self::EURONANO_REVISION, $direction, $directory);

        return new Model(
            id: "euronano/$variant/$direction",
            direction: $direction,
            variant: $variant,
            languages: self::EURONANO_LANGUAGES,
            files: [
                'model.intgemm.alphas.bin' => ['url' => $base.'model.intgemm.alphas.bin', 'sha256' => $modelSha, 'size' => $modelBytes],
                'vocab.spm' => ['url' => $base.'vocab.spm', 'sha256' => $vocabSha, 'size' => $vocabBytes],
            ],
            source: self::EURONANO,
            revision: self::EURONANO_REVISION,
            license: 'Apache-2.0',
        );
    }

    public function get(string $id): Model
    {
        foreach ($this->all() as $model) {
            if ($model->id === $id) {
                return $model;
            }
        }
        throw new \InvalidArgumentException(sprintf('Unknown model "%s"', $id));
    }
}
