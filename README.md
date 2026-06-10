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
- En el proyecto destino: lo que usen tus `verifyCommand` (p. ej. PHPUnit, PHPStan)

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

## Protocolo de salida del modelo

El agente espera los ficheros en bloques:

```
=== FILE: ruta/relativa.php ===
<contenido completo>
=== END FILE ===
```

Para modelos con *tool calling* fiable, sustituir el parser por function calling
con un esquema `write_file` reduce mucho los fallos de formato.

## Convenciones (fuente única de verdad)

Las convenciones de código viven en el `CONVENTIONS.md` del proyecto. El worker lo
**antepone** automáticamente a cada prompt de rol, de modo que Claude (interactivo) y
los agentes baratos beben de la misma fuente. No dupliques convenciones en los prompts.

## Estructura del paquete

```
src/
  Orchestrator.php        DAG + pool de procesos (paralelismo)
  AgentRunner.php         bucle llamar/editar/verificar/reintentar
  Contracts/              LlmProvider, Verifier, Workspace
  Data/                   Task, ModelProfile, Message, *Result, TaskStatus (readonly/enum)
  Provider/               OpenAiCompatible, Anthropic, ProviderFactory
  Verifier/CommandVerifier.php
  Workspace/GitWorktreeWorkspace.php
bin/
  orchestrate.php         entrypoint maestro
  agent-worker.php        un subproceso por tarea
config/agents.example.php
prompts/                  codegen, frontend, tests, docs, review
examples/plan.example.php
PLAN_FORMAT.md            spec del plan.php
```

## Limitaciones conocidas / a endurecer

- Parser de salida por regex (frágil con modelos flojos) → migrar a tool calling.
- Sin límite de coste/tokens por tarea ni telemetría de gasto.
- Pool de procesos casero con `proc_open`; `symfony/process` daría mejor control de timeouts.
- `verifyCommand` se ejecuta vía shell: úsalo solo con planes de confianza.
- Sin tests propios todavía.

## Estado

Beta. Extraído del sistema de trabajo multi-agente (junio 2026).
