<?php

declare(strict_types=1);

namespace App\Services\AntiCheat;

/**
 * Deterministic PRNG mathematically identical to PRNG.js.
 * Uses FNV-1a Murmur-style mixing + 32-bit Mulberry32.
 */
class DeterministicPrng
{
    public int $state;

    protected string $originalSeed;

    public function __construct(string $seed = '')
    {
        $this->originalSeed = $seed;
        $this->state = $this->hashSeed($seed);
    }

    protected function imul(int $a, int $b): int
    {
        $a = $a & 0xFFFFFFFF;
        $b = $b & 0xFFFFFFFF;
        $aHigh = ($a >> 16) & 0xFFFF;
        $aLow = $a & 0xFFFF;
        $bHigh = ($b >> 16) & 0xFFFF;
        $bLow = $b & 0xFFFF;

        $res = ($aLow * $bLow + ((($aHigh * $bLow + $aLow * $bHigh) & 0xFFFF) << 16)) & 0xFFFFFFFF;
        if ($res & 0x80000000) {
            return $res - 0x100000000;
        }

        return $res;
    }

    protected function uShiftRight(int $val, int $shift): int
    {
        $uVal = $val & 0xFFFFFFFF;

        return ($uVal >> $shift) & 0xFFFFFFFF;
    }

    public function hashSeed(string $seed): int
    {
        if ($seed === '') {
            return 0x12345678;
        }

        $h = 0x811C9DC5;
        if ($h & 0x80000000) {
            $h = $h - 0x100000000;
        }

        $len = strlen($seed);
        for ($i = 0; $i < $len; $i++) {
            $h ^= ord($seed[$i]);
            $h = $this->imul($h, 0x01000193);
        }

        $h ^= $this->uShiftRight($h, 16);
        $h = $this->imul($h, 0x85EBCA6B);
        $h ^= $this->uShiftRight($h, 13);
        $h = $this->imul($h, -1028477387);
        $h ^= $this->uShiftRight($h, 16);

        $res = $h & 0xFFFFFFFF;

        return $res !== 0 ? $res : 1;
    }

    public function next(): float
    {
        $this->state = ($this->state + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $this->state;
        if ($t & 0x80000000) {
            $t = $t - 0x100000000;
        }

        $term1 = $t ^ $this->uShiftRight($t, 15);
        $term2 = $t | 1;
        $t = $this->imul($term1, $term2);

        $term3 = $t ^ $this->uShiftRight($t, 7);
        $term4 = $t | 61;
        $part = ($t + $this->imul($term3, $term4)) & 0xFFFFFFFF;
        if ($part & 0x80000000) {
            $part = $part - 0x100000000;
        }
        $t ^= $part;

        $top = ($t ^ $this->uShiftRight($t, 14)) & 0xFFFFFFFF;

        return $top / 4294967296.0;
    }

    public function nextFloat(): float
    {
        return $this->next();
    }

    public function nextInt(int $min, int $max): int
    {
        if ($min >= $max) {
            return $min;
        }

        return (int) floor($this->next() * ($max - $min + 1)) + $min;
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $array
     * @return T|null
     */
    public function choice(array $array): mixed
    {
        if (empty($array)) {
            return null;
        }

        $values = array_values($array);
        $index = $this->nextInt(0, count($values) - 1);

        return $values[$index];
    }

    public function boolean(float $chance = 0.5): bool
    {
        return $this->next() < $chance;
    }

    public function getSeed(): string
    {
        return $this->originalSeed;
    }
}
