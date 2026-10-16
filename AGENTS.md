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
sail up -d scheduler                   # tareas programadas (tasa de cambio diaria 07:00 Caracas); llama a la API real
sail composer install && sail pnpm install
sail artisan test                      # suite completa (o sail pest --filter=E-06)
sail pint                              # formato PHP (obligatorio antes de cerrar una tarea)
sail pnpm build                        # debe compilar sin errores
sail artisan migrate:fresh --seed      # SOLO en entorno local
```
El servicio `scheduler` (`schedule:work`) está detrás del profile `scheduler` de `compose.yaml`: `sail up -d` no lo levanta. Arrancalo solo cuando quieras que corran las tareas programadas, porque la consulta diaria de la tasa (`exchange-rate:fetch`) llama a la API real.
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
| 4 | `odd/tasks/NNN-nombre.md` | Documento de feature ODD: alcance, diseño técnico, tareas y evidencia |
| 5 | Código y pruebas | Implementación |

Reglas:
- Un nivel inferior **nunca** contradice a uno superior. Si detectas una contradicción, detente y repórtala.
- `docs/specs/NNN-*.md` es la **fuente única** del comportamiento funcional. El documento `odd/tasks/NNN-nombre.md` es una derivación fiel: no añade, no quita, no reinterpreta requisitos.
- Si hay discrepancia entre un archivo del repo y la memoria de Engram, **manda el archivo**.
- Este `AGENTS.md` resume principios; el detalle vive en la constitution.
- Enmiendas en la spec original. Cuando una feature cambia comportamiento de una spec anterior, el agente propone la enmienda en esa spec (sección “Cambios posteriores”, citando el ID de la nueva) y no implementa hasta que la apruebe el humano.
- `openspec/` es **histórico** (specs 001–003 y el diseño congelado de 004, ver §4.2).
  No se crean ni se archivan cambios ahí. No es fuente de comportamiento: manda `docs/specs/`.

---

## 4. Flujo de trabajo (ODD con Gentle-AI v4)

### 4.1 Clasificación (no la decide el agente)
| Tipo de cambio | Ruta |
|---|---|
| Implementar o modificar una spec (`docs/specs/NNN`) | Sustancial y crítico: documento de feature + verificación |
| Refactor sin cambio de comportamiento, estilos, typos | Directo, con pruebas en verde |
| Bug que contradice una spec implementada | Directo, con la prueba del escenario que fallaba |

### 4.2 Documento de feature: `odd/tasks/NNN-nombre.md`
Secciones obligatorias, en este orden:
1. **Fuente**: ruta de la spec y commit en que se leyó.
2. **Alcance**: IDs cubiertos (`FND-xxx`, `CLI-xxx`, `PRD-xxx`, `COT-xxx`, `E-xx`, etc.). Nada fuera de la spec.
3. **Decisiones**: todas en estado Confirmada (ver 4.3).
4. **Diseño técnico**: modelos, migraciones, Actions, Policies, permisos, rutas y páginas. Sustituye al antiguo `design.md`; no crear `plan.md`.
5. **Tareas**: checklist; cada tarea cita los IDs que cubre.
6. **Evidencia**: prueba por cada `E-xx` y resultado de los comandos de §12.
7. **Siguiente paso**.

#### Excepción transitoria — spec 004
 (Iniciada con SDD, migrada a ODD en la Fase 9.)
- `openspec/changes/004-pricing-and-quotations/design.md` es la fuente del diseño técnico de 004.
  Está **congelado**: solo lectura, no se edita.
- En `odd/tasks/004-pricing-and-quotations.md`, la sección "Diseño técnico" lo referencia
  en lugar de copiarlo. Todo cambio al diseño se registra en una sección
  "Cambios al diseño" del documento ODD, con su motivo y DEC; nunca en openspec.
- Las fases 0–8 se registran como cerradas con una línea por fase y su commit;
  no se migra `apply-progress.md`.
- Desde 005, el documento ODD contiene el diseño técnico completo.

### 4.3 Puertas (obligatorias aunque ODD no las exija)
1. **Antes de crear el documento**: si la spec tiene decisiones sin confirmar, listarlas con el formato de §6 y detenerse.
2. **Antes de la primera línea de código**: con Diseño y Tareas escritos, detenerse y esperar aprobación humana.
3. **Cierre**: verificar contra la spec (cada `E-xx` con prueba en verde), registrar la evidencia y esperar confirmación humana.

La puerta 2 es **una sola aprobación por spec**. Aprobado el documento, las tareas
se ejecutan seguidas; el agente solo vuelve a detenerse ante un [DEC-PENDIENTE]
(§5–§6) o un cambio al Diseño técnico, y en ese caso solo se aprueba ese cambio.
En 004, la puerta 2 revisa el documento ODD (tareas 9–28), no el diseño ya aprobado en SDD.

### 4.4 Commits
ODD cierra cada tarea con un commit por defecto.
El agente crea la rama de la feature, hace commit pero no push ni merge.
Al cerrar cada tarea, anota en el documento el mensaje de commit sugerido.

### 4.5 Engram
Guardar: decisiones técnicas del Diseño técnico, hallazgos no obvios del código,
errores recurrentes y su solución.
No guardar: credenciales, contenido de `.env`, datos personales de clientes o empleados, precios reales de Ecolekua.
El documento de feature tiene copia en Engram; si difieren, manda el archivo del repositorio.

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
2. **Autorización en backend.** Toda operación protegida valida permisos con Policies/Gates. Ocultar un botón no es seguridad. **No usar `Gate::before` ni ningún "superadmin" implícito**: nadie tiene permisos no asignados. Las denegaciones por falta de permiso se hacen **siempre** con Policy (`authorize`, `can`), nunca con `abort(403)`, `abort_if(…, 403)` ni `abort_unless(…, 403)`, para que queden auditadas (FND-022). Una prueba de arquitectura (`tests/Feature/Architecture/NoManualForbiddenTest.php`) falla si aparece uno en `app/`.
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

Se confirma en Diseño técnico de odd/tasks/NNN-nombre.md

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
- TDD estricto es obligatorio en este proyecto; no preguntar.

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
- [ ] `odd/tasks/NNN-nombre.md` completo con todas las tareas cerradas y la sección de Evidencia registrada;
- [ ] documentación actualizada (spec, decisiones, este archivo si aplica);
- [ ] cierre confirmado por el humano.
