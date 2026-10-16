# Spec 004 — Precios y cotizaciones (`004-pricing-and-quotations`)

| Campo | Valor |
|---|---|
| Prefijo de requisitos | `COT` |
| Ubicación | `docs/specs/004-pricing-and-quotations.md` |
| Estado | **Lista para Design** (2026-10-12) — decisiones de §9 confirmadas. Los datos de la carga inicial (anexos A y B) se validan con Ecolekua antes de ejecutar COT-007, no antes de Design |
| Depende de | `001-foundation` (autenticación, permisos, auditoría, layout), `002-customers` (cliente, persona de contacto, asesora), `003-products` (catálogo, combinaciones, combos, PRD-011, PRD-019) |
| La usan | `005-public-web-portal`, `006-orders-and-payments`, `007-customer-portal`, `014-commissions-and-bonuses`, `015-notifications`, `016-management-dashboard` |
| Rama | `feat/004-pricing-and-quotations` |
| Fecha | 2026-10-12 |

> Subordinada a `docs/constitution.md` y `docs/business/business-rules.md`.

---

## 1. Contexto

Hoy Ecolekua cotiza a mano con la hoja de cálculo de precios (hojas **"Uniformes"** y **"Pañales para adultos"**). Cada fila tiene un código de combinación, el costo y varias columnas de precio. La asesora elige la columna según la cantidad, suma a mano, calcula el anticipo e imprime. El precio correcto depende de reglas que hoy viven en la cabeza de cada asesora:

- **Uniformes**: el tramo se elige por el total de piezas de uniformes de la cotización (1–6, 7–12, 13–49) y desde 50 piezas se negocia (business rules §2.2).
- **Pañales de adulto**: detal de 1 a 19 unidades y precio especial desde 20 (§2.3).
- **Protector de cama y absorbentes 3XG/4XG**: detal de 1 a 6 y especial desde 7, con packs de 2, 3 y 6 (§2.3, §2.4).
- **Combos (kits y packs)**: cada ítem tiene su propio precio dentro del combo (§2.4).
- **"Varios"**: precio fijo, sin tramos (§2.5).
- **Pantalones industriales `184` y `184-2`**: un precio hasta la talla 36 y otro desde la 38 (DEC-PRD-06).
- **Personalizaciones** (bordado, vinil, sublimación sencilla) añadidas a una prenda: no se cobran aparte; los servicios solo tienen precio cuando se venden solos (§26.2).

Los precios están en dólares; Ecolekua usa como referencia la tasa oficial del día publicada por el BCV.

`003` dejó el catálogo listo sin precios: cada combinación tiene su código y existen operaciones únicas para validar una selección (PRD-011) y para ofrecer las opciones de cada paso (PRD-019). Esta spec añade los precios, el cálculo de una cotización y la cotización como documento del ERP. No hay carrito: la cotización cumple esa función, con varias líneas, cada una con su propia selección (decisión 2026-10-04).

## 2. Objetivo

Que el backend sea la única autoridad que calcula cuánto cuesta lo que el cliente pide —tramo, precio unitario, subtotales, total, anticipo y su equivalente en bolívares— a partir de precios finales configurables, y que las asesoras puedan registrar, emitir, enviar y consultar cotizaciones de varias líneas en el ERP, listas para convertirse en pedido en `006`. El mismo cálculo lo usará el cotizador del portal (`005`).

## 3. Alcance

### 3.1 Dentro del alcance

- **Esquemas de precio** configurables: por tramos (límites libres, tramo final con precio o de negociación) o de precio fijo. Asignación de un esquema a cada producto.
- **Precios finales** en USD por código de combinación y tramo; precio por grupo de talla en las combinaciones que lo necesitan (`184`, `184-2`).
- **Precios de los ítems de cada combo**; total del combo = suma de ítems por cantidad.
- **Parámetros comerciales**: porcentaje de anticipo y vigencia de la cotización.
- **Tasa de cambio oficial del día** (USD → Bs) obtenida automáticamente, con carga manual de respaldo.
- **Carga inicial de precios** mediante un comando técnico.
- **Actualización masiva de precios por CSV** desde el ERP: exportar, editar fuera del sistema, validar, revisar y aplicar.
- **Cálculo de una cotización** (operación sin efectos): conteo de piezas por línea de negocio, tramo por producto, precios, combos, subtotales, total, anticipo, aviso de packs e indicadores de derivación a asesora.
- **Cotización del ERP** para un cliente registrado o un prospecto: alta, líneas con cantidades por talla, emisión, envío por correo, vigencia, vencimiento, duplicado, anulación y descarte de borradores.
- **Precio negociado** por línea y **anticipo acordado** cuando la línea requiere asesora.
- **Vista imprimible** de la cotización emitida.
- Historial de cotizaciones en la ficha del cliente (CLI-013).
- Bloqueos de eliminación e inmutabilidad hacia `002` y `003`.
- Permisos `quotations.*` y `pricing.*` verificados en backend, y auditoría.

### 3.2 Fuera del alcance

| Tema | Dónde se trata |
|---|---|
| Cotizador del portal público, botón de WhatsApp, número de contacto comercial, vista previa por capas | `005` |
| Cotización de visitantes del portal | `005` (usa la operación de COT-009) |
| Conversión cotización → pedido (estado "aprobada"), registro del cliente de un prospecto al convertir, anticipo como transacción, pagos en USD o Bs, origen definitivo de la venta | `006` |
| Desglose de IVA a partir del precio final, facturación | `006` / futura integración |
| Existencias, regla de stock mínimo y derivación por stock en el portal | `008` / `005` (RM-05) |
| Archivo de diseño o logo del cliente | `006` |
| Costos y márgenes: el sistema no calcula precios desde costos (business rules §2.1) | — |
| Descuentos manuales sobre el precio del tramo | No existen (DEC-COT-27) |
| PDF generado en el servidor | No se usa (DEC-COT-20): vista imprimible y correo |
| Ajuste porcentual de precios en pantalla | No existe (DEC-COT-37): se calcula en el CSV |
| Comisiones de asesoras | `014` |
| Notificaciones internas y WhatsApp sobre cotizaciones | `015` |

---

## 4. Actores y permisos

| Permiso | Permite |
|---|---|
| `quotations.view` | Ver cotizaciones, su detalle, su vista imprimible y el historial de cotizaciones en la ficha del cliente |
| `quotations.create` | Crear y editar borradores, emitirlos, reenviarlos por correo, duplicar cotizaciones y descartar borradores |
| `quotations.negotiate` | Fijar o confirmar el precio de las líneas que requieren asesora y acordar su anticipo (COT-012) |
| `quotations.cancel` | Anular cotizaciones emitidas |
| `pricing.view` | Ver esquemas, precios, precios de combos, parámetros comerciales y tasas; exportar los precios a CSV (COT-026) y ver el historial de importaciones (COT-029) |
| `pricing.manage` | Gestionar esquemas, asignarlos a productos, editar precios y parámetros comerciales, registrar la tasa manualmente (COT-030), importar y aplicar actualizaciones masivas (COT-027, COT-028) |

Asignación inicial a los roles de `001` (DEC-COT-25). El código verifica el permiso, nunca el nombre del rol (AGENTS §7.3).

| Permiso | Administrador | Gerente | Asesora de Ventas | Finanzas | Supervisor de Producción | Responsable de Calidad | Operario |
|---|---|---|---|---|---|---|---|
| `quotations.view` | ✓ | ✓ | ✓ | ✓ | — | — | — |
| `quotations.create` | ✓ | ✓ | ✓ | — | — | — | — |
| `quotations.negotiate` | ✓ | ✓ | ✓ | — | — | — | — |
| `quotations.cancel` | ✓ | ✓ | ✓ | — | — | — | — |
| `pricing.view` | ✓ | ✓ | ✓ | ✓ | — | — | — |
| `pricing.manage` | ✓ | ✓ | — | — | — | — | — |

- **Coherencia** (análoga a DEC-022, DEC-CLI-32 y DEC-PRD-23): `quotations.create`, `quotations.negotiate` y `quotations.cancel` exigen `quotations.view`; `pricing.manage` exige `pricing.view`. Una asignación que no cumpla se rechaza y el rol no cambia. Los permisos `pricing.*` no exigen `products.view`: las pantallas de precios muestran del catálogo solo los datos de referencia (código, nombre y estado) (DEC-COT-52).
- Todos los usuarios con `quotations.view` ven todas las cotizaciones; "Mis cotizaciones" es un filtro (DEC-COT-28, igual que DEC-CLI-08).
- `design.md` define cómo se aplica esta matriz a una base de datos existente, igual que en `002` y `003`.

---

## 5. Modelo conceptual

Describe **qué** información existe, no su implementación.

```text
Esquema de precio                    (Uniformes, Pañales de adulto, Protector y absorbentes, Precio fijo)
 ├── nombre, tipo (por tramos | precio fijo), estado
 └── Tramo (1..n, ordenado)          solo en esquemas por tramos
      ├── etiqueta                   (Detal, 7–12, 13–49, Especial…)
      ├── cantidad mínima            (el primero empieza en 1)
      ├── cantidad máxima            (vacía solo en el último)
      └── tipo                       (con precio | negociación)   el de negociación solo puede ser el último

Producto (de 003)
 └── esquema de precio asignado      (0..1; sin esquema no se cotiza)

Precio de combinación                por combinación (código de 003) y tramo
 ├── grupo de talla opcional         (p. ej., 28–36 / 38–44 en 184)
 └── precio final en USD             (decimal, 2 decimales)

Precio de ítem de combo              por componente de combo (de 003)
 └── precio final en USD por unidad

Parámetros comerciales               porcentaje de anticipo, días de vigencia

Tasa de cambio                       (una por día)
 ├── fecha, valor (Bs por USD)
 ├── origen                          (api | manual)
 ├── fecha de actualización de la fuente   (api)
 └── registrada por                  (manual)

Importación de precios               (actualización masiva por CSV, COT-027 a COT-029)
 ├── alcance                         (precios de combinaciones de un esquema | precios de ítems de combos)
 ├── usuario, fecha, nombre del archivo
 ├── estado                          (validada | aplicada | descartada)
 ├── resumen                         (precios nuevos, modificados, sin cambios)
 └── Cambio (0..n)                   precio afectado, valor anterior (vacío si no existía), valor nuevo, advertencias

Cotización
 ├── número                          (correlativo global, DEC-COT-21)
 ├── destinatario: cliente (002, con persona de contacto opcional) o prospecto (nombre, teléfono, correo opcional)
 ├── creada por, asesora responsable, origen (asesora | web)
 ├── estado                          (borrador | emitida | vencida | anulada | aprobada)
 ├── fecha de emisión, vence el, motivo de anulación, cotización de origen (si es duplicado)
 ├── observaciones
 ├── anticipo acordado de las líneas negociadas: monto, usuario, fecha
 ├── copia al emitir: piezas por línea de negocio, total, % de anticipo, anticipo requerido,
 │                    tasa (valor y fecha), total y anticipo en Bs
 ├── requiere asesora + motivos
 ├── Envío por correo (0..n)         fecha, destinatarios, resultado (enviado | fallido)
 └── Línea (1..n, ordenada)
      ├── tipo (producto | combo)
      ├── referencia a la combinación o al combo, código, nombre descriptivo (copia)
      ├── selección normalizada (copia de PRD-011: valores, colores con nombre y tono,
      │                          detalles, color personalizado con tono y nota)
      ├── Cantidad por talla (1..n)   talla + cantidad; una sola fila sin talla si el producto no la tiene como atributo de pedido
      ├── Personalización añadida (0..n)  servicio y cantidad por prenda, sin precio (DEC-COT-13)
      ├── Componente de combo (combo)     selección por componente, cantidad por kit, precio del ítem
      ├── tramo aplicado, origen del precio (tramo | fijo | combo | negociado)
      ├── precio unitario aplicado (por grupo de talla cuando corresponde)
      ├── precio negociado: valor, usuario, fecha     (solo si requiere asesora)
      ├── subtotal
      └── nota
```

- Tipos de esquema, tipos de tramo, estados de la cotización, origen de la cotización, origen del precio, origen de la tasa y resultado del envío se implementan como Enums de PHP (constitución §21.1).
- Todos los importes son **decimales exactos**, nunca `float` (AGENTS §8). Precios en USD con 2 decimales (DEC-COT-01, DEC-COT-02).
- Los esquemas y los precios son **configuración**, no un motor de reglas (constitución §11): no hay fórmulas, márgenes ni condiciones distintas de la cantidad.
- La cotización guarda copias (nombre, selección, precios aplicados, tasa) para que cambios posteriores del catálogo, de los precios o de la tasa no alteren una cotización emitida (compromiso de `003` §10).

---

## 6. Requisitos

### 6.1 Configuración de precios

#### COT-001 — Esquemas de precio

Un usuario con `pricing.manage` crea, edita, desactiva y reactiva esquemas de precio.

- **Por tramos**: una lista ordenada de tramos contiguos que empieza en 1, sin huecos ni superposiciones. Cada tramo tiene una etiqueta, una cantidad mínima y una máxima; solo el último tiene la máxima vacía (abierto). El backend rechaza un esquema que no cumpla.
- El último tramo puede ser de **negociación**: no tiene precio y la línea que cae en él requiere asesora (COT-012, business rules §2.2). Ningún otro tramo puede serlo.
- **Precio fijo**: un único precio por combinación, sin tramos. Sus unidades no cuentan para el tramo de su línea de negocio (business rules §2.1, §2.5).
- El nombre es único sin distinguir mayúsculas.
- El tipo (por tramos o precio fijo) se fija al crear el esquema y no se cambia. Para cambiar de tipo se crea otro esquema y se reasignan los productos (COT-002) (DEC-COT-51).
- Cambiar límites o etiquetas no toca los precios cargados. Agregar un tramo deja las combinaciones de esos productos sin precio en ese tramo hasta cargarlo (COT-025). Quitar un tramo con precios cargados requiere confirmación y elimina esos precios, con auditoría de los valores anteriores (DEC-COT-23). Convertir en tramo de negociación un tramo con precios cargados se trata igual que quitarlo (DEC-COT-57).
- Un esquema asignado a algún producto activo no se puede desactivar; el backend indica los productos.
- Los cambios no afectan a cotizaciones emitidas (COT-016).
- **Configuración inicial** (business rules §2.2–§2.5, DEC-COT-26, DEC-COT-05):

| Esquema | Tipo | Tramos |
|---|---|---|
| Uniformes | Por tramos | Detal 1–6 · 7–12 · 13–49 · Negociación 50 en adelante |
| Pañales de adulto | Por tramos | Detal 1–19 · Especial 20 en adelante |
| Protector y absorbentes | Por tramos | Detal 1–6 · Especial 7 en adelante |
| Precio fijo | Precio fijo | — |

La línea de pañales empieza sin tramo de negociación. Si Ecolekua lo pide, basta con añadirlo al esquema correspondiente; no requiere cambios de código.

#### COT-002 — Esquema de cada producto

Un usuario con `pricing.manage` asigna a cada producto un esquema activo (DEC-COT-22). La asignación puede hacerse en la ficha del producto o en bloque desde el listado (varios productos a la vez).

- Un producto sin esquema, o con un esquema inactivo, no se puede cotizar (COT-025).
- Cambiar el esquema de un producto con precios cargados requiere confirmación y elimina sus precios, con auditoría de los valores anteriores; las combinaciones quedan sin precio hasta cargarlas de nuevo (DEC-COT-23).
- Los combos no tienen esquema: su precio sale de sus ítems (COT-005).

#### COT-003 — Precios por combinación y tramo

Un usuario con `pricing.manage` registra y edita el **precio final** en USD (con ganancia e IVA incluidos) de cada combinación activa o inactiva en cada tramo con precio de su esquema, o su único precio si el esquema es de precio fijo (business rules §2.1).

- El precio es un decimal mayor que cero con 2 decimales como máximo (DEC-COT-02).
- El precio se identifica por el **código** de la combinación (`003`).
- La edición es una tabla por producto: una fila por combinación y una columna por tramo.
- Cada cambio queda auditado con el valor anterior y el nuevo (constitución §10).
- Un cambio de precio aplica a los cálculos nuevos y a los borradores en su siguiente recálculo; nunca a cotizaciones emitidas (COT-016).
- No se calculan precios a partir de costos ni márgenes (business rules §2.1).

#### COT-004 — Precio por grupo de talla

Una combinación cuyo producto tiene Talla como atributo de pedido puede dividir sus precios en **grupos de talla** (DEC-PRD-06).

- Cada grupo es un conjunto de valores de talla admitidos por la combinación y tiene su propio precio en cada tramo.
- Si una combinación tiene grupos, cada talla admitida pertenece a exactamente un grupo. El backend rechaza grupos que se superpongan o que dejen tallas sin grupo.
- Solo `184` y `184-2` lo usan en la configuración inicial (28–36 / 38–44). El cliente ve la lista normal de tallas; el precio de cada fila de talla sale de su grupo.
- Si se añade una talla a la combinación o al producto y la combinación tiene grupos, la talla queda sin precio hasta asignarla a un grupo (COT-025).
- Si se quita una talla del producto o de la combinación (`003`), la talla sale de su grupo, con copia en la auditoría. Si el grupo queda vacío, se eliminan el grupo y sus precios, con auditoría de los valores anteriores (DEC-COT-48, igual que DEC-PRD-52 B).
- Pasar de precios sin grupo a grupos, quitar los grupos, o fusionar o reorganizar grupos con precios cargados requiere confirmación. El `ConfirmDialog` lista los precios que se eliminarán (grupo, tramo y valor); al confirmar se eliminan con auditoría de los valores anteriores y la combinación queda sin precio hasta cargar los nuevos (COT-025) (DEC-COT-53, mismo criterio que DEC-COT-23). Mover una talla de un grupo a otro es reorganizar: se eliminan los precios de los dos grupos afectados. Solo asignar a un grupo una talla que no estaba en ningún grupo conserva los precios de ese grupo, y la talla toma su precio (DEC-COT-58).

#### COT-005 — Precios de los combos

Un usuario con `pricing.manage` registra el **precio final por unidad** de cada componente de un combo (business rules §2.4, compromiso de `003`).

- Cada combo tiene un único precio por ítem, que no depende de la cantidad de combos pedidos (DEC-COT-06).
- El precio del combo es la suma del precio de cada ítem multiplicado por su cantidad. El sistema no guarda un total aparte.
- Los packs son combos de un solo componente con su propio precio por ítem (DEC-PRD-13).
- Un combo con algún componente sin precio no se puede cotizar (COT-025).
- Al editar los componentes del combo (`003`), se conserva el precio de cada componente cuyo producto no cambia, aunque cambien su cantidad u orden. Un componente nuevo o con otro producto queda sin precio (DEC-COT-44).
- Cada cambio queda auditado.

#### COT-006 — Parámetros comerciales

Un usuario con `pricing.manage` edita:

- **Porcentaje de anticipo**: entero o decimal entre 0 y 100. Valor inicial: **50 %** (business rules §3).
- **Vigencia de la cotización** en días naturales: entero ≥ 1. Valor inicial: **7 días** (DEC-COT-15).

Los cambios quedan auditados y aplican a las cotizaciones que se emitan después.

#### COT-007 — Carga inicial de precios

El equipo técnico carga una única vez los precios de la lista actual mediante un comando de consola, sin pantalla en el ERP, siguiendo el patrón de PRD-018 y CLI-018. Se ejecuta después de la carga del catálogo (PRD-018).

- **Origen**: CSV en UTF-8 preparados a partir de las hojas "Uniformes" y "Pañales para adultos" y validados por Ecolekua:
  1. esquemas de cada producto (producto → esquema);
  2. precios por código y tramo, con grupo de talla opcional;
  3. precios de los ítems de cada combo.
  Las columnas se definen en `design.md`. La correspondencia entre columnas de la lista y tramos está en el Anexo A.
- En las secciones con IVA se cargan las columnas **con IVA**; en "Varios" se carga "Precio 4" tal como está, porque ya es el precio final (DEC-COT-04). Los precios se redondean a 2 decimales al cargarlos (DEC-COT-02).
- No se cargan el costo, las "Opciones 1/2/3" (DEC-PRD-13) ni la columna "más de 50" de uniformes (DEC-COT-03).
- Cada registro pasa por las mismas validaciones de COT-001 a COT-005. Los códigos deben existir en el catálogo.
- **Todo o nada**: el comando valida primero todas las filas, incluidos códigos repetidos en el archivo. Si alguna falla, no importa nada y entrega un informe con archivo, fila, campo y motivo. Si todas son válidas, importa en una sola transacción.
- El comando exige un usuario interno activo como autor. Cada registro queda auditado como alta marcada como importación.
- El archivo contiene precios reales de Ecolekua: no se versiona ni se guarda en Engram (AGENTS §4.5).
- Antes de ejecutarla, Ecolekua valida los datos del Anexo A y corrige las inconsistencias del Anexo B. Son datos, no decisiones de diseño: no bloquean Design ni la implementación.
- Los archivos 2 y 3 usan el mismo formato y las mismas validaciones que la actualización masiva (COT-026, COT-027), para que haya un solo formato de precios en el sistema (DT-04). El archivo 1 (esquemas) es exclusivo de la carga inicial.

#### COT-030 — Tasa de cambio del día

Los precios y las cotizaciones están en USD (DEC-COT-01). El sistema guarda la **tasa oficial del día** (Bs por USD) como referencia para las cotizaciones.

- **Obtención automática** (DEC-COT-43): una tarea programada consulta `https://ve.dolarapi.com/v1/dolares/oficial` **todos los días a las 7:00 a. m., hora de Caracas** (`America/Caracas`). El campo `promedio`, redondeado a 2 decimales (DEC-COT-60), se guarda como **la tasa de ese día**, con la fecha de la consulta en Caracas; `fechaActualizacion` se guarda solo como dato de la fuente y no cambia la fecha de la tasa. Si la tasa de ese día ya existe, no se duplica. Los reintentos tras un fallo se definen en `design.md`.
- **Respaldo** (DEC-COT-38): si la API no responde, responde con error o no trae un valor positivo, la tarea no guarda nada y queda registrado el fallo. El sistema usa la **última tasa guardada** y la muestra siempre con su fecha. Un usuario con `pricing.manage` puede registrar manualmente la tasa del día (valor > 0, hasta 2 decimales, DEC-COT-60); queda con origen "manual", su autor y auditoría. Si la API trae después la tasa de ese mismo día, no reemplaza la manual.
- La tasa vigente es la del día en curso o, si no existe, la más reciente. La pantalla de precios y el editor de cotizaciones la muestran con su fecha y origen.
- La falta de tasa **nunca** impide calcular ni emitir una cotización (COT-016).
- La integración está desacoplada (constitución §15): la API se consulta solo desde la tarea programada, nunca durante el cálculo ni la emisión.
- Las tasas guardadas no se editan ni se eliminan; una corrección es un nuevo registro manual del mismo día, que reemplaza al anterior como vigente y queda auditado.

### 6.2 Cálculo

#### COT-008 — Conteo de piezas para el tramo

El tramo se determina por el **total de piezas de cada línea de negocio** en la cotización (business rules §2.1):

- Cuentan las cantidades de las líneas de **productos que no son servicios** con esquema **por tramos**, sumadas por línea de negocio (uniformes por un lado, pañales por otro), aunque sean productos distintos y usen esquemas distintos.
- No cuentan las líneas de productos con esquema de precio fijo.
- No cuentan las líneas de productos sin esquema o con un esquema inactivo, que no se pueden cotizar (COT-025, DEC-COT-62).
- No cuentan las personalizaciones añadidas a una prenda (DEC-COT-13).
- No cuentan las unidades de los combos (DEC-COT-07).
- **Servicios vendidos solos** (DEC-COT-14): no suman a su línea de negocio. El tramo de un servicio se elige con la cantidad total de **ese mismo servicio** en la cotización (p. ej., 20 bordados pequeños `157` → tramo 13–49 de su esquema).
- El total de la línea de negocio se aplica a cada producto dentro de su propio esquema: con 4 pañales y 3 absorbentes 3XG/4XG (7 unidades de pañales), los pañales se cotizan en el tramo de 7 de su esquema (Detal) y los absorbentes en el tramo de 7 del suyo (Especial).

#### COT-009 — Calcular una cotización

El backend ofrece una única operación, **sin efectos**, que recibe una lista de líneas y devuelve el cálculo completo. La usan el editor de cotizaciones del ERP (COT-013) y el cotizador del portal (`005`). Ningún frontend calcula precios, tramos, anticipos ni equivalentes en Bs (AGENTS §7.1, §9).

**Entrada**: líneas, cada una con producto o combo, selección (como PRD-011), cantidades por talla (o una cantidad sin talla), personalizaciones añadidas con su cantidad por prenda, selección de cada componente en los combos y, si existe, el precio negociado de la línea; y el anticipo acordado de las líneas negociadas, si existe.

**Proceso**:

1. Cada línea se valida con PRD-011, una vez por cada fila de talla. Una fila inválida produce un error en esa línea y fila. Los atributos de pedido con un único valor posible se aplican solos (DEC-PRD-94).
2. Se cuentan las piezas por línea de negocio y por servicio (COT-008).
3. Para cada línea de producto se toma el tramo del esquema del producto que contiene el total que le corresponde, o el precio fijo.
4. El precio unitario de cada fila de talla es el de la combinación resuelta en ese tramo, o el de su grupo de talla (COT-004).
5. Las personalizaciones añadidas y las incluidas en la combinación **no tienen precio** (COT-011).
6. Un combo se cotiza con el precio de sus ítems (COT-005): subtotal = Σ (precio del ítem × cantidad del componente) × cantidad de combos.
7. Si el tramo de una línea es de negociación, o la selección tiene color personalizado (PRD-011, indicador "requiere asesora"), la línea **requiere asesora** (COT-012).
8. Subtotal de la línea = Σ (precio unitario × cantidad de cada fila). Total = Σ subtotales de las líneas con precio.
9. Anticipo requerido según COT-010, redondeado a 2 decimales.
10. Equivalente en Bs del total y del anticipo con la tasa vigente (COT-030), redondeado a 2 decimales. Si no hay ninguna tasa guardada, se informa "sin tasa" y no se calcula.
11. **Aviso de packs** (DEC-COT-08): para cada línea de producto, si existe un combo activo de un solo componente (pack) cuyo componente admite la combinación y la selección de la línea (DEC-PRD-42), la cantidad de la línea alcanza al menos la cantidad del pack y el precio por ítem del pack es menor que el precio unitario aplicado a la línea, el cálculo devuelve un **aviso informativo** con el pack, cuántos packs caben y el ahorro estimado. Si aplican varios packs, se devuelve un solo aviso: el del pack con mayor ahorro estimado y, si empatan, el de más unidades (DEC-COT-47); si también empatan en unidades, el de código de combo menor en orden alfabético (DEC-COT-61). Una línea con más de un precio unitario (grupos de talla) no tiene aviso de packs (DEC-COT-54); se cuentan los valores distintos, así que dos grupos con el mismo precio cuentan como uno (DEC-COT-59). La línea no cambia; la asesora decide si la reemplaza.

**Salida**:

- por línea: código, nombre descriptivo, tramo aplicado (etiqueta), origen del precio, precio unitario por fila de talla, subtotal, personalizaciones (sin cargo), indicador "requiere asesora" con su motivo, avisos de packs y errores por campo;
- por cotización: piezas por línea de negocio, total, porcentaje de anticipo, anticipo requerido con su desglose (automático, stock, acordado), tasa vigente con su fecha y origen, total y anticipo en Bs, indicador "requiere asesora" con la lista de motivos y un indicador de si la cotización está completa (sin errores y sin líneas pendientes de precio).

La operación no consulta existencias (`008`), no llama a la API de la tasa y no guarda nada.

#### COT-010 — Anticipo

El anticipo requerido depende del modo de abastecimiento del producto y de si la línea es negociada (business rules §3, DEC-COT-10, DEC-COT-11):

| Líneas | Anticipo |
|---|---|
| Productos **bajo pedido** y **servicios**, con precio automático | Porcentaje vigente (COT-006) × subtotal |
| Productos de **stock con mínimo** y **stock agotable**, y **combos**, con precio automático | 100 % del subtotal: se pagan completos |
| Líneas con **precio negociado o confirmado** (COT-012), de cualquier modo | **Anticipo acordado** por la asesora |

- **Anticipo acordado**: un usuario con `quotations.negotiate` registra un único monto en USD para el conjunto de líneas negociadas de la cotización, entre 0 y su subtotal. El sistema propone el porcentaje vigente × subtotal negociado; la asesora puede cambiarlo. Se guarda con usuario y fecha y queda auditado. Si cambian las líneas negociadas y el monto supera el nuevo subtotal negociado, el anticipo acordado se descarta y se vuelve a proponer.
- Una cotización con líneas negociadas no se puede emitir sin anticipo acordado.
- Anticipo requerido = suma de las tres partes.
- En `004` el anticipo es un **valor de la cotización**. Su registro como transacción, los pagos y el saldo son de `006`.

#### COT-011 — Personalizaciones en una línea

- Una línea de producto puede llevar personalizaciones añadidas entre las que admite el producto (PRD-008), validadas por PRD-011. Los productos de la línea de pañales no admiten personalizaciones (DEC-PRD-98).
- Las personalizaciones añadidas **no se cobran** (DEC-COT-13, business rules §26.2): son una especificación para producción. Las incluidas en la combinación tampoco se cobran (PRD-008).
- Cada personalización añadida tiene una **cantidad por prenda** (entero ≥ 1, por defecto 1) y se aplica a todas las unidades de la línea. Si solo algunas prendas la llevan, se cotizan en líneas separadas.
- La ubicación y el diseño de la personalización se describen en la nota de la línea; el archivo de diseño es de `006`.
- Un servicio vendido solo, como línea propia, sí tiene precio según su esquema (COT-008).

#### COT-012 — Líneas que requieren asesora y precio negociado

Una línea requiere asesora cuando cae en un tramo de negociación (50 piezas de uniformes o más en la configuración inicial) o tiene color personalizado (business rules §2.2, §26.1). Los combos nunca admiten color personalizado (DEC-PRD-87).

- **Tramo de negociación**: la línea no tiene precio automático. Un usuario con `quotations.negotiate` registra un **precio unitario negociado** para la línea (y para cada grupo de talla que usan sus filas, si la combinación tiene grupos; DEC-COT-66).
- **Color personalizado**: la línea muestra el precio del tramo como referencia. Un usuario con `quotations.negotiate` confirma ese precio o registra uno negociado, porque la asesora acuerda tela, precio y entrega con el cliente (§26.1).
- Las líneas negociadas tienen anticipo acordado (COT-010).
- Una cotización con alguna línea que requiere asesora sin precio negociado o confirmado, o sin anticipo acordado, no se puede emitir.
- El precio negociado guarda el valor, el usuario y la fecha, y queda auditado.
- Si un cambio en la cotización hace que la línea deje de requerir asesora (p. ej., bajan las piezas de 50 a 40), el precio negociado se descarta y la línea vuelve al precio automático. El cálculo lo indica y el editor lo avisa.
- Si la línea sigue requiriendo asesora pero cambia su combinación resuelta o el conjunto de grupos de talla, el precio negociado también se descarta, con el mismo aviso. Cambiar solo las cantidades o las tallas dentro de un mismo grupo no lo descarta. El anticipo acordado sigue la regla de COT-010 (DEC-COT-55).
- Un precio negociado enviado para una línea que no requiere asesora se rechaza.
- No existe ningún otro ajuste manual de precio (DEC-COT-27).
- En el portal (`005`), una cotización que requiere asesora no se procesa y deriva al cliente a WhatsApp.

### 6.3 Cotizaciones

#### COT-013 — Registrar una cotización

Un usuario con `quotations.create` crea una cotización en estado **borrador** para (DEC-COT-19):

- un **cliente** registrado y **activo** de `002`, con persona de contacto opcional si el cliente es empresa; o
- un **prospecto**, con **nombre** y **teléfono** obligatorios y **correo** opcional. El teléfono y el correo se validan y normalizan con las mismas reglas de CLI-004. El prospecto no se registra como cliente ni se compara con los clientes existentes; al convertir la cotización en pedido, `006` exige registrar o elegir el cliente (§10).

Además:

- La asesora responsable es, por defecto, la asesora asignada al cliente, solo si está activa y tiene `customers.portfolio`; si no hay cliente, no tiene asesora o su asesora no está disponible, quien crea la cotización si tiene `customers.portfolio`; si no, queda vacía. Cuando la asesora del cliente no está disponible, el sistema muestra el aviso "asesora del cliente no disponible" (DEC-COT-63). Se puede cambiar por un usuario activo con `customers.portfolio`.
- Si la asesora responsable se desactiva o pierde `customers.portfolio` después, no se reasigna automáticamente (como DEC-CLI-29): la cotización se marca "asesora no disponible". En un borrador se cambia editándolo; en una cotización emitida, un usuario con `customers.assign` puede cambiar solo la asesora, con auditoría, como única excepción a DEC-COT-18 (DEC-COT-64).
- El origen es **asesora** para las cotizaciones creadas en el ERP. `005` crea las de origen **web**.
- El número se asigna al crear (DEC-COT-21). Se guarda como entero y se muestra con prefijo y seis cifras, p. ej. `COT-000123`. Un borrador descartado no libera su número, por lo que la numeración puede tener huecos (DEC-COT-45).
- El editor usa PRD-019 para mostrar las opciones de cada paso y COT-009 para recalcular en cada cambio. El borrador se guarda aunque tenga errores, para continuarlo después.
- Mientras está en borrador, la cotización se recalcula con los precios y la tasa vigentes cada vez que se abre o se modifica.
- En un borrador se puede cambiar el destinatario (de prospecto a cliente o al revés, o a otro cliente). Cambiar el destinatario conserva la asesora responsable; se cambia solo a mano (DEC-COT-56).

#### COT-014 — Líneas y cantidades

- Una cotización tiene entre 1 y n líneas, en el orden que define el usuario. Cada línea es un producto o un combo con su propia selección.
- **Cantidades por talla**: si el producto tiene Talla como atributo de pedido, la línea tiene una fila por talla con su cantidad (entero ≥ 1), al menos una fila y sin tallas repetidas. Si no, la línea tiene una sola cantidad.
- Los demás atributos de pedido (color incluido) y los detalles son de la línea: todas las filas de talla comparten la misma selección. Otro color es otra línea (DEC-COT-09).
- En los productos con talla como eje (pañales) cada talla es una combinación distinta y, por tanto, una línea distinta.
- Una línea de combo tiene la cantidad de combos y la selección de cada componente (PRD-010).
- Cada línea admite una nota libre (longitud máxima en `design.md`).
- No existe tope de líneas ni de cantidades; los tramos de negociación cubren los volúmenes altos.

#### COT-015 — Estados de la cotización

| Estado | Significado | Se llega desde |
|---|---|---|
| Borrador | La asesora la está armando; se guarda a medias y se continúa después. Precios y tasa vigentes, recalculados. No se ha enviado al cliente | Alta, duplicado |
| Emitida | Cerrada, con precios y tasa congelados hasta su vencimiento, y enviada al cliente | Borrador (COT-016) |
| Vencida | Pasó su fecha de vencimiento sin aprobarse | Emitida (COT-017) |
| Anulada | Cancelada por Ecolekua o por el cliente | Emitida (COT-019) |
| Aprobada | El cliente la aceptó y se convirtió en pedido | Emitida, en `006` |

- Ninguna otra transición existe (DEC-COT-17). Una cotización emitida, vencida, anulada o aprobada no se edita (DEC-COT-18): para cambiarla, se duplica (COT-018).
- Badges: borrador → `neutral`, emitida → `active`, vencida → `pending`, anulada → `neutral`, aprobada → `done`.

#### COT-016 — Emitir una cotización

Un usuario con `quotations.create` emite un borrador. El backend:

1. recalcula con COT-009;
2. rechaza la emisión si hay errores, líneas sin precio (COT-025), líneas que requieren asesora sin precio negociado o confirmado, líneas negociadas sin anticipo acordado (COT-012), o si el cliente está inactivo;
3. guarda una **copia congelada** de cada línea (nombre, selección, precios unitarios, tramo, origen del precio, subtotales), del total, del porcentaje de anticipo vigente, del anticipo requerido, de la tasa vigente (valor y fecha) y del total y el anticipo en Bs (DEC-COT-16), y de los datos del destinatario (nombre, documento, teléfono, correo y persona de contacto) y de la asesora que muestran la ficha, la vista imprimible y el correo; el vínculo con el cliente se mantiene (DEC-COT-49). Si no hay ninguna tasa guardada, emite sin equivalente en Bs;
4. fija la fecha de emisión y la de vencimiento (emisión + días de vigencia vigentes);
5. pasa a **emitida** y lo audita;
6. después de confirmar la transacción, pone en cola el envío por correo (COT-031).

Los pasos 1 a 5 ocurren en una transacción. A partir de ese momento, ningún cambio de precios, esquemas, parámetros, tasas ni catálogo altera la cotización.

#### COT-017 — Vencimiento

- Una cotización emitida vence al terminar el día de su fecha de vencimiento, en la zona horaria de operación de Ecolekua.
- Al vencer pasa a **vencida**, sin intervención del usuario. El mecanismo (tarea programada o comprobación al consultar) se define en `design.md`, con la condición de que el listado y la ficha nunca muestren como emitida una cotización vencida.
- Una cotización vencida no se convierte en pedido (`006`). Se duplica para cotizar de nuevo con los precios vigentes.

#### COT-018 — Duplicar

Un usuario con `quotations.create` duplica cualquier cotización, en cualquier estado. El resultado es un **borrador** nuevo con el mismo destinatario, líneas, cantidades, personalizaciones y notas, recalculado con los precios y la tasa vigentes. Los precios negociados y el anticipo acordado **no** se copian. Si una línea ya no es válida (combinación inactiva, valor inactivo), se copia con su error para que el usuario la corrija. Si el cliente está inactivo, el borrador se crea y no se puede emitir hasta cambiar el destinatario. La copia guarda una referencia a la cotización de origen y queda auditada.

#### COT-019 — Anular y descartar

- Un usuario con `quotations.cancel` anula una cotización **emitida** indicando un motivo obligatorio (longitud máxima en `design.md`). Pasa a **anulada** y queda auditada. Requiere `ConfirmDialog`.
- Un usuario con `quotations.create` **descarta** un borrador: se elimina junto con sus líneas, con una copia en la auditoría (DEC-COT-24). Requiere `ConfirmDialog`.
- Las cotizaciones emitidas, vencidas, anuladas y aprobadas no se eliminan.

#### COT-020 — Listado, búsqueda y ficha

Un usuario con `quotations.view` puede:

- listar cotizaciones con `SegmentedTabs` por estado (Borradores, Emitidas, Vencidas, Anuladas, Aprobadas, Todas) y conteos calculados por el backend;
- buscar por número, por nombre o documento del cliente y por nombre o teléfono del prospecto;
- filtrar por asesora responsable ("Mis cotizaciones"), rango de fechas, "requiere asesora" y "prospecto";
- ver la ficha con destinatario, asesora, estado, fechas, líneas con su detalle, piezas por línea de negocio, total, anticipo con su desglose, tasa y equivalentes en Bs, motivos de derivación y envíos por correo;
- ver el historial de cotizaciones del cliente en su ficha (sección prevista en CLI-013). Las cotizaciones de prospectos no aparecen en ninguna ficha de cliente;
- ver el listado paginado en el backend.

#### COT-021 — Vista imprimible

Un usuario con `quotations.view` abre la **vista imprimible** de una cotización **emitida**, vencida, anulada o aprobada, para imprimirla o guardarla como PDF desde el navegador (DEC-COT-20). No se genera PDF en el servidor.

- Contenido: datos de Ecolekua, número, estado si no es emitida, fechas de emisión y vencimiento, destinatario, asesora, líneas con nombre descriptivo, colores, detalles, personalizaciones (sin cargo), cantidades por talla, precio unitario y subtotal en USD, total y anticipo requerido en USD, su equivalente en Bs con la tasa y su fecha ("referencial"), y la nota "Precios en USD con IVA incluido".
- Los datos de Ecolekua (nombre, RIF, dirección, teléfono, correo y logo) se leen de la configuración de la aplicación; los mantiene el equipo técnico, sin pantalla en el ERP (DEC-COT-46).
- No muestra costos, tramos internos ni el desglose interno del anticipo.
- Un borrador no tiene vista imprimible.
- Reemplaza la impresión de copias en papel.

#### COT-031 — Envío por correo

Al emitir, la cotización se envía automáticamente por correo (DEC-COT-41, DEC-COT-42).

- **Destinatarios**: el correo del cliente y el de su persona de contacto, si los tienen; o el correo del prospecto. Se envía un correo por destinatario, sin repetir direcciones, y cada uno tiene su propio resultado (DEC-COT-50).
- **Contenido**: la cotización completa en el cuerpo del correo (HTML), con el mismo contenido que la vista imprimible. Sin adjuntos ni enlaces a páginas del sistema.
- Si no hay ningún correo, la cotización se emite igual y la ficha y el editor muestran el aviso "sin correo: entréguela por otro medio".
- El envío se hace en segundo plano después de confirmar la emisión; un fallo del correo nunca revierte la emisión (constitución §15, AGENTS §7.11).
- Cada envío queda registrado en la cotización con fecha, destinatarios y resultado (enviado o fallido). Un envío fallido se muestra en la ficha.
- Un usuario con `quotations.create` puede **reenviar** una cotización emitida a los destinatarios actuales del cliente o del prospecto. El reenvío se registra igual que el envío.
- Las cotizaciones vencidas, anuladas y aprobadas no se reenvían.
- El remitente y el proveedor de correo se configuran en el entorno (`design.md`); en desarrollo se usa Mailpit.

#### COT-022 — Autorización en backend

Toda ruta y acción de precios, tasas y cotizaciones valida el permiso correspondiente con Policies en el backend (AGENTS §7.2). La operación de COT-009, cuando la invoca el ERP, exige `quotations.create`; `005` define su acceso público.

#### COT-023 — Auditoría

Se auditan, con usuario, acción, registro afectado, fecha y hora, IP y valores antes y después (AGENTS §7.8):

- altas, ediciones, desactivaciones y reactivaciones de esquemas y tramos; asignación de esquema a productos;
- altas, ediciones y eliminaciones de precios de combinación, de grupo de talla y de ítems de combo, incluida la carga inicial;
- aplicación de una actualización masiva (COT-028): cada precio cambiado, con la referencia a la importación, y la importación misma con su usuario, alcance y resumen; también su descarte;
- cambios de parámetros comerciales; registro manual de tasas;
- alta, emisión, precio negociado o confirmado, anticipo acordado, cambio de asesora responsable o de destinatario, duplicado, anulación, descarte y vencimiento de cotizaciones.

Las ediciones de líneas en borrador no se auditan una a una (DEC-COT-29): el borrador es un documento de trabajo y su contenido se congela y audita al emitir. Los envíos por correo se registran en la cotización (COT-031), no en la auditoría.

#### COT-024 — Integridad con clientes y productos

- **Clientes** (compromiso de `002`, CLI-010): un cliente con al menos una cotización, en cualquier estado, no se puede eliminar; se sugiere desactivar. Escenario E-19 de `002` (aquí E-39).
- **Productos** (compromiso de `003`, PRD-012, PRD-014, DEC-PRD-21): una combinación o un combo usados en alguna línea de cotización tienen historial. No se pueden eliminar, y el código y los valores de eje de esa combinación no se modifican. Escenario E-29 de `003` (aquí E-40).
- Las claves foráneas usan restricción de borrado.
- Un borrador descartado (COT-019) deja de contar como historial.
- Desactivar un cliente, un producto o una combinación no modifica las cotizaciones emitidas. Un borrador afectado muestra el error en su siguiente recálculo y no se puede emitir hasta corregirlo.

#### COT-025 — Líneas sin precio

Una línea no tiene precio cuando su producto no tiene esquema o lo tiene inactivo, su combinación no tiene precio en el tramo que le corresponde, su talla no pertenece a ningún grupo de talla, o un componente de combo no tiene precio.

- COT-009 devuelve un error **"sin precio"** en esa línea, sin inventar un valor.
- La cotización no se puede emitir con líneas sin precio.
- El listado de precios (COT-003) marca las combinaciones activas sin precio en algún tramo, para que el equipo las complete.

### 6.4 Actualización masiva de precios

Ecolekua necesita cambiar muchos precios a la vez (p. ej., un ajuste general de la lista o el cambio de precio de una tela que afecta a decenas de códigos), sin editarlos uno por uno en la tabla de COT-003. El flujo es: **exportar → editar en Excel → importar y validar → revisar los cambios → aplicar**. Es una función permanente del ERP, a diferencia de la carga inicial (COT-007), que es un comando único.

#### COT-026 — Exportar la plantilla de precios

Un usuario con `pricing.view` descarga un CSV con los precios vigentes, que sirve como plantilla para editarlos.

- **Alcance de la exportación** (uno por archivo):
  - **Precios de combinaciones de un esquema** (obligatorio elegir el esquema, porque las columnas dependen de sus tramos). Filtros opcionales: categoría, producto y estado de la combinación. Una fila por combinación, o por grupo de talla si la combinación los tiene (COT-004).
  - **Precios de ítems de combos**: una fila por componente de cada combo. Filtro opcional: estado del combo.
- **Columnas de combinaciones**: código, grupo de talla (vacío si no tiene), y como referencia el producto, el nombre descriptivo y el estado; después, una columna por cada tramo con precio del esquema, con su etiqueta como encabezado, o una sola columna de precio si el esquema es de precio fijo. Los tramos de negociación no tienen columna.
- **Columnas de combos**: código del combo, número de componente y, como referencia, el producto del componente y su cantidad; después, el precio por ítem.
- Las celdas de precio sin valor se exportan vacías, para que el equipo las complete (COT-025).
- El archivo indica en su primera fila el alcance y el esquema exportados, para que la importación detecte una plantilla equivocada (detalle en `design.md`).
- Formato (DEC-COT-31): separador `;`, coma decimal y UTF-8 con BOM, para que se abra directamente en el Excel en español de Ecolekua.
- La exportación no se audita (es una consulta).

#### COT-027 — Importar y validar

Un usuario con `pricing.manage` sube un CSV con el formato de COT-026. El backend lo valida completo **sin aplicar nada** y devuelve una previsualización.

**Reglas de interpretación**:

- Se acepta `;` o `,` como separador y coma o punto como separador decimal, sin separador de miles (DEC-COT-31).
- Las filas se identifican por **código** (y grupo de talla) o por **código de combo y número de componente**. Las columnas de referencia (producto, nombre, estado, cantidad) se ignoran aunque se hayan editado.
- **Celda de precio vacía = sin cambio.** Por CSV no se eliminan precios; eso se hace en la tabla de COT-003 (DEC-COT-32).
- **Filas ausentes = sin cambio.** El archivo puede traer solo las filas que se quieren modificar.
- Un precio vacío en el sistema y con valor en el archivo es un **precio nuevo**.
- Los grupos de talla no se crean ni se modifican por CSV; se definen en COT-004.

**Validaciones** (las mismas de COT-003, COT-004 y COT-005, más las del archivo):

- el archivo es CSV en la codificación esperada, no supera el tamaño ni el número de filas máximos (`design.md`) y su encabezado corresponde al alcance y al esquema elegidos;
- las columnas de tramos coinciden con los tramos **actuales** del esquema. Si el esquema cambió después de exportar, el archivo se rechaza completo y se pide descargar una plantilla nueva;
- cada código existe, pertenece a un producto con ese esquema y, si trae grupo de talla, el grupo existe en esa combinación; cada combo y número de componente existen;
- no hay filas repetidas para el mismo código y grupo de talla, o combo y componente;
- cada precio es un número mayor que cero. Si trae más de 2 decimales, se redondea según DEC-COT-02 y la previsualización lo marca como advertencia (DEC-COT-33).

**Resultado**:

- **Todo o nada**: si hay algún error, no se puede aplicar nada. La respuesta lista cada error con fila, columna y motivo, y el usuario corrige el archivo y lo vuelve a subir.
- Si no hay errores, la importación queda **validada** y la previsualización muestra:
  - el resumen: precios nuevos, modificados y sin cambios;
  - cada cambio con código, nombre descriptivo, grupo de talla, tramo, valor anterior, valor nuevo y variación porcentual;
  - las advertencias: valores redondeados, combinaciones o combos inactivos afectados y variaciones mayores que ±30 % (DEC-COT-34).
- Las advertencias no impiden aplicar; los errores sí.
- Un archivo sin ningún cambio se informa como tal y no genera una importación aplicable.

#### COT-028 — Aplicar la importación

El mismo usuario que validó la importación la **aplica** o la **descarta** desde la previsualización. Aplicar requiere `ConfirmDialog` con el resumen de cambios.

- Al aplicar, el backend comprueba que ningún precio afectado haya cambiado desde la validación (por la tabla de COT-003 o por otra importación) y que el esquema siga teniendo los mismos tramos. Si algo cambió, rechaza la aplicación, marca la importación como descartada y pide subir el archivo de nuevo (DEC-COT-35).
- Todos los cambios se aplican en **una sola transacción**: o se aplican todos o ninguno.
- La importación pasa a **aplicada**. Cada precio cambiado queda auditado con valor anterior y nuevo y con la referencia a la importación (COT-023).
- Igual que en COT-003: los nuevos precios aplican a los cálculos nuevos y a los borradores en su siguiente recálculo, nunca a cotizaciones emitidas (COT-016).
- Una importación validada que no se aplica caduca al cabo del tiempo definido en `design.md` y pasa a descartada.
- No existe "deshacer": para revertir, se exporta, se corrigen los valores y se importa de nuevo.

#### COT-029 — Historial de importaciones

Un usuario con `pricing.view` consulta la lista de importaciones aplicadas y descartadas, con fecha, usuario, alcance, nombre del archivo y resumen, y el detalle de los cambios de cada importación aplicada (valor anterior y nuevo). El archivo original no se guarda (DEC-COT-36).

---

## 7. Escenarios de aceptación

Las pruebas de autorización golpean el backend por HTTP. **Los precios y tasas de los ejemplos son ficticios**; los códigos son reales solo como referencia del formato.

Datos comunes de los ejemplos, salvo que el escenario diga otra cosa:

- Esquema "Uniformes": Detal 1–6 · 7–12 · 13–49 · Negociación 50+.
- Esquema "Pañales de adulto": Detal 1–19 · Especial 20+. Esquema "Protector y absorbentes": Detal 1–6 · Especial 7+.
- `110` camisa (bajo pedido): 40,00 / 36,00 / 32,00. Chemise `155-1` (bajo pedido): 20,00 / 18,00 / 16,00. Pantalón `184` (stock con mínimo): 28–36 a 25,00 / 23,00 / 21,00 y 38–44 a 28,00 / 26,00 / 24,00.
- Pañal `11` (stock con mínimo): Detal 50,00 · Especial 20,00. Absorbente `16` (stock con mínimo): Detal 40,00 · Especial 15,00. "Pack 6 absorbentes": 1 componente absorbente × 6, a 15,00 por ítem.
- Bordado pequeño `157` (servicio, esquema Uniformes): 3,00 / 2,00 / 1,50.
- Pañal infantil `001G` (stock agotable): precio fijo 3,50.
- Anticipo: 50 %. Vigencia: 7 días. Tasa vigente: 875,65 Bs por USD.

### E-01 — Crear un esquema por tramos válido (COT-001, COT-023)

- **GIVEN** un usuario con `pricing.manage`
- **WHEN** crea el esquema "Uniformes" con los tramos 1–6, 7–12, 13–49 y 50 en adelante de negociación
- **THEN** el esquema se crea activo con sus cuatro tramos
- **AND** se registra la auditoría con los valores creados

### E-02 — Tramos inválidos (COT-001)

- **GIVEN** un usuario con `pricing.manage`
- **WHEN** guarda un esquema que empieza en 2, o con un hueco entre 6 y 8, o con dos tramos que incluyen el 12, o con un tramo de negociación que no es el último, o con un tramo intermedio sin máximo
- **THEN** el backend rechaza el guardado con un error en los tramos y nada cambia

### E-03 — Esquema en uso no se desactiva (COT-001)

- **GIVEN** el esquema "Uniformes" asignado a la camisa corporativa, que está activa
- **WHEN** se intenta desactivar
- **THEN** el backend lo rechaza e indica los productos que lo usan

### E-04 — Asignar esquema a varios productos (COT-002)

- **GIVEN** un usuario con `pricing.manage` y tres productos sin esquema
- **WHEN** les asigna "Uniformes" desde el listado en una sola acción
- **THEN** los tres quedan con ese esquema y cada asignación queda auditada

### E-05 — Registrar y editar precios (COT-003, COT-023)

- **GIVEN** la combinación `110` sin precios y un usuario con `pricing.manage`
- **WHEN** registra 40,00 / 36,00 / 32,00 y después cambia el detal a 41,00
- **THEN** los precios se guardan por tramo
- **AND** la auditoría registra el detal anterior 40,00 y el nuevo 41,00

### E-06 — Precio inválido (COT-003)

- **GIVEN** un usuario con `pricing.manage`
- **WHEN** registra un precio de 0, negativo o con más de 2 decimales
- **THEN** el backend lo rechaza con un error en el precio

### E-07 — Precios sin permiso (COT-003, COT-022)

- **GIVEN** un usuario con `pricing.view` pero sin `pricing.manage`
- **WHEN** envía una petición para cambiar un precio
- **THEN** el backend responde 403 y el precio no cambia

### E-08 — Grupos de talla válidos (COT-004)

- **GIVEN** la combinación `184`, que admite las tallas 28 a 44
- **WHEN** se definen los grupos 28–36 y 38–44 con sus precios
- **THEN** los grupos se guardan
- **AND** un grupo que repite la 36, o que deja la 44 sin grupo, se rechaza

### E-09 — Precio por grupo de talla en una línea (COT-004, COT-009)

- **GIVEN** una línea de `184` con 2 unidades en talla 34 y 2 en talla 40 (4 piezas de uniformes)
- **WHEN** se calcula
- **THEN** la talla 34 se cotiza a 25,00 y la 40 a 28,00, en el tramo Detal
- **AND** el subtotal de la línea es 106,00

### E-10 — Precio del combo (COT-005, COT-009)

- **GIVEN** el "Kit Oro antiderrame" con 2 pañales a 30,00, 3 absorbentes a 20,00 y 1 protector a 35,00 por ítem
- **WHEN** se calcula una línea con 2 kits
- **THEN** el subtotal de la línea es 310,00 (155,00 por kit)

### E-11 — Parámetros comerciales (COT-006, COT-023)

- **GIVEN** un usuario con `pricing.manage`
- **WHEN** cambia el anticipo a 40 % y la vigencia a 10 días
- **THEN** los valores se guardan y quedan auditados
- **AND** un porcentaje de 120 o una vigencia de 0 se rechazan

### E-12 — Importar precios válidos (COT-007, COT-023)

- **GIVEN** el catálogo cargado, los tres CSV válidos y un usuario interno activo como autor
- **WHEN** se ejecuta el comando
- **THEN** se crean las asignaciones de esquema, los precios por tramo y grupo de talla y los precios de los ítems de los combos en una transacción
- **AND** cada alta queda auditada como importación

### E-13 — Importación con errores (COT-007)

- **GIVEN** un CSV con un código que no existe en el catálogo, un precio con texto o un código repetido
- **WHEN** se ejecuta el comando
- **THEN** no se importa nada
- **AND** el informe indica archivo, fila, campo y motivo

### E-14 — Tramo por el total de la línea de negocio (COT-008, COT-009)

- **GIVEN** una cotización con 5 camisas `110`, 5 chemises `155-1` y 3 pantalones `184` talla 32
- **WHEN** se calcula
- **THEN** las piezas de uniformes son 13
- **AND** las tres líneas se cotizan en el tramo 13–49: 32,00, 16,00 y 21,00

### E-15 — Mismo total, esquemas distintos (COT-008, COT-009)

- **GIVEN** una cotización con 4 pañales `11` y 3 absorbentes `16`
- **WHEN** se calcula
- **THEN** las piezas de pañales son 7
- **AND** los pañales se cotizan a Detal (50,00) y los absorbentes a Especial (15,00)

### E-16 — Pedido mixto (COT-008, COT-009)

- **GIVEN** una cotización con 15 camisas `110`, 10 pañales `11` y 2 pañales infantiles `001G`
- **WHEN** se calcula
- **THEN** uniformes cuenta 15 piezas (tramo 13–49) y pañales cuenta 10 (Detal)
- **AND** los pañales infantiles se cotizan a precio fijo 3,50 y no cuentan para el tramo de pañales

### E-17 — Límite de tramo (COT-008, COT-009)

- **GIVEN** una cotización con 6 camisas `110`
- **WHEN** se agrega una séptima unidad
- **THEN** la línea pasa del tramo Detal (40,00) al tramo 7–12 (36,00)

### E-18 — Tramo de negociación (COT-009, COT-012)

- **GIVEN** una cotización con 30 camisas `110` y 20 chemises `155-1` (50 piezas de uniformes)
- **WHEN** se calcula
- **THEN** ambas líneas quedan sin precio automático, marcadas "requiere asesora" con el motivo "50 piezas de uniformes o más"
- **AND** la cotización indica que requiere asesora
- **AND** con 49 piezas, las líneas se cotizan en el tramo 13–49

### E-19 — Precio negociado (COT-012, COT-023)

- **GIVEN** la cotización de E-18 en borrador y un usuario con `quotations.negotiate`
- **WHEN** registra 29,00 en la línea de camisas y 14,00 en la de chemises
- **THEN** los subtotales son 870,00 y 280,00
- **AND** cada precio negociado guarda usuario y fecha y queda auditado
- **AND** la cotización todavía no se puede emitir porque falta el anticipo acordado (E-62)

### E-20 — Negociar sin permiso (COT-012, COT-022)

- **GIVEN** un usuario con `quotations.create` pero sin `quotations.negotiate`
- **WHEN** envía un precio negociado o un anticipo acordado
- **THEN** el backend responde 403 y la cotización no cambia

### E-21 — Precio negociado en una línea que no lo requiere (COT-012)

- **GIVEN** una cotización con 10 camisas `110`
- **WHEN** un usuario con `quotations.negotiate` envía un precio negociado para esa línea
- **THEN** el backend lo rechaza con un error indicando que la línea no requiere asesora

### E-22 — Deja de requerir asesora (COT-012)

- **GIVEN** la cotización de E-19 con precios negociados
- **WHEN** se reducen las chemises a 10 (40 piezas de uniformes)
- **THEN** los precios negociados y el anticipo acordado se descartan y las líneas vuelven al tramo 13–49
- **AND** el cálculo indica que se descartaron precios negociados

### E-23 — Color personalizado (COT-009, COT-012)

- **GIVEN** una línea de 10 camisas `110` con color Personalizado
- **WHEN** se calcula
- **THEN** la línea muestra 36,00 como precio de referencia y queda "requiere asesora" con el motivo "color personalizado"
- **AND** la cotización no se puede emitir hasta que un usuario con `quotations.negotiate` confirme ese precio o registre otro y acuerde el anticipo

### E-24 — Personalización añadida sin cargo (COT-009, COT-011)

- **GIVEN** una línea de 10 camisas `110` con bordado pequeño, 2 por prenda
- **WHEN** se calcula
- **THEN** las piezas de uniformes son 10 (el bordado no cuenta)
- **AND** el bordado figura sin cargo con su cantidad por prenda, y el subtotal de la línea es 360,00
- **AND** en la combinación `139-1`, que incluye vinil, el vinil tampoco se cobra

### E-25 — Servicio vendido solo (COT-008, COT-009, DEC-COT-14)

- **GIVEN** una cotización con 10 camisas `110` y una línea de 20 bordados pequeños `157` sobre prendas del cliente
- **WHEN** se calcula
- **THEN** las piezas de uniformes son 10 y las camisas se cotizan en 7–12 (36,00)
- **AND** los bordados se cotizan con su propia cantidad, en el tramo 13–49 (1,50): subtotal 30,00

### E-26 — Línea sin precio (COT-025)

- **GIVEN** la combinación `110-1` sin precio en el tramo 7–12
- **WHEN** se calcula una línea de 8 unidades de `110-1`
- **THEN** la línea devuelve el error "sin precio" y ningún valor inventado
- **AND** la cotización no se puede emitir
- **AND** el listado de precios marca `110-1` como incompleta

### E-27 — Producto sin esquema (COT-002, COT-025)

- **GIVEN** un producto activo sin esquema asignado
- **WHEN** se calcula una línea de ese producto
- **THEN** la línea devuelve el error "sin precio"

### E-28 — Selección inválida en una fila de talla (COT-009, PRD-011)

- **GIVEN** una línea de `110-1` (dama) cuya combinación restringe las tallas a XS–XL
- **WHEN** se calcula con 3 unidades en M y 2 en 2XL
- **THEN** la fila 2XL devuelve un error en la talla y la fila M se calcula

### E-29 — Anticipo según el modo (COT-009, COT-010)

- **GIVEN** una cotización con 5 camisas `110` (bajo pedido), 2 pantalones `184` talla 32 (stock con mínimo) y 2 pañales infantiles `001G` (stock agotable)
- **WHEN** se calcula
- **THEN** uniformes cuenta 7 piezas (tramo 7–12): camisas 180,00 y pantalones 46,00; pañales infantiles 7,00; total 233,00
- **AND** el anticipo requerido es 143,00: 90,00 (50 % de las camisas) + 46,00 + 7,00 (los productos de stock se pagan completos)

### E-30 — Anticipo con redondeo (COT-010, DEC-COT-02)

- **GIVEN** un anticipo de 50 % y líneas bajo pedido por 100,01
- **WHEN** se calcula
- **THEN** el anticipo requerido es 50,01 (redondeo hacia arriba en la mitad)

### E-31 — El cálculo no guarda nada (COT-009)

- **GIVEN** un usuario con `quotations.create`
- **WHEN** invoca el cálculo con tres líneas
- **THEN** recibe el resultado completo
- **AND** no se crea ni modifica ninguna cotización y no se consulta la API de la tasa

### E-32 — Crear una cotización para un cliente (COT-013, COT-023)

- **GIVEN** un cliente activo con asesora asignada y un usuario con `quotations.create`
- **WHEN** crea una cotización
- **THEN** se crea en borrador, con número, origen "asesora" y la asesora del cliente como responsable
- **AND** la creación queda auditada

### E-33 — Cliente inactivo (COT-013, COT-016)

- **GIVEN** un cliente inactivo
- **WHEN** se intenta crear una cotización para él, o emitir un borrador suyo
- **THEN** el backend rechaza la acción con un error en el cliente

### E-34 — Crear sin permiso (COT-013, COT-022)

- **GIVEN** un usuario con `quotations.view` pero sin `quotations.create`
- **WHEN** envía una petición de alta de cotización
- **THEN** el backend responde 403 y no se crea nada

### E-35 — Cantidades por talla (COT-014)

- **GIVEN** un usuario editando una línea de `110`
- **WHEN** guarda una fila con cantidad 0, o dos filas con la talla M, o ninguna fila
- **THEN** el backend rechaza la línea con un error en las cantidades

### E-36 — Emitir congela precios y tasa (COT-016, COT-023)

- **GIVEN** un borrador completo con 10 camisas `110` (36,00; total 360,00; anticipo 180,00) y la tasa vigente 875,65
- **WHEN** se emite y después el precio de `110` en 7–12 cambia a 38,00 y se guarda una tasa nueva
- **THEN** la cotización emitida sigue mostrando 36,00, total 360,00 y anticipo 180,00
- **AND** conserva la tasa 875,65 con su fecha, total 315.234,00 Bs y anticipo 157.617,00 Bs
- **AND** vence 7 días después de la emisión, y la emisión queda auditada

### E-37 — Emitir con pendientes (COT-016)

- **GIVEN** un borrador con una línea sin precio, o con una línea que requiere asesora sin precio negociado o sin anticipo acordado, o con un error de selección
- **WHEN** se intenta emitir
- **THEN** el backend lo rechaza, indica las líneas pendientes y el borrador no cambia

### E-38 — Emitida no se edita (COT-015, DEC-COT-18)

- **GIVEN** una cotización emitida
- **WHEN** se intenta modificar una línea, una cantidad o el destinatario
- **THEN** el backend rechaza el cambio

### E-39 — Cliente con cotizaciones no se elimina (COT-024, CLI-010)

- **GIVEN** un cliente sin pedidos con una cotización emitida, y un usuario con `customers.delete`
- **WHEN** intenta eliminarlo
- **THEN** el backend lo rechaza, indica el motivo y sugiere desactivarlo

### E-40 — Combinación con historial (COT-024, PRD-012, PRD-014)

- **GIVEN** la combinación `110` usada en una línea de cotización
- **WHEN** se intenta eliminarla, cambiar su código o cambiar sus valores de eje
- **THEN** el backend rechaza cada cambio y sugiere crear una combinación nueva y desactivar la anterior
- *Cubre el escenario E-29 de `003`.*

### E-41 — Vencimiento (COT-017)

- **GIVEN** una cotización emitida que vence hoy
- **WHEN** termina el día en la zona horaria de Ecolekua
- **THEN** la cotización figura como vencida en el listado y en la ficha
- **AND** el cambio queda auditado

### E-42 — Duplicar con precios vigentes (COT-018)

- **GIVEN** la cotización vencida de E-41 con 10 camisas `110` a 36,00, y el precio actual de `110` en 7–12 en 38,00
- **WHEN** se duplica
- **THEN** se crea un borrador nuevo con las mismas líneas, a 38,00, con referencia a la cotización de origen
- **AND** los precios negociados y el anticipo acordado de la original no se copian

### E-43 — Anular (COT-019, COT-023)

- **GIVEN** una cotización emitida y un usuario con `quotations.cancel`
- **WHEN** la anula con un motivo
- **THEN** pasa a anulada y la auditoría registra el motivo
- **AND** anular sin motivo, o anular un borrador, se rechaza

### E-44 — Descartar un borrador (COT-019, COT-024)

- **GIVEN** un borrador que es la única cotización de un cliente
- **WHEN** un usuario con `quotations.create` lo descarta
- **THEN** el borrador y sus líneas se eliminan y la auditoría guarda una copia
- **AND** el cliente vuelve a poder eliminarse si no tiene otro historial

### E-45 — Consultar sin permiso (COT-020, COT-022)

- **GIVEN** un usuario autenticado sin `quotations.view`
- **WHEN** solicita el listado, el detalle o la vista imprimible de una cotización
- **THEN** el backend responde 403

### E-46 — Historial en la ficha del cliente (COT-020, CLI-013)

- **GIVEN** un cliente con dos cotizaciones, una emitida y una vencida, y un usuario con `quotations.view` y `customers.view`
- **WHEN** abre la ficha del cliente
- **THEN** la sección de cotizaciones muestra ambas con número, fecha, estado y total

### E-47 — Coherencia de permisos (§4)

- **GIVEN** un usuario que gestiona roles
- **WHEN** asigna `quotations.negotiate` a un rol sin `quotations.view`, o `pricing.manage` a un rol sin `pricing.view`
- **THEN** la asignación se rechaza y el rol no cambia

### E-48 — Borrador con combinación desactivada (COT-024)

- **GIVEN** un borrador con una línea de `110-4` y una cotización emitida con la misma combinación
- **WHEN** se desactiva `110-4`
- **THEN** el borrador muestra un error en esa línea en su siguiente recálculo y no se puede emitir
- **AND** la cotización emitida no cambia

### E-49 — Los combos no cuentan para el tramo (COT-008, DEC-COT-07)

- **GIVEN** una cotización con 1 "Kit Oro antiderrame" (6 unidades) y 15 pañales `11`
- **WHEN** se calcula
- **THEN** las piezas de pañales son 15 y los pañales se cotizan a Detal (50,00)

### E-50 — Vista imprimible (COT-021, DEC-COT-20)

- **GIVEN** la cotización emitida de E-36 y un usuario con `quotations.view`
- **WHEN** abre la vista imprimible
- **THEN** ve número, fechas, destinatario, líneas con cantidades por talla, precios, total y anticipo en USD, y su equivalente en Bs con la tasa y su fecha, sin costos ni tramos internos
- **AND** un borrador no tiene vista imprimible

### E-51 — Exportar la plantilla de un esquema (COT-026)

- **GIVEN** un usuario con `pricing.view`, el esquema "Uniformes" y las combinaciones `110` (con sus tres precios), `110-1` (sin precio en 7–12) y `184` (con grupos 28–36 y 38–44)
- **WHEN** exporta los precios del esquema "Uniformes"
- **THEN** el CSV usa `;` y coma decimal, y tiene las columnas de código, grupo de talla, referencias y una columna por tramo con precio (Detal, 7–12, 13–49), sin columna para el tramo de negociación
- **AND** `184` aparece en dos filas, una por grupo de talla
- **AND** la celda de `110-1` en 7–12 está vacía

### E-52 — Importar y previsualizar (COT-027)

- **GIVEN** la plantilla de E-51 con el detal de `110` cambiado de 40,00 a 42,00, el precio de `110-1` en 7–12 completado con 37,00 y las demás filas sin cambios
- **WHEN** un usuario con `pricing.manage` la importa
- **THEN** la importación queda validada y ningún precio cambia todavía
- **AND** la previsualización muestra 1 precio modificado (`110` Detal: 40,00 → 42,00, +5 %), 1 precio nuevo (`110-1` 7–12: 37,00) y el resto sin cambios
- **AND** el mismo archivo guardado con `,` y punto decimal da el mismo resultado

### E-53 — Aplicar la importación (COT-028, COT-023)

- **GIVEN** la importación validada de E-52
- **WHEN** el mismo usuario la aplica
- **THEN** el detal de `110` es 42,00 y `110-1` tiene 37,00 en 7–12, en una sola transacción
- **AND** la importación queda aplicada y cada precio cambiado queda auditado con la referencia a la importación
- **AND** una cotización emitida antes con `110` a 40,00 no cambia

### E-54 — Archivo con errores (COT-027)

- **GIVEN** un CSV con un código que no existe, un código de otro esquema, una fila repetida y un precio "abc"
- **WHEN** se importa
- **THEN** la respuesta lista los cuatro errores con fila, columna y motivo
- **AND** la importación no se puede aplicar y ningún precio cambia

### E-55 — Celdas vacías y filas ausentes (COT-027)

- **GIVEN** `110` con precios 40,00 / 36,00 / 32,00
- **WHEN** se importa un archivo que solo contiene la fila de `110` con el detal en 41,00 y las otras dos celdas vacías
- **THEN** la previsualización muestra solo el cambio del detal
- **AND** al aplicar, 7–12 y 13–49 de `110` siguen en 36,00 y 32,00, y las combinaciones que no estaban en el archivo no cambian

### E-56 — Plantilla desactualizada (COT-027)

- **GIVEN** una plantilla exportada cuando "Uniformes" tenía tres tramos con precio, y después se agregó un cuarto tramo con precio
- **WHEN** se importa esa plantilla
- **THEN** el backend rechaza el archivo completo e indica que se descargue una plantilla nueva

### E-57 — Redondeo y advertencias (COT-027, DEC-COT-33, DEC-COT-34)

- **GIVEN** `110` con detal 40,00 y `110-4` inactiva
- **WHEN** se importa un archivo con el detal de `110` en 44,4449 y un cambio de precio en `110-4`
- **THEN** la previsualización muestra 44,44 con la advertencia de redondeo, y la advertencia de combinación inactiva en `110-4`
- **AND** un cambio de 40,00 a 400,00 se marca como variación mayor a ±30 %
- **AND** las advertencias no impiden aplicar

### E-58 — Conflicto al aplicar (COT-028, DEC-COT-35)

- **GIVEN** una importación validada que cambia el detal de `110` de 40,00 a 42,00
- **WHEN** antes de aplicarla otro usuario cambia ese precio a 41,00 en la tabla de precios, y después se intenta aplicar
- **THEN** el backend rechaza la aplicación, la importación queda descartada y el precio sigue en 41,00

### E-59 — Importar sin permiso (COT-027, COT-022)

- **GIVEN** un usuario con `pricing.view` pero sin `pricing.manage`
- **WHEN** envía un CSV de precios para importar, o intenta aplicar una importación validada
- **THEN** el backend responde 403 y nada cambia
- **AND** ese usuario sí puede exportar la plantilla

### E-60 — Precios de combos por CSV (COT-026, COT-027, COT-028)

- **GIVEN** el "Kit Oro antiderrame" con el pañal a 30,00 por ítem
- **WHEN** se exportan los precios de combos, se cambia el pañal a 31,00, se importa y se aplica
- **THEN** el pañal del kit queda en 31,00 y una línea de 1 kit se calcula en 157,00

### E-61 — Historial de importaciones (COT-029)

- **GIVEN** una importación aplicada y otra descartada
- **WHEN** un usuario con `pricing.view` consulta el historial
- **THEN** ve ambas con fecha, usuario, alcance, archivo y resumen
- **AND** en la aplicada ve cada cambio con su valor anterior y nuevo

### E-62 — Anticipo acordado de las líneas negociadas (COT-010, COT-012, COT-023)

- **GIVEN** la cotización de E-19 (líneas negociadas por 1.150,00) y un usuario con `quotations.negotiate`
- **WHEN** consulta el anticipo propuesto y registra 400,00
- **THEN** el sistema proponía 575,00 (50 %), y el anticipo requerido queda en 400,00
- **AND** el anticipo acordado guarda usuario y fecha y queda auditado, y la cotización se puede emitir
- **AND** un monto negativo o mayor que 1.150,00 se rechaza
- **AND** si después ya no queda ninguna línea negociada o el anticipo supera el nuevo subtotal negociado, el anticipo acordado se descarta sin registro de auditoría propio y se vuelve a proponer (DEC-COT-68)

### E-63 — Tasa del día desde la API (COT-030)

- **GIVEN** la API responde `{"promedio": 875.6505, "fechaActualizacion": "2026-10-09T00:00:00-04:00"}`
- **WHEN** la tarea programada se ejecuta el 2026-10-12 a las 7:00 a. m. hora de Caracas
- **THEN** se guarda la tasa 875,65 (el `promedio` redondeado a 2 decimales, DEC-COT-60) como tasa del 2026-10-12, con origen "api" y la fecha de la fuente (2026-10-09) como dato aparte
- **AND** ejecutar la tarea de nuevo ese día no crea otra tasa
- **AND** la tarea está programada a las 7:00 en la zona `America/Caracas`, no en UTC

### E-64 — Falla la API y tasa manual (COT-030, COT-023)

- **GIVEN** la última tasa guardada es del día anterior y la API responde con error
- **WHEN** se ejecuta la tarea y después se calcula una cotización
- **THEN** no se guarda ninguna tasa, el fallo queda registrado y el cálculo usa la tasa del día anterior mostrando su fecha
- **AND** un usuario con `pricing.manage` registra manualmente la tasa del día; queda con origen "manual", su autor y auditoría, y pasa a ser la vigente
- **AND** un usuario sin `pricing.manage` recibe 403 al intentarlo, y un valor 0, negativo o con más de 2 decimales se rechaza

### E-65 — Sin ninguna tasa guardada (COT-009, COT-016, COT-030)

- **GIVEN** un sistema sin tasas guardadas y un borrador completo
- **WHEN** se calcula y se emite
- **THEN** el cálculo informa "sin tasa" y la cotización se emite sin equivalente en Bs

### E-66 — Envío por correo al emitir (COT-031)

- **GIVEN** un cliente empresa con correo y una persona de contacto con correo
- **WHEN** se emite su cotización
- **THEN** después de confirmar la emisión se envía un correo a ambos con la cotización completa en el cuerpo, y la cotización registra el envío con fecha, destinatarios y resultado
- **AND** si el envío falla, la cotización sigue emitida y la ficha muestra el envío fallido
- **AND** un usuario con `quotations.create` la reenvía y se registra un segundo envío

### E-67 — Emitir sin correo (COT-031)

- **GIVEN** un cliente sin correo ni persona de contacto con correo
- **WHEN** se emite su cotización
- **THEN** la cotización queda emitida, no se envía ningún correo y la ficha muestra el aviso "sin correo"

### E-68 — Cotización para un prospecto (COT-013, COT-020)

- **GIVEN** un usuario con `quotations.create`
- **WHEN** crea una cotización para el prospecto "Taller Ejemplo" con el teléfono 0414-1234567 y un correo
- **THEN** se crea en borrador con el teléfono normalizado (+584141234567), sin crear ningún cliente
- **AND** al emitirla, el correo se envía al prospecto
- **AND** un teléfono fijo o de otro país, o un prospecto sin nombre, se rechaza
- **AND** la cotización se encuentra buscando por el nombre o el teléfono del prospecto, y no aparece en la ficha de ningún cliente

### E-69 — Aviso de pack (COT-009, DEC-COT-08)

- **GIVEN** el "Pack 6 absorbentes" activo, a 15,00 por ítem
- **WHEN** se calcula una cotización con solo 6 absorbentes `16` (Detal, 40,00)
- **THEN** la línea se mantiene en 240,00 y el cálculo devuelve un aviso con el pack, 1 pack posible y un ahorro estimado de 150,00
- **AND** con 5 absorbentes no hay aviso

### E-70 — Cambiar los grupos de talla de una combinación con precios (COT-004, DEC-COT-53)

- **GIVEN** la combinación `184` con precios cargados sin grupos de talla y un usuario con `pricing.manage`
- **WHEN** define los grupos 28–36 y 38–44
- **THEN** el `ConfirmDialog` lista los precios que se eliminarán (grupo, tramo y valor)
- **AND** al confirmar, esos precios se eliminan con auditoría de los valores anteriores, los grupos se guardan y la combinación queda sin precio hasta cargar los de cada grupo (COT-025)
- **AND** sin confirmar no cambia nada
- **AND** lo mismo ocurre al quitar los grupos o al fusionar o reorganizar grupos con precios

### E-71 — Sin aviso de pack con varios precios unitarios (COT-009, DEC-COT-54)

- **GIVEN** un pack activo cuyo componente admite la combinación de una línea, y esa combinación tiene grupos de talla con precios distintos
- **WHEN** se calcula una cotización con esa línea en cantidad suficiente para el pack y un precio por ítem del pack menor que todos los precios unitarios de la línea
- **THEN** el cálculo no devuelve aviso de packs para esa línea
- **AND** la línea se calcula igual que sin packs

### E-72 — Cambia la combinación de una línea negociada (COT-012, DEC-COT-55)

- **GIVEN** la cotización de E-19 con precios negociados
- **WHEN** se cambia la tela de la línea de camisas, que resuelve otra combinación, y la cotización sigue con 50 piezas de uniformes o más
- **THEN** el precio negociado de esa línea se descarta y la línea vuelve a requerir un precio negociado
- **AND** el cálculo indica que se descartó un precio negociado
- **AND** el precio negociado de la línea de chemises se conserva
- **AND** cambiar solo las cantidades de las camisas, sin cambiar de combinación ni de grupo de talla, no descarta su precio negociado

### E-73 — Cambiar el destinatario conserva la asesora (COT-013, DEC-COT-56)

- **GIVEN** un borrador para un prospecto cuya asesora responsable es quien lo creó, y un cliente activo con otra asesora asignada
- **WHEN** se cambia el destinatario del borrador a ese cliente
- **THEN** la asesora responsable sigue siendo la misma
- **AND** un usuario puede cambiarla a mano por un usuario activo con `customers.portfolio`
- **AND** no se puede dejar el borrador sin asesora: un valor vacío conserva la asesora actual (DEC-COT-65)

### E-74 — Asesora del cliente no disponible al crear (COT-013, DEC-COT-63)

- **GIVEN** un cliente activo cuya asesora asignada está inactiva o no tiene `customers.portfolio`
- **WHEN** un usuario con `quotations.create` y `customers.portfolio` crea una cotización para ese cliente
- **THEN** la asesora responsable es quien la crea y el sistema muestra el aviso "asesora del cliente no disponible"
- **AND** si quien la crea no tiene `customers.portfolio`, la cotización queda sin asesora responsable, con el mismo aviso
- **AND** si quien la crea elige una asesora a mano, esa queda como responsable y el aviso igual se muestra

### E-75 — Asesora responsable que deja de estar disponible (COT-013, DEC-COT-64, DEC-CLI-29)

- **GIVEN** una cotización cuya asesora responsable se desactiva o pierde `customers.portfolio`
- **WHEN** se consulta la cotización
- **THEN** sigue a cargo de esa asesora, sin reasignación automática, y se marca "asesora no disponible"
- **AND** en un borrador la asesora se cambia editándolo
- **AND** en una cotización emitida, un usuario con `customers.assign` cambia solo la asesora, por una activa con `customers.portfolio`, y el cambio queda auditado; sin `customers.assign` el backend responde 403 y la cotización no cambia
- **AND** el documento de la cotización emitida (la asesora que muestra la vista imprimible y el correo) pasa a mostrar la asesora nueva; los precios, la tasa y el resto del documento no cambian

### E-76 — Precio negociado por los grupos de talla que usa la línea (COT-012, DEC-COT-66, DEC-COT-55)

- **GIVEN** una línea de `184` que requiere asesora, con filas solo en tallas del grupo 28–36, aunque la combinación también tiene el grupo 38–44, y un usuario con `quotations.negotiate`
- **WHEN** registra el precio negociado
- **THEN** se pide y se guarda un precio solo para el grupo 28–36
- **AND** un precio enviado para el grupo 38–44, que la línea no usa, se rechaza
- **AND** si después se agrega una talla del grupo 38–44 o se quitan las del grupo 28–36, el precio negociado se descarta con el aviso de E-22 y se negocia de nuevo (E-72)
- **AND** si una fila tiene una talla que no está en ningún grupo, la línea sigue "sin precio" (COT-025) y el precio negociado se rechaza indicando esa talla, hasta que la talla tenga grupo o se quite la fila (DEC-COT-67)

---

## 8. Interfaz (ERP)

Aplica `docs/ui/design-system.md` completo. Puntos específicos de esta spec:

| Pantalla | Componentes y reglas |
|---|---|
| Listado de cotizaciones | `DataTable`, `SegmentedTabs` por estado con conteos, búsqueda con `AppInput`, filtros con `AppSelect` (asesora, "Mis cotizaciones", requiere asesora, prospecto) y rango de fechas, `StatusBadge` según COT-015. Indicadores visibles "Requiere asesora" y "Prospecto" |
| Editor de cotización | Cabecera: destinatario (búsqueda de cliente o datos del prospecto), persona de contacto, asesora responsable. Líneas en `AppCard` apiladas: selección paso a paso con las opciones de PRD-019 (miniaturas y muestras de color; los atributos aplicados automáticamente se muestran fijos), tabla de cantidades por talla, personalizaciones con cantidad por prenda y la etiqueta "sin cargo", nota y avisos de packs. Resumen fijo (abajo en móvil, lateral en `lg:`): piezas por línea de negocio, tramo de cada línea, total, anticipo y su equivalente en Bs con la tasa y su fecha, siempre devueltos por el backend. Errores por línea y campo. Acciones: Guardar borrador, Emitir, Descartar |
| Negociación | En la línea que requiere asesora: campo de precio unitario (por grupo de talla si aplica) visible solo con `quotations.negotiate`; en color personalizado, botón "Confirmar precio de referencia". En el resumen: campo "Anticipo acordado" con el valor propuesto |
| Ficha de cotización | Datos, estado, fechas, líneas con su copia congelada, totales en USD y Bs, desglose del anticipo, motivos de derivación, envíos por correo (con resultado), cotización de origen si es un duplicado. Acciones según estado y permisos: Vista imprimible, Reenviar por correo, Duplicar, Anular |
| Vista imprimible | Página limpia para imprimir o guardar como PDF desde el navegador, con el contenido de COT-021 |
| Esquemas de precio | Listado con tipo y estado; editor de tramos ordenados (etiqueta, mínimo, máximo, tipo) con validación del backend |
| Precios por producto | Tabla: una fila por combinación (código, nombre descriptivo, estado) y una columna por tramo; filas de grupo de talla bajo su combinación; marca de "sin precio". Asignación de esquema en la cabecera |
| Precios de combos | En el combo: tabla de componentes con cantidad, precio por ítem y total calculado por el backend |
| Parámetros y tasa | Formulario con porcentaje de anticipo y días de vigencia. Tasa vigente con su fecha y origen, historial de tasas y acción "Registrar tasa manual" (con `pricing.manage`) |
| Actualización masiva | En "Precios": acción "Exportar CSV" (alcance, esquema y filtros) y acción "Importar CSV". Tras subir: lista de errores (fila, columna, motivo) o previsualización con el resumen en tarjetas (nuevos, modificados, sin cambios), `DataTable` de cambios (código, nombre, grupo de talla, tramo, anterior, nuevo, variación) con filtro "solo advertencias", y acciones Aplicar (`ConfirmDialog`) y Descartar. Pensada para escritorio, pero consultable en móvil |
| Historial de importaciones | `DataTable` con fecha, usuario, alcance, archivo, resumen y estado (aplicada → `done`, descartada → `neutral`); detalle con los cambios |
| Anular / Descartar / Quitar tramo con precios / Cambiar esquema con precios | `ConfirmDialog` variante `danger` |

- Las asesoras cotizan sobre todo desde el teléfono: el editor es mobile-first estricto (constitución §14), con objetivos táctiles de 44 px o más y entradas numéricas con `inputmode="numeric"` o `"decimal"` y `text-body-md` para evitar el zoom de iOS.
- Los importes se muestran en USD con 2 decimales y los equivalentes en Bs marcados como "referencial", con la tasa y su fecha. El frontend no recalcula: muestra lo que devuelve COT-009.
- El correo usa una plantilla HTML sencilla con la identidad de Ecolekua, legible en el teléfono.
- Textos visibles en español; código, rutas e identificadores en inglés. Sin clases `dark:` ni hexadecimales en los estilos.
- Opcional: la vista previa por capas de `005` en el editor (compromiso de `003` §10). No es requisito de esta spec.

---

## 9. Decisiones de esta Spec

Todas **Confirmadas** por el usuario: DEC-COT-01 a DEC-COT-43 el 2026-10-12; DEC-COT-44 a DEC-COT-59 el 2026-10-13, tras la exploración y el diseño del change; DEC-COT-60 el 2026-10-14 y DEC-COT-61 a DEC-COT-67 el 2026-10-15 y DEC-COT-68 el 2026-10-16, durante la implementación.

| ID | Decisión | Opciones | Estado |
|---|---|---|---|
| DEC-COT-01 | Moneda | A) USD · B) Bolívares · C) USD con equivalente en Bs | **Confirmada**: **A con tasa**. Precios y cotizaciones en USD; se guarda la tasa oficial del día de `ve.dolarapi.com/v1/dolares/oficial` (campo `promedio`) (COT-030) |
| DEC-COT-02 | Precisión y redondeo | A) Precios con 2 decimales, redondeados al cargarlos (mitad hacia arriba); subtotales y total exactos; anticipo y equivalentes en Bs redondeados a 2 decimales · B) Todos los decimales | **Confirmada**: **A** |
| DEC-COT-03 | Columna "más de 50 unidades" de uniformes | A) Se descarta · B) Precio de referencia | **Confirmada**: **A** |
| DEC-COT-04 | Precio fijo de la sección "Varios" | A) Dato de la carga · B) "Precio 4" tal cual, ya es final · C) "Precio 4" + 16 % | **Confirmada**: **B** |
| DEC-COT-05 | Umbral VIP en la línea de pañales | A) Sin VIP al inicio; se añade por configuración · B) Con VIP desde el inicio | **Confirmada**: **A** |
| DEC-COT-06 | Precio de los combos | A) Un precio fijo por ítem en cada combo; qué columna (P1–P4) usa cada kit es dato de la carga · B) Según la cantidad de combos | **Confirmada**: **A** |
| DEC-COT-07 | ¿Las unidades de los combos cuentan para el tramo? | A) No · B) Sí | **Confirmada**: **A** |
| DEC-COT-08 | Sugerir packs | A) No · B) Aviso informativo · C) Sustitución automática | **Confirmada**: **B** (COT-009, paso 11) |
| DEC-COT-09 | Color en la línea o por talla | A) En la línea · B) Por fila de talla | **Confirmada**: **A** |
| DEC-COT-10 | Anticipo por tipo de producto | A) Por modo: bajo pedido y servicios, %; stock y combos, pago completo · B) Mismo % para todo · C) Solo bajo pedido | **Confirmada**: **A** |
| DEC-COT-11 | Anticipo de las líneas negociadas | A) El mismo % · B) Lo acuerda la asesora | **Confirmada**: **B**, como monto en USD entre 0 y el subtotal negociado, con el % vigente como propuesta |
| DEC-COT-12 | Precio de las líneas que requieren asesora | A) Precio negociado en el ERP con `quotations.negotiate` · B) Fuera del sistema | **Confirmada**: **A** |
| DEC-COT-13 | Personalizaciones añadidas | A) Se cobran con el precio del servicio · B) Sin cargo por producto · C) Sin cargo siempre en uniformes | **Confirmada**: **C**. Son especificación para producción, con cantidad por prenda; los servicios solo tienen precio cuando se venden solos. Se actualiza business rules §26.2 |
| DEC-COT-14 | Tramo de un servicio vendido solo | A) Su propia cantidad · B) Sumado a las prendas | **Confirmada**: **A**, la cantidad total de ese mismo servicio en la cotización |
| DEC-COT-15 | Vigencia de la cotización | Días naturales, configurable | **Confirmada**: **7 días** |
| DEC-COT-16 | Precios al emitir | A) Congelados hasta el vencimiento · B) Recalculados | **Confirmada**: **A** |
| DEC-COT-17 | Estados | Borrador → Emitida → Vencida / Anulada / Aprobada | **Confirmada**. "Aprobada" reemplaza a "Convertida": el cliente aceptó y la cotización se convirtió en pedido (`006`) |
| DEC-COT-18 | Cambiar una emitida | A) No se edita; se duplica · B) Vuelve a borrador | **Confirmada**: **A** |
| DEC-COT-19 | Destinatario de la cotización del ERP | A) Cliente obligatorio y activo · B) Opcional | **Confirmada**: **B**. Cliente registrado activo o prospecto con nombre, teléfono y correo opcional; `006` exige el cliente al convertir |
| DEC-COT-20 | Documento para el cliente | A) Vista imprimible · B) PDF en el servidor · C) Sin documento | **Confirmada**: **A**, más envío por correo (COT-031) |
| DEC-COT-21 | Numeración | A) Correlativo global · B) Por año | **Confirmada**: **A** |
| DEC-COT-22 | Asignación de esquemas | A) Por producto, con asignación en bloque · B) Por categoría | **Confirmada**: **A** |
| DEC-COT-23 | Quitar un tramo o cambiar el esquema con precios | A) Se permite con confirmación; los precios se eliminan con auditoría · B) Se rechaza | **Confirmada**: **A** |
| DEC-COT-24 | Borradores | Se descartan (eliminación con copia en la auditoría); mientras existen cuentan como historial | **Confirmada** |
| DEC-COT-25 | Permisos iniciales | Matriz de §4 | **Confirmada** |
| DEC-COT-26 | Límites iniciales | Uniformes 13–49 y negociación desde 50; pañales especial desde 20 | **Confirmada** (business rules §2.2 actualizadas por el usuario) |
| DEC-COT-27 | Descuentos manuales | A) No existen · B) Descuento libre | **Confirmada**: **A** |
| DEC-COT-28 | Visibilidad | A) Todas, con filtro "Mis cotizaciones" · B) Solo las propias | **Confirmada**: **A** |
| DEC-COT-29 | Auditoría de los borradores | A) Solo eventos clave · B) Cada edición | **Confirmada**: **A** |
| DEC-COT-30 | Permiso para la actualización masiva | A) `pricing.manage` · B) `pricing.import` | **Confirmada**: **A** |
| DEC-COT-31 | Formato del CSV | A) Exporta con `;`, coma decimal y UTF-8 con BOM; importa con `;` o `,` y coma o punto decimal · B) Estricto con `,` y punto | **Confirmada**: **A** |
| DEC-COT-32 | Celda vacía | A) Sin cambio · B) Borra el precio | **Confirmada**: **A** |
| DEC-COT-33 | Más de 2 decimales en el archivo | A) Se redondea con advertencia · B) Error | **Confirmada**: **A** |
| DEC-COT-34 | Variación grande | A) Advertir sobre ±30 %, sin bloquear · B) Sin aviso | **Confirmada**: **A** |
| DEC-COT-35 | Precios cambiados entre validar y aplicar | A) Se rechaza y se sube de nuevo · B) Se sobrescribe | **Confirmada**: **A** |
| DEC-COT-36 | Guardar el archivo importado | A) No · B) Sí | **Confirmada**: **A** |
| DEC-COT-37 | Ajuste porcentual en pantalla | A) No; se calcula en el CSV · B) Sí | **Confirmada**: **A** |
| DEC-COT-38 | Falla de la API de la tasa | A) Última tasa guardada con su fecha + registro manual con `pricing.manage`; nunca se bloquea la emisión · B) Solo la última tasa · C) Bloquear la emisión | **Confirmada**: **A** |
| DEC-COT-39 | Equivalente en Bs | A) Se muestra al cliente como referencia (vista imprimible y correo) · B) Solo se guarda | **Confirmada**: **A** |
| DEC-COT-40 | Forma del anticipo acordado | A) Monto en USD · B) Porcentaje | **Confirmada**: **A** |
| DEC-COT-41 | Envío por correo | A) Automático al emitir, a cliente y persona de contacto (o prospecto); sin correo se emite igual con aviso; reenvío manual · B) Manual · C) Correo obligatorio | **Confirmada**: **A** |
| DEC-COT-42 | Contenido del correo | A) Cotización completa en el cuerpo (HTML) · B) Enlace firmado | **Confirmada**: **A** |
| DEC-COT-43 | Hora de consulta y fecha de la tasa | Consulta diaria a las 7:00 a. m. `America/Caracas`; el valor obtenido es la tasa de ese día, aunque `fechaActualizacion` de la fuente sea otra | **Confirmada** (2026-10-12) |
| DEC-COT-44 | Precios de un combo al editar sus componentes | A) Se conserva el precio de los componentes cuyo producto no cambia, aunque cambien su cantidad u orden; un componente nuevo o con otro producto queda sin precio · B) Cualquier cambio borra todos los precios del combo · C) No se editan los componentes de un combo con precios | **Confirmada** (2026-10-13): **A**. El combo no se puede cotizar hasta cargar los precios que falten (COT-025) |
| DEC-COT-45 | Formato del número y huecos | A) Huecos aceptados; se muestra con prefijo y ceros (`COT-000123`) y se guarda como entero · B) Huecos aceptados; entero simple · C) Sin huecos; número al emitir | **Confirmada** (2026-10-13): **A**. Un borrador descartado no libera su número; la auditoría registra el descarte (DEC-COT-24) |
| DEC-COT-46 | Datos de Ecolekua en la vista imprimible y el correo | A) Archivo de configuración con valores de entorno; los cambia el equipo técnico · B) Pantalla de parámetros editable y auditada · C) Fijos en la plantilla | **Confirmada** (2026-10-13): **A**. Sin pantalla, permiso ni auditoría en `004` |
| DEC-COT-47 | Varios packs aplicables a una línea | A) Un aviso por pack, ordenados por ahorro · B) Un solo aviso: el pack con mayor ahorro estimado; si empatan, el de más unidades · C) Un aviso con la mejor mezcla de packs | **Confirmada** (2026-10-13): **B** |
| DEC-COT-48 | Grupos de talla al quitar una talla | A) La talla sale de su grupo con auditoría; un grupo vacío se elimina con sus precios y auditoría · B) Se rechaza quitar una talla que pertenece a un grupo | **Confirmada** (2026-10-13): **A**, igual que DEC-PRD-52 B |
| DEC-COT-49 | Datos del destinatario y la asesora en una emitida | A) Se congelan al emitir; el vínculo con el cliente se mantiene · B) Se leen siempre los actuales | **Confirmada** (2026-10-13): **A** |
| DEC-COT-50 | Correos de una cotización | A) Un solo correo con todos los destinatarios · B) Un correo por destinatario | **Confirmada** (2026-10-13): **B**, sin repetir direcciones |
| DEC-COT-51 | Cambio de tipo de un esquema | A) El tipo se fija al crear · B) Solo sin productos asignados · C) Siempre, borrando sus precios | **Confirmada** (2026-10-13): **A**; se usa otro esquema y COT-002 |
| DEC-COT-52 | ¿`pricing.*` exige `products.view`? | A) Sí · B) No; las pantallas de precios muestran solo código, nombre y estado del catálogo | **Confirmada** (2026-10-13): **B** |
| DEC-COT-53 | Precios al cambiar los grupos de talla | A) Con confirmación se eliminan con auditoría (como DEC-COT-23) · B) Los grupos heredan los precios sin grupo · C) Se rechaza con precios cargados | **Confirmada** (2026-10-13): **A**, para pasar a grupos, quitarlos y fusionar o reorganizar. El `ConfirmDialog` lista grupo, tramo y valor. Escenario E-70 |
| DEC-COT-54 | Aviso de pack en una línea con varios precios unitarios | A) Sin aviso · B) Comparar con el menor precio · C) Ahorro fila por fila | **Confirmada** (2026-10-13): **A**. Hoy no ocurre: los combos son de pañales (DEC-PRD-43) y en pañales la talla es eje (COT-014). Escenario E-71 |
| DEC-COT-55 | Precio negociado si cambia la combinación o los grupos de una línea que sigue requiriendo asesora | A) Se descarta, con el mismo aviso que E-22 · B) Se conserva y la asesora lo revisa | **Confirmada** (2026-10-13): **A**. Cambiar solo cantidades o tallas dentro del mismo grupo no lo descarta. Escenario E-72 |
| DEC-COT-56 | Asesora al cambiar el destinatario de un borrador | A) Se conserva; se cambia a mano · B) Se vuelve a aplicar la regla por defecto | **Confirmada** (2026-10-13): **A**. Escenario E-73 |
| DEC-COT-57 | Convertir en negociación un tramo con precios | Se trata como quitar el tramo (DEC-COT-23) | **Confirmada** (2026-10-13) |
| DEC-COT-58 | Asignar o mover tallas entre grupos con precios | Mover una talla de un grupo a otro es reorganizar (DEC-COT-53: confirmación y borrado de los precios de los grupos afectados); solo asignar una talla que no estaba en ningún grupo conserva los precios del grupo | **Confirmada** (2026-10-13) |
| DEC-COT-59 | "Más de un precio unitario" en DEC-COT-54 | Se cuentan valores distintos: dos grupos con el mismo precio son un único precio | **Confirmada** (2026-10-13) |
| DEC-COT-60 | Decimales de la tasa de cambio | A) 2 decimales en todo: la API se redondea HALF_UP a 2 y la carga manual rechaza más de 2 · B) guardar 4 y usar 2 · C) como A pero la carga manual redondea | **Confirmada**: **A** (2026-10-14). Sustituye los 4 decimales de COT-030 y DT-01; E-36, E-63 y E-64 se ajustan |
| DEC-COT-61 | Empate de DEC-COT-47 en ahorro y en unidades | A) El primero según el orden de carga · B) El de código de combo menor (orden alfabético) | **Confirmada**: **B** (2026-10-15) |
| DEC-COT-62 | ¿Cuentan para el tramo las piezas de un producto con esquema por tramos inactivo? | A) No: se trata como un producto sin esquema · B) Sí: el esquema sigue siendo por tramos | **Confirmada**: **A** (2026-10-15) |
| DEC-COT-63 | Asesora por defecto cuando la asesora del cliente está inactiva o sin `customers.portfolio` | A) Se asigna igual · B) Se salta: quien crea si tiene cartera; si no, ninguna · C) Se rechaza la creación | **Confirmada**: **B** (2026-10-15), con el aviso "asesora del cliente no disponible", que se muestra también cuando se elige una asesora a mano. Escenario E-74 |
| DEC-COT-68 | ¿Se audita el descarte automático del anticipo acordado? | A) No, igual que el descarte automático del precio negociado (DEC-COT-29) · B) Sí, como `quotations.agreed_advance_set` | **Confirmada**: **A** (2026-10-16). Escenario E-62 |
| DEC-COT-67 | Línea que requiere asesora con una talla en ningún grupo de su combinación | A) Sigue "sin precio" y no se negocia hasta que la talla tenga grupo o se quite la fila · B) Esa fila se negocia con un precio "sin grupo" | **Confirmada**: **A** (2026-10-15), coherente con COT-025. Escenario E-76 |
| DEC-COT-66 | Grupos de talla que se negocian en una línea que requiere asesora | A) Solo los grupos que usan las filas de la línea · B) Todos los grupos de la combinación | **Confirmada**: **A** (2026-10-15), coherente con DEC-COT-55: si un cambio agrega o quita un grupo, el precio se descarta y se negocia de nuevo. Escenario E-76 |
| DEC-COT-65 | ¿Se puede quitar la asesora de un borrador? | A) No: solo se cambia por una activa con `customers.portfolio`; un valor vacío la deja como está · B) Sí, queda vacía y auditada | **Confirmada**: **A** (2026-10-15). Escenario E-73 |
| DEC-COT-64 | Asesora responsable que se desactiva o pierde `customers.portfolio` después | Sin reasignación automática (como DEC-CLI-29); se marca "asesora no disponible"; en borrador se cambia editando; en emitida, un usuario con `customers.assign` cambia solo la asesora, auditado (excepción a DEC-COT-18), y el documento emitido pasa a mostrar la asesora nueva | **Confirmada** (2026-10-15). Escenario E-75 |

### Decisiones técnicas

| ID | Decisión | Estado |
|---|---|---|
| DT-01 | Importes en `DECIMAL` de MySQL y aritmética exacta en PHP (p. ej., `brick/math`, que Laravel ya instala), nunca `float`. Tasas con 2 decimales (DEC-COT-60) | Propuesta — se confirma en `design.md`. Dependencia aprobada (2026-10-13): `brick/math` se declara como dependencia directa en `composer.json`, con la versión que ya instala Laravel (AGENTS §2) |
| DT-02 | COT-009 se implementa como una Action reutilizable (`app/Actions/Quotations/CalculateQuotation`) que usa `ResolveSelection` de `003` y no escribe en la base de datos | Propuesta — se confirma en `design.md` |
| DT-03 | La emisión y el número correlativo se asignan dentro de una transacción con bloqueo, para que dos emisiones simultáneas no repitan número | Propuesta — se confirma en `design.md` |
| DT-04 | La lectura y validación del CSV de precios es una sola Action (`app/Actions/Pricing/ValidatePriceImport`) que usan el comando de carga inicial (COT-007) y la importación del ERP (COT-027). Lectura con las funciones nativas de PHP, sin dependencias nuevas. La comprobación de conflictos de COT-028 compara los valores anteriores guardados en la importación con los vigentes, dentro de la transacción y con bloqueo | Propuesta — se confirma en `design.md` |
| DT-05 | La tasa se obtiene con el cliente HTTP de Laravel desde un comando programado (Scheduler, `dailyAt('07:00')->timezone('America/Caracas')`), con tiempo de espera y reintentos acotados; las pruebas simulan la API (`Http::fake`). Sin dependencias nuevas | Propuesta — se confirma en `design.md` |
| DT-06 | El correo es un Mailable en cola despachado después del commit (`afterCommit`); la vista imprimible y el correo comparten la misma vista Blade del contenido. Sin dependencias nuevas | Propuesta — se confirma en `design.md` |

---

## 10. Compromisos para specs posteriores

| Spec | Compromiso |
|---|---|
| `business-rules.md` | **Hecho** (2026-10-12): §2.1 (combos no cuentan; servicios vendidos solos con su propia cantidad), §2.2 (13–49 y negociación desde 50), §2.3 (sin VIP inicial en pañales), §2.4 (precio fijo por ítem en cada kit), §2.5 ("Precio 4" como precio final), §2.6 (moneda y tasa), §3 (anticipo por modo y anticipo acordado) y §26.2 (personalizaciones sin cargo en uniformes) |
| `005` | Usar COT-009 para todo cálculo del portal, sin cálculo en JavaScript. Cotización de visitantes; origen "web". Derivar a WhatsApp cuando COT-009 indica "requiere asesora". Número de WhatsApp comercial. Mostrar los avisos de packs y el equivalente en Bs |
| `006` | Convertir solo cotizaciones emitidas y vigentes; pasarlas a "aprobada". Si la cotización es de un prospecto, exigir registrar o elegir el cliente al convertir. El pedido copia las líneas congeladas, el anticipo requerido y la tasa de la cotización. Anticipo como transacción. Pagos en USD o Bs con la tasa que defina `006`. Desglose de IVA desde el precio final |
| `006` | Añadir la condición "cliente con pedidos" a CLI-010 y "combinación con pedidos" a PRD-014 |
| `006` / `014` | El origen y la asesora responsable de la cotización pasan al pedido (business rules §1, §19, §20) |
| `007` | El cliente ve en su área privada sus cotizaciones emitidas, con el mismo contenido de la vista imprimible |
| `008` | Añadir la condición "combinación con movimientos de stock" a PRD-014 |
| `015` | Eventos de Laravel al emitir, anular y vencer una cotización, cuando una cotización requiere asesora, cuando falla un envío por correo y cuando falla la obtención de la tasa, para que las notificaciones solo se suscriban |
| `016` | Indicadores de cotizaciones: emitidas, aprobadas, vencidas y tasa de aprobación por asesora; cotizaciones de prospectos |

---

## 11. Trazabilidad con las fuentes

| Fuente | Aporta |
|---|---|
| Informe de propuesta §3.1 | Cotizador automático, reglas de precios por cantidad, cálculo automático del anticipo (sus tramos quedan reemplazados por business rules §2) |
| Informe de propuesta §4 | Cotizaciones y pagos en la ficha del cliente; flujo Cotización → Confirmación → Anticipo |
| Business rules §2, §2.6 | Precios finales, conteo por línea de negocio, tramos, combos y packs, precio fijo, moneda y tasa |
| Business rules §3 | Anticipo configurable por modo y anticipo acordado |
| Business rules §26.1, §26.2 | Color personalizado; personalizaciones sin cargo |
| Business rules §25 | Tramos, umbrales VIP, precios y precios de combos configurables |
| Lista de precios ("Uniformes", "Pañales para adultos") | Columnas de precio, kits, packs, grupo de talla de `184` y `184-2`, datos de la carga |
| `002-customers` | Cliente activo, persona de contacto, correo, asesora asignada, validación de teléfonos, CLI-010 y CLI-013 |
| `003-products` | Códigos, PRD-011, PRD-019 (DEC-PRD-94), combos (DEC-PRD-42, DEC-PRD-87), personalizaciones (DEC-PRD-47, DEC-PRD-98), compromisos de §10 |
| Roadmap §5 y §7 | Compromisos hacia `004` e información pendiente de Ecolekua |
| Necesidades y decisiones del usuario (2026-10-08 y 2026-10-12) | Actualización masiva por CSV, tasa desde `ve.dolarapi.com`, correo al emitir, prospectos, estado "aprobada", decisiones de §9 |
| Constitución §3.2, §10, §11, §15 | Backend como autoridad, auditoría de precios, configuración sin motor de reglas, integraciones desacopladas |
| AGENTS §7, §8, §9 | Dinero sin `float`, el portal no calcula precios, transacciones, integraciones después del commit |

---

## 12. Requisitos no funcionales y Definition of Done

- [ ] Cada escenario E-xx tiene al menos una prueba Pest cuyo nombre empieza por su ID.
- [ ] Las pruebas se ejecutan sobre MySQL (base `testing` de Sail).
- [ ] Ningún importe usa `float` en PHP, TypeScript ni en la base de datos. El frontend no realiza ninguna operación aritmética sobre importes.
- [ ] COT-009 tiene pruebas unitarias de los límites de cada tramo, del conteo por línea de negocio y por servicio, de grupos de talla, combos, precio fijo, anticipo por modo, avisos de packs, equivalentes en Bs y redondeo.
- [ ] Ninguna prueba llama a la API real de la tasa ni envía correos reales (`Http::fake`, `Mail::fake`).
- [ ] Autorización con Policies; ninguna comprobación por nombre de rol.
- [ ] Altas, emisiones, anulaciones, descartes, importación, tasas y cambios de precios dentro de Actions (`app/Actions/Pricing/`, `app/Actions/Quotations/`), con transacción cuando afecten a varias entidades.
- [ ] Unicidad del número de cotización y de la tasa por día garantizada en la base de datos.
- [ ] Claves foráneas con restricción de borrado hacia clientes, combinaciones y combos.
- [ ] Seeders con precios y tasas ficticios. Los CSV de carga no se versionan.
- [ ] La importación masiva tiene pruebas de ida y vuelta: un archivo exportado e importado sin cambios no genera ningún cambio.
- [ ] La aplicación de una importación es atómica: una prueba fuerza un fallo a mitad de la aplicación y verifica que ningún precio cambió.
- [ ] Un fallo del correo no revierte la emisión: existe una prueba que lo verifica.
- [ ] `sail artisan test`, `sail pint --test` y `sail pnpm build` en verde.
- [ ] Checklist del design system (§11) verificado a 375, 768 y 1280 px, incluida la vista imprimible.
- [ ] Los anexos A y B son datos para la carga inicial: se validan con Ecolekua antes de ejecutar COT-007, no antes de Design.

---

## Anexo A — Correspondencia entre la lista de precios y los tramos (datos para la carga inicial)

**Hoja "Uniformes"** (columnas: COD, PRODUCTO, COSTO, y para cada grupo un precio sin IVA y otro con IVA). Se cargan solo las columnas con IVA.

| Columna de la lista | Encabezado | Tramo del esquema "Uniformes" |
|---|---|---|
| Precio 4 con IVA (columna "IVA") | 1–6 UNID | Detal 1–6 |
| Precio 3 con IVA | 7–12 UNID | 7–12 |
| Precio 2 con IVA | 13–49 UNID | 13–49 |
| Precio 1 con IVA | MAS 50 UNID | No se carga: negociación (DEC-COT-03) |
| COSTO | — | No se carga |

- `184` y `184-2` aparecen dos veces ("hasta la talla 36" / "a partir de la talla 38"): cada fila es un grupo de talla (COT-004).
- Lanyards (`173`, `173-1`) y bordados (`157`, `157-1`) usan las mismas columnas; se cargan igual. Los servicios con tramos (`157`, `157-1`, `180`) reciben el esquema "Uniformes", salvo que Ecolekua indique otro.
- **Sección "Varios"** (`001RN`…`001XXG`, `125`, `125-1`, `173-2`, `176`…`181`): esquema "Precio fijo" con el valor de "Precio 4" tal como está, que ya es el precio final (DEC-COT-04).

**Hoja "Pañales para adultos"** (columnas: COD, PRODUCTO, COSTO, Especial, Precio 1, Precio 2, Precio 3, Precio 4, cada una con su precio con IVA).

| Productos | Esquema | Detal | Especial |
|---|---|---|---|
| Pañales antiderrame `10`–`13` y antigoteo `010-1`–`012-1` | Pañales de adulto | Precio 4 con IVA (1–19) | Especial con IVA (20+) |
| Protector de cama `29` y absorbentes 3XG/4XG `16` | Protector y absorbentes | Precio 4 con IVA (1–6) | Especial con IVA (7+) |
| Absorbentes 2XG `013-1`, toalla clínica `016-1` | Por confirmar con Ecolekua (business rules §2.3) | — | — |
| Bolsas ecológicas `163` | Por confirmar (Anexo A de `003`) | — | — |

- Las columnas Precio 1 a Precio 3 se usan solo para los precios de ítems de combos.
- **Packs** (combos de un componente): individual = Precio 4 · pack de 2 = Precio 2 · pack de 3 = Precio 1 · pack de 6 = Especial (todas con IVA), según la tabla de la hoja.
- **Kits**: cada kit tiene un único precio por ítem (DEC-COT-06). Ecolekua indica qué columna (Precio 1 a 4) se carga para cada kit.
- Las "Opciones 1/2/3" no se registran (DEC-PRD-13).

## Anexo B — Inconsistencias detectadas en la lista (validar con Ecolekua antes de la carga)

| Ubicación | Hallazgo | Propuesta |
|---|---|---|
| Kit Diamante antiderrame, columna Precio 4 | El pañal usa el precio **sin IVA**; los demás ítems y kits usan precio con IVA | Corregir al precio con IVA |
| Kit Antigoteo Severa, columna Precio 1 | El absorbente usa el precio del absorbente **2XG**; en las demás columnas usa el de 3XG/4XG | Confirmar cuál absorbente lleva el kit |
| Kit Moderado Juvenil y Kit Diamante Juvenil, columna Precio 1 | El pañal usa el precio **sin IVA**; el Kit Básico Juvenil usa el precio con IVA | Corregir al precio con IVA |
| Kits juveniles | El pañal se describe como "antiderrame / tipo panty" con el precio de la talla 2XG (`13`), pero no existe antigoteo 2XG | Confirmar qué combinación es: si admite dos productos, son dos combos distintos |
| Kits "tipo panty" | "Pañal tipo panty" tiene el precio del antigoteo (`010-1`…`012-1`) | Confirmar que "tipo panty" = antigoteo |
| Nota de la hoja de pañales | "especial más de 30" | Se usa desde 20 (DEC-COT-26) |
| Todas las hojas | Precios con muchos decimales | Redondeo a 2 decimales al cargar (DEC-COT-02) |
