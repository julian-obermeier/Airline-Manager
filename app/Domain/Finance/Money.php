<?php

namespace App\Domain\Finance;

use InvalidArgumentException;

final readonly class Money
{
    private function __construct(
        public int $minor,
        public string $currency,
    ) {
    }

    public static function fromMinor(int $minor, string $currency): self
    {
        $currency = strtoupper(trim($currency));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Currency must be a three-letter ISO-style code.');
        }

        return new self($minor, $currency);
    }

    public static function zero(string $currency): self
    {
        return self::fromMinor(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(sprintf(
                'Cannot combine %s with %s.',
                $this->currency,
                $other->currency,
            ));
        }
    }
}
