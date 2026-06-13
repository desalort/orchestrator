# desalort/orchestrator

Orquestador multi-agente **provider-agnóstico** para desarrollo asistido por LLM.

Descompone un hito en **tareas con contrato**, las ejecuta en **paralelo** con
modelos económicos (Together.ai, Ollama local, cualquier endpoint OpenAI-compatible,
o Anthropic), y solo da por buena una tarea cuando **pasa su verificador**. Cada
agente trabaja aislado en su propio `git worktree`, así que varios pueden tocar el
código a la vez sin pisarse.

> **Herramienta de desarrollo** (`require-dev`), no dependencia de runtime de la
> aplicación. El modelo caro (Opus) planifica y revisa; este motor ejecuta el trabajo
> mecánico con modelos baratos.

## Idea en una frase

El `plan.php` es la interfaz entre el **pensar caro** (Opus genera el plan y los
contratos) y el **hacer barato** (los agentes ejecutan y se autocorrigen contra el
verificador). El orquestador no necesita un LLM para coordinar: es determinista.

## Arquitectura agnóstica

Tres niveles de indirección, todos en `agents.php`:

```
role  ->  profile  ->  provider
codegen   codegen_fast   together (Qwen3-Coder-30B)
```

Mover `codegen` a tu GPU local: cambia su `profile` a uno que apunte al provider
`ollama`. **Una línea, sin tocar código.** Together, Ollama, vLLM, LM Studio y
OpenRouter comparten el driver `openai_compatible`; solo Anthropic tiene el suyo.

## Requisitos

- PHP ≥ 8.3 (`ext-curl`, `ext-json`)
- `git` (para el aislamiento por worktree)
- `timeout` (coreutils) si usas `verify_timeout` (recomendado; ver más abajo)
- `desalort/llm-gateway` (^1.0) — providers, *tool calling*, `Usage` normalizado
  y retries; se instala automáticamente como dependencia
- En el proyecto destino: lo que usen tus `verifyCommand` (p. ej. PHPUnit, PHPStan)
- **El modelo de cada rol debe soportar *tool calling* forzado** (`toolChoice: 'required'`):
  la entrega es vía la tool `write_files`. Verificado OK: familia Qwen (Instruct), DeepSeek.
  **No sirven** modelos que solo razonan sin emitir la tool (p. ej. `openai/gpt-oss-*`).

## Instalación

```bash
composer require --dev desalort/orchestrator
```

Luego copia la plantilla de config y los prompts a tu proyecto y ajústalos:

```bash
cp vendor/desalort/orchestrator/config/agents.example.php ./agents.php
cp -r vendor/desalort/orchestrator/prompts ./prompts
# CONVENTIONS.md debe estar en la raíz del proyecto (fuente única de convenciones)
```

## Uso

1. **Diseño (Opus).** Documento técnico, scaffold del proyecto e **interfaces +
   tests que fallan** (los contratos). Esto define "hecho".
2. **Plan (Opus, en VS Code).** Genera `plan.php` según [`PLAN_FORMAT.md`](PLAN_FORMAT.md),
   viendo el scaffold real en disco.
3. **Ejecuta** desde la raíz del repo git del proyecto:

   ```bash
   export TOGETHER_API_KEY=...
   php vendor/desalort/orchestrator/bin/orchestrate.php agents.php plan.php
   ```

4. **Revisa e integra.** Cada tarea deja una rama `agent/<id>`. Revisa los diffs,
   mergea las que pasaron, reescribe a mano o re-planifica las que no.

El orquestador imprime el progreso por STDERR y un resumen final con el estado y la
rama de cada tarea.

## Cómo funciona el bucle de cada agente

```
setUp worktree -> [ llamar modelo -> aplicar ficheros -> verificar ] xN -> tearDown
```

Si el verificador falla, su salida se realimenta al modelo para que se autocorrija,
hasta `max_attempts`. Sin verificador no hay ahorro: un modelo barato sin gate solo
produce deuda técnica barata.

Además, el prompt incluye el **contenido actual de los ficheros de `scopePaths`** (si existen):
así el agente conserva firma, namespace e imports del esqueleto y solo reemplaza el cuerpo,
en vez de reconstruirlos de memoria (fuente típica de errores en ficheros con muchos `use`).

## Escribir buenos `verifyCommand` (entorno del worktree)

El verificador corre en el **worktree** de la tarea, que sale de `base_ref` y **solo contiene
ficheros versionados** — por tanto **no hay `vendor/`** (está en `.gitignore`). Además se ejecuta
vía `exec()` → `/bin/sh` (en Linux suele ser **dash**, no bash). Consecuencias prácticas:

- Deja el worktree autosuficiente: instala dependencias en el propio comando, p. ej.
  `composer install -q --prefer-dist --no-interaction && vendor/bin/phpunit --filter X`.
  Usa `--prefer-dist` (no `--prefer-source`): el `vendor/` con histórico git puede pesar GB y
  los worktrees corren en paralelo (a menudo sobre `tmpfs`).
- **Nada de bashismos** (`${PIPESTATUS[0]}`, arrays…): dash falla con "Bad substitution".
- Define `verify_timeout` para que un bucle no terminante del agente no cuelgue la corrida.

## Protocolo de salida del modelo

El agente entrega su trabajo mediante *tool calling*: cada `LlmRequest` declara
una tool `write_files` (`{ files: [{ path, content }] }`) y se fuerza con
`toolChoice: 'required'`, vía `desalort/llm-gateway` (válido tanto para
Anthropic como para cualquier proveedor OpenAI-compatible). El `AgentRunner`
lee `response->toolCalls`, vuelca `files` al workspace y, si el verificador
falla, realimenta la salida como `Message::toolResult` para que el modelo
corrija y vuelva a llamar a `write_files`.

## Convenciones (fuente única de verdad)

Las convenciones de código viven en el `CONVENTIONS.md` del proyecto. El worker lo
**antepone** automáticamente a cada prompt de rol, de modo que Claude (interactivo) y
los agentes baratos beben de la misma fuente. No dupliques convenciones en los prompts.

## Estructura del paquete

```
src/
  Orchestrator.php        DAG + pool de procesos (paralelismo)
  Dag.php                  lógica pura del grafo de dependencias (testeable sin proc_open)
  AgentRunner.php         bucle llamar/editar/verificar/reintentar + usage/coste
  Contracts/              Verifier, Workspace
  Data/                   Task, ModelProfile, *Result, TaskStatus (readonly/enum)
  Provider/               ProviderFactory (role -> profile -> provider -> LlmGateway)
  Verifier/CommandVerifier.php
  Workspace/GitWorktreeWorkspace.php
bin/
  orchestrate.php         entrypoint maestro
  agent-worker.php        un subproceso por tarea
config/agents.example.php
prompts/                  codegen, frontend, tests, docs, review
examples/plan.example.php
tests/                    suite PHPUnit del propio paquete
PLAN_FORMAT.md            spec del plan.php
```

## Coste y tokens

Cada `profile` en `agents.php` declara `cost_input_per_1m` / `cost_output_per_1m`
(USD por millón de tokens, default `0.0`). El `AgentRunner` acumula
`promptTokens`/`completionTokens` de cada respuesta (vía `Usage` de
`desalort/llm-gateway`) y calcula `costUsd` del `TaskResult`. El resumen final
de `orchestrate.php` muestra tokens y coste por tarea, más una línea de totales.

## Observabilidad y robustez (`runtime`)

- **`verify_timeout`** (s, default 600): el `verifyCommand` corre bajo `timeout(1)`; un agente con
  un bucle no terminante (p. ej. bisección sin guardia) se mata (exit 124 → reintento/fallo) en
  vez de colgar la corrida entera. `0` = sin límite. Requiere `timeout` (coreutils).
- **`worker_log_dir`** (opcional): si se define, el **STDERR de cada worker** se persiste en
  `<dir>/<taskId>.worker.log` (fatales de PHP, errores de composer, trazas fuera del verificador)
  en vez de descartarse. Útil para diagnosticar fallos que no son del verificador.

## Limitaciones conocidas / a endurecer

- Pool de procesos casero con `proc_open`; `symfony/process` daría aún mejor control.
- `verifyCommand` se ejecuta vía shell: úsalo solo con planes de confianza.
- La salida del verificador por intento no se persiste como transcript estructurado (sí el
  STDERR del worker con `worker_log_dir`); para un log por intento, envuélvelo en el propio
  `verifyCommand` (`… | tee -a <log>` con redirección POSIX que preserve el exit code).

## Estado

Beta. Extraído del sistema de trabajo multi-agente (junio 2026). `v2.1`: `verify_timeout`,
`worker_log_dir`, inyección del scope file en el prompt y nota de compatibilidad de modelos.
