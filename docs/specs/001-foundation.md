# Specification 001 — Foundation

| Campo | Valor |
|---|---|
| Spec | 001 |
| Nombre | Foundation |
| Versión | 1.2 |
| Estado | Confirmada |
| Documentos rectores | `docs/constitution.md`, `docs/business/business-rules.md` |

### Historial de revisiones

| Versión | Cambios |
|---|---|
| 1.0 | Versión inicial. |
| 1.1 | Unificada longitud mínima de contraseña (FND-013). Corregida referencia de "Operación sensible". Definidas operación pública y protección por defecto (FND-026, E-30, BR-FND-010, DEC-015). Aclarado que clientes y visitantes del portal no son usuarios (DEC-014). Actor en auditoría de operaciones públicas (FND-023). Orden y dependencias de specs alineados con el cronograma comprometido (sección 15, DEC-016). |
| 1.2 | Decisiones surgidas en Design: reinicio del contador de intentos fallidos (DEC-017), mensaje durante el bloqueo (DEC-018), zona horaria de operación (DEC-019), acciones del administrador sobre sí mismo (DEC-020), creación del primer administrador (DEC-021). |

---

## 1. Propósito

Esta especificación define la base funcional sobre la cual se construirán los demás módulos del Sistema de Gestión Empresarial de Ecolekua.

Establece:

- autenticación y sesión;
- usuarios y su ciclo de vida;
- roles y permisos;
- autorización;
- auditoría;
- seguridad básica.

El resultado debe permitir que los módulos posteriores utilicen una estructura de usuarios y autorización consistente, agregando sus propios permisos sin modificar este modelo.

---

## 2. Contexto

Ecolekua requiere un sistema interno para gestionar sus operaciones comerciales, administrativas y productivas.

El sistema será utilizado por personas con responsabilidades distintas. El acceso de cada una debe controlarse mediante permisos asignados a través de roles.

---

## 3. Alcance 

Esta especificación incluye:

1. Autenticación de usuarios (inicio y cierre de sesión).
2. Gestión de sesiones.
3. Gestión de usuarios (alta, consulta, modificación, activación, desactivación).
4. Gestión de contraseñas (contraseña temporal, cambio obligatorio, cambio propio, restablecimiento por administrador).
5. Roles.
6. Catálogo de permisos.
7. Asignación de roles a usuarios.
8. Autorización en backend.
9. Protección de rutas y operaciones.
10. Protecciones administrativas.
11. Auditoría de acciones relevantes.

---

## 4. Fuera de Alcance

Esta especificación no incluye:

- Recuperación de contraseña de autoservicio por correo electrónico.
- Autenticación de dos factores.
- Inicio de sesión con proveedores externos (Google, Microsoft, etc.).
- Registro público de usuarios internos. El registro de clientes desde el portal se define en las specs 015 y 016.
- Edición de datos personales por el propio usuario (salvo su contraseña).
- Configuración general del sistema. Cada configuración será introducida por la spec que la necesite.
- Clientes, productos, inventario, materiales, cotizaciones, ventas, pedidos, pagos, producción, control de tiempos, calidad, reprocesos, entregas, comisiones, WhatsApp, notificaciones, reportes, dashboard gerencial, portal público, portal de clientes y aplicación móvil.

Estos componentes serán definidos en especificaciones posteriores o en revisiones documentadas de esta spec.

Referencia no normativa: existe un portal web público previo (maquetación HTML/CSS sin lógica) que se integrará en la misma aplicación y alojará el cotizador. Su comportamiento se define en la spec 015; esta spec solo establece la distinción entre operaciones públicas y protegidas (FND-019, FND-026).

---

## 5. Modelo Organizacional

El sistema pertenece a una única empresa: **Ecolekua**.

No se requiere:

- multiempresa;
- multi-tenancy;
- `tenant_id`;
- selección de empresa;
- aislamiento entre empresas;
- cuentas empresariales múltiples.

La existencia de múltiples usuarios representa diferentes personas que trabajan en Ecolekua, no diferentes empresas.

---

## 6. Glosario

| Término | Definición |
|---|---|
| Usuario | Persona que trabaja en Ecolekua y está autorizada para utilizar el sistema interno. |
| Visitante / Cliente del portal | Persona externa a Ecolekua que utiliza el portal público o el portal de clientes. No es un Usuario en el sentido de esta spec (ver DEC-014). |
| Rol | Conjunto nombrado de permisos asociado a una responsabilidad dentro de Ecolekua. |
| Permiso | Autorización para ejecutar una acción concreta del sistema. |
| Permisos efectivos | Unión de los permisos de todos los roles asignados a un usuario. |
| Operación pública | Operación que una spec declara explícitamente accesible sin autenticación de Usuario. |
| Operación protegida | Cualquier operación no declarada pública. Requiere un Usuario autenticado. |
| Operación sensible | Operación que debe quedar registrada en auditoría (ver FND-022). |
| Contraseña temporal | Contraseña asignada por un administrador que debe cambiarse en el siguiente inicio de sesión. |

---

## 7. Actores y Roles Iniciales

El sistema se inicializa con los siguientes roles:

| Rol | Descripción | Permisos en esta spec |
|---|---|---|
| Administrador | Administra usuarios, roles y auditoría. | Todos los de la sección 9 |
| Gerente | Supervisión general del negocio. | Ninguno |
| Asesora de Ventas | Funciones comerciales. | Ninguno |
| Supervisor de Producción | Supervisión de producción. | Ninguno |
| Operario | Ejecución de producción. | Ninguno |
| Responsable de Calidad | Control de calidad. | Ninguno |
| Finanzas | Funciones financieras y administrativas. | Ninguno |

Los roles distintos de Administrador existen desde el inicio para permitir la asignación de usuarios, pero no tienen permisos en esta spec. Sus permisos serán asignados por las specs que definan las funcionalidades correspondientes.

Referencia no normativa: para el rol Finanzas se prevén funciones de seguimiento de pedidos, compras, inventario, cierre de pedidos y notificación al cliente. Estas funciones se especificarán en las specs 005, 006, 011 y 012 y no forman parte de esta spec.

---

## 8. Requisitos Funcionales

### Autenticación y sesión

#### FND-001 — Autenticación

El sistema debe permitir que un usuario registrado y activo se autentique mediante su correo electrónico y su contraseña.

Un usuario no autenticado no debe poder acceder a operaciones protegidas.

#### FND-002 — Mensaje de autenticación fallida

Ante credenciales incorrectas, correo inexistente o usuario inactivo, el sistema debe mostrar el mismo mensaje genérico de error, sin revelar cuál de las condiciones ocurrió.

#### FND-003 — Limitación de intentos

Tras 5 intentos fallidos consecutivos de inicio de sesión para un mismo correo electrónico, el sistema debe bloquear temporalmente nuevos intentos para ese correo durante 15 minutos.

Un inicio de sesión exitoso reinicia el contador de intentos fallidos.

#### FND-004 — Cierre de sesión

Un usuario autenticado debe poder cerrar su sesión. Tras cerrarla, la sesión no debe permitir acceder a operaciones protegidas.

#### FND-005 — Expiración de sesión

Una sesión debe expirar tras 120 minutos de inactividad.

Una sesión expirada no debe permitir acceder a operaciones protegidas y el usuario debe volver a autenticarse.

#### FND-006 — Invalidación de sesiones

Cuando un usuario es desactivado, todas sus sesiones abiertas deben quedar invalidadas de inmediato. La siguiente petición de ese usuario debe ser rechazada.

Cuando cambian los roles de un usuario, o los permisos de un rol, el cambio debe aplicarse a partir de la siguiente petición del usuario afectado, sin necesidad de volver a iniciar sesión.

### Usuarios

#### FND-007 — Identidad del Usuario

Cada usuario debe disponer como mínimo de:

- identificador;
- nombre;
- apellido;
- correo electrónico;
- contraseña almacenada de forma segura;
- estado (activo o inactivo);
- indicador de cambio de contraseña obligatorio;
- fecha de creación;
- fecha de actualización.

Los campos adicionales se definirán cuando exista una necesidad funcional concreta.

#### FND-008 — Unicidad del Correo Electrónico

El correo electrónico es el identificador de inicio de sesión y debe ser único entre todos los usuarios, activos e inactivos.

El correo electrónico debe normalizarse (sin espacios al inicio o al final y en minúsculas) antes de guardarse y antes de compararse.

Un usuario inactivo conserva su correo electrónico; este no puede reutilizarse para otro usuario.

#### FND-009 — Estado del Usuario

Un usuario debe encontrarse activo o inactivo.

Un usuario inactivo no debe poder iniciar sesión ni ejecutar operaciones protegidas.

#### FND-010 — Gestión de Usuarios

Un usuario con los permisos correspondientes debe poder:

- consultar el listado y el detalle de usuarios (`users.view`);
- crear usuarios (`users.create`);
- modificar nombre, apellido y correo electrónico de un usuario (`users.update`);
- activar y desactivar usuarios (`users.deactivate`);
- restablecer la contraseña de un usuario (`users.reset_password`);
- asignar y retirar roles a un usuario (`users.assign_roles`).

La eliminación física de usuarios no está permitida.

Todo usuario nuevo se crea en estado activo, con una contraseña temporal y con al menos un rol asignado.

#### FND-011 — Historial de Usuarios Inactivos

La desactivación de un usuario no debe eliminar ni alterar su historial. Las acciones históricas realizadas por dicho usuario deben permanecer asociadas a su identidad.

### Contraseñas

#### FND-012 — Seguridad de Contraseñas

Las contraseñas nunca deben almacenarse ni registrarse en texto plano, incluidos los registros de auditoría y los logs técnicos.

Deben utilizarse mecanismos de hashing seguros proporcionados por el framework o por librerías reconocidas.

#### FND-013 — Política de Contraseñas

Toda contraseña debe tener al menos 10 caracteres.

Al cambiar su contraseña, un usuario no puede reutilizar la contraseña actual.

#### FND-014 — Contraseña Temporal y Cambio Obligatorio

Cuando un administrador crea un usuario o restablece su contraseña, asigna una contraseña temporal y el usuario queda marcado con cambio de contraseña obligatorio.

Mientras tenga el cambio obligatorio pendiente, el usuario solo puede cambiar su contraseña o cerrar sesión. Cualquier otra operación protegida debe ser rechazada.

Restablecer la contraseña de un usuario invalida todas sus sesiones abiertas.

#### FND-015 — Cambio de Contraseña Propia

Todo usuario autenticado debe poder cambiar su propia contraseña, indicando su contraseña actual. Esta operación no requiere permisos adicionales.

### Roles y permisos

#### FND-016 — Roles

El sistema debe permitir, a un usuario con el permiso correspondiente:

- consultar roles y sus permisos (`roles.view`);
- crear roles, modificar su nombre y descripción, y asignar o retirar permisos a un rol (`roles.manage`);
- eliminar roles (`roles.manage`).

El nombre de un rol debe ser único.

Un rol no puede eliminarse mientras tenga usuarios asignados.

El rol Administrador no puede eliminarse ni renombrarse.

El nombre de un rol no concede acceso por sí mismo. El acceso efectivo se determina exclusivamente por permisos.

#### FND-017 — Catálogo de Permisos

Los permisos forman un catálogo definido por las especificaciones del sistema. No pueden crearse, modificarse ni eliminarse desde la interfaz.

Cada spec que introduzca funcionalidades protegidas debe declarar sus permisos siguiendo la nomenclatura `modulo.accion` (por ejemplo, `users.create`).

Al incorporarse un permiso nuevo, este no se asigna automáticamente a ningún rol, salvo que la spec que lo introduce lo indique.

#### FND-018 — Asignación de Roles

Un usuario activo debe tener al menos un rol y puede tener varios.

Los permisos efectivos de un usuario son la unión de los permisos de todos sus roles.

No existe acceso implícito total: ningún rol, incluido Administrador, obtiene permisos que no le hayan sido asignados explícitamente.

### Autorización

#### FND-019 — Autorización en Backend

Toda operación protegida debe validar en el backend la autenticación y, cuando corresponda, los permisos del usuario.

Ocultar una opción en el frontend no constituye una medida de autorización.

Un usuario no autenticado que intente acceder a una operación protegida debe ser rechazado y dirigido a iniciar sesión.

Un usuario autenticado sin el permiso requerido debe recibir una respuesta de acceso denegado, sin ejecutarse ningún efecto de la operación.

#### FND-026 — Protección por Defecto y Operaciones Públicas

Toda operación del sistema es protegida por defecto.

Una operación solo puede ser pública si una spec la declara explícitamente como tal. Esta spec no declara ninguna operación pública distinta del inicio de sesión.

Las operaciones públicas no conceden acceso a operaciones protegidas ni a datos internos no declarados por la spec que las define.

La autenticación de Visitantes o Clientes del portal, si existe, se define en las specs 015 y 016 y no concede roles ni permisos del modelo de esta spec.

### Protecciones administrativas

#### FND-020 — Protección contra Pérdida de Administración

El sistema debe impedir cualquier operación que deje sin al menos un usuario activo que posea los permisos `users.assign_roles` y `roles.manage`. Esto incluye:

- desactivar a ese usuario;
- retirarle roles;
- retirar esos permisos de sus roles.

#### FND-021 — Acciones sobre Uno Mismo

Un usuario no puede:

- desactivarse a sí mismo;
- modificar sus propios roles.

### Auditoría

#### FND-022 — Eventos Auditados

El sistema debe registrar en auditoría, como mínimo, los siguientes eventos:

| Grupo | Eventos |
|---|---|
| Autenticación | Inicio de sesión exitoso, inicio de sesión fallido, bloqueo por intentos, cierre de sesión |
| Usuarios | Creación, modificación de datos, activación, desactivación |
| Contraseñas | Restablecimiento por administrador, cambio de contraseña propia |
| Roles | Creación, modificación, eliminación, asignación y retiro de permisos |
| Asignaciones | Asignación y retiro de roles a usuarios |
| Autorización | Intento de operación denegado por falta de permiso |

Las specs posteriores podrán añadir eventos auditados propios.

#### FND-023 — Contenido del Registro de Auditoría

Cada registro de auditoría debe contener:

- usuario que ejecutó la acción (o el correo intentado, en inicios de sesión fallidos);
- acción realizada;
- entidad afectada y su identificador, cuando corresponda;
- fecha y hora;
- dirección IP de origen;
- valores anteriores y nuevos de los campos modificados, cuando corresponda.

Los registros de auditoría nunca deben contener contraseñas, contraseñas temporales ni sus hashes.

Cuando una spec posterior audite operaciones públicas, debe definir cómo se identifica al actor (por ejemplo, cliente o visitante anónimo) conservando siempre fecha, hora e IP de origen.

#### FND-024 — Inmutabilidad de la Auditoría

Los registros de auditoría no pueden modificarse ni eliminarse desde el sistema.

Se conservan de forma indefinida mientras no exista una política de retención documentada.

#### FND-025 — Consulta de Auditoría

Un usuario con el permiso `audit.view` debe poder consultar los registros de auditoría, filtrando al menos por usuario, acción y rango de fechas.

---

## 9. Catálogo de Permisos de esta Spec

| Permiso | Descripción |
|---|---|
| `users.view` | Consultar listado y detalle de usuarios |
| `users.create` | Crear usuarios |
| `users.update` | Modificar nombre, apellido y correo electrónico |
| `users.deactivate` | Activar y desactivar usuarios |
| `users.reset_password` | Restablecer la contraseña de un usuario |
| `users.assign_roles` | Asignar y retirar roles a usuarios |
| `roles.view` | Consultar roles y sus permisos |
| `roles.manage` | Crear, modificar y eliminar roles y asignarles permisos |
| `audit.view` | Consultar registros de auditoría |

Estado inicial: todos estos permisos se asignan al rol Administrador.

---

## 10. Reglas de Negocio

**BR-FND-001** — El sistema pertenece a Ecolekua y no gestiona múltiples empresas.

**BR-FND-002** — Cada usuario representa una persona autorizada para utilizar el sistema de Ecolekua.

**BR-FND-003** — El acceso a funcionalidades se determina por autenticación y permisos efectivos, nunca por el nombre del rol.

**BR-FND-004** — La autorización se valida siempre en el backend.

**BR-FND-005** — Los usuarios no se eliminan físicamente; se desactivan, y desactivarlos no elimina su historial.

**BR-FND-006** — Siempre debe existir al menos un usuario activo capaz de administrar usuarios y roles.

**BR-FND-007** — Las acciones sensibles quedan registradas en una auditoría inmutable.

**BR-FND-008** — No se deben implementar comportamientos funcionales no definidos en esta spec.

**BR-FND-009** — Los visitantes y clientes del portal no son Usuarios del sistema interno y no reciben roles ni permisos de este modelo.

**BR-FND-010** — Toda operación es protegida salvo que una spec la declare pública de forma explícita.

---

## 11. Escenarios de Aceptación

### Autenticación y sesión

**E-01 — Inicio de sesión correcto** (FND-001)
**Dado** un usuario activo sin cambio de contraseña pendiente,
**cuando** introduce su correo y contraseña correctos,
**entonces** el sistema lo autentica y le permite acceder a las operaciones que sus permisos autorizan.

**E-02 — Credenciales incorrectas** (FND-001, FND-002)
**Dado** un usuario registrado,
**cuando** introduce una contraseña incorrecta,
**entonces** el sistema rechaza la autenticación con el mensaje genérico.

**E-03 — Correo inexistente** (FND-002)
**Dado** un correo que no pertenece a ningún usuario,
**cuando** se intenta iniciar sesión con él,
**entonces** el sistema rechaza la autenticación con el mismo mensaje genérico de E-02.

**E-04 — Usuario inactivo** (FND-002, FND-009)
**Dado** un usuario inactivo,
**cuando** intenta iniciar sesión con credenciales correctas,
**entonces** el sistema rechaza el acceso con el mismo mensaje genérico de E-02.

**E-05 — Correo con mayúsculas y espacios** (FND-008)
**Dado** un usuario registrado con `ana@ecolekua.com`,
**cuando** inicia sesión con ` Ana@Ecolekua.com `,
**entonces** el sistema lo identifica correctamente.

**E-06 — Bloqueo por intentos fallidos** (FND-003)
**Dado** un correo con 5 intentos fallidos consecutivos,
**cuando** se realiza un sexto intento, incluso con la contraseña correcta, antes de 15 minutos,
**entonces** el sistema rechaza el intento y registra el bloqueo en auditoría.

**E-07 — Cierre de sesión** (FND-004)
**Dado** un usuario autenticado,
**cuando** cierra su sesión e intenta acceder a una operación protegida,
**entonces** el sistema lo rechaza y lo dirige a iniciar sesión.

**E-08 — Sesión expirada** (FND-005)
**Dado** un usuario cuya sesión lleva más de 120 minutos inactiva,
**cuando** intenta ejecutar una operación protegida,
**entonces** el sistema la rechaza y lo dirige a iniciar sesión.

**E-09 — Desactivación con sesión abierta** (FND-006)
**Dado** un usuario con una sesión abierta,
**cuando** un administrador lo desactiva,
**entonces** la siguiente petición de ese usuario es rechazada.

**E-10 — Cambio de permisos en caliente** (FND-006)
**Dado** un usuario autenticado cuyo rol pierde un permiso,
**cuando** intenta ejecutar la operación que ese permiso autorizaba,
**entonces** el backend la rechaza sin que el usuario haya vuelto a iniciar sesión.

### Usuarios y contraseñas

**E-11 — Creación de usuario** (FND-010, FND-014)
**Dado** un usuario con `users.create`,
**cuando** crea un usuario con nombre, apellido, correo, contraseña temporal y al menos un rol,
**entonces** el usuario queda activo y con cambio de contraseña obligatorio.

**E-12 — Correo duplicado** (FND-008)
**Dado** un usuario existente, activo o inactivo, con un correo determinado,
**cuando** se intenta crear o modificar otro usuario con ese mismo correo,
**entonces** el sistema rechaza la operación.

**E-13 — Usuario sin rol** (FND-010, FND-018)
**Dado** un usuario con `users.create`,
**cuando** intenta crear un usuario sin roles,
**entonces** el sistema rechaza la operación.

**E-14 — Cambio obligatorio de contraseña** (FND-014)
**Dado** un usuario con cambio de contraseña obligatorio,
**cuando** inicia sesión e intenta ejecutar cualquier operación distinta de cambiar su contraseña o cerrar sesión,
**entonces** el sistema la rechaza hasta que cambie su contraseña.

**E-15 — Restablecimiento de contraseña** (FND-014)
**Dado** un usuario con una sesión abierta,
**cuando** un administrador restablece su contraseña,
**entonces** sus sesiones quedan invalidadas y en su siguiente inicio de sesión debe cambiar la contraseña.

**E-16 — Contraseña demasiado corta** (FND-013)
**Dado** cualquier operación que establezca una contraseña,
**cuando** la contraseña tiene menos de 10 caracteres,
**entonces** el sistema rechaza la operación.

**E-17 — Cambio de contraseña propia** (FND-015)
**Dado** un usuario autenticado,
**cuando** cambia su contraseña indicando correctamente la actual,
**entonces** la nueva contraseña queda vigente; si la actual es incorrecta, el cambio se rechaza.

**E-18 — Historial de usuario inactivo** (FND-011)
**Dado** un usuario que realizó operaciones registradas en auditoría,
**cuando** es desactivado,
**entonces** sus registros conservan la referencia a su identidad.

### Roles y autorización

**E-19 — Usuario sin permiso** (FND-019)
**Dado** un usuario autenticado sin el permiso requerido,
**cuando** intenta ejecutar la operación directamente contra el backend,
**entonces** recibe acceso denegado, la operación no produce efectos y el intento queda auditado.

**E-20 — Usuario con permiso** (FND-019)
**Dado** un usuario autenticado con el permiso requerido,
**cuando** ejecuta la operación,
**entonces** el backend la permite.

**E-21 — Permisos de varios roles** (FND-018)
**Dado** un usuario con dos roles que conceden permisos distintos,
**cuando** ejecuta una operación autorizada por cualquiera de ellos,
**entonces** el backend la permite.

**E-22 — Eliminación de rol con usuarios** (FND-016)
**Dado** un rol con al menos un usuario asignado,
**cuando** se intenta eliminar,
**entonces** el sistema rechaza la operación.

**E-23 — Rol Administrador protegido** (FND-016)
**Dado** el rol Administrador,
**cuando** se intenta eliminar o renombrar,
**entonces** el sistema rechaza la operación.

**E-24 — Permiso nuevo sin asignación automática** (FND-017, FND-018)
**Dado** un permiso incorporado por una spec posterior que no indica asignación,
**cuando** un Administrador intenta ejecutar la operación que protege,
**entonces** el backend la rechaza hasta que el permiso se asigne a alguno de sus roles.

**E-30 — Protección por defecto** (FND-026)
**Dado** el conjunto de rutas y operaciones registradas en el sistema,
**cuando** un visitante no autenticado intenta acceder a cualquiera de ellas que no haya sido declarada pública por una spec,
**entonces** el sistema la rechaza y lo dirige a iniciar sesión.

### Protecciones administrativas

**E-25 — Último administrador** (FND-020)
**Dado** que existe un único usuario activo con `users.assign_roles` y `roles.manage`,
**cuando** se intenta desactivarlo, retirarle roles o retirar esos permisos de sus roles,
**entonces** el sistema rechaza la operación.

**E-26 — Acciones sobre uno mismo** (FND-021)
**Dado** un usuario con permisos de administración,
**cuando** intenta desactivarse o modificar sus propios roles,
**entonces** el sistema rechaza la operación.

### Auditoría

**E-27 — Registro de operación sensible** (FND-022, FND-023)
**Dado** un usuario autorizado,
**cuando** modifica el correo de otro usuario,
**entonces** queda un registro con autor, acción, usuario afectado, fecha y hora, IP y los valores anterior y nuevo.

**E-28 — Auditoría sin contraseñas** (FND-023)
**Dado** un restablecimiento o cambio de contraseña,
**cuando** se registra en auditoría,
**entonces** el registro no contiene la contraseña ni su hash.

**E-29 — Auditoría inmutable** (FND-024)
**Dado** un registro de auditoría existente,
**cuando** cualquier usuario, incluido un Administrador, intenta modificarlo o eliminarlo desde el sistema,
**entonces** no existe operación que lo permita.

---

## 12. Integridad de Datos

- Las relaciones entre usuarios, roles y permisos deben mantener integridad referencial.
- No deben existir asignaciones a usuarios, roles o permisos inexistentes.
- El correo electrónico es único a nivel de persistencia, no solo de validación.
- Los registros de auditoría deben conservar la referencia al usuario aunque este esté inactivo.
- Ninguna operación de esta spec elimina físicamente usuarios ni registros de auditoría.

---

## 13. Seguridad

Debe garantizarse:

- hashing seguro de contraseñas;
- ausencia de contraseñas en texto plano en base de datos, auditoría y logs;
- protección de sesiones, incluida su regeneración al iniciar sesión;
- limitación de intentos de inicio de sesión;
- mensajes de error de autenticación que no revelen información;
- autorización en backend;
- protección de rutas;
- validación de todas las entradas en backend;
- protección contra peticiones entre sitios en formularios que modifican datos.

No se debe confiar en controles implementados exclusivamente en JavaScript o en la interfaz.

---

## 14. Pruebas

La implementación debe incluir pruebas automatizadas que cubran todos los escenarios de aceptación de la sección 11 (E-01 a E-30).

La prueba de E-30 debe recorrer todas las rutas registradas y fallar si aparece una ruta no autenticada que no figure en la lista de operaciones públicas declaradas.

Cada prueba debe referenciar el identificador del escenario que cubre.

Las pruebas de autorización deben ejecutarse contra el backend directamente, no solo a través de la interfaz.

---

## 15. Dependencias

Esta spec no depende de ninguna otra. Todas las specs posteriores dependen de ella.

Orden de implementación previsto:

```text
001 Foundation
002 Customers
003 Products
004 Pricing & Quotations
015 Public Web Portal (incluye cotizador)
005 Orders & Payments
016 Customer Portal
006 Inventory & Materials
007 Material Reservations
008 Production
009 Time Tracking
010 Quality & Rework
011 Delivery
012 Notifications
013 Reporting
014 Management Dashboard
017 Mobile API
```

La numeración de las specs es un identificador estable y no indica el orden de implementación. El orden se alinea con las fases comprometidas con Ecolekua: primero portal, clientes, productos y cotizador; luego ventas, pedidos e inventario; luego producción, tiempos y calidad; finalmente dashboard, reportes e integraciones (DEC-016).

Dependencias funcionales previstas (a confirmar en cada spec):

| Spec | Depende de |
|---|---|
| 002 Customers | 001 |
| 003 Products | 001 |
| 004 Pricing & Quotations | 002, 003 |
| 005 Orders & Payments | 002, 003, 004 |
| 006 Inventory & Materials | 001 |
| 007 Material Reservations | 005, 006 |
| 008 Production | 005, 007 |
| 009 Time Tracking | 008 |
| 010 Quality & Rework | 008 |
| 011 Delivery | 005, 010 |
| 012 Notifications | 005 |
| 013 Reporting | Specs cuyos datos reporte |
| 014 Management Dashboard | 013 |
| 015 Public Web Portal | 002, 003, 004 |
| 016 Customer Portal | 002, 005, 015 |
| 017 Mobile API | Specs cuyas operaciones exponga |

El orden de implementación puede modificarse mediante una decisión documentada, siempre que respete las dependencias funcionales.

---

## 16. Decisiones de esta Spec

Las siguientes decisiones completan comportamientos que la versión anterior dejaba abiertos. Deben confirmarse antes de pasar a Design.

| ID | Decisión | Estado |
|---|---|---|
| DEC-001 | El login se realiza con correo electrónico, único y normalizado. | Confirmada |
| DEC-002 | Un usuario puede tener varios roles; los permisos efectivos son la unión. | Confirmada |
| DEC-003 | No existe acceso implícito total; el Administrador solo tiene los permisos asignados. | Confirmada |
| DEC-004 | Los permisos son un catálogo definido por las specs; los roles son editables desde la interfaz. | Confirmada |
| DEC-005 | Sin recuperación por correo en esta spec; el administrador restablece con contraseña temporal. | Confirmada |
| DEC-006 | Contraseña de al menos 10 caracteres. | Confirmada |
| DEC-007 | Bloqueo de 15 minutos tras 5 intentos fallidos consecutivos. | Confirmada |
| DEC-008 | Expiración de sesión tras 120 minutos de inactividad. | Confirmada |
| DEC-009 | Mismo mensaje genérico para credenciales incorrectas, correo inexistente y usuario inactivo. | Confirmada |
| DEC-010 | El usuario solo puede cambiar su propia contraseña; sus datos personales los modifica un administrador. | Confirmada |
| DEC-011 | La auditoría es inmutable y se conserva indefinidamente. | Confirmada |
| DEC-012 | Se elimina la configuración general (antes FND-010) hasta que una spec defina configuraciones concretas. | Confirmada |
| DEC-013 | Los roles distintos de Administrador se crean sin permisos. | Confirmada |
| DEC-014 | "Usuario" designa exclusivamente al personal de Ecolekua. Visitantes y clientes del portal no son Usuarios, no reciben roles ni permisos de este modelo y su identidad y acceso se definen en las specs 015 y 016. | Confirmada |
| DEC-015 | Toda operación es protegida por defecto; una operación solo es pública si una spec la declara explícitamente. | Confirmada |
| DEC-016 | El orden de implementación sigue las fases comprometidas con Ecolekua: 015 (portal y cotizador) se implementa tras 004, y 016 (portal de clientes) tras 005. | Confirmada |
| DEC-017 | Los intentos fallidos no caducan por tiempo: el contador solo vuelve a 0 con un inicio de sesión exitoso o al terminar el bloqueo de 15 minutos (FND-003). | Confirmada |
| DEC-018 | Mientras un correo está bloqueado, el intento de inicio de sesión muestra un mensaje específico que indica el bloqueo temporal por demasiados intentos y los minutos restantes. El bloqueo aplica a cualquier correo, exista o no, por lo que el mensaje no revela la existencia de la cuenta (FND-002, FND-003). | Confirmada |
| DEC-019 | La zona horaria de operación de Ecolekua es `America/Caracas`. Las fechas se almacenan en UTC; la consulta de auditoría las muestra y filtra por rango de fechas en esa zona horaria (FND-025). | Confirmada |
| DEC-020 | Un administrador con los permisos correspondientes puede modificar sus propios datos y restablecer su propia contraseña desde la gestión de usuarios, igual que con cualquier otro usuario. Las únicas restricciones sobre sí mismo son las de FND-021: no puede desactivarse ni modificar sus propios roles (FND-010, FND-021, DEC-010). | Confirmada |
| DEC-021 | El primer administrador se crea mediante un comando de consola interactivo que solicita nombre, apellido y correo, genera una contraseña temporal que obliga a cambiarla en el primer inicio de sesión, solo se ejecuta si no existe ya un usuario activo con rol Administrador y queda registrado en la auditoría como evento de consola. No se almacenan credenciales en variables de entorno ni en el código (FND-014, FND-020, FND-022). | Confirmada |

---

## 17. Criterios de Aceptación

La especificación se considera cumplida cuando:

- todos los escenarios E-01 a E-30 tienen pruebas automatizadas que pasan;
- no existe ninguna operación accesible sin autenticación que no haya sido declarada pública por una spec;
- el correo electrónico es único a nivel de persistencia;
- las contraseñas no aparecen en texto plano en base de datos, auditoría ni logs;
- ninguna operación protegida puede ejecutarse sin autenticación ni sin el permiso requerido, verificado contra el backend;
- es imposible dejar el sistema sin un usuario activo capaz de administrar usuarios y roles;
- no existen operaciones de eliminación física de usuarios ni de modificación o eliminación de auditoría;
- el seed inicial crea los roles de la sección 7 y los permisos de la sección 9, asignados al Administrador;
- no existe ninguna implementación de multiempresa o multi-tenancy;
- todas las decisiones de la sección 16 están en estado Confirmada.

---

## 18. Comportamientos No Definidos

Cualquier comportamiento que no esté definido en esta especificación queda fuera de alcance.

Si durante el diseño o la implementación aparece una decisión funcional necesaria que no esté definida:

> **La implementación debe detenerse para esa decisión y solicitar aclaración.**

No se debe inventar el comportamiento.

Las decisiones técnicas que no alteren el comportamiento funcional pueden resolverse durante las fases de Design y Apply, respetando, en este orden:

1. `docs/constitution.md`
2. `docs/business/business-rules.md`
3. esta spec activa.
