# AGENTS.md — Ecolekua Micro-ERP

> Instrucciones operativas para agentes de IA (Claude Code + Gentle-AI).
> Responde siempre en español. El código, identificadores y nombres de tablas van en inglés; los textos visibles al usuario, en español.

---

## 0. Regla fundamental

**No inventar el negocio.** Si un comportamiento funcional no está definido, se detiene esa parte y se pregunta (ver §5 y §6). Las decisiones puramente técnicas se resuelven de forma simple y coherente con este documento.

---

## 1. Qué es este proyecto

Micro-ERP **a medida para una única empresa: Ecolekua** (pañales ecológicos y confección/sublimación de uniformes corporativos). Reemplaza un flujo manual en papel: cotización → confirmación → anticipo → reserva de materiales → producción → calidad → entrega.

No es SaaS ni multiempresa. Prohibido introducir `tenant`, `tenant_id`, selección de empresa, organizaciones múltiples o cualquier abstracción pensada para vender el sistema a terceros, salvo decisión explícita documentada en la constitution.

---

## 2. Stack y comandos

| Capa | Tecnología |
|---|---|
| Backend | Laravel (PHP) — versión fijada en `composer.json` |
| Frontend interno | Vue 3 + Inertia.js + TypeScript + Tailwind CSS (base: `laravel/blank-vue-starter-kit`) |
| Portal público | Ver §9 |
| Base de datos | MySQL |
| Pruebas | Pest |
| Estilo | Laravel Pint |

Para documentación de Laravel, Inertia, Vue o Pest, consulta Context7 con la versión instalada; no asumas APIs de memoria.

El entorno corre en **Laravel Sail (Docker)** dentro de WSL2. Todos los comandos PHP, Composer y pnpm se ejecutan con `sail`, nunca con `php` o `composer` del sistema. Los agentes deben invocarlo como `./vendor/bin/sail` (el alias `sail` no existe en shells no interactivos).

```bash
sail up -d                             # levantar contenedores (app, mysql, mailpit)
sail composer install && sail pnpm install
sail artisan test                      # suite completa (o sail pest --filter=E-06)
sail pint                              # formato PHP (obligatorio antes de cerrar una tarea)
sail pnpm build                        # debe compilar sin errores
sail artisan migrate:fresh --seed      # SOLO en entorno local
```

Las pruebas usan MySQL (base `testing` creada por Sail), nunca SQLite en memoria.

Gestor de paquetes JS: **pnpm** (nunca npm ni yarn; no generar `package-lock.json`). Antes de añadir una dependencia nueva, justificarla y preguntar.

> Ajusta este bloque si cambian los comandos reales del repositorio.

---

## 3. Mapa de documentación y jerarquía

| Nivel | Archivo | Autoridad sobre |
|---|---|---|
| 1 | `docs/constitution.md` | Principios y decisiones arquitectónicas |
| 2 | `docs/business/business-rules.md` | Reglas de negocio conocidas de Ecolekua |
| 3 | `docs/specs/NNN-nombre.md` | Comportamiento funcional de cada funcionalidad (spec de producto, redactada por el equipo) |
| 4 | `openspec/changes/NNN-nombre/` | Artefactos SDD de Gentle-AI: `proposal.md`, `specs/`, `design.md`, `tasks.md` |
| 5 | Código y pruebas | Implementación |

Reglas:

- Un nivel inferior **nunca** contradice a uno superior. Si detectas una contradicción, detente y repórtala.
- `docs/specs/NNN-*.md` es la **fuente** del comportamiento. Los delta specs de `openspec/changes/…/specs/` son una **transcripción fiel** de ella: no añaden, no quitan, no reinterpretan requisitos.
- Si hay discrepancia entre un archivo del repo y la memoria de Engram, **manda el archivo**.
- Este AGENTS.md resume principios; el detalle vive en la constitution.

---

## 4. Flujo SDD con Gentle-AI

### 4.1 Cuándo es obligatorio

| Tipo de cambio | Ruta |
|---|---|
| Cualquier cambio que agregue o altere comportamiento funcional, datos, permisos o reglas | **SDD completo** |
| Refactor sin cambio de comportamiento, corrección de estilos, typos, dependencias | Directo, con pruebas en verde |
| Corrección de bug que contradice una spec ya implementada | Directo, añadiendo la prueba del escenario que fallaba |

### 4.2 Correspondencia de fases

| Fase | Comando | Artefacto | Regla para este proyecto |
|---|---|---|---|
| Init | `/sdd-init` | `openspec/config.yaml` | Verificar que detecta Laravel + Pest y activa Strict TDD |
| Explore | `/sdd-new NNN-nombre` / `/sdd-explore` | notas | Leer la spec en `docs/specs/` y el código existente |
| Propose | — | `proposal.md` | Alcance = el de la spec; nada extra |
| Spec | — | `specs/<dominio>/spec.md` | Transcripción fiel (§4.3) |
| Design | — | `design.md` | **Este es el "plan"**. No crear un `plan.md` aparte |
| Tasks | — | `tasks.md` | Cada tarea referencia los IDs que cubre (FND-xxx, E-xx) |
| Apply | `/sdd-apply` | código + pruebas | Test-first (Strict TDD) |
| Verify | `/sdd-verify` | informe | Contra spec, design y tasks |
| Archive | `/sdd-archive` | `openspec/specs/` | Solo tras verify aprobado y confirmación humana |

`/sdd-ff` (fast-forward) **no se usa** si la spec tiene decisiones pendientes.

### 4.3 Transcripción de specs a formato OpenSpec

- Cada requisito `FND-xxx` → un `### Requirement: FND-xxx — <título>`.
- Cada escenario `E-xx` → un `#### Scenario: E-xx — <título>` con GIVEN/WHEN/THEN.
- Conservar siempre los IDs originales para trazabilidad.
- Los prefijos de cada spec se mantienen (FND para 001; las siguientes definen el suyo).

### 4.4 Puerta de entrada a Design

No pasar a Design mientras la spec tenga decisiones en estado distinto de **Confirmada** (p. ej. sección "Decisiones de esta Spec"). Informa cuáles faltan y detente.

### 4.5 Engram (memoria)

Guardar: decisiones técnicas tomadas en design, hallazgos no obvios del código, errores recurrentes y su solución.
No guardar: credenciales, contenido de `.env`, datos personales de clientes o empleados, precios reales de Ecolekua.

---

## 5. Cuándo detenerse y preguntar

Detén **esa parte** del trabajo (no todo) cuando exista:

- una decisión de negocio faltante;
- un requisito ambiguo o contradictorio;
- una condición sin comportamiento definido;
- una contradicción entre documentos de distinto nivel (§3).

No completes el hueco con patrones de otros ERPs, prácticas genéricas ni suposiciones de "lo normal".

---

## 6. Cómo preguntar

Formato de cada pregunta:

```text
[DEC-PENDIENTE] <spec>/<ID afectado>
Contexto: qué se estaba implementando.
Duda: qué no está definido.
Opciones: A) … B) … (con consecuencias)
Recomendación técnica (si aplica): …
```

Cuando la respuesta llegue, se registra en la spec correspondiente (sección de decisiones) **antes** de implementarla. Si contradice la constitution, es una decisión arquitectónica, no un ajuste menor.

---

## 7. Principios no negociables (resumen)

1. **Backend como autoridad.** Precios, descuentos, anticipos, disponibilidad, reservas, inventario, estados, producción, tiempos, calidad, reprocesos, comisiones y permisos se calculan y validan en el servidor. El frontend (incluido el portal público) solo muestra resultados.
2. **Autorización en backend.** Toda operación protegida valida permisos con Policies/Gates. Ocultar un botón no es seguridad. **No usar `Gate::before` ni ningún "superadmin" implícito**: nadie tiene permisos no asignados.
3. **Permisos, no nombres de rol.** El código nunca pregunta `hasRole('Administrador')`; pregunta por permisos `modulo.accion`.
4. **Transacciones** en toda operación que afecte varias entidades (confirmar pedidos, anticipos, reservas, movimientos de inventario, órdenes y resultados de producción, defectos, reprocesos). Nada parcialmente ejecutado.
5. **Inventario trazable.** Toda variación de cantidad se hace mediante un movimiento con: ítem, cantidad, tipo, fecha, usuario, referencia y motivo. Nunca `update` directo de existencias. Disponible = físico − reservado.
6. **Producción trazable:** orden, operación, estación, responsable, inicio, pausas, reanudaciones, fin, tiempo estándar vs real, cantidades, defectos.
7. **Reprocesos solo sobre unidades afectadas**, vinculados al defecto y al proceso que lo originó.
8. **Auditoría** de acciones sensibles (quién, qué, sobre qué, cuándo, IP, valores antes/después). Inmutable. Nunca contiene contraseñas ni hashes.
9. **Sin borrado físico** de entidades con historial (usuarios, pedidos, movimientos, auditoría).
10. **Simplicidad.** Sin microservicios, motores genéricos de reglas o configuración, plugins internos ni capas artificiales. Parametrizar solo valores que Ecolekua realmente cambia (porcentaje de anticipo, descuentos, tiempos estándar, motivos de pausa).
11. **Integraciones desacopladas** (WhatsApp, correo, pagos, almacenamiento). El núcleo nunca depende de ellas para mantener su integridad; se invocan después del commit (eventos/colas).
12. **Sin API ni app móvil** hasta que una spec lo pida. No bloquear su futura existencia (lógica fuera de controladores).

---

## 8. Convenciones de código

Se confirman en el `design.md` de 001; a partir de ahí son obligatorias.

- **Controladores delgados**: validan con `FormRequest`, autorizan con Policy y delegan en una **Action** (`app/Actions/<Modulo>/`), donde vive la lógica de negocio y la transacción.
- **Permisos** declarados como catálogo en un único lugar (seeder/enum) con nombres `modulo.accion` en inglés.
- **Vue/Inertia**: respetar la estructura del starter kit: páginas en `resources/js/pages/<modulo>/`, componentes en `resources/js/components/`, layouts en `resources/js/layouts/`. Código frontend en TypeScript.
- **Dinero**: nunca `float`. Usar `decimal` en BD y aritmética exacta en PHP. Moneda, redondeo e impuestos son decisiones de negocio: preguntar.
- **Fechas**: persistir en UTC; mostrar en la zona horaria de operación de Ecolekua.
- **Migraciones**: nunca editar una migración ya aplicada en un entorno compartido; crear una nueva.
- **Seeds**: sin datos reales de clientes. Datos de ejemplo claramente ficticios.

---

## 9. Portal web público existente

Existe un portal web previo (HTML + CSS, **sin lógica**) que será la cara pública del sistema y alojará el **cotizador inteligente**.

- Ubicación de referencia: `resources/views/portal/` (maquetas originales conservadas en `docs/portal/html-original/`).
- Se integra **dentro de esta misma aplicación Laravel** (misma base de datos, mismas reglas de precio). No se crea un backend ni una API separada para el portal.
- Conservar la identidad visual existente. No rediseñar ni reemplazar estilos sin que se pida.
- El cotizador **no calcula precios en JavaScript**: envía la selección al backend y muestra el resultado que este devuelve (§7.1).
- Las rutas públicas se declaran explícitamente como públicas; todo lo demás está protegido por defecto.
- Los visitantes y clientes del portal **no son usuarios internos** del modelo de 001.
- El comportamiento funcional del portal y del cotizador lo definen sus specs (catálogo, precios/cotizaciones, portal público, portal de clientes). Mientras esas specs no existan, solo se permite trabajo de maquetación/migración visual, sin lógica.

---

## 10. Pruebas (Strict TDD)

- Prueba primero, luego implementación.
- Cada escenario de aceptación tiene al menos una prueba, y su nombre empieza por el ID: `it('E-06 bloquea el correo tras 5 intentos fallidos', …)`.
- Las pruebas de autorización golpean el backend directamente (peticiones HTTP), no la UI.
- Probar comportamiento y reglas, no cobertura por sí misma.
- Prioridad: precios, descuentos, anticipos, inventario, reservas, pedidos, producción, tiempos, defectos, reprocesos, comisiones, permisos.

---

## 11. Git

- Una rama por change: `feat/NNN-nombre`, `fix/…`, `chore/…`.
- Commits convencionales que citen la spec: `feat(001): bloquear login tras 5 intentos [FND-003]`.
- No hacer commit, push ni merge sin que se pida.
- Nunca versionar `.env`, credenciales ni volcados de base de datos.

---

## 12. Definition of Done

Un change está terminado cuando:

- [ ] cumple la spec activa y todos sus escenarios tienen prueba en verde;
- [ ] respeta business rules y constitution;
- [ ] tiene autorización en backend y auditoría donde corresponda;
- [ ] mantiene integridad de datos (transacciones, restricciones en BD);
- [ ] `sail artisan test`, `sail pint --test` y `sail pnpm build` pasan;
- [ ] `tasks.md` completo y `/sdd-verify` sin hallazgos bloqueantes;
- [ ] documentación actualizada (spec, decisiones, este archivo si aplica);
- [ ] archivado con `/sdd-archive` tras confirmación humana.
