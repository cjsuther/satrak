<?php
/**
 * Maneja el envío del formulario de contacto:
 * valida, anti-spam, sanitiza, persiste (opcional) y envía mail.
 * Responde JSON si la petición es fetch; si no, redirige (degradación sin JS).
 */
class ContactController
{
    private bool $wantsJson;

    public function submit(): void
    {
        $this->wantsJson = $this->expectsJson();

        // 1) CSRF
        if (!csrf_verify($_POST['_token'] ?? null)) {
            $this->fail(['_token' => 'Token inválido. Recargá la página e intentá de nuevo.'], 419);
            return;
        }

        // 2) Honeypot: si viene relleno, simulamos éxito y descartamos.
        if (!empty($_POST['website'])) {
            $this->ok();
            return;
        }

        // 3) Rate-limit simple por sesión.
        if ($this->rateLimited()) {
            $this->fail(['general' => 'Demasiados envíos. Esperá un momento e intentá de nuevo.'], 429);
            return;
        }

        // 4) Validación + sanitización
        [$data, $errors] = $this->validate($_POST);
        if ($errors) {
            $this->fail($errors, 422);
            return;
        }

        // 5) Persistencia opcional en DB
        $this->maybeStore($data);

        // 6) Envío de mail (garantía principal)
        $sent = $this->sendMail($data);
        if (!$sent) {
            $this->fail([
                'general' => 'No pudimos enviar tu consulta en este momento. Escribinos por WhatsApp y te respondemos enseguida.',
            ], 500);
            return;
        }

        $_SESSION['_last_submit'] = time();
        $this->ok();
    }

    /** Valida y sanitiza. Devuelve [data, errors]. */
    private function validate(array $input): array
    {
        $errors = [];

        $nombre = trim((string) ($input['nombre'] ?? ''));
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 80) {
            $errors['nombre'] = 'Ingresá tu nombre y apellido (2 a 80 caracteres).';
        }

        $email = trim((string) ($input['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) {
            $errors['email'] = 'Ingresá un email válido.';
        }

        $telefono = trim((string) ($input['telefono'] ?? ''));
        $soloDigitos = preg_replace('/\D/', '', $telefono);
        if (strlen($soloDigitos) < 6 || strlen($soloDigitos) > 20) {
            $errors['telefono'] = 'Ingresá un teléfono válido (6 a 20 dígitos).';
        }

        $empresa = trim((string) ($input['empresa'] ?? ''));
        if (mb_strlen($empresa) > 100) {
            $errors['empresa'] = 'La empresa no puede superar los 100 caracteres.';
        }

        $servicio = (string) ($input['servicio'] ?? '');
        if (!in_array($servicio, ['vehiculos', 'personal', 'ambos'], true)) {
            $errors['servicio'] = 'Elegí un servicio de interés.';
        }

        $unidades = (string) ($input['unidades'] ?? '');
        if ($unidades !== '' && !in_array($unidades, ['1', '2-10', '11-50', '50+'], true)) {
            $errors['unidades'] = 'Cantidad de unidades inválida.';
        }

        $mensaje = trim((string) ($input['mensaje'] ?? ''));
        if (mb_strlen($mensaje) > 1000) {
            $errors['mensaje'] = 'El mensaje no puede superar los 1000 caracteres.';
        }

        $data = [
            'nombre'     => $nombre,
            'email'      => $email,
            'telefono'   => $telefono,
            'empresa'    => $empresa,
            'servicio'   => $servicio,
            'unidades'   => $unidades,
            'mensaje'    => $mensaje,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ];

        return [$data, $errors];
    }

    /** Rate-limit: máximo 1 envío cada 30 s y 5 por sesión. */
    private function rateLimited(): bool
    {
        $now = time();
        $last = $_SESSION['_last_submit'] ?? 0;
        if ($now - $last < 30) {
            return true;
        }
        $_SESSION['_submit_count'] = ($_SESSION['_submit_count'] ?? 0) + 1;
        return $_SESSION['_submit_count'] > 5;
    }

    /** Inserta el lead si hay DB configurada. Silencioso ante fallo (el mail es la garantía). */
    private function maybeStore(array $data): void
    {
        $db = config('db');
        if (empty($db['host']) || empty($db['name'])) {
            return;
        }
        try {
            $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
            $pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $stmt = $pdo->prepare(
                'INSERT INTO leads (nombre, email, telefono, empresa, servicio, unidades, mensaje, ip, user_agent)
                 VALUES (:nombre, :email, :telefono, :empresa, :servicio, :unidades, :mensaje, :ip, :user_agent)'
            );
            $stmt->execute([
                ':nombre'     => $data['nombre'],
                ':email'      => $data['email'],
                ':telefono'   => $data['telefono'],
                ':empresa'    => $data['empresa'] ?: null,
                ':servicio'   => $data['servicio'],
                ':unidades'   => $data['unidades'] ?: null,
                ':mensaje'    => $data['mensaje'] ?: null,
                ':ip'         => $data['ip'],
                ':user_agent' => $data['user_agent'],
            ]);
        } catch (Throwable $e) {
            $this->logError('DB lead insert failed: ' . $e->getMessage());
        }
    }

    /** Envía el mail vía PHPMailer (SMTP). Devuelve true si se envió. */
    private function sendMail(array $data): bool
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $smtp = config('smtp');
        $to   = (string) config('contact.to_email');

        $servicios = ['vehiculos' => 'Vehículos', 'personal' => 'Personal', 'ambos' => 'Ambos'];
        $servicioLabel = $servicios[$data['servicio']] ?? $data['servicio'];

        $cuerpo = "Nuevo lead desde satrak.online\n\n"
            . "Nombre: {$data['nombre']}\n"
            . "Email: {$data['email']}\n"
            . "Teléfono: {$data['telefono']}\n"
            . "Empresa: " . ($data['empresa'] ?: '-') . "\n"
            . "Servicio: {$servicioLabel}\n"
            . "Unidades: " . ($data['unidades'] ?: '-') . "\n"
            . "Mensaje: " . ($data['mensaje'] ?: '-') . "\n\n"
            . "IP: {$data['ip']}\n"
            . "Fecha: " . date('Y-m-d H:i:s') . "\n";

        // Si PHPMailer no está instalado o SMTP no está configurado, intentamos mail() como fallback.
        if (!is_file($autoload) || empty($smtp['host'])) {
            return $this->sendMailFallback($to, $data, $cuerpo);
        }

        require_once $autoload;
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host       = $smtp['host'];
            $mail->Port       = (int) $smtp['port'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtp['user'];
            $mail->Password   = $smtp['pass'];
            if (!empty($smtp['secure'])) {
                $mail->SMTPSecure = $smtp['secure'];
            }
            $mail->setFrom($smtp['from'], $smtp['from_name']);
            $mail->addAddress($to);
            $mail->addReplyTo($data['email'], $data['nombre']);
            $mail->Subject = 'Nuevo lead — ' . $servicioLabel . ' — ' . $data['nombre'];
            $mail->Body    = $cuerpo;
            $mail->send();
            return true;
        } catch (Throwable $e) {
            $this->logError('PHPMailer failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Fallback con mail() nativo cuando no hay SMTP/PHPMailer (útil en local). */
    private function sendMailFallback(string $to, array $data, string $cuerpo): bool
    {
        if (config('app.env') === 'local') {
            // En local no hay MTA: logueamos y damos por exitoso para poder probar el flujo.
            $this->logError("[LOCAL] Lead recibido:\n" . $cuerpo);
            return true;
        }
        $headers = 'From: ' . config('smtp.from') . "\r\n"
            . 'Reply-To: ' . $data['email'] . "\r\n"
            . 'Content-Type: text/plain; charset=UTF-8';
        return @mail($to, 'Nuevo lead — ' . $data['nombre'], $cuerpo, $headers);
    }

    private function logError(string $msg): void
    {
        $dir = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($dir . '/contact.log', '[' . date('c') . '] ' . $msg . "\n", FILE_APPEND);
    }

    private function expectsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xhr    = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        return $xhr || str_contains($accept, 'application/json');
    }

    private function ok(): void
    {
        if ($this->wantsJson) {
            $this->json(['ok' => true, 'message' => 'Recibimos tu consulta, te contactamos a la brevedad.']);
            return;
        }
        header('Location: ' . url('/contacto?ok=1'), true, 303);
    }

    private function fail(array $errors, int $status): void
    {
        if ($this->wantsJson) {
            http_response_code($status);
            $this->json(['ok' => false, 'errors' => $errors]);
            return;
        }
        $_SESSION['_form_errors'] = $errors;
        $_SESSION['_form_old']    = $_POST;
        header('Location: ' . url('/contacto?error=1#formulario'), true, 303);
    }

    private function json(array $payload): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
