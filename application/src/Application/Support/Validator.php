<?php

declare(strict_types=1);

namespace Satrak\Application\Support;

/**
 * Validador simple acumulador de errores. Server-side, sin dependencias.
 *
 *   $v = new Validator($data);
 *   $v->required('email')->email('email');
 *   if ($v->fails()) { ... $v->errors() ... }
 */
final class Validator
{
    /** @var array<string,mixed> */
    private array $data;
    /** @var array<string,string> campo => primer error */
    private array $errors = [];

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    private function value(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    private function fail(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    public function required(string $field, ?string $label = null): self
    {
        $v = $this->value($field);
        if ($v === null || (is_string($v) && trim($v) === '')) {
            $this->fail($field, ($label ?? $field) . ' es obligatorio.');
        }

        return $this;
    }

    public function email(string $field): self
    {
        $v = $this->value($field);
        if (is_string($v) && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->fail($field, 'Email inválido.');
        }

        return $this;
    }

    public function minLength(string $field, int $min, ?string $label = null): self
    {
        $v = $this->value($field);
        if (is_string($v) && mb_strlen($v) < $min) {
            $this->fail($field, ($label ?? $field) . " debe tener al menos {$min} caracteres.");
        }

        return $this;
    }

    public function maxLength(string $field, int $max, ?string $label = null): self
    {
        $v = $this->value($field);
        if (is_string($v) && mb_strlen($v) > $max) {
            $this->fail($field, ($label ?? $field) . " no puede superar {$max} caracteres.");
        }

        return $this;
    }

    public function matches(string $field, string $other, string $message): self
    {
        if (($this->value($field)) !== ($this->value($other))) {
            $this->fail($field, $message);
        }

        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
