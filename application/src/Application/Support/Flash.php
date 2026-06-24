<?php

declare(strict_types=1);

namespace Satrak\Application\Support;

/**
 * Mensajes flash de un solo uso (success / error / info / warning) en sesión.
 */
final class Flash
{
    private const KEY = '_flash';

    public function add(string $type, string $message): void
    {
        $_SESSION[self::KEY][] = ['type' => $type, 'message' => $message];
    }

    public function success(string $m): void { $this->add('success', $m); }
    public function error(string $m): void   { $this->add('error', $m); }
    public function info(string $m): void    { $this->add('info', $m); }
    public function warning(string $m): void { $this->add('warning', $m); }

    /**
     * Devuelve y limpia los mensajes acumulados.
     *
     * @return array<int,array{type:string,message:string}>
     */
    public function consume(): array
    {
        $items = $_SESSION[self::KEY] ?? [];
        unset($_SESSION[self::KEY]);

        return $items;
    }
}
