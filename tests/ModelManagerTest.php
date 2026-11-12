<?php

declare(strict_types=1);

namespace Survos\Bergamot\Tests;

use PHPUnit\Framework\TestCase;
use Survos\Bergamot\Model;
use Survos\Bergamot\ModelCatalog;
use Survos\Bergamot\ModelManager;

/** Installing, verifying and removing a model, against files on disk instead of a download. */
final class ModelManagerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/bergamot-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/published', 0777, true);
        file_put_contents($this->dir.'/published/model.intgemm.alphas.bin', str_repeat('m', 5000));
        file_put_contents($this->dir.'/published/vocab.spm', 'vocabulary');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testTheCatalogPinsEveryFileOfEveryModel(): void
    {
        $models = (new ModelCatalog())->all();

        self::assertCount(6, $models, 'three sizes, two directions');
        foreach ($models as $model) {
            self::assertCount(9, $model->languages);
            foreach ($model->files as $file) {
                self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $file['sha256']);
                self::assertStringStartsWith('https://huggingface.co/qvac/TranslatePsy-EuroNano/resolve/'.ModelCatalog::EURONANO_REVISION.'/', $file['url']);
            }
        }
        self::assertSame('##DE ', (new ModelCatalog())->euronano('base', Model::EN_XX)->tagFor('de'));
        self::assertSame('', (new ModelCatalog())->euronano('base', Model::XX_EN)->tagFor('en'));
    }

    public function testInstallVerifyRemove(): void
    {
        $manager = new ModelManager($this->dir.'/home');
        $model = $this->model();
        self::assertFalse($manager->isInstalled($model));

        $seen = [];
        $path = $manager->install($model, function (string $file, int $done, int $total) use (&$seen): void { $seen[$file] = [$done, $total]; });

        self::assertSame($this->dir.'/home/models/test/small/xx-en', $path);
        self::assertTrue($manager->isInstalled($model));
        self::assertSame(['model.intgemm.alphas.bin' => [5000, 5000], 'vocab.spm' => [10, 10]], $seen);
        self::assertSame(['model.intgemm.alphas.bin' => true, 'vocab.spm' => true], $manager->verify($model));
        self::assertSame('test/small/xx-en', json_decode((string) file_get_contents($path.'/model.json'), true)['id']);

        file_put_contents($path.'/vocab.spm', 'VOCABULARY'); // same size, different bytes
        self::assertSame(['model.intgemm.alphas.bin' => true, 'vocab.spm' => false], $manager->verify($model));

        self::assertTrue($manager->remove($model));
        self::assertFalse($manager->isInstalled($model));
    }

    public function testAFileWithTheWrongChecksumIsNotInstalled(): void
    {
        $manager = new ModelManager($this->dir.'/home');
        $model = $this->model(vocabSha: str_repeat('0', 64));

        try {
            $manager->install($model);
            self::fail('expected a checksum mismatch');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('checksum mismatch for vocab.spm', $e->getMessage());
        }
        self::assertFalse($manager->isInstalled($model));
        self::assertFileDoesNotExist($this->dir.'/home/models/test/small/xx-en/vocab.spm');
        self::assertFileDoesNotExist($this->dir.'/home/models/test/small/xx-en/vocab.spm.part');
    }

    public function testAModelFetchedByAiToolsIsFoundWhereItLies(): void
    {
        $legacy = $this->dir.'/home/models/euronano-native/tiny/xx-en';
        mkdir($legacy, 0777, true);
        $model = (new ModelCatalog())->euronano('tiny', Model::XX_EN);
        foreach ($model->files as $name => $file) {
            $handle = fopen($legacy.'/'.$name, 'w');
            ftruncate($handle, $file['size']);
            fclose($handle);
        }

        self::assertSame($legacy, (new ModelManager($this->dir.'/home'))->path($model));
    }

    private function model(?string $vocabSha = null): Model
    {
        $published = $this->dir.'/published/';

        return new Model('test/small/xx-en', Model::XX_EN, 'small', ['de'], [
            'model.intgemm.alphas.bin' => ['url' => 'file://'.$published.'model.intgemm.alphas.bin', 'sha256' => hash('sha256', str_repeat('m', 5000)), 'size' => 5000],
            'vocab.spm' => ['url' => 'file://'.$published.'vocab.spm', 'sha256' => $vocabSha ?? hash('sha256', 'vocabulary'), 'size' => 10],
        ], 'test', 'rev', 'MIT');
    }
}
