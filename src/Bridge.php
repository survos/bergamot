<?php

declare(strict_types=1);

namespace Survos\Bergamot;

/**
 * One loaded model: sentences in, sentences out, through FFI.
 *
 * The model is loaded on first use (about 30 ms) and stays loaded until this object is destroyed. Keep it for the
 * life of the process: in a FrankenPHP worker or a queue consumer that is one load for everything that follows.
 */
final class Bridge
{
    private const CDEF = <<<'C'
        const char* bn_error(void);
        void* bn_load(const char* library, const char* model, const char* vocab);
        char* bn_translate(void* handle, const char* input, double* seconds, int* tokens);
        int bn_workers(void* handle);
        void bn_string_free(char* text);
        void bn_free(void* handle);
        C;

    private ?\FFI $ffi = null;
    private ?\FFI\CData $handle = null;
    private int $loads = 0;
    private int $translations = 0;

    public function __construct(
        private readonly string $bridgeLibrary,
        private readonly string $runtimeLibrary,
        private readonly string $modelDirectory,
    ) {
    }

    public function __destruct()
    {
        if (null !== $this->handle && null !== $this->ffi) {
            $this->ffi->bn_free($this->handle);
        }
    }

    /** @return array{loads: int, translations: int} */
    public function usage(): array
    {
        return ['loads' => $this->loads, 'translations' => $this->translations];
    }

    /** @return array{text: string, tokens: int, seconds: float} */
    public function translate(string $sentence): array
    {
        $ffi = $this->ffi();
        if (null === $this->handle) {
            throw new BergamotException('Model is not loaded');
        }
        $seconds = $ffi->new('double');
        $tokens = $ffi->new('int');
        $pointer = $ffi->bn_translate($this->handle, $sentence, \FFI::addr($seconds), \FFI::addr($tokens));
        if (null === $pointer) {
            throw new BergamotException('Inference failed: '.$ffi->bn_error());
        }
        ++$this->translations;
        try {
            $text = \FFI::string($pointer);
        } finally {
            $ffi->bn_string_free($pointer);
        }

        // The two numbers the engine wrote through the pointers, read back from their bytes.
        return [
            'text' => $text,
            'tokens' => (int) (unpack('l', \FFI::string(\FFI::addr($tokens), 4))[1] ?? 0),
            'seconds' => (float) (unpack('d', \FFI::string(\FFI::addr($seconds), 8))[1] ?? 0.0),
        ];
    }

    private function ffi(): \FFI
    {
        if (null !== $this->ffi) {
            return $this->ffi;
        }
        $ffi = \FFI::cdef(self::CDEF, $this->bridgeLibrary);
        $handle = $ffi->bn_load($this->runtimeLibrary, $this->modelDirectory.'/model.intgemm.alphas.bin', $this->modelDirectory.'/vocab.spm');
        if (null === $handle) {
            throw new BergamotException('Model initialization failed: '.$ffi->bn_error());
        }
        $this->handle = $handle;
        ++$this->loads;

        return $this->ffi = $ffi;
    }
}
