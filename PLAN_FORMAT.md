# PLAN_FORMAT.md — Especificación del `plan.php`

Este documento define el formato del `plan.php` que consume el orquestador.
Vive **aquí**, junto al código del `Task`, para no engordar el `CLAUDE.md` de
cada proyecto. El modelo fuerte (Opus) lo lee al generar el plan.

## Qué es un plan

Un fichero PHP que devuelve un **array indexado por id** de objetos
`Desalort\Orchestrator\Data\Task`:

```php
<?php
declare(strict_types=1);

use Desalort\Orchestrator\Data\Task;

$tasks = [ new Task( ... ), new Task( ... ) ];

$indexed = [];
foreach ($tasks as $t) { $indexed[$t->id] = $t; }
return $indexed;
```

## El objeto `Task`

| Campo | Tipo | Obligatorio | Significado |
|---|---|---|---|
| `id` | `string` | sí | Identificador único. Da nombre a la rama `agent/<id>`. Usa kebab-case. |
| `role` | `string` | sí | Debe existir en `roles` de la config (`codegen`, `frontend`, `tests`, `docs`, `review`). |
| `instruction` | `string` | sí | Qué hacer, en lenguaje natural, autocontenido. |
| `verifyCommand` | `string` | sí | Comando que define "hecho". `exit 0` = pasa. Se ejecuta en el worktree de la tarea. |
| `scopePaths` | `list<string>` | no | Ficheros/carpetas que la tarea puede tocar. **Clave para el paralelismo seguro.** |
| `dependsOn` | `list<string>` | no | ids que deben completarse antes. Modela la serialización. |
| `contextPaths` | `list<string>` | no | Ficheros a inyectar como contexto (interfaces, tests, ejemplos). |

## Reglas para generar un buen plan

1. **Una tarea = una unidad verificable.** Si no puedes escribir un `verifyCommand`
   que decida objetivamente si está hecha, la tarea está mal definida.
2. **Paraleliza por módulo.** Tareas con `scopePaths` disjuntos y sin `dependsOn`
   entre sí corren en paralelo sin pisarse. Aprovecha la arquitectura modular.
3. **Serializa solo lo que de verdad depende.** `tests` y `docs` de un módulo
   dependen de su `codegen`; dos módulos independientes no.
4. **Los contratos van antes.** El `verifyCommand` apunta a tests/interfaces que el
   modelo fuerte ya escribió en el scaffold. El agente barato los hace pasar, no los crea.
5. **Da contexto, no toda la base de código.** En `contextPaths`, las interfaces y el
   test que define el contrato; nada más.
6. **No metas en el plan trabajo no orquestable.** Diseño, exploración o cambios muy
   acoplados se hacen interactivos (Opus/Sonnet), no por agentes baratos.

## verifyCommand habituales

- Código con tests: `vendor/bin/phpunit --filter <TestClass>`
- Sintaxis: `php -l ruta/al/fichero.php`
- Estático: `vendor/bin/phpstan analyse <ruta> --level <N>`
- Existencia (docs): `test -f ruta/README.md`
- Revisión sin oráculo binario: `true` (el valor está en el informe del rol `review`)

## Grafo de dependencias

El orquestador resuelve el DAG: lanza las tareas listas (sin dependencias
pendientes) hasta `max_concurrency`, y si una dependencia falla, las que dependen
de ella quedan en estado `skipped`. No debe haber ciclos.
