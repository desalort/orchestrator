<?php

declare(strict_types=1);

/**
 * Entrypoint maestro del orquestador.
 *
 *   cd /ruta/al/proyecto
 *   php vendor/desalort/orchestrator/bin/orchestrate.php [config.php] [plan.php]
 *
 * Por defecto busca ./agents.php y ./plan.php en el directorio actual.
 * Debe ejecutarse desde la raíz del repo git que tocarán los agentes.
 */

use Desalort\Orchestrator\Orchestrator;
use Desalort\Orchestrator\Data\TaskStatus;

// --- Autoloader: standalone (clon) o instalado como dependencia ---
(static function (): void {
    $candidates = [
        __DIR__ . '/../vendor/autoload.php',     // clon del propio repo
        __DIR__ . '/../../../autoload.php',       // vendor/desalort/orchestrator/bin -> vendor/autoload.php
    ];
    foreach ($candidates as $autoload) {
        if (is_file($autoload)) {
            require $autoload;
            return;
        }
    }
    fwrite(STDERR, "No se encontró el autoloader de Composer. Ejecuta 'composer install'.\n");
    exit(1);
})();

$cwd        = getcwd() ?: '.';
$configPath = $argv[1] ?? $cwd . '/agents.php';
$planPath   = $argv[2] ?? $cwd . '/plan.php';

foreach (['config' => $configPath, 'plan' => $planPath] as $label => $p) {
    if (!is_file($p)) {
        fwrite(STDERR, "No existe el fichero de {$label}: {$p}\n");
        fwrite(STDERR, "Uso: php orchestrate.php [config.php] [plan.php] (desde la raíz del proyecto)\n");
        exit(1);
    }
}

// Rutas absolutas: los workers son subprocesos y deben resolverlas igual.
$configPath = realpath($configPath) ?: $configPath;
$planPath   = realpath($planPath) ?: $planPath;

/** @var array<string,mixed> $config */
$config = require $configPath;
/** @var array<string,\Desalort\Orchestrator\Data\Task> $tasks */
$tasks = require $planPath;

$log = static function (string $msg): void {
    fwrite(STDERR, '[' . date('H:i:s') . "] {$msg}\n");
};

$orchestrator = new Orchestrator(
    runtimeCfg: $config['runtime'] ?? [],
    configPath: $configPath,
    planPath:   $planPath,
    workerBin:  __DIR__ . '/agent-worker.php',
    log:        $log,
);

$log('Iniciando orquestación de ' . count($tasks) . ' tareas');
$results = $orchestrator->run(array_values($tasks));

// --- Resumen ---
echo "\n=== RESUMEN ===\n";
$failed = 0;
foreach ($results as $r) {
    $mark = match ($r->status) {
        TaskStatus::Done    => 'OK  ',
        TaskStatus::Failed  => 'FAIL',
        TaskStatus::Skipped => 'SKIP',
        default             => '... ',
    };
    if ($r->status !== TaskStatus::Done) {
        $failed++;
    }
    printf("%s  %-22s %-8s rama: %s\n", $mark, $r->taskId, $r->status->value, $r->branch ?? '-');
}

echo "\n" . ($failed === 0
    ? "Todas las tareas pasaron su verificador. Revisa las ramas agent/* antes de mergear.\n"
    : "{$failed} tarea(s) sin completar. Revisa su salida y las ramas generadas.\n");

exit($failed === 0 ? 0 : 1);
