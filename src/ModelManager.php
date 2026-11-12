<?php

declare(strict_types=1);

namespace Survos\Bergamot;

/**
 * Keeps models on disk under <home>/models/<model id>/: what is installed, fetching what is not, checking what is.
 *
 * A model counts as installed when all its files are there with the right size. install() downloads to a temporary
 * name, checks the SHA-256 against the catalog, and only then moves the file into place, so a model directory never
 * holds a half-written or wrong file. Models are looked for in every directory in $searchPaths (first match wins) and
 * installed into the first one; that lets a model already fetched by another tool be used where it lies.
 */
final class ModelManager
{
    /** @var list<string> */
    private readonly array $searchPaths;

    /**
     * @param string               $home    where models are installed: <home>/models/<model id>/
     * @param array<string,string> $aliases model id => an existing directory holding that model's files
     */
    public function __construct(
        public readonly string $home,
        private readonly ModelCatalog $catalog = new ModelCatalog(),
        private readonly array $aliases = [],
    ) {
        $this->searchPaths = [rtrim($home, '/').'/models'];
    }

    public function catalog(): ModelCatalog
    {
        return $this->catalog;
    }

    /** The directory holding the model's files, or null when it is not installed. */
    public function path(Model $model): ?string
    {
        $candidates = array_map(static fn (string $root): string => $root.'/'.$model->id, $this->searchPaths);
        // Where ai-tools' prepare script puts the same files.
        $candidates[] = sprintf('%s/euronano-native/%s/%s', $this->searchPaths[0], $model->variant, $model->direction);
        if (isset($this->aliases[$model->id])) {
            array_unshift($candidates, rtrim($this->aliases[$model->id], '/'));
        }
        foreach ($candidates as $directory) {
            if ($this->complete($model, $directory)) {
                return $directory;
            }
        }

        return null;
    }

    public function isInstalled(Model $model): bool
    {
        return null !== $this->path($model);
    }

    /** @return list<Model> */
    public function installed(): array
    {
        return array_values(array_filter($this->catalog->all(), $this->isInstalled(...)));
    }

    /**
     * Fetches whatever files of the model are missing. Returns the model's directory.
     *
     * @param (callable(string $file, int $done, int $total): void)|null $progress
     */
    public function install(Model $model, ?callable $progress = null): string
    {
        if (null !== ($existing = $this->path($model))) {
            return $existing;
        }
        $directory = $this->searchPaths[0].'/'.$model->id;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create $directory");
        }
        foreach ($model->files as $name => $file) {
            $target = $directory.'/'.$name;
            if (is_file($target) && filesize($target) === $file['size'] && hash_file('sha256', $target) === $file['sha256']) {
                continue;
            }
            $partial = $target.'.part';
            $this->download($file['url'], $partial, $name, $file['size'], $progress);
            $actual = hash_file('sha256', $partial);
            if ($actual !== $file['sha256']) {
                unlink($partial);
                throw new \RuntimeException(sprintf('%s: checksum mismatch for %s (expected %s, got %s); nothing was installed', $model->id, $name, $file['sha256'], $actual));
            }
            rename($partial, $target);
        }
        file_put_contents($directory.'/model.json', json_encode([
            'id' => $model->id, 'source' => $model->source, 'revision' => $model->revision, 'license' => $model->license,
            'direction' => $model->direction, 'languages' => $model->languages, 'installedAt' => gmdate('c'),
            'files' => array_map(static fn (array $f): array => ['sha256' => $f['sha256'], 'size' => $f['size']], $model->files),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");

        return $directory;
    }

    /**
     * Re-hashes an installed model's files.
     *
     * @return array<string, bool> file name => intact
     */
    public function verify(Model $model): array
    {
        $directory = $this->path($model) ?? throw new \RuntimeException($model->id.' is not installed');
        $result = [];
        foreach ($model->files as $name => $file) {
            $result[$name] = hash_file('sha256', $directory.'/'.$name) === $file['sha256'];
        }

        return $result;
    }

    /** Removes a model this manager installed. A model found through an alias is someone else's and is left alone. */
    public function remove(Model $model): bool
    {
        $directory = $this->searchPaths[0].'/'.$model->id;
        if (!is_dir($directory)) {
            return false;
        }
        foreach ([...array_keys($model->files), 'model.json'] as $name) {
            is_file($directory.'/'.$name) && unlink($directory.'/'.$name);
        }

        return @rmdir($directory);
    }

    private function complete(Model $model, string $directory): bool
    {
        foreach ($model->files as $name => $file) {
            if (!is_file($directory.'/'.$name) || filesize($directory.'/'.$name) !== $file['size']) {
                return false;
            }
        }

        return true;
    }

    /** @param (callable(string, int, int): void)|null $progress */
    private function download(string $url, string $to, string $name, int $total, ?callable $progress): void
    {
        $context = stream_context_create(['http' => ['follow_location' => 1, 'timeout' => 120, 'user_agent' => 'bergamot-php']]);
        $in = @fopen($url, 'r', false, $context);
        if (false === $in) {
            throw new \RuntimeException("Cannot download $url");
        }
        $out = fopen($to, 'w');
        if (false === $out) {
            fclose($in);
            throw new \RuntimeException("Cannot write $to");
        }
        $done = 0;
        try {
            while (!feof($in)) {
                $chunk = fread($in, 1 << 20);
                if (false === $chunk) {
                    throw new \RuntimeException("Download of $url was interrupted");
                }
                fwrite($out, $chunk);
                $done += \strlen($chunk);
                null !== $progress && $progress($name, $done, $total);
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }
}
