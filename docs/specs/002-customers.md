# Spec 002 — Clientes (`002-customers`)

| Campo | Valor |
|---|---|
| Prefijo de requisitos | `CLI` |
| Ubicación | `docs/specs/002-customers.md` |
| Estado | **Confirmada** — todas las decisiones de §9 confirmadas (2026-09-30); lista para Design |
| Depende de | `001-foundation` (autenticación, permisos, auditoría, layout del ERP) |
| La usan | `004-pricing-and-quotations`, `005-public-web-portal`, `006-orders-and-payments`, `007-customer-portal`, `014-commissions` |
| Rama | `feat/002-customers` |
| Fecha | 2026-09-30 |

> Subordinada a `docs/constitution.md` y `docs/business/business-rules.md`. Todo requisito marcado con ⏳ depende de una decisión pendiente y no se implementa hasta que esa decisión esté **Confirmada** (AGENTS §4.4).

---

## 1. Contexto

Hoy los datos de los clientes de Ecolekua están repartidos entre papeles, hojas de cálculo y conversaciones de WhatsApp. Cada pedido vuelve a capturar los datos del cliente y no existe un historial único.

El informe de la propuesta (§4) establece que cada cliente tendrá un **registro centralizado** con datos personales o empresariales, información de contacto, observaciones y, a medida que existan los módulos correspondientes, su historial de cotizaciones, pedidos y pagos.

Además, la regla de negocio §4 exige un **cliente válido** para confirmar un pedido, por lo que esta spec es requisito previo de cotizaciones y pedidos.

## 2. Objetivo

Permitir a los usuarios internos autorizados registrar, consultar, editar, desactivar y reactivar clientes desde un único lugar, con trazabilidad completa y sin borrado físico de clientes con historial, dejando la ficha del cliente preparada para que las specs posteriores añadan su historial comercial.

## 3. Alcance

### 3.1 Dentro del alcance

- Ficha del cliente (persona natural o empresa) gestionada por usuarios internos.
- Alta, edición, desactivación y reactivación.
- Eliminación restringida a clientes sin historial (DEC-CLI-13).
- Identificación del cliente y detección de duplicados.
- Datos de contacto y observaciones.
- Persona de contacto de las empresas (una por cliente) y dirección del cliente (una por cliente).
- Asesora asignada (cartera de clientes).
- Listado con búsqueda y vistas por estado.
- Carga inicial única de los clientes existentes mediante un comando técnico (CLI-018).
- Permisos `customers.*` verificados en backend.
- Auditoría de las acciones sobre clientes.

### 3.2 Fuera del alcance

| Tema | Dónde se trata |
|---|---|
| Cuenta de acceso del cliente, autorregistro, área privada | `005` / `007` |
| Historial de cotizaciones en la ficha | `004` |
| Historial de pedidos y pagos en la ficha | `006` |
| Dirección y datos de entrega de cada pedido | `006` / `011` |
| Atribución de ventas y comisiones | `006` / `014` |
| Fecha de la última compra y orden por actividad reciente en el listado | `006` (ver §10) |
| Facturación y datos fiscales para factura | Futura integración (constitución §15) |
| Importación de clientes desde la interfaz del ERP o de forma recurrente | No contemplada; `002` solo incluye la carga inicial única por comando (CLI-018, DEC-CLI-19) |

---

## 4. Actores y permisos

El cliente **no es un usuario interno** del modelo de `001` (AGENTS §9). En esta spec el cliente es un registro gestionado por usuarios internos; su acceso al portal se define en `007`.

| Permiso | Permite |
|---|---|
| `customers.view` | Ver el listado y la ficha de todos los clientes, estén o no en su cartera (DEC-CLI-08) |
| `customers.create` | Registrar clientes |
| `customers.update` | Editar datos del cliente, su persona de contacto y su dirección |
| `customers.deactivate` | Desactivar y reactivar clientes |
| `customers.delete` | Eliminar clientes sin historial (CLI-010) |
| `customers.assign` | Asignar y reasignar la asesora de un cliente (CLI-014) |
| `customers.portfolio` | Poder tener cartera: ser asesora asignada de clientes (CLI-014) |

- Los nombres siguen la convención `modulo.accion` definida en `001`. Si `001` separa activar y desactivar en permisos distintos, esta tabla se alinea con esa convención en `design.md`.
- Asignación inicial a los roles de `001` (DEC-CLI-11). El código verifica el permiso, nunca el nombre del rol (AGENTS §7.3); después se gestiona desde la pantalla de roles.

| Permiso | Administrador | Gerente | Asesora de Ventas | Finanzas | Supervisor de Producción · Operario · Responsable de Calidad |
|---|---|---|---|---|---|
| `customers.view` | ✓ | ✓ | ✓ | ✓ | — |
| `customers.create` | ✓ | ✓ | ✓ | — | — |
| `customers.update` | ✓ | ✓ | ✓ | — | — |
| `customers.deactivate` | ✓ | ✓ | — | — | — |
| `customers.delete` | ✓ | — | — | — | — |
| `customers.assign` | ✓ | ✓ | — | — | — |
| `customers.portfolio` | — | — | ✓ | — | — |

- **Coherencia** (DEC-CLI-32, análoga a DEC-022 de `001`): al asignar permisos a cualquier rol, `customers.create`, `customers.update`, `customers.deactivate`, `customers.delete`, `customers.assign` y `customers.portfolio` exigen `customers.view`. Una asignación que no cumpla se rechaza y el rol no cambia. La matriz inicial de esta sección ya la cumple.
- `001` asigna todos los permisos al Administrador solo al crear el rol (`RoleSeeder`). `design.md` debe definir cómo se aplica esta matriz a una base de datos existente.

---

## 5. Modelo conceptual

Describe **qué** información existe, no su implementación. Tablas, índices y tipos se definen en `design.md`.

```text
Cliente
 ├── tipo                  (natural | empresa)           DEC-CLI-01
 ├── nombre / razón social
 ├── identificación        (tipo de documento + número, opcional, único) DEC-CLI-02, DEC-CLI-03
 ├── teléfono / WhatsApp
 ├── correo electrónico
 ├── Fecha de cumpleaños del cliente (dias/mes) (Opcional)
 ├── Fecha de aniversario de empresa (dias/mes) (Opcional)  - Solo empresas,                                   
 ├── observaciones
 ├── asesora asignada      (usuario interno, 0..1)       DEC-CLI-07, DEC-CLI-18
 ├── estado                (activo | inactivo)
 ├── creado por / fecha de creación
 ├── Persona de contacto   (0..1, solo empresa)          DEC-CLI-05
 │    └── nombre, cargo, teléfono, correo
 └── Dirección             (0..1)                        DEC-CLI-06
      └── dirección, ciudad, estado, referencia          DEC-CLI-17
```

- El estado del cliente se implementa como Enum de PHP (constitución §21.1).
- Los datos que las specs posteriores asocien al cliente (cotizaciones, pedidos, pagos) referencian al cliente; esta spec no los define.
- El cliente no tiene clasificación propia (línea de negocio ni origen). El origen comercial se registra en cada pedido (`006`, business rules §1) (DEC-CLI-10).

---

## 6. Requisitos

### CLI-001 — Registrar cliente

Un usuario con `customers.create` puede registrar un cliente con los datos mínimos obligatorios (DEC-CLI-04): **tipo**, **nombre o razón social** y **teléfono**. El resto de los datos es opcional. El cliente se crea en estado **activo** y la acción queda auditada.

### CLI-002 — Tipo de cliente

Todo cliente tiene un tipo: **persona natural** o **empresa** (DEC-CLI-01). Los entes públicos e instituciones se registran como empresa.

Campos que dependen del tipo:

- Nombre: nombre de la persona (natural) o razón social (empresa).
- Aniversario de la empresa: solo empresa (CLI-017).
- Persona de contacto: solo empresa (CLI-005).

El tipo se puede cambiar al editar el cliente (DEC-CLI-15):

- De natural a empresa: se conservan todos los datos.
- De empresa a natural: el backend elimina el aniversario de la empresa y la persona de contacto en la misma transacción que el cambio de tipo. La auditoría registra sus valores anteriores.
- La pantalla de edición advierte qué datos se eliminarán antes de guardar el cambio.
- El documento de identificación **no** se elimina automáticamente. Si no corresponde al nuevo tipo (CLI-003), el backend rechaza el guardado con un error de validación en ese campo hasta que el usuario lo cambie o lo vacíe en la misma edición (DEC-CLI-15).

### CLI-003 — Identificación del cliente

El cliente puede tener un documento de identificación compuesto por tipo y número (DEC-CLI-02):

- Es **opcional** al registrar y al editar el cliente. Se exige al confirmar un pedido; esa validación la aplica `006` (§10).
- Tipos admitidos según el tipo de cliente:
  - Persona natural: cédula V, cédula E o pasaporte.
  - Empresa: RIF J, G, V, E o P.
- El backend rechaza un tipo de documento que no corresponde al tipo de cliente y valida el formato del número según el tipo de documento (DEC-CLI-30):
  - Cédula V/E: solo dígitos, de 6 a 9.
  - RIF J/G/V/E/P: 8 dígitos más el dígito verificador, que el backend comprueba con el algoritmo del SENIAT.
  - Pasaporte: letras y números, de 5 a 20 caracteres.
- El usuario puede escribir el número con puntos, guiones o espacios; el backend los elimina y guarda el número en mayúsculas y sin separadores. La unicidad se compara sobre esa forma normalizada.
- El documento es único por tipo + número (CLI-012, DEC-CLI-03).

### CLI-004 — Datos de contacto

El cliente registra al menos un teléfono de contacto utilizable por WhatsApp. El teléfono se almacena normalizado en formato internacional (DT-01). Solo se admiten números de Venezuela (+58); el backend rechaza números de otros países (DEC-CLI-25). El teléfono del cliente debe ser un celular (04xx); el backend rechaza números fijos en este campo (DEC-CLI-26). El correo electrónico es opcional; si se informa, el backend valida su formato (DEC-CLI-04).

### CLI-005 — Persona de contacto

Un cliente de tipo empresa puede tener **una** persona de contacto, opcional, con nombre, cargo, teléfono y correo (DEC-CLI-05). Los clientes de tipo persona natural no tienen persona de contacto.

- Cuando se registra, la persona de contacto requiere **nombre** y **teléfono**; cargo y correo son opcionales (DEC-CLI-16).
- El teléfono se normaliza igual que el del cliente (DT-01) y admite celulares o fijos de Venezuela (DEC-CLI-25, DEC-CLI-26). El correo, si se informa, se valida en formato.

### CLI-006 — Dirección

Un cliente, de cualquier tipo, puede tener **una** dirección, opcional (DEC-CLI-06).

- Cuando se registra, la dirección requiere **dirección**, **ciudad** y **estado**; la referencia es opcional (DEC-CLI-17).
- El **estado** se elige de una lista cerrada con las 24 entidades federales de Venezuela (23 estados y el Distrito Capital); el backend rechaza cualquier otro valor. La ciudad es texto libre (DEC-CLI-33).
- La relación entre esta dirección y la dirección de entrega de un pedido se define en `006` (§10).

### CLI-007 — Observaciones

El cliente dispone de un campo de observaciones de texto libre, editable con `customers.update`.

### CLI-008 — Editar cliente

Un usuario con `customers.update` puede modificar los datos del cliente. Cada modificación queda auditada con los valores anteriores y nuevos de los campos cambiados. Las mismas validaciones del alta aplican a la edición.

### CLI-009 — Desactivar y reactivar cliente

Un usuario con `customers.deactivate` puede desactivar un cliente activo y reactivar uno inactivo.

- Desactivar requiere confirmación con `ConfirmDialog` (design system §7.10, UI-07). Reactivar no la requiere.
- Ninguna de las dos acciones borra datos ni relaciones.
- Ambas quedan auditadas.
- Desactivar un cliente que ya está inactivo, o reactivar uno que ya está activo, no produce cambios ni registro de auditoría, igual que en el módulo de Usuarios de `001`.
- Un cliente inactivo no admite cotizaciones ni pedidos nuevos; las cotizaciones y pedidos que ya estén en curso siguen su flujo normal (DEC-CLI-09). Estas reglas las aplican `004` y `006` (§10).
- Un cliente inactivo se puede editar (CLI-008) y reasignar (CLI-014) igual que uno activo (DEC-CLI-28).

### CLI-010 — Eliminación restringida

Un usuario con `customers.delete` puede eliminar un cliente **solo si no tiene historial**: ninguna cotización, pedido, pago ni otro registro que lo referencie (DEC-CLI-13).

- Uso previsto: duplicados, registros creados por error y datos de prueba. Los clientes con historial no se eliminan; se desactivan (CLI-009). Esto cumple AGENTS §7.9.
- Eliminar requiere confirmación con `ConfirmDialog` (UI-07), variante `danger` y verbo "Eliminar".
- La persona de contacto y la dirección forman parte del cliente: se eliminan junto con él, en la misma transacción.
- La eliminación queda auditada con una copia de los valores anteriores del cliente, su persona de contacto y su dirección.
- Si el cliente tiene historial, el backend rechaza la eliminación, indica el motivo y sugiere desactivarlo.
- En esta spec todavía no existen registros dependientes. Cada spec que introduzca uno (`004`, `006`, …) añade su condición de bloqueo a la eliminación y su escenario de prueba. Además, las claves foráneas de esos registros de historial hacia el cliente usan restricción de borrado, para que la base de datos impida la eliminación aunque falte la validación. La persona de contacto y la dirección no son historial y no bloquean la eliminación.

### CLI-011 — Listado y búsqueda

Un usuario con `customers.view` puede listar clientes:

- con vistas **Activos**, **Inactivos** y **Todos** (`SegmentedTabs`, design system §7.11), cuyos conteos calcula el backend;
- con la vista por defecto que se use en el listado de Usuarios de `001`;
- con búsqueda por nombre o razón social, número de documento y teléfono;
- con el filtro **Mis clientes**, disponible para usuarios con `customers.portfolio`, que muestra solo los clientes asignados al usuario y se combina con las vistas por estado y la búsqueda (DEC-CLI-08);
- paginado en el backend.

La fecha de la última compra y el orden por actividad reciente se añaden en `006`, cuando existan pedidos (§10).

### CLI-012 — Detección de duplicados

Al registrar o editar, el backend comprueba si ya existe otro cliente, activo o inactivo, con el mismo documento o el mismo teléfono (DEC-CLI-03):

- **Documento**: único por tipo de documento + número. Si ya existe, el backend rechaza el guardado con un error de validación. La base de datos garantiza la unicidad aunque falte la validación.
- **Teléfono**: puede repetirse. Si ya existe, el backend no guarda en el primer intento: devuelve una advertencia que identifica el cliente o los clientes que coinciden. El usuario puede confirmar, y entonces se guarda. La comparación es solo contra el teléfono principal de otros clientes (no contra personas de contacto) y solo se hace cuando el teléfono es nuevo o cambió; editar otros datos no repite la advertencia (DEC-CLI-27).

### CLI-013 — Ficha del cliente

Un usuario con `customers.view` puede consultar la ficha del cliente con sus datos, estado, persona de contacto, dirección, asesora asignada y observaciones. La ficha se estructura en secciones para que `004` y `006` añadan el historial de cotizaciones, pedidos y pagos sin rediseñarla.

### CLI-014 — Asesora asignada y visibilidad

El cliente puede tener **una** asesora asignada: un usuario interno responsable de su cartera (DEC-CLI-07).

- La asignación y cada reasignación quedan auditadas con la asesora anterior y la nueva (CLI-016).
- Reglas de asignación (DEC-CLI-18):
  - Solo pueden ser asesoras asignadas los usuarios **activos** con `customers.portfolio`.
  - Al registrar un cliente, se asigna automáticamente a quien lo crea si tiene `customers.portfolio`. Si no lo tiene, el cliente nace sin asesora.
  - Asignar o reasignar después del alta requiere `customers.assign`. El backend rechaza una asesora que no cumpla la primera regla.
  - La asesora asignada es opcional: un usuario con `customers.assign` puede dejar al cliente sin asesora.
  - Si la asesora asignada se desactiva o pierde `customers.portfolio`, sus clientes siguen asignados a ella; la ficha y el listado la marcan como "asesora no disponible" y un usuario con `customers.assign` los reasigna manualmente. No hay desasignación automática (DEC-CLI-29).
- La asignación no restringe la visibilidad: todo usuario con `customers.view` ve todos los clientes (DEC-CLI-08). La cartera se usa como filtro del listado (CLI-011).

La asignación **no** determina por sí sola la atribución de ventas ni comisiones (business rules §20); esa regla se define en `006` / `014`.

### CLI-015 — Autorización en backend

Toda ruta y acción de clientes valida el permiso correspondiente mediante Policies en el backend. Ocultar un botón no sustituye esta validación (AGENTS §7.2).

### CLI-016 — Auditoría

Se auditan el alta (incluida la importación, CLI-018), la edición, la desactivación, la reactivación, la eliminación y la asignación o reasignación de asesora (CLI-014), registrando usuario, acción, cliente afectado, fecha y hora, IP y valores antes y después (AGENTS §7.8).

### CLI-017 — Fechas conmemorativas

El cliente puede registrar fechas conmemorativas opcionales, expresadas solo como día y mes (sin año) (DEC-CLI-14):

- **Cumpleaños del cliente**: disponible para cualquier tipo de cliente.
- **Aniversario de la empresa**: disponible solo para clientes de tipo empresa. El backend rechaza este dato en otros tipos.

El backend valida que el día y el mes formen una fecha válida; el 29 de febrero se admite (DEC-CLI-31). Las fechas se muestran en la ficha y se editan con `customers.update`.

Esta spec **no** genera avisos. Los avisos de cumpleaños a las asesoras de venta, a Gerencia y a Administración los define la spec de Notificaciones (§10).

### CLI-018 — Carga inicial de clientes existentes

El equipo técnico puede cargar una única vez la lista de clientes existentes de Ecolekua mediante un comando de consola, sin pantalla en el ERP (DEC-CLI-19). La lista sigue creciendo durante el desarrollo; la carga se ejecuta una vez, con la lista final, al poner el sistema en producción.

- Cada cliente importado pasa por las mismas validaciones del alta (CLI-001 a CLI-006, CLI-012, CLI-017).
- Cada cliente creado queda auditado como un alta, identificando que proviene de la importación.
- El archivo es CSV codificado en UTF-8 (DEC-CLI-20). Las columnas se definen en `design.md` a partir de los campos de §5. Si el archivo no está en UTF-8 válido o no tiene las columnas esperadas, el comando no importa nada e indica el motivo.
- **Todo o nada** (DEC-CLI-21): el comando valida primero todas las filas, incluidas las coincidencias entre filas del mismo archivo. Si alguna falla, no importa ningún cliente y entrega un informe con número de fila, campo y motivo. Si todas son válidas, las importa en una sola transacción.
- **Teléfonos repetidos** (DEC-CLI-22), en el archivo o contra la base: el informe los lista como advertencia y el comando no importa. Solo se importan si se vuelve a ejecutar con una opción explícita de confirmación, después de revisarlos.
- **Asesora** (DEC-CLI-23): el archivo tiene una columna opcional con el correo de la asesora. Si se informa, debe corresponder a un usuario activo con `customers.portfolio`; si no, la fila es inválida. Si está vacía, el cliente queda sin asesora. La asignación automática de CLI-014 no se aplica en la importación.
- **Autor** (DEC-CLI-24): el comando exige indicar un usuario interno activo, que figura como creador de cada cliente y como autor de su registro de auditoría, marcado como importación. Si el usuario no existe o está inactivo, el comando no importa nada.
- El archivo de origen contiene datos reales de clientes: no se versiona en el repositorio ni se guarda en Engram (AGENTS §4.5, §11).

---

## 7. Escenarios de aceptación

Los escenarios marcados ⏳ se completan cuando se confirme la decisión indicada. Las pruebas de autorización golpean el backend por HTTP.

### E-01 — Registrar un cliente con datos válidos (CLI-001, CLI-016)

- **GIVEN** un usuario con `customers.create`
- **WHEN** envía los datos mínimos obligatorios válidos
- **THEN** el cliente se crea en estado activo
- **AND** se registra una entrada de auditoría con el usuario, la acción y los valores creados

### E-02 — Rechazar un alta sin datos obligatorios (CLI-001)

- **GIVEN** un usuario con `customers.create`
- **WHEN** envía el formulario sin un dato obligatorio
- **THEN** el backend rechaza la petición con un error de validación en ese campo
- **AND** no se crea ningún cliente

### E-03 — Registrar sin permiso (CLI-015)

- **GIVEN** un usuario autenticado sin `customers.create`
- **WHEN** envía una petición de alta de cliente
- **THEN** el backend responde 403
- **AND** no se crea ningún cliente

### E-04 — Consultar sin permiso (CLI-015)

- **GIVEN** un usuario autenticado sin `customers.view`
- **WHEN** solicita el listado o la ficha de un cliente
- **THEN** el backend responde 403

### E-05 — Editar un cliente (CLI-008, CLI-016)

- **GIVEN** un cliente existente y un usuario con `customers.update`
- **WHEN** modifica el teléfono del cliente
- **THEN** el cambio se guarda
- **AND** la auditoría registra el valor anterior y el nuevo del teléfono

### E-06 — Normalizar el teléfono (CLI-004) · DT-01

- **GIVEN** un usuario con `customers.create`
- **WHEN** registra un teléfono venezolano escrito en formato local
- **THEN** el teléfono se almacena en formato internacional
- **AND** se muestra en un formato legible

### E-07 — Desactivar un cliente (CLI-009, CLI-016)

- **GIVEN** un cliente activo y un usuario con `customers.deactivate`
- **WHEN** confirma la desactivación
- **THEN** el cliente pasa a inactivo
- **AND** sus datos y relaciones se conservan
- **AND** la acción queda auditada

### E-08 — Reactivar un cliente (CLI-009, CLI-016)

- **GIVEN** un cliente inactivo y un usuario con `customers.deactivate`
- **WHEN** lo reactiva
- **THEN** el cliente pasa a activo
- **AND** la acción queda auditada

### E-09 — Desactivar sin permiso (CLI-009, CLI-015)

- **GIVEN** un usuario con `customers.update` pero sin `customers.deactivate`
- **WHEN** envía la petición de desactivación
- **THEN** el backend responde 403
- **AND** el estado del cliente no cambia

### E-10 — Eliminar un cliente sin historial (CLI-010, CLI-016)

- **GIVEN** un cliente de tipo empresa sin historial, con persona de contacto y dirección, y un usuario con `customers.delete`
- **WHEN** confirma la eliminación
- **THEN** el cliente, su persona de contacto y su dirección dejan de existir
- **AND** la auditoría conserva quién lo eliminó, cuándo y una copia de los valores anteriores del cliente, su persona de contacto y su dirección

### E-11 — Buscar clientes (CLI-011)

- **GIVEN** clientes registrados con distintos nombres, documentos y teléfonos
- **WHEN** un usuario con `customers.view` busca por un fragmento del nombre, del documento o del teléfono
- **THEN** el listado devuelve solo los clientes que coinciden

### E-12 — Vistas por estado (CLI-011)

- **GIVEN** clientes activos e inactivos
- **WHEN** un usuario con `customers.view` selecciona la vista Inactivos
- **THEN** el listado muestra solo los clientes inactivos
- **AND** los conteos de cada vista coinciden con los datos del backend

### E-13 — Documento duplicado (CLI-012)

- **GIVEN** un cliente, activo o inactivo, registrado con un tipo y número de documento
- **WHEN** se intenta registrar o editar otro cliente con el mismo tipo y número
- **THEN** el backend rechaza la petición con un error de validación en el documento
- **AND** no se crea ni se modifica ningún cliente

### E-14 — Teléfono duplicado (CLI-012)

- **GIVEN** un cliente registrado con un teléfono
- **WHEN** se intenta registrar otro cliente con el mismo teléfono sin confirmar
- **THEN** el backend no crea el cliente y devuelve una advertencia que identifica el cliente que coincide
- **AND** si el usuario reenvía la petición confirmando, el cliente se crea
- **AND** al editar otro cliente para ponerle ese mismo teléfono, el backend devuelve la misma advertencia y solo guarda con confirmación
- **AND** al editar otros datos de un cliente cuyo teléfono ya coincide con el de otro cliente, sin cambiar el teléfono, el backend guarda sin advertencia (DEC-CLI-27)

### E-15 — Visibilidad y filtro Mis clientes (CLI-011, CLI-014)

- **GIVEN** dos asesoras con `customers.view` y `customers.portfolio`, cada una con clientes asignados
- **WHEN** una de ellas consulta el listado sin filtro
- **THEN** ve los clientes de ambas carteras y los que no tienen asesora
- **AND** al aplicar el filtro Mis clientes, ve solo los clientes asignados a ella

### E-16 — Persona de contacto de una empresa (CLI-005)

- **GIVEN** un cliente de tipo empresa sin persona de contacto y un usuario con `customers.update`
- **WHEN** registra una persona de contacto con los datos obligatorios
- **THEN** la persona de contacto queda asociada al cliente y se muestra en la ficha
- **AND** si intenta registrar una persona de contacto en un cliente de tipo persona natural, el backend lo rechaza con un error de validación

### E-17 — Dirección del cliente (CLI-006)

- **GIVEN** un cliente sin dirección y un usuario con `customers.update`
- **WHEN** registra una dirección con los datos obligatorios
- **THEN** la dirección queda asociada al cliente y se muestra en la ficha
- **AND** el cliente sigue teniendo una sola dirección

### E-18 — Eliminar sin permiso (CLI-010, CLI-015)

- **GIVEN** un cliente sin historial y un usuario con `customers.update` y `customers.deactivate`, pero sin `customers.delete`
- **WHEN** envía la petición de eliminación
- **THEN** el backend responde 403
- **AND** el cliente sigue existiendo

### E-19 — Eliminación bloqueada por historial (CLI-010)

- **GIVEN** un cliente con al menos un registro dependiente y un usuario con `customers.delete`
- **WHEN** envía la petición de eliminación
- **THEN** el backend la rechaza e indica el motivo
- **AND** el cliente y su historial siguen existiendo

> En `002` no existen registros dependientes. Este escenario se prueba en cada spec que introduzca uno (por ejemplo, "cliente con una cotización" en `004` y "cliente con un pedido" en `006`).

### E-20 — Registrar el cumpleaños del cliente (CLI-017)

- **GIVEN** un usuario con `customers.update` y un cliente de cualquier tipo
- **WHEN** registra un cumpleaños con un día y un mes válidos
- **THEN** la fecha se guarda sin año
- **AND** se muestra en la ficha del cliente

### E-21 — Rechazar una fecha conmemorativa inválida (CLI-017)

- **GIVEN** un usuario con `customers.update`
- **WHEN** registra un cumpleaños o un aniversario con una combinación de día y mes inexistente
- **THEN** el backend rechaza la petición con un error de validación en ese campo

### E-22 — Aniversario solo para empresas (CLI-017)

- **GIVEN** un usuario con `customers.update`
- **WHEN** registra un aniversario de empresa en un cliente de tipo empresa
- **THEN** la fecha se guarda
- **AND** si lo intenta en un cliente de otro tipo, el backend lo rechaza con un error de validación

### E-23 — Cambiar una empresa a persona natural (CLI-002, CLI-016)

- **GIVEN** un cliente de tipo empresa con aniversario y persona de contacto cargados y un usuario con `customers.update`
- **WHEN** cambia el tipo a persona natural
- **THEN** el cliente queda como persona natural, sin aniversario ni persona de contacto
- **AND** la auditoría registra el cambio de tipo y los valores eliminados

### E-24 — Cambio de tipo con documento incompatible (CLI-002, CLI-003)

- **GIVEN** un cliente de tipo empresa con RIF J y un usuario con `customers.update`
- **WHEN** cambia el tipo a persona natural sin cambiar el documento
- **THEN** el backend rechaza la petición con un error de validación en el documento
- **AND** el cliente conserva su tipo y sus datos sin cambios

### E-25 — Asignación automática al crear (CLI-014)

- **GIVEN** un usuario con `customers.create` y `customers.portfolio`
- **WHEN** registra un cliente
- **THEN** el cliente queda asignado a ese usuario
- **AND** si el usuario no tiene `customers.portfolio`, el cliente queda sin asesora

### E-26 — Reasignar la asesora (CLI-014, CLI-016)

- **GIVEN** un cliente asignado a una asesora, otra asesora activa con `customers.portfolio` y un usuario con `customers.assign`
- **WHEN** reasigna el cliente a la otra asesora
- **THEN** el cliente queda asignado a la nueva asesora
- **AND** la auditoría registra la asesora anterior y la nueva

### E-27 — Reasignar sin permiso (CLI-014, CLI-015)

- **GIVEN** un usuario con `customers.update` pero sin `customers.assign`
- **WHEN** envía una petición para cambiar la asesora de un cliente
- **THEN** el backend responde 403
- **AND** la asesora del cliente no cambia

### E-28 — Asesora no elegible (CLI-014)

- **GIVEN** un usuario con `customers.assign`
- **WHEN** intenta asignar un cliente a un usuario inactivo o sin `customers.portfolio`
- **THEN** el backend rechaza la petición con un error de validación
- **AND** la asesora del cliente no cambia

### E-29 — Importar un archivo válido (CLI-018, CLI-016)

- **GIVEN** un CSV UTF-8 con filas válidas, alguna con correo de asesora elegible, y un usuario interno activo indicado como autor
- **WHEN** se ejecuta el comando de importación
- **THEN** se crean todos los clientes, activos, con su asesora cuando la fila la indica
- **AND** cada cliente figura creado por el usuario indicado y tiene su registro de auditoría marcado como importación

### E-30 — Importación con una fila inválida (CLI-018)

- **GIVEN** un CSV en el que una fila no cumple las validaciones del alta (por ejemplo, sin teléfono o con un documento ya registrado)
- **WHEN** se ejecuta el comando de importación
- **THEN** no se crea ningún cliente
- **AND** el informe indica el número de fila, el campo y el motivo

### E-31 — Teléfonos repetidos en la importación (CLI-018, CLI-012)

- **GIVEN** un CSV válido con un teléfono que ya existe en la base o se repite en el archivo
- **WHEN** se ejecuta el comando sin la opción de confirmación
- **THEN** no se crea ningún cliente y el informe lista las coincidencias como advertencia
- **AND** al ejecutarlo con la opción de confirmación, se crean todos los clientes

### E-32 — Asesora o autor no válidos en la importación (CLI-018)

- **GIVEN** un CSV cuya columna de asesora indica un usuario inactivo o sin `customers.portfolio`, o un autor inexistente o inactivo
- **WHEN** se ejecuta el comando de importación
- **THEN** no se crea ningún cliente
- **AND** el informe indica el motivo

### E-33 — Asesora no disponible (CLI-014)

- **GIVEN** un cliente asignado a una asesora que después se desactiva
- **WHEN** un usuario con `customers.view` consulta la ficha o el listado
- **THEN** el cliente sigue asignado a esa asesora
- **AND** se indica que la asesora no está disponible

### E-34 — Validar el número de documento (CLI-003)

- **GIVEN** un usuario con `customers.create`
- **WHEN** registra un cliente con un documento que no cumple su formato: una cédula con menos de 6 o más de 9 dígitos, un RIF con dígito verificador incorrecto o un pasaporte con menos de 5 o más de 20 caracteres
- **THEN** el backend rechaza la petición con un error de validación en el documento
- **AND** no se crea ningún cliente

### E-35 — Tipo de documento según el tipo de cliente (CLI-003)

- **GIVEN** un usuario con `customers.create`
- **WHEN** registra un cliente de tipo persona natural con un RIF J, o un cliente de tipo empresa con una cédula
- **THEN** el backend rechaza la petición con un error de validación en el documento

### E-36 — Normalizar el documento antes de comparar (CLI-003, CLI-012)

- **GIVEN** un cliente registrado con un RIF válido escrito con guiones
- **WHEN** se registra otro cliente con el mismo RIF escrito en minúsculas, con puntos y espacios
- **THEN** el backend lo reconoce como el mismo documento y rechaza el alta por duplicado
- **AND** el documento del primer cliente está guardado en mayúsculas y sin separadores

### E-37 — Teléfono del cliente: solo celulares de Venezuela (CLI-004)

- **GIVEN** un usuario con `customers.create`
- **WHEN** registra un cliente con un teléfono fijo venezolano o con un número de otro país
- **THEN** el backend rechaza la petición con un error de validación en el teléfono

### E-38 — Teléfono fijo en la persona de contacto (CLI-005)

- **GIVEN** un cliente de tipo empresa y un usuario con `customers.update`
- **WHEN** registra una persona de contacto con un teléfono fijo venezolano
- **THEN** la persona de contacto se guarda con el teléfono normalizado
- **AND** si el teléfono es de otro país, el backend lo rechaza con un error de validación

### E-39 — Importación con un archivo ilegible (CLI-018)

- **GIVEN** un archivo que no está en UTF-8 válido o al que le faltan columnas esperadas
- **WHEN** se ejecuta el comando de importación
- **THEN** no se crea ningún cliente
- **AND** el comando indica el motivo

### E-40 — Cambio de estado sin efecto (CLI-009)

- **GIVEN** un cliente inactivo y un usuario con `customers.deactivate`
- **WHEN** envía la petición de desactivación
- **THEN** el cliente sigue inactivo
- **AND** no se registra ninguna entrada de auditoría

### E-41 — Coherencia de permisos de clientes (§4, DEC-CLI-32)

- **GIVEN** un usuario con permiso para gestionar roles y un rol sin `customers.view`
- **WHEN** intenta asignarle `customers.update` (o cualquier otro permiso `customers.*` distinto de `customers.view`) sin `customers.view`
- **THEN** el backend rechaza la asignación con un error de validación
- **AND** los permisos del rol no cambian

---

## 8. Interfaz (ERP)

Aplica `docs/ui/design-system.md` completo. Puntos específicos de esta spec:

| Pantalla | Componentes y reglas |
|---|---|
| Listado de clientes | `DataTable` (tabla en `lg:`, pila de `AppCard` en `< md`), `SegmentedTabs` para las vistas, búsqueda con `AppInput` e icono `search`, `StatusBadge` para el estado (activo → `done`, inactivo → `neutral`) |
| Ficha del cliente | Secciones en `AppCard`: datos generales, contacto, persona de contacto (solo empresa), dirección, observaciones. Espacio reservado para las secciones de `004` y `006` |
| Alta y edición | Campos con etiqueta visible, errores enlazados con `aria-describedby`, `type="tel"` e `inputmode="tel"` en teléfonos, `type="email"` en correos, texto ≥16px |
| Desactivar | `ConfirmDialog` con variante `danger` y el verbo "Desactivar"; texto de consecuencia: el cliente no podrá recibir cotizaciones ni pedidos nuevos, y lo que esté en curso continúa (DEC-CLI-09) |
| Eliminar | Acción visible solo con `customers.delete`. `ConfirmDialog` con variante `danger`, verbo "Eliminar" y texto que indica que la acción es irreversible. Si el backend la rechaza por historial, se muestra el motivo y se ofrece desactivar |

- Mobile-first: las asesoras usarán principalmente el teléfono y tablets (constitución §14).
- Objetivos táctiles ≥44px, sin clases `dark:` ni hexadecimales.
- Textos visibles en español; código, rutas e identificadores en inglés.

---

## 9. Decisiones de esta Spec

| ID | Decisión | Opciones | Estado | Recomendación técnica |
|---|---|---|---|---|
| DEC-CLI-01 | Tipos de cliente | A) Natural y empresa · B) Natural, empresa y ente público/institución | **Confirmada** (2026-09-30): **A** | — Los entes públicos se registran como empresa |
| DEC-CLI-02 | Documento de identificación | Tipos admitidos (cédula V/E, RIF J/G/V/E/P, pasaporte…) y si es obligatorio al crear o solo al confirmar un pedido | **Confirmada** (2026-09-30): opcional al crear; exigido al confirmar un pedido (`006`). Natural: cédula V/E o pasaporte · Empresa: RIF J/G/V/E/P | — No frena a los contactos que entran por WhatsApp |
| DEC-CLI-03 | Unicidad y duplicados | Documento: bloquear o advertir · Teléfono: bloquear o advertir | **Confirmada** (2026-09-30): documento único por tipo + número (bloquear); teléfono advierte y permite guardar con confirmación | — Una empresa y su contacto pueden compartir teléfono |
| DEC-CLI-04 | Datos mínimos obligatorios | A) Nombre + teléfono · B) Nombre + teléfono + correo | **Confirmada** (2026-09-30): **A** (tipo + nombre/razón social + teléfono; correo opcional) | — Muchos clientes llegan por WhatsApp sin correo |
| DEC-CLI-05 | Personas de contacto de empresa | A) Una sola · B) Varias · C) No se registran | **Confirmada** (2026-09-30): **A**, una sola, opcional, solo para empresas | — |
| DEC-CLI-06 | Direcciones del cliente | A) Varias reutilizables · B) Una · C) Solo en cada pedido | **Confirmada** (2026-09-30): **B**, una sola dirección opcional por cliente | — |
| DEC-CLI-07 | Asesora asignada (cartera) | A) Sí · B) No | **Confirmada** (2026-09-30): **A**, una asesora por cliente | — La asignación no decide por sí sola la comisión (business rules §20) |
| DEC-CLI-08 | Visibilidad para asesoras | A) Ven todos los clientes · B) Solo los asignados | **Confirmada** (2026-09-30): **A**, todos ven todos los clientes, con filtro "Mis clientes" | — Sin necesidad de confidencialidad entre asesoras; evita duplicados de clientes ajenos |
| DEC-CLI-09 | Efecto de un cliente inactivo | Si puede recibir cotizaciones y pedidos nuevos; qué ocurre con los que están en curso | **Confirmada** (2026-09-30): **A**, sin cotizaciones ni pedidos nuevos; lo que está en curso continúa | — No frenar la producción ni la entrega de lo ya cobrado |
| DEC-CLI-10 | Clasificación del cliente | Línea de negocio (pañales/uniformes) y/o origen del cliente, o solo el origen de cada pedido | **Confirmada** (2026-09-30): **A**, el cliente no se clasifica; el origen vive en cada pedido (`006`, business rules §1) | — La línea de negocio se deduce de lo que compra |
| DEC-CLI-11 | Permisos iniciales por rol | Qué roles reciben cada permiso `customers.*` | **Confirmada** (2026-09-30): matriz de §4 | — Roles reales de `001` (`RoleSeeder`) |
| DEC-CLI-12 | Código visible de cliente | A) Código correlativo visible · B) Sin código visible | **Confirmada** (2026-09-30): **B**, el cliente no tiene código visible; se identifica por nombre y documento | — |
| DEC-CLI-13 | Eliminación de clientes | A) Solo clientes sin ningún historial · B) Clientes sin pedidos activos (borrado lógico o anonimización) | **Confirmada** (2026-09-30): **A** | — La preocupación por el volumen de datos se resuelve en el listado (última compra y orden por actividad en `006`), no eliminando clientes |
| DEC-CLI-14 | Avisos de fechas conmemorativas | A) `002` solo guarda y muestra las fechas; los avisos van en la spec de Notificaciones · B) `002` incluye los avisos | **Confirmada** (2026-09-30): **A** | — La constitución (§4, §14) define Notificaciones como un módulo propio con su estrategia de canales |
| DEC-CLI-15 | Cambio de tipo de cliente | A) No se permite · B) Se permite; al pasar de empresa a natural se eliminan aniversario y contactos, con auditoría · C) Se permite solo si no hay aniversario ni contactos | **Confirmada** (2026-09-30): **B**. El documento incompatible con el nuevo tipo no se elimina: el backend rechaza el guardado hasta que se cambie o se vacíe | — |
| DEC-CLI-16 | Datos obligatorios de la persona de contacto | A) Nombre + teléfono · B) Solo nombre · C) Nombre + teléfono + correo | **Confirmada** (2026-09-30): **A** | — Un contacto sin teléfono no sirve para coordinar por WhatsApp |
| DEC-CLI-17 | Datos obligatorios de la dirección | A) Dirección + ciudad + estado; referencia opcional · B) Solo dirección (texto libre) · C) Todos los campos opcionales | **Confirmada** (2026-09-30): **A** | — Ciudad y estado separados permiten agrupar entregas y reportes |
| DEC-CLI-18 | Reglas de asignación de asesora | Quién asigna y reasigna, qué usuarios pueden ser asesoras, si es obligatoria y si se asigna automáticamente a quien crea el cliente | **Confirmada** (2026-09-30): **A**. Automática a quien crea si tiene `customers.portfolio`; reasignación con `customers.assign`; asignación opcional | — Permisos, no nombres de rol (AGENTS §7.3) |
| DEC-CLI-19 | Importación de clientes existentes | A) Carga inicial única por comando técnico · B) Función permanente en el ERP | **Confirmada** (2026-09-30): **A** | — La lista se carga una sola vez al arrancar |
| DEC-CLI-20 | Formato del archivo de importación | A) CSV · B) Excel (.xlsx) | **Confirmada** (2026-09-30): **A**, CSV en UTF-8 | — Sin dependencias nuevas para una carga única |
| DEC-CLI-21 | Filas con errores en la importación | A) Todo o nada · B) Importar las válidas y reportar las inválidas | **Confirmada** (2026-09-30): **A** | — La base nunca queda a medio cargar |
| DEC-CLI-22 | Teléfonos duplicados en la importación | A) Advertencia en el informe; se importan solo si se confirma con una opción explícita del comando · B) Error que bloquea · C) Se importan y solo se listan | **Confirmada** (2026-09-30): **A** | — Respeta la regla de advertir y confirmar de CLI-012 |
| DEC-CLI-23 | Asesora de los clientes importados | A) Columna opcional con el correo de la asesora · B) Todos sin asesora; se asignan después | **Confirmada** (2026-09-30): **A** | — Las asesoras deben existir como usuarios antes de importar |
| DEC-CLI-24 | Autor de los clientes importados | A) El comando exige indicar un usuario interno activo, que figura como creador y como autor en la auditoría · B) Un usuario técnico "Sistema" | **Confirmada** (2026-09-30): **A** | — Sin usuarios ficticios en el modelo de `001` |
| DEC-CLI-25 | Alcance de los teléfonos | A) Solo Venezuela (+58) · B) Venezuela por defecto y cualquier país con +código | **Confirmada** (2026-09-30): **A** | — Sin dependencias nuevas; validación simple |
| DEC-CLI-26 | Teléfonos fijos | A) Solo celulares en todos los campos · B) Cliente: solo celular; persona de contacto: celular o fijo · C) Fijos en todos los campos | **Confirmada** (2026-09-30): **B** | — El teléfono del cliente debe servir para WhatsApp (CLI-004) |
| DEC-CLI-27 | Alcance del aviso de teléfono duplicado | A) Solo contra el teléfono principal de otros clientes, cuando el teléfono es nuevo o cambió · B) A + teléfonos de personas de contacto | **Confirmada** (2026-09-30): **A** | — |
| DEC-CLI-28 | Edición de clientes inactivos | A) Se editan y reasignan igual que los activos · B) Solo lectura hasta reactivar | **Confirmada** (2026-09-30): **A** | — |
| DEC-CLI-29 | Cartera de una asesora no disponible | A) Los clientes siguen asignados, marcados "asesora no disponible", reasignación manual · B) Desasignación automática | **Confirmada** (2026-09-30): **A** | — No modifica `001` |
| DEC-CLI-30 | Validación del número de documento | A) Formato + dígito verificador del RIF · B) Solo formato | **Confirmada** (2026-09-30): **A**. Cédula 6–9 dígitos; RIF 8 dígitos + verificador; pasaporte 5–20 alfanuméricos; se guarda sin separadores y en mayúsculas | — |
| DEC-CLI-31 | 29 de febrero en fechas conmemorativas | A) Válido · B) Rechazado | **Confirmada** (2026-09-30): **A** | — En años no bisiestos, la spec de Notificaciones decide el día del aviso |
| DEC-CLI-32 | Coherencia de permisos `customers.*` | A) Los permisos de escritura, asignación y cartera exigen `customers.view` · B) Permisos independientes | **Confirmada** (2026-09-30): **A** | — Misma regla y mismo comportamiento que DEC-022 de `001` |
| DEC-CLI-33 | Valores del campo "estado" de la dirección | A) Lista cerrada de las 24 entidades federales de Venezuela · B) Texto libre | **Confirmada** (2026-09-30): **A**; la ciudad sigue siendo texto libre | — Hace útil la agrupación de entregas y reportes de DEC-CLI-17 |

### Decisiones técnicas

| ID | Decisión | Estado |
|---|---|---|
| DT-01 | Guardar el teléfono normalizado en formato internacional (E.164), con prefijo por defecto +58 | Propuesta — se confirma en `design.md` |

---

## 10. Compromisos para specs posteriores

| Spec | Compromiso |
|---|---|
| `004` | Añadir "tiene cotizaciones" como condición de bloqueo de CLI-010, con su escenario E-19 |
| `006` | Añadir "tiene pedidos o pagos" como condición de bloqueo de CLI-010, con su escenario E-19 |
| `006` | Exigir que el cliente tenga documento de identificación para confirmar un pedido (CLI-003, DEC-CLI-02) |
| `004` / `006` | Rechazar cotizaciones y pedidos nuevos para clientes inactivos, sin afectar a los que están en curso (CLI-009, DEC-CLI-09) |
| `006` / `011` | Definir si la dirección del cliente (CLI-006) se propone como dirección de entrega del pedido y si el pedido guarda su propia copia |
| `006` | Mostrar la **fecha de la última compra** en el listado y la ficha del cliente, y permitir **ordenar por actividad reciente**. No se incluye un filtro de inactividad ("sin compras en X meses"): la fecha de la última compra es suficiente (decisión del usuario, 2026-09-30) |
| Notificaciones | Avisar de los cumpleaños de los clientes (CLI-017) a las asesoras de venta, a Gerencia y a Administración. Esa spec define los destinatarios por permiso (AGENTS §7.3), el canal (constitución §14), la antelación, si el aniversario de empresa también genera aviso la regla contra la saturación (DEC-CLI-14) y qué día se avisa de un 29 de febrero en años no bisiestos (DEC-CLI-31) |

---

## 11. Trazabilidad con las fuentes

| Fuente | Aporta |
|---|---|
| Informe de propuesta §4 | Registro centralizado, datos personales o empresariales, contacto, observaciones, historial |
| Informe de propuesta §16 | Roles iniciales para la asignación de permisos |
| Constitución §9 | Separación entre usuarios internos y clientes; autorización en servidor |
| Constitución §10 | Auditoría de acciones sensibles |
| Constitución §14 | Mobile-first para asesoras |
| Business rules §4 | Cliente válido como condición para confirmar un pedido |
| Business rules §20 | La asignación no implica atribución de ventas web |
| AGENTS §7 | Backend como autoridad, permisos por nombre, sin borrado físico de clientes con historial, auditoría inmutable |

---

## 12. Requisitos no funcionales y Definition of Done

- [ ] Cada escenario E-xx tiene al menos una prueba Pest cuyo nombre empieza por su ID, salvo E-19, que no es comprobable en `002` porque aún no existen registros dependientes; se prueba en `004` y `006` (§10).
- [ ] Las pruebas se ejecutan sobre MySQL (base `testing` de Sail).
- [ ] Autorización con Policies; ninguna comprobación por nombre de rol.
- [ ] Alta, edición, desactivación, reactivación y eliminación dentro de Actions (`app/Actions/Customers/`) con transacción cuando afecten a varias entidades (cliente + persona de contacto + dirección).
- [ ] Claves foráneas hacia el cliente con restricción de borrado.
- [ ] Seeders solo con datos ficticios; ningún dato real de clientes en seeds ni en Engram.
- [ ] `sail artisan test`, `sail pint --test` y `sail pnpm build` en verde.
- [ ] Checklist del design system (§11) verificado a 375, 768 y 1280 px.
- [x] Todas las decisiones de §9 en estado **Confirmada** antes de pasar a Design.
