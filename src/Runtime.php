<?php

declare(strict_types=1);

namespace Survos\Bergamot;

/**
 * The two native libraries a model needs, under <home>/native/:
 *
 *   libbergamot_bridge.{dylib,so}   a small C bridge (bn_load, bn_translate, bn_error, bn_workers, bn_string_free,
 *                                   bn_free) that PHP can call through FFI; built from bridge/bergamot_bridge.cpp
 *   libeuronano-qvac-*.{dylib,so}   the Bergamot runtime itself: the platform's prebuilt library from the npm package
 *
 *                                   @qvac/translation-nmtcpp (Apache-2.0), which ships macOS, Linux and Windows builds
 *
 * This class only finds them and says what is missing; it does not build or download them.
 */
final class Runtime
{
    public function __construct(public readonly string $home)
    {
    }

    public function bridge(): ?string
    {
        return $this->first(['libbergamot_bridge.dylib', 'libbergamot_bridge.so']);
    }

    public function library(): ?string
    {
        return $this->first(['libeuronano-qvac-*.dylib', 'libeuronano-qvac-*.so', 'qvac__translation-nmtcpp.bare']);
    }

    /** Why translation cannot run in this PHP on this machine; null when it can. */
    public function unavailableBecause(): ?string
    {
        if ('' === $this->home) {
            return 'no home directory configured';
        }
        if (!\extension_loaded('ffi')) {
            return 'PHP has no FFI extension';
        }
        if ('cli' !== \PHP_SAPI && !\in_array(strtolower((string) \ini_get('ffi.enable')), ['1', 'true', 'on'], true)) {
            return 'ffi.enable is not on for this server (php.ini)';
        }
        if (null === $this->bridge()) {
            return 'missing '.$this->home.'/native/libbergamot_bridge.{dylib,so}';
        }
        if (null === $this->library()) {
            return 'missing the Bergamot runtime library in '.$this->home.'/native/';
        }

        return null;
    }

    /** @param list<string> $patterns */
    private function first(array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            $found = glob($this->home.'/native/'.$pattern) ?: [];
            if ([] !== $found) {
                sort($found);

                return end($found);
            }
        }

        return null;
    }
}
