<?php

declare(strict_types=1);

namespace Wmos\Domain;

/** Integer-exact monetary value. Currency conversion is deliberately explicit. */
final readonly class Money
{
    public function __construct(public int $minor, public string $currency, public int $exponent = 2)
    {
        if (!preg_match('/^[A-Z]{3}$/D', $currency) || $exponent < 0 || $exponent > 6 || $minor === PHP_INT_MIN) {
            throw new \InvalidArgumentException('Invalid monetary value.');
        }
    }

    public static function fromDecimal(string $value, string $currency, int $exponent = 2): self
    {
        if ($exponent < 0 || $exponent > 6 || !preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $value, $m)) {
            throw new \InvalidArgumentException('Invalid decimal monetary value.');
        }
        $fraction = $m[3] ?? '';
        if (strlen($fraction) > $exponent && trim(substr($fraction, $exponent), '0') !== '') {
            throw new \InvalidArgumentException('Amount exceeds currency precision.');
        }
        $digits = ltrim($m[2] . str_pad(substr($fraction, 0, $exponent), $exponent, '0'), '0');
        $digits = $digits === '' ? '0' : $digits;
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new \OverflowException('Monetary value exceeds integer range.');
        }
        return new self(($m[1] === '-' ? -1 : 1) * (int) $digits, $currency, $exponent);
    }

    public function add(self $other): self
    {
        if ($other->currency !== $this->currency || $other->exponent !== $this->exponent) {
            throw new \InvalidArgumentException('Currencies or exponents differ.');
        }
        if (($other->minor > 0 && $this->minor > PHP_INT_MAX - $other->minor) || ($other->minor < 0 && $this->minor < -PHP_INT_MAX - $other->minor)) {
            throw new \OverflowException('Monetary arithmetic overflow.');
        }
        return new self($this->minor + $other->minor, $this->currency, $this->exponent);
    }

    /** Largest remainder allocation; key ordering provides deterministic ties. */
    public function allocate(array $weights): array
    {
        if ($weights === [] || count($weights) > 1000) {
            throw new \InvalidArgumentException('Allocation requires a bounded set of weights.');
        }
        $total = 0;
        foreach ($weights as $weight) {
            if (!is_int($weight) || $weight < 0 || $weight > 1000000) {
                throw new \InvalidArgumentException('Weights must be nonnegative integer quanta.');
            }
            $total += $weight;
        }
        if ($total < 1 || $total > 1000000) {
            throw new \InvalidArgumentException('Allocation weight total must be 1..1000000.');
        }
        $amount = abs($this->minor);
        $quotient = intdiv($amount, $total);
        $mod = $amount % $total;
        $allocated = [];
        $remainders = [];
        $sum = 0;
        foreach ($weights as $key => $weight) {
            $product = $mod * $weight; // <= 10^12; quotient*weight cannot exceed amount.
            $allocated[$key] = $quotient * $weight + intdiv($product, $total);
            $sum += $allocated[$key];
            $remainders[$key] = $product % $total;
        }
        $keys = array_keys($weights);
        usort($keys, static fn($a, $b): int => ($remainders[$b] <=> $remainders[$a]) ?: strcmp((string) $a, (string) $b));
        for ($i = 0; $i < $amount - $sum; ++$i) {
            ++$allocated[$keys[$i]];
        }
        foreach ($allocated as $key => $minor) {
            $allocated[$key] = new self($this->minor < 0 ? -$minor : $minor, $this->currency, $this->exponent);
        }
        return $allocated;
    }

    public function toArray(): array
    {
        return ['minor' => (string) $this->minor, 'currency' => $this->currency, 'exponent' => $this->exponent];
    }
}
