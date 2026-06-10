<?php

declare(strict_types=1);

/**
 * EJEMPLO de plan. El plan REAL lo genera el modelo fuerte (Opus) en la
 * primera interacción de VS Code, viendo el scaffold y los contratos ya
 * escritos en disco. Copia/adapta como `plan.php` en la raíz del proyecto.
 *
 * Observa cómo el ámbito por módulo evita colisiones: 'codegen-pricing' y
 * 'frontend-dashboard' tocan paquetes distintos -> corren EN PARALELO.
 * 'tests' y 'docs' dependen de 'codegen' -> se serializan tras él.
 *
 * @return array<string,\Desalort\Orchestrator\Data\Task>
 */

use Desalort\Orchestrator\Data\Task;

$tasks = [
    new Task(
        id:            'codegen-pricing',
        role:          'codegen',
        instruction:   'Implementa la clase que satisface FifoCalculatorInterface '
                     . 'según las firmas dadas en el contexto. No cambies la interfaz.',
        verifyCommand: 'vendor/bin/phpunit --filter FifoCalculatorTest',
        scopePaths:    ['packages/pricing/src/'],
        contextPaths:  ['packages/pricing/src/FifoCalculatorInterface.php',
                        'packages/pricing/tests/FifoCalculatorTest.php'],
    ),

    new Task(
        id:            'frontend-dashboard',
        role:          'frontend',
        instruction:   'Crea la vista del dashboard de posiciones en PHP + JS vanilla.',
        verifyCommand: 'php -l public/dashboard.php',
        scopePaths:    ['public/', 'templates/'],
        // sin dependencias -> paralelo con codegen-pricing
    ),

    new Task(
        id:            'tests-pricing',
        role:          'tests',
        instruction:   'Añade casos de borde al test del FifoCalculator '
                     . '(ventas parciales, lotes múltiples, base de coste cero).',
        verifyCommand: 'vendor/bin/phpunit --filter FifoCalculatorTest',
        scopePaths:    ['packages/pricing/tests/'],
        dependsOn:     ['codegen-pricing'],
    ),

    new Task(
        id:            'docs-pricing',
        role:          'docs',
        instruction:   'Documenta el módulo pricing en su README con ejemplos de uso.',
        verifyCommand: 'test -f packages/pricing/README.md',
        scopePaths:    ['packages/pricing/'],
        dependsOn:     ['codegen-pricing'],
    ),
];

/** Indexa por id (formato que esperan orquestador y worker). */
$indexed = [];
foreach ($tasks as $t) {
    $indexed[$t->id] = $t;
}

return $indexed;
