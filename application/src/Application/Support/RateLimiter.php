<?php

declare(strict_types=1);

namespace Satrak\Application\Support;

/**
 * Rate-limiter simple basado en archivos (sin tocar el esquema de la base).
 *
 * Cuenta intentos por clave (ej. email+IP) dentro de una ventana; si se supera
 * el máximo, bloquea hasta que la ventana expira. Pensado para login.
 */
final class RateLimiter
{
    public function __construct(
        private string $storageDir,
        private int $maxAttempts = 5,
        private int $windowSeconds = 900   // 15 min
    ) {
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0775, true);
        }
    }

    private function pathFor(string $key): string
    {
        return $this->storageDir . '/' . hash('sha256', $key) . '.json';
    }

    /**
     * @return array{count:int,first:int}
     */
    private function read(string $key): array
    {
        $file = $this->pathFor($key);
        if (!is_file($file)) {
            return ['count' => 0, 'first' => time()];
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !isset($data['count'], $data['first'])) {
            return ['count' => 0, 'first' => time()];
        }
        // Ventana expirada => reset.
        if (time() - (int) $data['first'] > $this->windowSeconds) {
            return ['count' => 0, 'first' => time()];
        }

        return ['count' => (int) $data['count'], 'first' => (int) $data['first']];
    }

    public function tooManyAttempts(string $key): bool
    {
        return $this->read($key)['count'] >= $this->maxAttempts;
    }

    /**
     * Segundos restantes de bloqueo (0 si no está bloqueado).
     */
    public function retryAfter(string $key): int
    {
        $state = $this->read($key);
        if ($state['count'] < $this->maxAttempts) {
            return 0;
        }

        return max(0, $this->windowSeconds - (time() - $state['first']));
    }

    public function hit(string $key): void
    {
        $state = $this->read($key);
        $state['count']++;
        @file_put_contents($this->pathFor($key), json_encode($state), LOCK_EX);
    }

    public function clear(string $key): void
    {
        @unlink($this->pathFor($key));
    }
}
