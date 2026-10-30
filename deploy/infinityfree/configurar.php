<?php

// Herramienta local. No subir esta carpeta al hosting.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Dotenv\Dotenv;

$root = dirname(__DIR__, 2);
$mode = $argv[1] ?? 'status';
$values = Dotenv::parse(file_get_contents($root.'/.env'));

if (in_array($mode, ['env', 'env-local-qa'], true)) {
    $target = $argv[2] ?? throw new RuntimeException('Falta el archivo de destino.');
    $original = $values;
    $values = array_replace($values, [
        'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
        'APP_URL' => 'https://CAMBIAR_SUBDOMINIO',
        'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
        'DB_HOST' => 'CAMBIAR_HOST_MYSQL', 'DB_PORT' => '3306',
        'DB_DATABASE' => 'CAMBIAR_BASE_MYSQL', 'DB_USERNAME' => 'CAMBIAR_USUARIO_MYSQL',
        'DB_PASSWORD' => 'CAMBIAR_PASSWORD_MYSQL',
        'SESSION_DRIVER' => 'file', 'SESSION_DOMAIN' => 'null',
        'SESSION_SECURE_COOKIE' => 'true', 'CACHE_STORE' => 'file',
        'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'single', 'LOG_LEVEL' => 'error',
        'GOOGLE_CALENDAR_REDIRECT_URI' => 'https://CAMBIAR_SUBDOMINIO/calendario/google/callback',
    ]);
    if ($mode === 'env-local-qa') {
        foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            $values[$key] = $original[$key] ?? '';
        }
        $values['APP_URL'] = 'http://127.0.0.1:18087';
        $values['SESSION_SECURE_COOKIE'] = 'false';
    }
    // Conservar APP_KEY y secretos de integraciones sin imprimirlos.
    if (empty($values['APP_KEY'])) {
        throw new RuntimeException('La clave local de aplicacion esta vacia.');
    }
    $lines = [];
    foreach ($values as $key => $value) {
        $value = str_replace(['\\', '"', '$', "\r", "\n"], ['\\\\', '\\"', '\\$', '', '\\n'], (string) $value);
        $lines[] = $key.'="'.$value.'"';
    }
    file_put_contents($target, implode("\n", $lines)."\n");
    echo "Configuracion privada preparada; clave de aplicacion conservada.\n";
    exit;
}

try {
    $host = $values['DB_HOST'] ?? '127.0.0.1';
    $port = $values['DB_PORT'] ?? '3306';
    $name = $values['DB_DATABASE'] ?? '';
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $values['DB_USERNAME'] ?? '', $values['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `'.str_replace('`', '``', $table).'`')->fetchColumn();
    }
    if ($mode === 'status') {
        echo json_encode(['connected' => true, 'tables' => $counts], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
        exit;
    }
    if ($mode !== 'export') {
        throw new RuntimeException('Modo desconocido.');
    }
    $output = $argv[2] ?? throw new RuntimeException('Falta destino SQL.');
    $config = tempnam(dirname($output), 'mysql-');
    $quote = static fn ($s) => '"'.str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '\\r', '\\n'], (string) $s).'"';
    file_put_contents($config, "[client]\nhost=".$quote($host)."\nport=".(int) $port."\nuser=".$quote($values['DB_USERNAME'] ?? '')."\npassword=".$quote($values['DB_PASSWORD'] ?? '')."\n");
    try {
        $command = ['C:/xampp/mysql/bin/mysqldump.exe', '--defaults-extra-file='.$config,
            '--single-transaction', '--skip-lock-tables', '--skip-add-locks', '--skip-comments',
            '--default-character-set=utf8mb4', '--hex-blob', '--result-file='.$output];
        $transient = ['sessions', 'cache', 'cache_locks', 'password_reset_tokens', 'jobs', 'failed_jobs', 'job_batches'];
        foreach ($transient as $table) {
            $command[] = '--ignore-table-data='.$name.'.'.$table;
        }
        $command[] = $name;
        $process = proc_open($command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar la exportacion.');
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0 || !is_file($output) || filesize($output) === 0) {
            throw new RuntimeException('Fallo la exportacion SQL; comprobar MySQL local.');
        }
        echo json_encode(['exported' => true, 'bytes' => filesize($output), 'tables' => $counts,
            'data_excluded' => $transient], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
    } finally {
        if (is_file($config)) unlink($config);
    }
} catch (Throwable $e) {
    // No imprimir excepciones PDO: pueden incluir informacion de conexion.
    fwrite(STDERR, "No se pudo consultar o exportar la base local. Comprueba que MySQL este iniciado y que .env tenga los datos correctos.\n");
    exit(1);
}
