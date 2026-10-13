# Spec 003 — Productos (`003-products`)

| Campo | Valor |
|---|---|
| Prefijo de requisitos | `PRD` |
| Ubicación | `docs/specs/003-products.md` |
| Estado | **Lista para Design** (2026-10-06) — decisiones de §9 confirmadas. Los datos de la carga inicial se validan con Ecolekua antes de ejecutar PRD-018 (§12) |
| Depende de | `001-foundation` (autenticación, permisos, auditoría, layout del ERP) |
| La usan | `004-pricing-and-quotations`, `005-public-web-portal`, `006-orders-and-payments`, `008-inventory-and-materials`, `009-material-reservations`, `010-production` |
| Rama | `feat/003-products` |
| Fecha | 2026-10-06 |

> Subordinada a `docs/constitution.md` y `docs/business/business-rules.md`. Todo requisito marcado con ⏳ depende de una decisión pendiente y no se implementa hasta que esa decisión esté **Confirmada** (AGENTS §4.4).

---

## 1. Contexto

Hoy el catálogo de Ecolekua vive en una hoja de cálculo de precios con dos hojas: **"Uniformes"** y **"Pañales para adultos"** (DEC-PRD-27). Cada fila es una combinación vendible con su código, por ejemplo:

```text
110    CAMISA CORP / ALG-OXF PIMA / COLUMBIA ESPECIAL / MANGA CORTA / CABALLERO
110-1  CAMISA CORP / ALG-OXF PIMA / COLUMBIA ESPECIAL / MANGA CORTA / DAMA
         │            │              │                  │             └─ género
         │            │              │                  └─ tipo de manga
         │            │              └─ modelo
         │            └─ tela
         └─ producto
```

El código identifica la combinación de atributos que **determinan el precio**. La talla, el color y los detalles no forman parte del código porque no lo cambian, con dos excepciones conocidas:

- En los **pañales** cada talla tiene su propio código (`10` = antiderrame 3XG, `11` = 4XG…).
- En los **pantalones industriales de caballero** el precio cambia según la talla (hasta la 36 / a partir de la 38) sin cambiar el código (`184`, `184-2`) (DEC-PRD-06).

Ecolekua trabaja con dos líneas de negocio:

- **Uniformes**: confección a medida de uniformes corporativos, escolares y de restaurante, con bordado y sublimación. Es el volumen principal. Algunos productos se venden terminados desde stock (monos y franelas escolares de Los Cedros, pantalones industriales, tazas, mouse pads, gorras).
- **Pañales**: pañales de adulto y productos de incontinencia vendidos desde stock, de forma individual o en combos. Se confeccionan solo para reponer stock. Los pañales ecológicos infantiles se venden hasta agotar existencias y no se fabrican más.

En la reunión con Ecolekua del 2026-10-02 se pidió que los atributos y sus combinaciones sean **dinámicos y configurables** desde el ERP, conservando los códigos de la lista actual.

## 2. Objetivo

Permitir a los usuarios internos autorizados mantener el catálogo de Ecolekua (categorías, productos, atributos, combinaciones con su código, detalles, personalizaciones, parámetros de stock y combos) y ofrecer al resto del sistema una forma única, validada en el backend, de convertir una selección del cliente en una combinación vendible. Los precios no forman parte de esta spec.

## 3. Alcance

### 3.1 Dentro del alcance

- Categorías del catálogo.
- Catálogo editable de atributos y sus valores (tela, modelo, manga, género, talla, color…), con su forma de presentación. Los colores tienen nombre y tono de referencia, y cada tela tiene los colores que Ecolekua ofrece en ella. Opción de color personalizado en los productos bajo pedido (PRD-004).
- Productos con su línea de negocio, categoría y modo de abastecimiento.
- Atributos de cada producto con su rol: **eje** (define la combinación y su código) o **de pedido** (se elige al pedir sin cambiar el código).
- Combinaciones comerciales con el código de la lista actual.
- Ubicaciones de detalle (pechera, orilla de pechera, orilla de mangas, pie de cuello) con color.
- Personalizaciones que admite cada producto (bordado, vinil, sublimación sencilla…).
- Parámetros de stock: modo de abastecimiento y stock mínimo.
- Combos de pañales de adulto: composición.
- Opciones disponibles en cada paso de una selección parcial, en el orden de ejes del producto (PRD-019).
- Resolución y validación de una selección (producto + valores) a una combinación (PRD-011).
- Visibilidad en el portal público.
- Recursos visuales: imagen principal del producto, imagen opcional por valor de atributo y por ubicación de detalle, y plantillas SVG por capas para la vista previa de la prenda, una por género cuando el corte cambia (PRD-020).
- Alta, edición, desactivación, reactivación y eliminación restringida.
- Carga inicial única desde la lista actual mediante un comando técnico (PRD-018).
- Permisos `products.*` verificados en backend y auditoría.

### 3.2 Fuera del alcance

| Tema | Dónde se trata |
|---|---|
| Precios por tramo, precio fijo, precio de los ítems de un combo, tramos configurables, umbrales de negociación VIP | `004` (§10) |
| Distribución de cantidades por talla y color dentro de una línea de cotización o pedido | `004` / `006` |
| Regla de stock mínimo y botón de WhatsApp en el portal y en los pedidos | `005` / `006` (§10) |
| Archivo de diseño o logo del cliente (sublimación, bordado) | `006` |
| Existencias, movimientos y artículos de stock (combinación + talla) | `008` |
| Prendas base en stock (p. ej., franelas blancas) y su relación con las combinaciones que pueden abastecer | `008` / `009` |
| Consumo de materiales por producto: tela, cinta (lanyards), hilo (bordados), papel (sublimación y vinil) | `008` |
| Reposición del stock por confección | `010` |
| IVA desglosado y facturación | `006` / futura integración |
| Dibujo de la vista previa en el cotizador | `005` |

---

## 4. Actores y permisos

El catálogo lo gestionan usuarios internos. Los visitantes del portal solo lo consultan, según `005`.

| Permiso | Permite |
|---|---|
| `products.view` | Ver categorías, atributos, productos, combinaciones y combos |
| `products.create` | Registrar productos, combinaciones y combos |
| `products.update` | Editar productos, combinaciones, combos, detalles, personalizaciones, parámetros de stock, imagen y plantillas del producto |
| `products.deactivate` | Desactivar y reactivar productos, combinaciones y combos |
| `products.delete` | Eliminar productos, combinaciones y combos sin historial (PRD-014) |
| `products.catalog` | Gestionar categorías, atributos y sus valores, y ubicaciones de detalle, incluidos sus tonos, imágenes y capas |

Asignación inicial propuesta a los roles de `001` (DEC-PRD-22). El código verifica el permiso, nunca el nombre del rol (AGENTS §7.3).

| Permiso | Administrador | Gerente | Asesora de Ventas | Finanzas | Supervisor de Producción | Responsable de Calidad | Operario |
|---|---|---|---|---|---|---|---|
| `products.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| `products.create` | ✓ | ✓ | ✓ | — | — | — | — |
| `products.update` | ✓ | ✓ | ✓ | — | — | — | — |
| `products.deactivate` | ✓ | ✓ | ✓ | — | — | — | — |
| `products.delete` | ✓ | — | — | — | — | — | — |
| `products.catalog` | ✓ | ✓ | ✓ | — | — | — | — |

- **Coherencia** (DEC-PRD-23, análoga a DEC-022 de `001` y DEC-CLI-32): `products.create`, `products.update`, `products.deactivate`, `products.delete` y `products.catalog` exigen `products.view`. Una asignación que no cumpla se rechaza y el rol no cambia.
- `design.md` define cómo se aplica esta matriz a una base de datos existente, igual que en `002`.

---

## 5. Modelo conceptual

Describe **qué** información existe, no su implementación.

```text
Categoría                         (Camisas, Línea escolar, Restaurantes, Pañales de adulto…)  DEC-PRD-18
 └── nombre, orden, estado

Atributo                          (Tela, Modelo, Manga, Género, Talla, Color, Tipo de prenda…)  DEC-PRD-03
 ├── presentación                 (texto | imagen | color)  DEC-PRD-30
 ├── uso especial                 (Tela | Talla | Género | ninguno; como máximo uno de cada uso)  DEC-PRD-32, DEC-PRD-49
 └── Valor                        (ALG-OXF PIMA, Columbia especial, Manga corta, 3XG, Azul marino…)
      └── nombre, orden, descripción opcional (p. ej., "40 a 60 kg"), estado,
          tono de referencia      (obligatorio si la presentación es "color")  DEC-PRD-17
          imagen opcional         (p. ej., textura de la tela, ícono de manga)  DEC-PRD-20
          capa SVG opcional       (p. ej., manga-corta)  DEC-PRD-29
          colores ofrecidos       (solo en el atributo de tela: lista de colores)  DEC-PRD-32

Ubicación de detalle              (Pechera, Orilla de pechera, Orilla de mangas, Pie de cuello)
 └── nombre, estado, imagen opcional (dibujo de la zona), capa SVG (p. ej., orilla-mangas)

Producto                          (Camisa corporativa)
 ├── nombre, descripción, categoría, línea de negocio (uniformes | pañales)  DEC-PRD-26
 ├── modo de abastecimiento       (bajo pedido | stock con mínimo | stock agotable | servicio)  DEC-PRD-09
 ├── stock mínimo por defecto     (solo modo "stock con mínimo"; valor propio opcional por artículo)  DEC-PRD-10, DEC-PRD-46
 ├── admite color personalizado  (sí | no; solo modo "bajo pedido" con color)  DEC-PRD-34
 ├── visible en el portal         DEC-PRD-19
 ├── imagen principal             DEC-PRD-20
 ├── estado                       (activo | inactivo)
 ├── Atributo del producto        (0..n)
 │    └── atributo, rol (eje | de pedido), valores admitidos
 ├── Ubicación de detalle admitida (0..n)
 │    └── ubicación
 ├── Personalización admitida     (0..n, producto de modo "servicio")  DEC-PRD-08
 ├── Plantilla visual             (0..n)  DEC-PRD-29
 │    └── archivo SVG saneado, valor de Género opcional, capas detectadas
 └── Combinación comercial        (1..n)
      ├── código                  (de la lista actual, texto, único)  DEC-PRD-01, DEC-PRD-02
      ├── uno o varios valores por cada eje del producto  DEC-PRD-33
      ├── descripción opcional    (la carga inicial guarda la de la lista)
      ├── personalización incluida (0..n)  DEC-PRD-08
      └── estado

Combo                             (Kit Oro antiderrame…)  DEC-PRD-12
 ├── código DEC-PRD-14, nombre, visible en el portal, estado
 └── Componente (1..n)
      └── producto, valores admitidos por eje (subconjunto), cantidad
```

- Estados, modos de abastecimiento y líneas de negocio se implementan como Enums de PHP (constitución §21.1).
- Los atributos son **datos**: se crean y editan desde el ERP sin tocar código. No hay reglas condicionales, fórmulas ni dependencias entre atributos (constitución §11: configurabilidad no es un motor genérico de reglas).
- El nombre descriptivo de una combinación se compone con el nombre del producto y los valores de sus ejes en el orden del producto. Si un eje tiene varios valores, se muestran unidos por "o" (p. ej., "Kimono completo · Drill o Gabardina · Caballero"); en una cotización o pedido se muestra el valor elegido, por ejemplo: "Camisa corporativa · ALG-OXF Pima · Columbia especial · Manga corta · Caballero".
- El precio de cada combinación y de cada componente de combo lo define `004`, usando el código como referencia. La excepción de precio por rango de talla de `184` y `184-2` también es de `004` (DEC-PRD-06).
- El catálogo del portal muestra el nombre del producto y el nombre descriptivo se usa en cotizaciones, pedidos y en el ERP.

---

## 6. Requisitos

### PRD-001 — Categorías

Un usuario con `products.catalog` puede crear, editar, ordenar, desactivar y reactivar categorías. Cada producto pertenece a **una** categoría (DEC-PRD-18). Una categoría inactiva no se muestra en el portal. Las categorías no se eliminan; se desactivan. El nombre de una categoría es único, sin distinguir mayúsculas (DEC-PRD-45).

### PRD-002 — Catálogo de atributos y valores

Un usuario con `products.catalog` puede crear atributos y sus valores, editarlos, ordenarlos, desactivarlos y reactivarlos (DEC-PRD-03).

- Cada valor tiene nombre, orden y una descripción opcional (p. ej., la talla 3XG con "40 a 60 kg").
- El nombre de un atributo es único. El nombre de un valor es único dentro de su atributo.
- Cada atributo indica su presentación: **texto**, **imagen** o **color** (DEC-PRD-30). El catálogo tiene como máximo un atributo de presentación "color": la paleta de Ecolekua, de la que salen el color de la prenda, los colores ofrecidos en cada tela y los colores de los detalles (DEC-PRD-38).
- Cada valor de un atributo de presentación "color" tiene, además del nombre, un **tono de referencia** que el usuario elige con un selector de colores; el sistema lo guarda como código hexadecimal. El backend rechaza un valor sin tono o con un tono inválido. El tono es una referencia visual: el color real es el de la tela disponible con ese nombre (DEC-PRD-17).
- **Atributos en uso (DEC-PRD-51).** Desactivar un atributo se rechaza mientras algún producto activo lo declare, e indica cuáles. Un atributo inactivo no se puede declarar en un producto, y un producto que declara un atributo inactivo no se puede seleccionar. Cambiar la presentación o el uso especial de un atributo se rechaza mientras algún producto lo declare.
- Los atributos, los valores y las ubicaciones de detalle no se eliminan; se desactivan. Un valor inactivo no se ofrece en selecciones nuevas, las combinaciones y combos que lo usan como eje dejan de ofrecerse, y las cotizaciones y pedidos existentes lo conservan.
- El nombre de un valor se puede editar para corregir erratas. El cambio se refleja en todas las combinaciones que lo usan y queda auditado. Para una tela o un color distinto se crea un valor nuevo.
- Un atributo puede marcarse como **atributo de tela** (DEC-PRD-32). Cada valor de ese atributo tiene la lista de **colores que Ecolekua ofrece** en esa tela, elegidos entre los valores activos del atributo de color. Son los colores que se ofrecen aunque haya que comprar la tela, no solo los que hay en stock. El catálogo tiene como máximo un atributo de tela.
- **Uso especial (DEC-PRD-49).** Un atributo puede tener un uso especial: **Tela**, **Talla** o **Género**, con como máximo un atributo de cada uso en el catálogo. La marca de tela anterior es el uso "Tela". El uso "Talla" identifica la talla del artículo de stock (PRD-009) y el uso "Género" identifica qué plantilla aplica cuando el corte cambia por género (PRD-020). La carga inicial asigna los usos a Tela, Talla y Género, y se pueden editar en el catálogo con `products.catalog`.
- Después de la carga inicial, los colores y los colores ofrecidos en cada tela se gestionan desde el ERP con `products.catalog`: crear un color, editar su nombre o tono, desactivarlo, y agregarlo o quitarlo de la lista de una tela. Quitar un color de una tela solo afecta a las selecciones nuevas; las cotizaciones y pedidos existentes lo conservan. Cada cambio queda auditado.
- La carga inicial crea los atributos Tela (atributo de tela), Modelo, Manga, Género y Talla con los valores de la lista actual, y el atributo Color, de presentación "color", con la paleta validada por Ecolekua y los colores ofrecidos en cada tela (PRD-018).

### PRD-003 — Registrar producto

Un usuario con `products.create` registra un producto con **nombre**, **categoría**, **línea de negocio** y **modo de abastecimiento**. La descripción y la visibilidad en el portal son opcionales. El producto se crea **activo** y la acción queda auditada. El nombre de un producto es único en todo el catálogo, sin distinguir mayúsculas (DEC-PRD-45).

### PRD-004 — Atributos del producto

El producto declara qué atributos usa, con qué rol y qué valores admite (DEC-PRD-03):

- **Eje**: define la combinación comercial y su código. Ejemplo: en la camisa corporativa, tela, modelo, manga y género; en los pañales de adulto, talla.
- **De pedido**: se elige al cotizar o pedir y no cambia el código. Ejemplo: talla y color en la camisa corporativa.
- Un atributo aparece una sola vez en el producto y con un solo rol.
- Si el producto declara el atributo de tela, lo declara como eje. El atributo de color, cuando se declara, es siempre de pedido. El backend rechaza otro rol (DEC-PRD-50).
- El producto define el **orden de sus ejes** (p. ej., Tela → Modelo → Manga → Género). Es el orden de los pasos del cotizador (PRD-019).
- Cada atributo declarado admite al menos un valor activo, salvo el color de un producto que declara el atributo de tela, que no guarda valores admitidos (DEC-PRD-35).
- Todo atributo declarado es obligatorio al seleccionar el producto (PRD-011).
- Los colores de la prenda son una lista cerrada (DEC-PRD-17):
  - si el producto declara el atributo de tela, los colores disponibles son los que se ofrecen en la tela elegida y el producto no tiene lista propia: declara el color sin valores admitidos (DEC-PRD-35);
  - si el producto lleva color pero no declara el atributo de tela (p. ej., gorras), el producto tiene su propia lista de colores admitidos, que el equipo marca;
  - un producto sin color (p. ej., sublimación completa, pañales) no declara el atributo de color.
- **Color personalizado (DEC-PRD-34).** Un producto de modo "bajo pedido" que lleva color admite además la opción **Personalizado**, al final de la lista de colores, salvo que el equipo la desactive en ese producto. Los productos de otros modos no la admiten.
  - Es para el cliente que no encuentra un color de su gusto entre los ofrecidos. Indica el tono que busca con un selector de colores (obligatorio) y una nota opcional con el nombre del color como referencia (longitud máxima en `design.md`).
  - No es un color de la paleta ni pertenece a ninguna tela. Aplica solo al color de la prenda; los colores de los detalles siguen saliendo de la paleta (PRD-007).
  - Una selección con color personalizado requiere atención de una asesora: el pedido no se procesa desde la web y se habilita el botón de WhatsApp (§10).
- Un producto puede no tener atributos (p. ej., lanyard corporativo: el cliente solo elige la cantidad).
- Mientras existan combinaciones del producto, no se puede agregar un eje ni cambiar el rol de un atributo (de pedido a eje o de eje a pedido). Para reestructurar, se crea un producto nuevo y se desactiva el anterior (DEC-PRD-41).
- No se puede retirar un atributo con rol de eje mientras existan combinaciones del producto, ni retirar un valor admitido que use alguna combinación (como valor de eje o en la restricción de un atributo de pedido, DEC-PRD-37) o componente de combo. El backend rechaza el cambio e indica qué lo impide.

### PRD-005 — Combinaciones comerciales

Un usuario con `products.create` crea las combinaciones de un producto **de forma explícita**, una por una, como en la lista actual (DEC-PRD-04). El ERP permite duplicar una combinación para agilizar la carga.

- Cada combinación tiene un **código** que es **texto** (conserva ceros a la izquierda y sufijos: `088-1`, `001RN`) y es **único** en todo el catálogo, incluidos los códigos de combos (DEC-PRD-01, DEC-PRD-02).
- Tiene uno o varios valores por cada eje del producto, elegidos entre los valores admitidos. Varios valores en un eje significan opciones bajo el mismo código y precio: `159-1` admite Tela Drill o Gabardina, y el cliente elige una (DEC-PRD-33).
- Opcionalmente, restringe los valores de uno o varios atributos de pedido a un subconjunto de los admitidos por el producto. Por ejemplo, una combinación de dama admite solo las tallas XS a XL. Si un atributo de pedido no tiene restricción, la combinación admite todos los valores del producto. La restricción funciona igual que en los componentes de combo (PRD-010) y no aplica al color de un producto con tela, que sale de la tela elegida (DEC-PRD-35, DEC-PRD-36).
- Dos combinaciones activas del mismo producto no pueden coincidir en ninguna selección posible: si comparten al menos un valor en todos sus ejes, el backend rechaza el guardado.
- Un producto sin ejes tiene una sola combinación.
- Un producto activo necesita al menos una combinación activa para mostrarse en el portal y aceptarse en cotizaciones.

### PRD-007 — Ubicaciones de detalle

Un producto puede declarar las ubicaciones de detalle que admite (pechera, orilla de pechera, orilla de mangas, pie de cuello). En cada ubicación, el cliente elige un color entre los valores activos del atributo de presentación "color" al cotizar o pedir.

- El nombre de una ubicación de detalle es único, sin distinguir mayúsculas (DEC-PRD-45).
- Los detalles son opcionales al seleccionar el producto.
- Los detalles no afectan al precio y son una especificación para producción (DEC-PRD-07).
- Los botones son transparentes y no se eligen (business rules §26).

### PRD-008 — Personalizaciones

Personalizaciones añadidas a la prenda e incluidas en ciertas combinaciones (DEC-PRD-08).

- Un producto puede declarar qué productos de modo **servicio** admite como personalización añadida a la prenda (bordado pequeño o grande, vinil, sublimación sencilla).
- Una combinación puede declarar personalizaciones **incluidas** en su precio. Por ejemplo, `119` "…/BORD/SUBLSENC", `158-4` "(1 BORDADO)" o `139-1` "C/VINIL". Una personalización incluida no se cobra de nuevo.
- Las personalizaciones incluidas y las admitidas son independientes (DEC-PRD-47): "incluida" describe lo que cubre el precio de la combinación y "admitida" es lo que se puede agregar como extra. Por ejemplo, `139-1` (mono escolar "C/VINIL") incluye vinil sin que el producto ofrezca vinil adicional.
- La cantidad, el precio y la relación con el pedido los definen `004` y `006`.
- Un servicio activo y visible en el portal se vende también por sí solo (p. ej., sublimación sobre una prenda del cliente) (DEC-PRD-28).

### PRD-009 — Modo de abastecimiento y stock mínimo

Cada producto tiene uno de estos modos (DEC-PRD-09):

| Modo | Significado | Ejemplos (Anexo A) |
|---|---|---|
| Bajo pedido | Se confecciona o produce para cada pedido. Sin stock | Camisas, chemises, kimonos, lanyards |
| Stock con mínimo | Se vende desde stock y se repone. Tiene stock mínimo | Pañales de adulto, pantalones industriales, tazas, mouse pads, gorras |
| Stock agotable | Se vende solo desde stock y no se repone. Sin stock mínimo | Pañal ecológico infantil |
| Servicio | No es una prenda ni tiene stock propio | Bordados, vinil, sublimación, planchado |

- El **stock mínimo** solo existe en el modo "stock con mínimo". Es un entero ≥ 0 y se aplica **por artículo de stock**, es decir, por combinación más la talla cuando el producto la tiene como atributo de pedido (DEC-PRD-10).
- **Mínimo por defecto y excepciones (DEC-PRD-46).** En el modo "stock con mínimo", el producto tiene un **mínimo por defecto obligatorio**. Al registrarlo, el formulario propone 6. Cada artículo de stock hereda ese valor y puede tener un **valor propio opcional** que lo reemplaza.
  - Al cambiar el producto a otro modo, se borran el mínimo por defecto y los valores propios, con auditoría de los valores anteriores.
  - Al volver al modo "stock con mínimo", se pide de nuevo el mínimo por defecto. Los valores propios anteriores no se recuperan.
- Valor inicial: **6** para productos terminados y **2 por talla** para pantalones industriales (business rules §27).
- El backend rechaza un stock mínimo en productos de otros modos.
- Las existencias y los movimientos son de `008`. La forma de aplicar el mínimo en el portal y en los pedidos es de `005` / `006` (DEC-PRD-11, §10).

### PRD-010 — Combos

Un usuario con `products.create` registra combos de la línea de pañales (DEC-PRD-12), por ejemplo "Kit Oro antiderrame": 2 pañales, 3 absorbentes y 1 protector de cama.

- Cada componente indica un **producto**, la **cantidad** (entero ≥ 1) y, opcionalmente, qué valores admite en cada atributo del producto, sea eje o de pedido (DEC-PRD-44). En un atributo de pedido, el valor elegido debe respetar tanto la restricción del componente como la de la combinación resuelta, si la tiene (DEC-PRD-36). El usuario los marca al registrar el combo. Por ejemplo, al crear "Kit juvenil", el usuario marca solo la talla 2XG para el absorbente. Si en un eje no se marca ninguna restricción, se admiten todos sus valores activos. Si un eje queda con un solo valor admitido, el cliente no elige nada en ese eje y el sistema lo aplica directamente.
- Al cotizar o pedir, el cliente elige los valores de cada componente dentro de lo admitido. La elección vale para todas las unidades del componente (DEC-PRD-12).
- Solo se admiten componentes de productos que no sean de modo "servicio".
- Los combos son siempre de la línea de pañales: solo admiten componentes de productos de esa línea y el backend rechaza cualquier otro. El combo no guarda la línea; es implícita (DEC-PRD-43).
- El combo tiene código propio, asignado por Ecolekua (DEC-PRD-14). Su nombre es único, sin distinguir mayúsculas (DEC-PRD-45).
- Un combo con algún componente que no se pueda resolver a una combinación activa (producto inactivo o todos sus valores admitidos inactivos) no se ofrece.
- El precio de cada ítem dentro del combo y el total son de `004` (§10).

### PRD-011 — Resolver una selección

El backend ofrece una única operación que valida una selección y devuelve la combinación vendible. La usan el ERP (`004`, `006`) y el portal (`005`), y ningún frontend la reimplementa (AGENTS §7.1).

**Entrada**: producto o combo, valores de los ejes, valores de los atributos de pedido, colores de detalle y personalizaciones.

**Salida**: código de la combinación, la selección normalizada, con el nombre y el tono de referencia de cada color (o el tono y la nota del color personalizado), y un indicador **requiere asesora** cuando el color es personalizado. Si la selección no es válida, devuelve errores por campo.

Reglas:

1. El producto (o el combo) está activo, y también su categoría.
2. Cada eje y cada atributo de pedido del producto tiene un valor, que está admitido y activo. El color, además, es uno de los ofrecidos en la tela elegida o, si el producto no declara el atributo de tela, uno de los admitidos por el producto. Si el color es **Personalizado**, el producto debe admitirlo y el tono debe ser un código de color válido.
3. Existe **exactamente una** combinación activa cuyos valores de eje incluyen los elegidos. La selección normalizada guarda el valor elegido en cada eje, aunque la combinación admita varios. Si esa combinación restringe un atributo de pedido, el valor elegido está dentro de la restricción (DEC-PRD-36).
4. Cada ubicación de detalle elegida está admitida y su color es un valor activo del atributo de presentación "color".
5. En un combo, cada componente se resuelve con estas mismas reglas y sus valores respetan el subconjunto admitido.

Esta operación no consulta precios ni existencias.

### PRD-012 — Editar producto, combinación y combo

Un usuario con `products.update` modifica productos, combinaciones, combos, detalles, personalizaciones y parámetros de stock con las mismas validaciones del alta. Cada cambio queda auditado con los valores anteriores y nuevos.

El código y los valores de eje de una combinación con historial (cotizaciones, pedidos o movimientos de stock) no se modifican: se crea una combinación nueva y se desactiva la anterior (DEC-PRD-21). En `003` todavía no existe historial; cada spec que lo cree añade esta condición (§10).

Ediciones que afectan a otros registros (DEC-PRD-52):

- Cambiar la línea de negocio de un producto que es componente de un combo, o pasarlo a modo "servicio", se rechaza e indica los combos que lo usan.
- Sacar del modo "servicio" un producto que otros productos admiten o que otras combinaciones incluyen como personalización se rechaza e indica cuáles lo usan. Lo mismo aplica a su eliminación (PRD-014).
- Quitar una talla de los valores admitidos de un producto elimina los mínimos propios de esa talla (PRD-009), con auditoría de los valores anteriores. El bloqueo por existencias lo añade `008` (§10).

### PRD-013 — Desactivar y reactivar

Un usuario con `products.deactivate` puede desactivar y reactivar productos, combinaciones y combos.

- Desactivar requiere confirmación con `ConfirmDialog` (design system §7.10). Reactivar no la requiere.
- Lo inactivo no se ofrece en el portal ni se acepta en cotizaciones o pedidos nuevos. Lo que ya esté en curso sigue su flujo (aplican `004` y `006`).
- Desactivar un producto deja fuera de la oferta todas sus combinaciones sin cambiar el estado propio de cada una. Al reactivarlo, cada combinación vuelve a su estado anterior.
- Reactivar una combinación que se superpone con otra combinación activa del mismo producto (PRD-005) se rechaza con un error que indica la combinación con la que coincide (DEC-PRD-40).
- Cambiar al estado que ya se tiene no produce cambios ni auditoría, igual que en `001` y `002`.

### PRD-014 — Eliminación restringida

Un usuario con `products.delete` puede eliminar un producto, una combinación o un combo **solo si no tienen historial**. El uso previsto es corregir registros creados por error o datos de prueba; lo que tiene historial se desactiva (AGENTS §7.9).

- Eliminar un producto elimina sus atributos, detalles, personalizaciones y combinaciones en la misma transacción, siempre que ninguna combinación tenga historial ni forme parte de un combo.
- No se puede eliminar un producto ni una combinación que forme parte de un combo. Una combinación forma parte de un combo cuando su producto es componente del combo y la combinación comparte al menos un valor en cada eje con los valores que admite el componente, es decir, cuando el cliente podría elegirla en ese combo (DEC-PRD-42).
- La eliminación queda auditada con una copia de los valores eliminados.
- En `003` todavía no existen registros de historial. `004`, `006` y `008` añaden su condición de bloqueo y su escenario (§10), con claves foráneas de restricción de borrado.

### PRD-015 — Listado, búsqueda y ficha

Un usuario con `products.view` puede:

- listar productos con las vistas **Activos**, **Inactivos** y **Todos** (`SegmentedTabs`), con conteos calculados por el backend;
- buscar por nombre de producto y por **código** de combinación; buscar un código lleva al producto que lo contiene;
- filtrar por categoría, línea de negocio y modo de abastecimiento;
- consultar la ficha del producto, con sus datos, atributos, combinaciones, detalles, personalizaciones y parámetros de stock;
- listar y consultar combos;
- ver el listado paginado en el backend.

### PRD-016 — Autorización en backend

Toda ruta y acción del catálogo valida el permiso correspondiente con Policies en el backend (AGENTS §7.2).

### PRD-017 — Auditoría

Se auditan el alta (incluida la importación), la edición, la desactivación, la reactivación y la eliminación de categorías, atributos, valores, productos, combinaciones y combos, con usuario, acción, registro afectado, fecha y hora, IP y valores antes y después (AGENTS §7.8).

### PRD-018 — Carga inicial del catálogo

El equipo técnico carga una única vez el catálogo existente mediante un comando de consola, sin pantalla en el ERP (DEC-PRD-24), siguiendo el patrón de CLI-018.

- **Origen**: un CSV en UTF-8 preparado a partir de las hojas "Uniformes" y "Pañales para adultos" y validado por Ecolekua. Cada fila es una combinación e indica producto, categoría, línea, modo, stock mínimo, código, valores de eje, valores admitidos de los atributos de pedido y la descripción original de la lista. Cuando los valores de pedido de una fila son un subconjunto de los del producto, se cargan como restricción de esa combinación (DEC-PRD-36). El stock mínimo de cada fila es el mínimo por defecto del producto (DEC-PRD-46): si las filas de un mismo producto traen valores distintos, la carga se rechaza con fila, campo y motivo. Los valores propios por artículo se cargan después desde el ERP (DEC-PRD-48). Los combos van en un segundo archivo con sus componentes. Un tercer archivo trae la paleta de colores (nombre y tono) y los colores ofrecidos en cada tela, preparado a partir del archivo de limpieza de telas y colores validado por Ecolekua. Las columnas se definen en `design.md`.
- **Normalización** (Anexo B): se unifican los nombres de telas y valores, y se aplican las correcciones confirmadas por Ecolekua. Los códigos se cargan tal como están.
- Cada registro pasa por las mismas validaciones del alta (PRD-002 a PRD-010).
- **Todo o nada**: el comando valida primero todas las filas, incluidos los códigos repetidos dentro del archivo. Si alguna falla, no importa nada y entrega un informe con fila, campo y motivo. Si todas son válidas, importa en una sola transacción.
- El comando exige indicar un usuario interno activo como autor. Cada registro queda auditado como alta marcada como importación.
- No se importan precios ni costos. `004` los carga usando el código como referencia (§10).
- Antes de ejecutarla, Ecolekua valida los datos: clasificación del Anexo A, normalizaciones del Anexo B, archivo de telas y colores (paleta con tonos y colores por tela) y códigos de Los Cedros. Son datos, no decisiones de diseño: no bloquean Design ni la implementación.
- La carga no puede ejecutarse mientras haya códigos duplicados sin resolver (DEC-PRD-02), colores sin tono o telas del archivo de colores que no existan en el catálogo.

### PRD-019 — Opciones disponibles para una selección parcial

El backend ofrece una operación de consulta, sin efectos, que el cotizador del ERP (`004`) y el del portal (`005`) usan para mostrar en cada paso solo las opciones que existen (DEC-PRD-31). Ningún frontend calcula estas opciones por su cuenta (AGENTS §7.1).

- **Entrada**: producto (o combo y componente) y los valores de eje ya elegidos, en el orden de ejes del producto.
- **Mientras falten ejes**: devuelve los valores activos del siguiente eje que llevan al menos a una combinación activa compatible con lo ya elegido. Una combinación con varios valores en un eje (DEC-PRD-33) aporta todos ellos. En un componente de combo, solo se devuelven los valores admitidos por el componente, tanto en los ejes como en las opciones de pedido (DEC-PRD-44).
- **Con todos los ejes elegidos**: devuelve el código de la combinación y las opciones de pedido:
  - colores: los ofrecidos en la tela elegida o, si el producto no declara tela, los admitidos por el producto, o por la combinación si los restringe; más la opción **Personalizado** si el producto la admite (PRD-004);
  - valores admitidos de los demás atributos de pedido (p. ej., tallas), por el producto o por la combinación si los restringe (DEC-PRD-36);
  - ubicaciones de detalle admitidas, con los colores de la paleta;
  - personalizaciones admitidas.
- Cada opción incluye lo necesario para mostrarla: nombre, orden, descripción, imagen, tono de referencia y capa SVG (PRD-020).
- Usa los mismos datos y criterios que PRD-011, que sigue siendo la validación final. No consulta precios ni existencias.

### PRD-020 — Recursos visuales

Un usuario con `products.catalog` gestiona las imágenes y capas de los valores y de las ubicaciones de detalle. Un usuario con `products.update` gestiona la imagen y las plantillas del producto.

**Imágenes (DEC-PRD-20)**

- El producto tiene una imagen principal opcional.
- Cada valor de atributo puede tener una imagen opcional que se muestra en su opción: la textura de una tela, el ícono de una manga o el de un género.
- Cada ubicación de detalle puede tener una imagen opcional con un dibujo que marca la zona.
- Un valor de presentación "color" se muestra como muestra de color con su tono de referencia y su nombre (PRD-002). Si además tiene imagen, se muestra la imagen.
- Un valor sin imagen se muestra como texto.
- Formatos admitidos: JPG, PNG y WebP. El tamaño máximo y las versiones optimizadas que genera el backend se definen en `design.md`.
- Reemplazar o quitar una imagen queda auditado.

**Plantillas visuales (DEC-PRD-29)**

- Un producto puede tener una plantilla SVG general o, si su corte cambia por género, una plantilla por cada valor de Género que admite. Cuando hay plantillas por género, la vista previa usa la del género elegido.
- Un valor de atributo y una ubicación de detalle pueden tener asignada una capa SVG (p. ej., Manga corta → `manga-corta`; Orilla de mangas → `orilla-mangas`).
- Al subir una plantilla, el backend:
  1. la **sanea**: elimina scripts, atributos de evento (`on…`), `foreignObject` y referencias a archivos o sitios externos (DT-03);
  2. detecta los grupos con `id` y los guarda como capas;
  3. exige la capa `cuerpo`;
  4. exige la capa de cada valor admitido por el producto que tenga capa asignada y la de cada ubicación de detalle admitida. Si falta alguna, rechaza la plantilla e indica cuáles faltan.
- Se rechaza un archivo que no sea un SVG válido, que supere el tamaño máximo definido en `design.md` o que esté asociado a un género que el producto no admite.
- Si se añade al producto un valor o una ubicación cuya capa no existe en sus plantillas, el backend rechaza el cambio hasta que se suba una plantilla que la incluya.
- Asignar o cambiar desde el catálogo la capa de un valor o de una ubicación que admiten productos con plantilla se rechaza si alguna de esas plantillas no tiene la capa, e indica los productos afectados (DEC-PRD-53).

**Vista previa** (la dibuja `005`; el ERP la muestra al subir una plantilla)

- El tono de referencia del color elegido, o el tono indicado en un color personalizado, pinta la capa `cuerpo`. Mientras el cliente no elige color, la plantilla se muestra con sus colores originales.
- En un atributo cuyos valores tienen capa, se muestra la capa del valor elegido y se ocultan las de los demás valores (p. ej., Manga corta muestra `manga-corta` y oculta `manga-larga`).
- Cada ubicación de detalle con color elegido pinta su capa con el tono de ese color. Sin color elegido, la capa toma el color del cuerpo.
- La capa `sombras`, si existe, se dibuja encima de todas y no se pinta.
- La tela no se representa en la vista previa; se muestra en la miniatura de su opción.
- Si el producto no tiene plantilla, se muestran su imagen principal y las miniaturas de las opciones.

---

## 7. Escenarios de aceptación

Los escenarios marcados ⏳ se completan cuando se confirme la decisión indicada. Las pruebas de autorización golpean el backend por HTTP. Los datos de ejemplo son ficticios salvo los códigos, que se usan como referencia del formato.

### E-01 — Registrar un producto con datos válidos (PRD-003, PRD-017)

- **GIVEN** un usuario con `products.create` y una categoría activa
- **WHEN** envía nombre, categoría, línea de negocio y modo de abastecimiento válidos
- **THEN** el producto se crea en estado activo
- **AND** se registra una entrada de auditoría con los valores creados

### E-02 — Rechazar un producto sin datos obligatorios (PRD-003)

- **GIVEN** un usuario con `products.create`
- **WHEN** envía el formulario sin modo de abastecimiento
- **THEN** el backend rechaza la petición con un error de validación en ese campo
- **AND** no se crea ningún producto

### E-03 — Registrar sin permiso (PRD-016)

- **GIVEN** un usuario autenticado sin `products.create`
- **WHEN** envía una petición de alta de producto
- **THEN** el backend responde 403 y no se crea ningún producto

### E-04 — Consultar sin permiso (PRD-016)

- **GIVEN** un usuario autenticado sin `products.view`
- **WHEN** solicita el listado de productos
- **THEN** el backend responde 403

### E-05 — Declarar atributos con su rol (PRD-004)

- **GIVEN** el producto "Camisa corporativa"
- **WHEN** declara Tela, Modelo, Manga y Género como ejes, y Talla y Color como atributos de pedido; Tela, Modelo, Manga, Género y Talla con sus valores admitidos y Color sin valores admitidos
- **THEN** el producto guarda los seis atributos con su rol, y los valores admitidos de todos salvo Color, cuyos colores salen de la tela elegida (DEC-PRD-35)

### E-06 — Atributo repetido en el producto (PRD-004)

- **GIVEN** un producto con Talla como atributo de pedido
- **WHEN** se intenta declarar Talla también como eje
- **THEN** el backend rechaza el cambio con un error de validación

### E-07 — Crear una combinación válida (PRD-005)

- **GIVEN** el producto "Camisa corporativa" con sus ejes declarados
- **WHEN** se crea la combinación `110` con ALG-OXF PIMA, Columbia especial, Manga corta y Caballero
- **THEN** la combinación se crea activa con código `110`
- **AND** nombre descriptivo de la combinación es "Camisa corporativa · ALG-OXF Pima · Columbia especial · Manga corta · Caballero"

### E-08 — Código repetido (PRD-005)

- **GIVEN** una combinación existente con código `110`
- **WHEN** se intenta crear otra combinación, o un combo, con código `110`
- **THEN** el backend rechaza el guardado con un error en el código
- **AND** la base de datos garantiza la unicidad aunque falte la validación

### E-09 — Valores de eje repetidos (PRD-005)

- **GIVEN** la combinación `110`
- **WHEN** se crea otra combinación del mismo producto con los mismos valores en todos los ejes y otro código
- **THEN** el backend rechaza el guardado

### E-10 — Combinación incompleta o con valor no admitido (PRD-005)

- **GIVEN** un producto con cuatro ejes
- **WHEN** se crea una combinación sin valor de Género, o con una tela no admitida por el producto
- **THEN** el backend rechaza el guardado con un error en el campo correspondiente

### E-11 — Código con ceros a la izquierda (PRD-005)

- **GIVEN** un usuario con `products.create`
- **WHEN** crea las combinaciones `088-1` y `001RN`
- **THEN** los códigos se guardan y se muestran exactamente así, sin perder ceros ni sufijos

### E-14 — Resolver una selección válida (PRD-011)

- **GIVEN** la combinación activa `110-1` (ALG-OXF PIMA, Columbia especial, Manga corta, Dama) con Talla M y Color Blanco admitidos
- **WHEN** se resuelve esa selección de ejes con Talla M y Color Blanco
- **THEN** la operación devuelve el código `110-1` y la selección normalizada

### E-15 — Resolver un pantalón por talla (PRD-011)

- **GIVEN** la combinación `184` con las tallas admitidas 28 a 44
- **WHEN** se resuelve con Talla 38
- **THEN** la operación devuelve el código `184` y la selección normalizada con Talla 38
- **AND** si se resuelve con Talla 46, la operación devuelve un error en la talla

### E-16 — Resolver una selección inválida (PRD-011)

- **GIVEN** la combinación `110-1`
- **WHEN** se resuelve sin Talla, o con un color no admitido
- **THEN** la operación devuelve un error en ese campo y ninguna combinación

### E-17 — No resolver lo inactivo (PRD-011, PRD-013)

- **GIVEN** la combinación `110-1` inactiva, o su producto inactivo
- **WHEN** se resuelve una selección que coincide con ella
- **THEN** la operación devuelve un error indicando que la selección no está disponible

### E-19 — Color de detalle inválido (PRD-007, PRD-011)

- **GIVEN** un producto que admite la ubicación Pechera
- **WHEN** se resuelve con Pechera y un color inactivo, o con una ubicación que el producto no admite
- **THEN** la operación devuelve un error de validación

### E-20 — Stock mínimo según el modo (PRD-009)

- **GIVEN** un producto de modo "bajo pedido" o "stock agotable"
- **WHEN** se intenta guardar un stock mínimo
- **THEN** el backend rechaza el valor
- **AND** en un producto de modo "stock con mínimo" el valor 6 se acepta

### E-21 — Crear un combo (PRD-010)

- **GIVEN** los productos activos Pañal antiderrame (talla como eje: 3XG, 4XG, 5XG), Absorbente y Protector de cama
- **WHEN** se crea "Kit Oro antiderrame" con 2 pañales (tallas 3XG–5XG), 3 absorbentes y 1 protector
- **THEN** el combo se crea activo con sus tres componentes

### E-22 — Componente de combo inválido (PRD-010)

- **GIVEN** un usuario con `products.create`
- **WHEN** crea un combo con un componente de cantidad 0, de modo "servicio" o con un valor no admitido por el producto
- **THEN** el backend rechaza el combo

### E-23 — Resolver un combo (PRD-011, PRD-010)

- **GIVEN** el combo de E-21
- **WHEN** se resuelve con talla 4XG para el pañal
- **THEN** cada componente se resuelve a su combinación (`11` para el pañal)
- **AND** si se elige 2XG para el pañal, la operación devuelve un error en ese componente

### E-24 — Desactivar un valor de eje en uso (PRD-002, PRD-004)

- **GIVEN** el valor Microfibra, usado por la combinación `110-4`
- **WHEN** se desactiva
- **THEN** la combinación `110-4` deja de resolverse con PRD-011
- **AND** al reactivarlo vuelve a resolverse
- **AND** retirar Microfibra de los valores admitidos del producto se rechaza mientras `110-4` lo use

### E-25 — Desactivar y reactivar un producto (PRD-013, PRD-017)

- **GIVEN** un producto activo con una combinación activa y otra inactiva
- **WHEN** se desactiva y después se reactiva
- **THEN** mientras está inactivo, ninguna de sus combinaciones se resuelve
- **AND** al reactivarlo, cada combinación conserva su estado propio
- **AND** ambas acciones quedan auditadas

### E-26 — Desactivar sin permiso (PRD-013, PRD-016)

- **GIVEN** un usuario sin `products.deactivate`
- **WHEN** intenta desactivar un producto
- **THEN** el backend responde 403 y el producto no cambia

### E-27 — Eliminar sin historial (PRD-014, PRD-017)

- **GIVEN** un usuario con `products.delete` y un producto sin historial que no forma parte de ningún combo
- **WHEN** lo elimina
- **THEN** se eliminan el producto, sus atributos, detalles y combinaciones en una transacción
- **AND** la auditoría guarda una copia de los valores eliminados

### E-28 — Eliminación bloqueada por un combo (PRD-014)

- **GIVEN** un producto que forma parte de un combo
- **WHEN** se intenta eliminar
- **THEN** el backend lo rechaza, indica el combo y sugiere desactivar

### E-29 — Eliminación bloqueada por historial ⏳ (PRD-014)

- **GIVEN** una combinación con historial
- **WHEN** se intenta eliminar
- **THEN** el backend la rechaza y sugiere desactivarla
- *No se puede probar en `003`; se prueba en `004`, `006` y `008` (§10).*

### E-30 — Buscar por código (PRD-015)

- **GIVEN** un usuario con `products.view`
- **WHEN** busca `147-12`
- **THEN** el listado muestra el producto que contiene esa combinación

### E-31 — Importar un archivo válido (PRD-018, PRD-017)

- **GIVEN** un CSV válido y un usuario interno activo como autor
- **WHEN** se ejecuta el comando
- **THEN** se crean todas las categorías, atributos, productos, combinaciones, combos, colores y colores por tela en una transacción
- **AND** cada alta queda auditada como importación

### E-32 — Importación con errores (PRD-018)

- **GIVEN** un CSV con una fila inválida, con un código repetido, con un color sin tono o con una tela que no existe en el catálogo
- **WHEN** se ejecuta el comando
- **THEN** no se importa nada
- **AND** el informe indica fila, campo y motivo

### E-33 — Coherencia de permisos (§4, DEC-PRD-23)

- **GIVEN** un usuario que gestiona roles
- **WHEN** asigna `products.update` a un rol sin `products.view`
- **THEN** la asignación se rechaza y el rol no cambia

### E-34 — Personalización no admitida (PRD-008, PRD-011)

- **GIVEN** un producto que no admite vinil
- **WHEN** se resuelve una selección con vinil
- **THEN** la operación devuelve un error en la personalización

### E-35 — Imágenes del producto y de las opciones (PRD-020, PRD-017)

- **GIVEN** un usuario con `products.update` y `products.catalog`
- **WHEN** sube una imagen JPG para la camisa corporativa y otra para el valor de tela ALG-OXF PIMA
- **THEN** cada imagen queda asociada a su producto o valor
- **AND** un archivo PDF o que supere el tamaño máximo se rechaza
- **AND** los cambios quedan auditados

### E-36 — Color sin tono (PRD-002)

- **GIVEN** el atributo Color, de presentación "color"
- **WHEN** se crea el valor "Azul marino" sin elegir el tono, o con un tono inválido
- **THEN** el backend rechaza el valor
- **AND** con el tono elegido en el selector, el valor se crea y se guarda su código hexadecimal

### E-37 — Subir una plantilla válida (PRD-020)

- **GIVEN** la camisa corporativa, que admite Manga corta (capa `manga-corta`), Manga larga (capa `manga-larga`) y la ubicación Orilla de mangas (capa `orilla-mangas`)
- **WHEN** se sube un SVG con las capas `cuerpo`, `manga-corta`, `manga-larga`, `orilla-mangas` y `sombras`
- **THEN** la plantilla se guarda con esas cinco capas detectadas

### E-38 — Plantilla incompleta (PRD-020)

- **GIVEN** el producto de E-37
- **WHEN** se sube un SVG sin la capa `cuerpo`, o sin la capa `manga-larga`
- **THEN** el backend rechaza la plantilla e indica las capas que faltan

### E-39 — Plantilla con código peligroso (PRD-020)

- **GIVEN** un SVG válido que además contiene un `<script>` y un atributo `onclick`
- **WHEN** se sube como plantilla
- **THEN** se guarda sin el script ni el atributo de evento
- **AND** las capas se detectan sobre el archivo saneado

### E-40 — Valor nuevo sin capa en la plantilla (PRD-020, PRD-004)

- **GIVEN** la camisa corporativa con la plantilla de E-37
- **WHEN** se añade a sus valores admitidos un valor de Manga con la capa `manga-tres-cuartos`, que no existe en la plantilla
- **THEN** el backend rechaza el cambio e indica la capa que falta

### E-41 — Plantillas por género (PRD-020)

- **GIVEN** la camisa corporativa con una plantilla para Caballero y otra para Dama
- **WHEN** se consulta la plantilla de la combinación `110-1` (Dama)
- **THEN** se obtiene la plantilla de Dama
- **AND** subir una plantilla asociada a un género que el producto no admite se rechaza

### E-42 — Recursos visuales sin permiso (PRD-020, PRD-016)

- **GIVEN** un usuario sin `products.update`
- **WHEN** intenta subir una imagen o una plantilla de producto
- **THEN** el backend responde 403

### E-43 — Colores según la tela (PRD-004, PRD-011)

- **GIVEN** la tela ALG-OXF PIMA, que se ofrece en azul marino, blanco y verde, y la combinación activa `110` con esa tela
- **WHEN** se resuelve `110` con Blanco
- **THEN** la operación devuelve el código `110`
- **AND** si se resuelve con Rojo, la operación devuelve un error en el color

### E-44 — Colores de un producto sin tela (PRD-004, PRD-011)

- **GIVEN** la gorra en dryfit, que lleva color pero no declara el atributo de tela, con los colores admitidos Negro y Blanco
- **WHEN** se resuelve con Negro
- **THEN** la selección es válida
- **AND** con Azul marino la operación devuelve un error en el color

### E-45 — Un solo atributo de tela (PRD-002)

- **GIVEN** el atributo Tela marcado como atributo de tela
- **WHEN** se intenta marcar otro atributo como atributo de tela
- **THEN** el backend rechaza el cambio

### E-46 — Gestionar los colores de una tela (PRD-002, PRD-017)

- **GIVEN** la tela ALG-OXF PIMA, que se ofrece en azul marino, blanco y verde
- **WHEN** un usuario con `products.catalog` crea el color Gris perla con su tono, lo agrega a la tela y quita el verde
- **THEN** la combinación `110` se resuelve con Gris perla y devuelve un error con Verde
- **AND** los cambios quedan auditados

### E-47 — Combinación con telas alternativas (PRD-005, PRD-011)

- **GIVEN** la combinación `159-1` del kimono completo con Tela Drill o Gabardina y Género Caballero
- **WHEN** se resuelve con Gabardina
- **THEN** la operación devuelve el código `159-1` y la selección normalizada con Tela Gabardina
- **AND** los colores disponibles son los que se ofrecen en Gabardina

### E-48 — Combinaciones superpuestas (PRD-005)

- **GIVEN** la combinación `159-1` con Tela Drill o Gabardina y Género Caballero
- **WHEN** se crea otra combinación del mismo producto con Tela Drill y Género Caballero
- **THEN** el backend rechaza el guardado porque ambas coinciden en Drill y Caballero

### E-49 — Color personalizado (PRD-004, PRD-011)

- **GIVEN** la camisa corporativa, de modo "bajo pedido", que admite color personalizado
- **WHEN** se resuelve la combinación `110` con color Personalizado, tono #7A9A3B y la nota "verde oliva corporativo"
- **THEN** la operación devuelve el código `110`, la selección con ese tono y esa nota, y el indicador "requiere asesora"
- **AND** sin tono, o con un tono inválido, devuelve un error en el color

### E-50 — Color personalizado no admitido (PRD-004, PRD-011)

- **GIVEN** la gorra en dryfit, de modo "stock con mínimo", y un producto bajo pedido con la opción desactivada
- **WHEN** se resuelve cualquiera de los dos con color Personalizado
- **THEN** la operación devuelve un error en el color
- **AND** un color personalizado en una ubicación de detalle también se rechaza

### E-51 — Opciones del siguiente eje (PRD-019)

- **GIVEN** la camisa corporativa con el orden de ejes Tela → Modelo → Manga → Género, donde la tela Algodón Egipto solo existe en la combinación `147-12` (Columbia especial, manga larga, dama)
- **WHEN** se consultan las opciones con Tela = Algodón Egipto
- **THEN** el siguiente eje, Modelo, ofrece solo Columbia especial
- **AND** con Modelo = Columbia especial, el eje Manga ofrece solo Manga larga

### E-52 — Opciones de pedido con los ejes completos (PRD-019)

- **GIVEN** la combinación activa `110` (ALG-OXF PIMA), cuya tela se ofrece en azul marino, blanco y verde, en un producto que admite color personalizado
- **WHEN** se consultan las opciones con todos los ejes de `110`
- **THEN** la operación devuelve el código `110`, los colores Azul marino, Blanco y Verde con su tono, la opción Personalizado, las tallas admitidas y las ubicaciones de detalle admitidas

### E-53 — Lo inactivo no se ofrece (PRD-019)

- **GIVEN** la camisa corporativa con la combinación `110-4` (Microfibra) inactiva y ninguna otra combinación activa con Microfibra
- **WHEN** se consultan las opciones del eje Tela
- **THEN** Microfibra no aparece entre las opciones

### E-54 — Combinación que restringe las tallas (PRD-005, PRD-011, PRD-019)

- **GIVEN** un producto que admite las tallas XS a 3XL y una combinación de dama que restringe la Talla a XS a XL
- **WHEN** se consultan las opciones de pedido con los ejes de esa combinación completos
- **THEN** la operación devuelve solo las tallas XS a XL
- **AND** resolver esa combinación con Talla 2XL se rechaza con un error en la talla

### E-55 — Combinación sin restricción (PRD-005, PRD-019)

- **GIVEN** un producto que admite las tallas XS a 3XL y una combinación sin restricción de Talla
- **WHEN** se consultan las opciones de pedido con los ejes de esa combinación completos
- **THEN** la operación devuelve las tallas XS a 3XL

### E-56 — Restricción fuera de lo admitido por el producto (PRD-005)

- **GIVEN** un producto que admite las tallas S a XL
- **WHEN** se guarda una combinación que restringe la Talla a M y 2XL
- **THEN** el backend rechaza el guardado con un error en la talla y la combinación no cambia

### E-57 — Retirar del producto un valor usado en una restricción (PRD-004, DEC-PRD-37)

- **GIVEN** un producto que admite las tallas XS a 3XL y una combinación que restringe la Talla a XS a XL
- **WHEN** se retira XS de las tallas admitidas del producto
- **THEN** el backend rechaza el cambio, indica la combinación que lo impide y el producto no cambia

### E-58 — Un solo atributo de color (PRD-002, DEC-PRD-38)

- **GIVEN** el atributo Color, de presentación "color"
- **WHEN** se intenta crear otro atributo de presentación "color" o cambiar a "color" la presentación de otro atributo
- **THEN** el backend rechaza el cambio

### E-59 — Reactivar una combinación superpuesta (PRD-005, PRD-013, DEC-PRD-40)

- **GIVEN** la combinación inactiva `110-4` (Microfibra, Dama) y una combinación activa del mismo producto con los mismos valores de eje
- **WHEN** se reactiva `110-4`
- **THEN** el backend rechaza la reactivación, indica la combinación con la que coincide y `110-4` sigue inactiva

### E-60 — Cambiar la estructura de un producto con combinaciones (PRD-004, DEC-PRD-41)

- **GIVEN** la camisa corporativa con combinaciones y Talla como atributo de pedido
- **WHEN** se intenta agregar un eje nuevo, pasar Talla a eje o pasar Manga a atributo de pedido
- **THEN** el backend rechaza cada cambio, indica que el producto tiene combinaciones y el producto no cambia

### E-61 — Eliminar una combinación según el componente del combo (PRD-014, DEC-PRD-42)

- **GIVEN** un combo cuyo componente "absorbente" admite solo la talla 2XG, y dos combinaciones del absorbente sin historial: una con talla 2XG y otra con talla 3XG
- **WHEN** se intenta eliminar cada una
- **THEN** el backend rechaza eliminar la de talla 2XG e indica el combo
- **AND** elimina la de talla 3XG

### E-62 — Componente de otra línea (PRD-010, DEC-PRD-43)

- **GIVEN** un combo de pañales
- **WHEN** se intenta agregar como componente un producto de la línea de uniformes
- **THEN** el backend rechaza el componente con un error en el producto y el combo no cambia

### E-63 — Componente que restringe un atributo de pedido (PRD-010, PRD-011, DEC-PRD-44)

- **GIVEN** un combo cuyo componente "protector de cama" restringe el Color a Blanco
- **WHEN** se consultan las opciones de pedido de ese componente y se resuelve con Color Azul
- **THEN** las opciones devuelven solo Blanco
- **AND** la resolución con Azul se rechaza con un error en el color del componente

### E-64 — Nombre repetido (PRD-001, PRD-003, PRD-007, PRD-010, DEC-PRD-45)

- **GIVEN** la categoría "Camisas", el producto "Camisa corporativa", la ubicación de detalle "Pechera" y el combo "Kit Oro antiderrame"
- **WHEN** se intenta crear otra entidad del mismo tipo con el mismo nombre en otras mayúsculas (p. ej., "camisas")
- **THEN** el backend rechaza cada alta con un error en el nombre

### E-65 — Mínimo por defecto obligatorio y valor propio (PRD-009, DEC-PRD-46)

- **GIVEN** un producto de modo "stock con mínimo" con Talla como atributo de pedido
- **WHEN** se guarda sin mínimo por defecto
- **THEN** el backend rechaza el guardado con un error en el mínimo
- **AND** con mínimo por defecto 2, la talla 38 de una combinación con valor propio 4 tiene mínimo 4 y las demás tallas tienen mínimo 2

### E-66 — Cambio de modo borra los mínimos (PRD-009, PRD-017, DEC-PRD-46)

- **GIVEN** un producto de modo "stock con mínimo" con mínimo por defecto 6 y un valor propio en un artículo
- **WHEN** se cambia a modo "bajo pedido"
- **THEN** se borran el mínimo por defecto y el valor propio, y la auditoría registra los valores anteriores
- **AND** al volver a "stock con mínimo" se exige un mínimo por defecto nuevo y no hay valores propios

### E-67 — Personalización incluida no admitida como extra (PRD-008, DEC-PRD-47)

- **GIVEN** el mono escolar, que no admite vinil como personalización adicional
- **WHEN** se guarda la combinación `139-1` con vinil como personalización incluida
- **THEN** el backend acepta la combinación
- **AND** al resolver `139-1`, vinil no se ofrece como personalización adicional

### E-68 — Editar producto y combinación (PRD-012, PRD-017)

- **GIVEN** el producto "Camisa corporativa" y su combinación `110-1`, sin historial
- **WHEN** un usuario con `products.update` cambia el nombre del producto y la descripción de `110-1`
- **THEN** se guardan los cambios y la auditoría registra, en cada uno, los valores anteriores y nuevos
- **AND** una edición que no cumple las validaciones del alta (p. ej., un nombre de producto ya existente) se rechaza con un error en el campo y nada cambia

### E-69 — Atributo en uso (PRD-002, DEC-PRD-51)

- **GIVEN** el atributo Tela, declarado por la camisa corporativa, que está activa
- **WHEN** se intenta desactivarlo, quitarle el uso especial "Tela" o cambiar su presentación
- **THEN** el backend rechaza cada cambio, indica los productos que lo declaran y el atributo no cambia
- **AND** un atributo inactivo no se puede declarar en un producto

### E-70 — Edición que rompería un combo o una personalización (PRD-012, PRD-014, DEC-PRD-52)

- **GIVEN** un pañal que es componente de un combo y el servicio "Bordado pequeño", admitido por la camisa corporativa
- **WHEN** se intenta pasar el pañal a la línea de uniformes, sacar "Bordado pequeño" del modo "servicio" o eliminarlo
- **THEN** el backend rechaza cada cambio, indica el combo o los productos que los usan y nada cambia

### E-71 — Quitar una talla con mínimo propio (PRD-009, PRD-012, DEC-PRD-52)

- **GIVEN** un producto de modo "stock con mínimo" con un mínimo propio 4 en la talla 38 de una combinación
- **WHEN** se quita la talla 38 de los valores admitidos del producto
- **THEN** se guarda el cambio, se elimina el mínimo propio de la talla 38 y la auditoría registra el valor anterior

### E-72 — Cambiar la capa de un valor en uso (PRD-020, DEC-PRD-53)

- **GIVEN** el valor "Manga 3/4" con la capa `manga-3-4`, admitido por un producto cuya plantilla tiene esa capa pero no `manga-tres-cuartos`
- **WHEN** se cambia en el catálogo la capa de "Manga 3/4" a `manga-tres-cuartos`
- **THEN** el backend rechaza el cambio, indica el producto afectado y el valor no cambia

---

## 8. Interfaz (ERP)

Aplica `docs/ui/design-system.md` completo. Puntos específicos de esta spec:

| Pantalla | Componentes y reglas |
|---|---|
| Listado de productos | `DataTable`, `SegmentedTabs` para las vistas por estado, búsqueda con `AppInput` (nombre o código), filtros con `AppSelect` (categoría, línea, modo), `StatusBadge` (activo → `done`, inactivo → `neutral`) |
| Ficha del producto | Secciones en `AppCard`: datos generales, atributos (rol, valores y orden de los ejes), combinaciones (tabla con código, valores de eje y estado), detalles, personalizaciones, stock e imágenes. En "Imágenes": imagen principal y plantillas (general o por género); al subir una plantilla se muestran la vista previa y las capas detectadas. Indicador "Admite color personalizado" en los productos bajo pedido con color |
| Editor de combinación | Una selección múltiple por eje, limitada a los valores admitidos; código como texto; acción "Duplicar" |
| Catálogo de atributos | Listado de atributos y valores ordenables, con estado y presentación. Por valor: tono de referencia elegido con un selector de colores (con muestra), imagen y capa SVG. En el atributo de tela, cada valor muestra y permite editar sus colores ofrecidos. Ubicaciones de detalle con imagen y capa. Visible con `products.catalog` |
| Categorías | Listado ordenable con estado |
| Combos | Listado y editor de componentes: producto, cantidad y valores admitidos por eje |
| Desactivar / Eliminar | `ConfirmDialog` variante `danger`. Si el backend rechaza la eliminación, se muestra el motivo y se ofrece desactivar |

- La gestión del catálogo se hará sobre todo en escritorio, pero las pantallas siguen siendo mobile-first (constitución §14) y deben poder consultarse en el teléfono.
- Objetivos táctiles de 44 px o más, sin clases `dark:` ni hexadecimales en el código de estilos. Los tonos de referencia de los colores son datos del catálogo y se aplican como estilo en línea en las muestras; no contradicen esta regla. Textos visibles en español; código, rutas e identificadores en inglés.

---

## 9. Decisiones de esta Spec

Estados: **Confirmada** (respondida por Ecolekua o el usuario), **Propuesta** (recomendación técnica pendiente de visto bueno) y **Pendiente** (falta información).

| ID | Decisión | Opciones | Estado | Recomendación técnica |
|---|---|---|---|---|
| DEC-PRD-01 | Nivel del código de la lista | A) Código = combinación comercial (valores de eje); talla, color y detalles por debajo · B) Código por SKU | **Confirmada**: **A** | — Así está construida la lista |
| DEC-PRD-02 | Unicidad del código | A) Único en todo el catálogo · B) Se admiten duplicados | **Confirmada** (2026-10-06): **A**; se conservan los códigos de la lista. Duplicados resueltos en la lista de precios: chemises de sublimación completa con códigos `170-4` y `170-5`; `153` = chemise terry dama y `153-1` = caballero; `158-6` y `158-8` aparecen una sola vez. `184` y `184-2` se repiten a propósito por la excepción de precio por talla (DEC-PRD-06) y se cargan como una combinación cada uno | — La carga inicial no se ejecuta con duplicados |
| DEC-PRD-03 | Atributos configurables | A) Lista fija en el código · B) Catálogo editable de atributos y valores, asignados a cada producto con rol eje o de pedido, sin reglas | **Confirmada** (2026-10-06): **B** | — Cumple constitución §11 sin motor de reglas |
| DEC-PRD-04 | Creación de combinaciones | A) Explícita, con opción de duplicar · B) Producto cartesiano y desactivar las que no existen | **Confirmada**: **A** | — La lista es dispersa (p. ej., Algodón Egipto solo en dama) |
| DEC-PRD-05 | Códigos "Dama - Caballero" (`89`, `089-1` a `089-3`, `117` a `117-4`, `160`, `172-1`) | A) Valor de género "Unisex": no se pregunta el género · B) El género se elige en el pedido para producción | **Confirmada**: **A** | — |
| DEC-PRD-06 | Precio por talla | A) Grupos de talla en la combinación (`003`) · B) Excepción de precio por rango de talla en `004` | **Confirmada** (2026-10-05): **B**. Solo los pantalones `184` y `184-2` varían su precio por talla (28–36 / 38–44). El cliente solo ve la lista normal de tallas | — |
| DEC-PRD-07 | Efecto de los detalles en el precio | A) No afectan; son especificación de producción · B) Tienen precio | **Confirmada**: **A** | — |
| DEC-PRD-08 | Personalizaciones | A) Servicios añadidos a la línea de la prenda, más personalizaciones incluidas en ciertas combinaciones · B) Líneas independientes del pedido | **Confirmada**: **A**. Los servicios que se venden solos quedan en DEC-PRD-28 | — |
| DEC-PRD-09 | Modos de abastecimiento | Bajo pedido · stock con mínimo · stock agotable · servicio | **Confirmada** (2026-10-06). La clasificación de cada producto (Anexo A) es un dato de la carga inicial que Ecolekua valida antes de PRD-018 | — |
| DEC-PRD-10 | Nivel del stock mínimo | A) Combinación + talla + color · B) Combinación + talla · C) Por combinación | **Confirmada**: **B**. Cantidades: 6 en productos terminados (incluye tazas, mouse pads y gorras), 2 por talla en pantalones industriales, sin mínimo en el pañal infantil | — |
| DEC-PRD-11 | Regla del mínimo en el portal y los pedidos | A) Protege el mínimo · B) Solo deriva si pedido > disponible · C) Tope por pedido | **Confirmada** (2026-10-05): **B**. Si el stock queda bajo el mínimo, se notifica a ventas, administración o gerencia para reponer. Si el pedido supera el disponible, se deriva a WhatsApp y la asesora gestiona la entrega y la fabricación | — |
| DEC-PRD-12 | Estructura de los combos | Componente = producto + valores admitidos + cantidad; una elección por componente | **Confirmada** (2026-10-06): combos y packs de la línea de pañales, con precio por ítem y total = suma (`004`); estructura de componentes según PRD-010 | — |
| DEC-PRD-13 | "Opción 1/2/3" y packs  | — | **Confirmada** (2026-10-05): las "Opciones 1/2/3" son referencia para calcular precios de combos y no se registran. Los packs de 2, 3 y 6 de protector de cama y absorbentes 3XG/4XG se registran como combos de un solo componente, con precio propio por ítem (004) | — |
| DEC-PRD-14 | Código de los combos | A) Ecolekua asigna un código a cada combo · B) Sin código | **Confirmada**: **A** | — En el mismo espacio de códigos que las combinaciones |
| DEC-PRD-15 | Productos de Los Cedros | Qué códigos son; si son públicos o exclusivos del colegio | **Confirmada**: son públicos. Sus códigos (o los datos para crearlos) son datos de la carga inicial | — |
| DEC-PRD-16 | Pijama infantil (`125`, `125-1`) | Si lleva una tela por defecto | **Confirmada**: usa microdurazno. El producto no declara el atributo Tela; la tela figura en su descripción | — |
| DEC-PRD-17 | Colores | A) Lista cerrada · B) Color libre | **Confirmada** (2026-10-06): **A**. Los colores dependen de la tela: cada tela tiene los colores que Ecolekua ofrece, aunque haya que comprar la tela. Los productos con color y sin tela tienen su propia lista. Cada color tiene nombre y tono de referencia elegido con un selector; el cliente elige entre las muestras. La sublimación completa no declara color | — Que haya tela en stock lo verifica `009` al confirmar el pedido |
| DEC-PRD-18 | Categorías por producto | A) Una · B) Varias | **Confirmada**: **A** | — |
| DEC-PRD-19 | Visibilidad en el portal | A) Indicador por producto y combo · B) Sin indicador: todo lo activo se muestra | **Confirmada**: **A** | — Visible por defecto, incluidos los servicios |
| DEC-PRD-20 | Imágenes | Imagen principal por producto; imagen opcional por valor de atributo y por ubicación de detalle | **Confirmada** (2026-10-06) | — El catálogo del portal la necesita (informe §3.1) |
| DEC-PRD-21 | Cambios con historial | A) Código y ejes inmutables con historial: nueva combinación + desactivar · B) Editables | **Confirmada**: **A** | — Protege el significado de cotizaciones y pedidos pasados |
| DEC-PRD-22 | Permisos iniciales por rol | Matriz de §4 | **Confirmada** | — |
| DEC-PRD-23 | Coherencia de permisos `products.*` | Escritura y catálogo exigen `products.view` | **Confirmada** | — Igual que DEC-022 y DEC-CLI-32 |
| DEC-PRD-24 | Carga inicial | A) Comando único con CSV, todo o nada · B) Pantalla de importación | **Confirmada**: **A** | — Mismo patrón que CLI-018 |
| DEC-PRD-25 | Precios y costos | A) Fuera de `003`; los carga `004` por código · B) En `003` | **Confirmada**: **A** | — `003` se prueba sin precios |
| DEC-PRD-26 | Línea de negocio | En cada producto (uniformes \| pañales) | **Confirmada** (2026-10-05): el tramo se calcula con el total de piezas de cada línea (business rules §2.1) | — |
| DEC-PRD-27 | Fuente de datos | Hojas "Uniformes" y "Pañales para adultos" | **Confirmada** (2026-10-04) | — La hoja anterior y sus productos exclusivos quedan fuera |
| DEC-PRD-28 | Servicios que se venden solos | Qué servicios se venden también sin prenda | **Confirmada** (2026-10-06): es configuración. Un servicio activo y visible en el portal se vende solo; además se ofrece como personalización en los productos que lo admiten (PRD-008) | — |
| DEC-PRD-29 | Vista previa de la prenda | A) Foto por combinación · B) Plantilla SVG por capas pintada en el navegador, una por género si el corte cambia | **Confirmada** (2026-10-06): **B**. Ecolekua tiene diseñadores para dibujar las plantillas | — Un dibujo por silueta cubre todas las combinaciones |
| DEC-PRD-30 | Presentación de los atributos | Texto · imagen · color, configurable por atributo | **Confirmada** (2026-10-06) | — El sistema identifica el color de la prenda por la presentación, no por el nombre "Color" |
| DEC-PRD-32 | Colores por tela | A) Lista de colores en cada valor del atributo de tela · B) Lista por producto · C) Restricción por combinación | **Confirmada** (2026-10-06): **A**, según el inventario de telas de Ecolekua | — Relación fija entre dos atributos, no un motor de reglas (constitución §11) |
| DEC-PRD-33 | Telas alternativas bajo un mismo código | A) Varios valores por eje en una combinación · B) Un valor combinado "Drill/Gabardina" | **Confirmada** (2026-10-06): en la lista de precios, "DRILL/GABARDINA" significa que el cliente elige una de las dos con el mismo código y precio. Lo mismo en `170-2` (Atlética o Microdurazno) y `170-4` (Manchester o Atlética). Mecanismo: **A** | — Producción sabe qué tela se eligió y los colores salen de esa tela |
| DEC-PRD-34 | Color personalizado | Opción "Personalizado" cuando el cliente no encuentra un color de su gusto | **Confirmada** (2026-10-06): solo para el color de la prenda (no para los detalles), en productos bajo pedido. El cliente indica el tono con un selector y una nota con el nombre de referencia. Se deriva a la asesora por WhatsApp. La tela se compra para ese pedido y no entra al inventario salvo que el equipo lo decida | — |
| DEC-PRD-31 | Opciones disponibles en cada paso del cotizador | A) PRD-019: dada una selección parcial, el backend devuelve los valores válidos del siguiente eje, en el orden de ejes del producto · B) Cada frontend lo calcula | **Confirmada** (2026-10-06): **A** | — El backend es la autoridad (AGENTS §7.1) |
| DEC-PRD-35 | Colores admitidos de un producto con tela | A) No guarda lista: declara el color sin valores y los colores salen solo de la tela elegida · B) Guarda una lista que filtra los colores de la tela | **Confirmada** (2026-10-07): **A**. Se corrige E-05 y la regla de PRD-004 sobre valores admitidos | — Coincide con PRD-011 regla 2 y PRD-019 |
| DEC-PRD-36 | Valores admitidos de los atributos de pedido | A) Por producto · B) Por combinación · C) Por producto, con restricción opcional por combinación | **Confirmada** (2026-10-07): **C**, para cualquier atributo de pedido, incluido el color de un producto sin tela. Sin restricción, la combinación admite todos los valores del producto. Mismo mecanismo que los componentes de combo (PRD-010) | — No es un concepto nuevo |
| DEC-PRD-37 | Retirar del producto un valor de pedido usado en una restricción | A) Se rechaza mientras alguna restricción lo use · B) Se quita también de las restricciones | **Confirmada** (2026-10-07): **A** | — Misma regla que los valores de eje (E-24) |
| DEC-PRD-38 | Cantidad de atributos de presentación "color" | A) Uno en todo el catálogo (la paleta) · B) Varios, con vínculo explícito desde telas y detalles | **Confirmada** (2026-10-07): **A** | — Igual que el atributo de tela; coincide con DEC-PRD-17 |
| DEC-PRD-39 | Garantía de no superposición de combinaciones | A) Backend en transacción con bloqueo del producto, más índice único para duplicados exactos · B) Triggers de MySQL | **Confirmada** (2026-10-07): **A**. Se ajusta la Definition of Done (§12) | — Con varios valores por eje (DEC-PRD-33) la superposición no se expresa con un índice único; evita lógica de negocio en la base de datos (constitución §11) |
| DEC-PRD-40 | Reactivar una combinación superpuesta | A) Se rechaza indicando la combinación activa con la que coincide · B) Se reactiva y se desactiva la otra | **Confirmada** (2026-10-07): **A** | — Misma regla que el guardado, sin efectos colaterales |
| DEC-PRD-41 | Cambiar la estructura de un producto con combinaciones | A) Agregar un eje o cambiar el rol de un atributo se rechaza; se crea un producto nuevo · B) Asistente que asigna el nuevo eje a cada combinación | **Confirmada** (2026-10-07): **A** | — Coherente con DEC-PRD-21; sin caso real que pida B (constitución §11) |
| DEC-PRD-42 | Cuándo una combinación forma parte de un combo | A) Su producto es componente y comparte al menos un valor en cada eje con lo admitido por el componente · B) Basta con que su producto sea componente | **Confirmada** (2026-10-07): **A** | — Reutiliza la compatibilidad de PRD-019 |
| DEC-PRD-43 | Línea de negocio de los combos | A) Siempre pañales, implícita; solo componentes de esa línea · B) Línea propia del combo · C) Sin restricción | **Confirmada** (2026-10-07): **A** | — Coincide con DEC-PRD-12; el tramo por línea no queda ambiguo |
| DEC-PRD-44 | Restricción de atributos de pedido en un componente de combo | A) El componente restringe cualquier atributo, eje o de pedido · B) Solo ejes | **Confirmada** (2026-10-07): **A**. El valor de pedido respeta la restricción del componente y la de la combinación | — Mismo mecanismo que DEC-PRD-36 |
| DEC-PRD-45 | Unicidad de nombres | A) Únicos sin distinguir mayúsculas en categorías, productos (todo el catálogo), ubicaciones de detalle y combos · B) Productos con nombre repetible · C) Ninguno único | **Confirmada** (2026-10-07): **A** | — Evita duplicados y búsquedas ambiguas; la carga inicial rechaza nombres de producto repetidos |
| DEC-PRD-46 | Carga del stock mínimo y cambio de modo | A) Obligatorio por artículo · B) Opcional, 0 por defecto · C) Se conserva al salir del modo · D) Mínimo por defecto obligatorio en el producto, heredado por cada artículo con valor propio opcional; al salir del modo se borran ambos | **Confirmada** (2026-10-07): **D**. El formulario propone 6; los pantalones industriales reciben 2 en la carga inicial (Anexo A) | — B dejaría 0 sin aviso de reposición (business rules §27); A obliga a cargar cada combinación + talla |
| DEC-PRD-47 | Relación entre personalizaciones incluidas y admitidas | A) La incluida debe estar entre las admitidas · B) Independientes | **Confirmada** (2026-10-07): **B**. "Incluida" es lo que cubre el precio de la combinación; "admitida" es lo que se agrega como extra (p. ej., `139-1` incluye vinil sin ofrecer vinil adicional) | — |
| DEC-PRD-48 | Stock mínimo en el CSV de la carga inicial | A) El valor de la fila es el mínimo por defecto del producto; filas del mismo producto con valores distintos se rechazan · B) El archivo admite valores propios por artículo | **Confirmada** (2026-10-07): **A**. Los valores propios por artículo se cargan después desde el ERP | — Una fila por combinación |
| DEC-PRD-49 | Cómo se identifican Talla y Género | A) Marca de uso especial en el atributo (Tela \| Talla \| Género), como máximo uno de cada uso · B) Por nombre · C) Elección por producto | **Confirmada** (2026-10-07): **A**. Generaliza la marca de tela (DEC-PRD-32) | — Explícito y garantizado en la base de datos |
| DEC-PRD-50 | Rol de la tela y del color | A) Tela siempre eje; color siempre de pedido · B) Cualquier rol | **Confirmada** (2026-10-07): **A**. Ningún código de la lista cambia por color (DEC-PRD-01), y las telas alternativas bajo un código ya son un eje con varios valores (DEC-PRD-33) | — |
| DEC-PRD-51 | Desactivar o cambiar un atributo en uso | A) Inactivo cuenta como valores inactivos; cambios de presentación o uso rechazados en uso · B) Solo impide nuevas declaraciones | **Confirmada** (2026-10-07): **A con ajuste**. Desactivar se rechaza mientras un producto activo lo declare (desactivar Tela sacaría de oferta casi todo el catálogo). Cambiar presentación o uso especial se rechaza en uso. Un producto que declara un atributo inactivo no se puede seleccionar | — Coherente con E-24 |
| DEC-PRD-52 | Ediciones que invalidan otros registros | A) Rechazar e indicar quién lo usa · B) Aplicar y borrar referencias | **Confirmada** (2026-10-07): **A** para cambios de línea o modo de un componente de combo y para servicios admitidos o incluidos (misma regla que PRD-014 y DEC-PRD-37). **B** al quitar una talla: se eliminan sus mínimos propios con auditoría; el bloqueo por existencias lo añade `008` | — |
| DEC-PRD-53 | Cambiar la capa SVG de un valor o ubicación en uso | A) Se rechaza si una plantilla de los productos que lo admiten no tiene la capa · B) Se permite y la vista previa no la muestra | **Confirmada** (2026-10-07): **A** | — Misma regla que E-40 |
| DEC-PRD-54 | Listas de ubicaciones de detalle y personalizaciones al editar un producto | A) Lista omitida conserva lo guardado; lista vacía o nula lo borra · B) La lista siempre es obligatoria | **Confirmada** (2026-10-08): **A** | — Mismo criterio que la descripción (PRD-003) |
| DEC-PRD-55 | "Activa al agregarse" en ubicaciones y personalizaciones admitidas | A) Solo se exige estado activo a lo que se agrega; lo ya admitido se conserva y puede reenviarse aunque se haya desactivado después · B) Todo lo enviado debe estar activo | **Confirmada** (2026-10-08): **A** | — |
| DEC-PRD-56 | Estado del servicio en las personalizaciones incluidas | A) Al agregarla, el servicio debe estar en modo servicio y activo; las ya incluidas en la combinación se conservan aunque el servicio se desactive después (igual que DEC-PRD-55) · B) Basta con el modo servicio | **Confirmada** (2026-10-08): **A** | — |
| DEC-PRD-57 | Mensaje del rechazo por servicio en uso (E-70) | A) Nombra los productos y cada código de combinación con su producto, p. ej. "110-4 (Camisa corporativa)" · B) Solo los códigos | **Confirmada** (2026-10-08): **A** | — |
| DEC-PRD-58 | Mínimos propios fuera del modo stock con mínimo | A) Una lista con elementos se rechaza en `overrides`; una lista vacía se acepta sin efecto · B) Cualquier lista se rechaza | **Confirmada** (2026-10-08): **A** | — |
| DEC-PRD-59 | Estado de la combinación con mínimo propio | A) Se admiten mínimos propios en combinaciones activas e inactivas · B) Solo en combinaciones activas | **Confirmada** (2026-10-08): **A** | — |
| DEC-PRD-60 | Talla del mínimo propio cuando el producto no declara la talla como atributo de pedido | A) El mínimo propio va sin talla; si se envía una talla, se rechaza · B) La talla se ignora | **Confirmada** (2026-10-08): **A** | — |
| DEC-PRD-61 | Rango del mínimo propio | A) De 0 a 9999, igual que el mínimo por defecto · B) Rango propio | **Confirmada** (2026-10-08): **A** | — |
| DEC-PRD-62 | Auditoría de los mínimos propios | A) Clave `stock_minimum_overrides` con filas {código, talla, mínimo}; el evento distingue la edición (`products.stock_minimums_updated`), el borrado por cambio de modo (`products.updated`) y el borrado por talla retirada (`products.attributes_updated`) · B) Un evento único | **Confirmada** (2026-10-08): **A** | — |

### Decisiones técnicas

| ID | Decisión | Estado |
|---|---|---|
| DT-01 | Los códigos se guardan como texto y se comparan sin distinguir mayúsculas, sin recortar ceros ni sufijos | Propuesta — se confirma en `design.md` |
| DT-02 | PRD-011 se implementa como una Action reutilizable (`app/Actions/Products/ResolveSelection`) sin dependencias de precio ni de stock | Propuesta — se confirma en `design.md` |
| DT-03 | El SVG se sanea en el backend con una librería mantenida, no con expresiones regulares propias. Las imágenes y plantillas se guardan con el sistema de archivos de Laravel | Propuesta — se confirma en `design.md`. Dependencia aprobada (2026-10-07): `enshrined/svg-sanitize` (GPL-2.0-or-later, versión fijada; uso interno sin distribución) |

---

## 10. Compromisos para specs posteriores

| Spec | Compromiso |
|---|---|
| `business-rules.md` | **Hecho** (2026-10-05): §2 (precios por línea, conteo por línea, configuración inicial de pañales, combos y packs), §3 (anticipo), §26 (catálogo), §27 (stock de producto terminado y regla de stock mínimo) y §28 (prendas base). Pendientes de Ecolekua: umbral VIP de pañales, columna de precio de los kits, si los combos cuentan para el tramo y anticipo en productos de stock y de precio fijo |
| `005` / `008` | La regla de stock del portal (DEC-PRD-11) necesita las existencias de `008`. `005` define cómo se comporta el portal con los productos de stock mientras `008` no exista |
| `004` | Cargar los precios finales (ganancia e IVA incluidos) por código y tramo desde la hoja "Uniformes", con excepción de precio por rango de talla en `184` y `184-2` (28–36 / 38–44) |
| `004` | Precio de cada ítem dentro de un combo (precio final con descuento) y total = suma. Los packs tienen precio fijo por ítem; definir qué columna de precio (P1 a P4) se aplica a cada kit |
| `004` | Esquemas de tramos configurables (cantidad de tramos y límites libres), asignados por producto o categoría; precio fijo para "Varios". El tramo se elige con el total de piezas de la línea de negocio y se aplica a cada producto en su propio esquema (business rules §2.1) |
| `004` / `006` / `008` | Añadir su condición de bloqueo a PRD-014 y la inmutabilidad de PRD-012 (DEC-PRD-21), con el escenario E-29 |
| `004` / `005` / `006` | Usar PRD-011 para validar toda selección. Ningún frontend resuelve combinaciones por su cuenta |
| `005` / `006` | Regla de stock mínimo con WhatsApp (DEC-PRD-11). Pañal infantil: si el pedido supera lo disponible, no se procesa y se habilita el botón de WhatsApp (decisión 2026-10-04). El cliente no ve existencias |
| `006` | Guardar en cada línea de pedido una copia del nombre descriptivo y de la selección normalizada, incluidos el nombre y el tono de referencia de cada color, para que cambios posteriores del catálogo no alteren pedidos pasados |
| `006` | Archivo de diseño o logo del cliente para sublimación y bordado. Desglose de IVA a partir del precio final |
| `008` | Artículos de stock por combinación + talla; existencias de producto terminado; stock de tela por color; consumo de materiales (tela, cinta de lanyard, hilo de bordado, papel de sublimación y vinil, pendiente de datos de Ecolekua) |
| `008` / `009` | Prendas base en stock: un pedido de 15 franelas de sublimación completa dryfit blancas con 5 franelas dryfit blancas en stock reserva tela solo para 10 |
| `008` / `009` | Stock inicial de tela por color a partir del inventario de materia prima, usando la correspondencia entre telas del inventario y telas comerciales del archivo de limpieza. La reserva de `009` usa esa correspondencia para saber qué tela del inventario reservar |
| `008` | Bloquear quitar una talla de un producto mientras haya existencias de esa talla (DEC-PRD-52) |
| `008` / `014` | Detectar cuando el stock de un producto queda por debajo del mínimo y notificar a ventas, administración o gerencia para reponer (DEC-PRD-11) |
| `010` | Reposición del stock por confección (pañales de adulto, productos de Los Cedros, pantalones industriales) |
| `005` / `006` | Una cotización con al menos una línea de color personalizado no se procesa desde la web: se habilita el botón de WhatsApp y la asesora acuerda tela, precio y entrega (business rules §26). La copia de la selección guarda el tono y la nota |
| `008` / `009` | La tela de un color personalizado se compra para ese pedido y queda asociada a él, sin pasar al stock general. El sobrante entra al inventario solo si el equipo lo decide |
| `005` | Miniaturas de las opciones en el cotizador y vista previa por capas según PRD-020; sin plantilla, imagen principal. Opciones de cada paso según DEC-PRD-31 |
| `004` | Opcional: usar el mismo componente de vista previa en las cotizaciones del ERP |
| Ecolekua | Plantillas SVG según la guía para diseñadores: capas con `id` fijo, zonas pintables en color plano, capa `sombras`, mismo lienzo. Colores de cada producto con su nombre y tono |

---

## 11. Trazabilidad con las fuentes

| Fuente | Aporta |
|---|---|
| Informe de propuesta §3.1 | Catálogo digital, fichas de productos y variantes, modelos, colores, personalizaciones, bordado y sublimación |
| Reunión con Ecolekua (2026-10-02) | Líneas de negocio, productos terminados, atributos y ubicaciones de detalle, botones transparentes, stock mínimo, combos, prendas base en stock, códigos |
| Aclaraciones del usuario (2026-10-04) | Fuente de datos, tramos, precio final con IVA, pañal infantil, combos, talla en pantalones, vinil |
| Inventario de telas ("colores de telas.xlsx") y archivo de limpieza | Telas, colores ofrecidos por tela y paleta |
| Aclaraciones del usuario (2026-10-05 y 2026-10-06) | Conteo de tramos por línea, packs, regla de stock mínimo, valores y categorías solo se desactivan, colores con tono de referencia, imágenes y vista previa por capas |
| Lista de precios ("Uniformes", "Pañales para adultos") | Códigos, combinaciones, ejes, kits |
| Business rules §2, §26, §27 y §28 | Precios por línea, catálogo y códigos, modos de abastecimiento y stock mínimo, prendas base en stock |
| Constitución §3.2 y §11 | Backend como autoridad; configurabilidad sin motor genérico de reglas |
| Constitución §10 | Auditoría |
| Business rules §5 | Requisitos de material según producto, variante y cantidad (`008`) |
| AGENTS §7 | Permisos por nombre, sin borrado físico con historial, transacciones |

---

## 12. Requisitos no funcionales y Definition of Done

- [ ] Cada escenario E-xx tiene al menos una prueba Pest cuyo nombre empieza por su ID, salvo E-29, que se prueba en `004`, `006` y `008`.
- [ ] Las pruebas se ejecutan sobre MySQL (base `testing` de Sail).
- [ ] Autorización con Policies; ninguna comprobación por nombre de rol.
- [ ] Altas, ediciones, eliminaciones e importación dentro de Actions (`app/Actions/Products/`), con transacción cuando afecten a varias entidades.
- [ ] Unicidad de códigos garantizada también en la base de datos. La no superposición de combinaciones activas (PRD-005) la garantiza el backend en una transacción con bloqueo del producto, y la base de datos impide al menos los duplicados exactos (DEC-PRD-39).
- [ ] Ninguna plantilla SVG se guarda sin sanear; existe una prueba con un SVG malicioso (E-39).
- [ ] Seeders con datos ficticios. El CSV de carga no se versiona si contiene datos que Ecolekua considere reservados.
- [ ] `sail artisan test`, `sail pint --test` y `sail pnpm build` en verde.
- [ ] Checklist del design system (§11) verificado a 375, 768 y 1280 px.
- [ ] Todas las decisiones de §9 en estado **Confirmada** antes de pasar a Design.
- [ ] Los anexos A y B, el archivo de telas y colores y los códigos de Los Cedros son datos para la carga inicial: se validan con Ecolekua antes de ejecutar PRD-018, no antes de Design.

---

## Anexo A — Clasificación inicial de productos (datos para la carga inicial)

Base para el CSV de carga. Las filas marcadas "Confirmar" requieren respuesta de Ecolekua (DEC-PRD-09).

| Sección de la lista | Códigos | Modo | Stock mínimo | Estado |
|---|---|---|---|---|
| Camisas Columbia y tradicional | `110…`, `147…`, `112…`, `148…` | Bajo pedido | — | Confirmar |
| Línea escolar | `88`, `088-1`, `130`, `131`, `131-2`, `139`, `139-1`, `172`, `172-11` | Bajo pedido, salvo los productos de Los Cedros: stock con mínimo | 6 | Los Cedros son públicos; falta identificar sus códigos (DEC-PRD-15) |
| Chemises | `155…`, `153`, `153-1`, `158`, `158-1` | Bajo pedido | — | Confirmar |
| Franela | `119…`, `172-1` | Bajo pedido | — | Confirmar |
| Sublimación completa | `169`, `170` a `170-5`, `131-1`, `188`, `158-8`, `158-6` | Bajo pedido | — | Confirmar |
| Monos | `89`, `089-1` a `089-3` | Bajo pedido | — | Confirmar (los monos de stock son los escolares de Los Cedros) |
| Kimonos, chaquetas, otros, pijamas, restaurantes | `117…`, `159…`, `158-3…`, `171`, `182…`, `161…`, `160`, `154`, `174…` | Bajo pedido | — | Confirmar |
| Lanyard / llaveros | `173`, `173-1` | Bajo pedido, sin atributos (solo cantidad) | — | Confirmada (2026-10-02) |
| Bordados | `157`, `157-1` | Servicio | — | Confirmada (2026-10-02) |
| Pantalones industriales | `184`, `184-1`, `184-2`, `184-3` | Stock con mínimo | 2 por talla | Confirmada (2026-10-02) |
| Pantalones táctico y de vestir | `186`, `186-1` a `186-3` | ¿Stock con mínimo? | ¿2 por talla? | Confirmar: están en la misma sección |
| Otros | `180` (vinil) | Servicio | — | Confirmada (2026-10-04) |
| Otros | `178` (tazas), `185` (mouse pad), `175`, `175-1`, `175-2` (gorras) | Stock con mínimo | 6 | Confirmada (2026-10-04) |
| Varios | `001RN`, `001P`, `001G`, `001XG`, `001XXG` | Stock agotable | — | Confirmada (2026-10-04) |
| Varios | `125`, `125-1` (pijama infantil) | Bajo pedido | — | Confirmada; tela microdurazno (DEC-PRD-16) |
| Varios | `173-2` (carnet) | Bajo pedido | — | Confirmar |
| Varios | `176`, `176-1` a `176-5`, `177`, `179`, `181` | Servicio | — | Confirmar |
| Pañales para adultos | `10`–`13`, `010-1`–`012-1`, `29`, `013-1`, `16`, `016-1` | Stock con mínimo | 6 | Confirmar protector, absorbentes y toalla clínica |
| Pañales para adultos | `163` (bolsas ecológicas, costo y precio 0) | ¿Obsequio o producto? | — | Confirmar |

## Anexo B — Normalización de datos para la carga (datos para la carga inicial)

| Tipo | Hallazgo | Propuesta |
|---|---|---|
| Codificación | `PAÐAL`, `PEQUEÐOS` | `Ñ` |
| Telas alternativas | `GABARDINA/DRILL`, `GABARDINA-DRILL`, `DRILL/GABARDINA` (camisas, kimonos `159…`, filipina `160`) | Dos valores de tela, Drill y Gabardina, en la misma combinación (DEC-PRD-33) |
| Telas alternativas | `TELA ATLETICA/MICRODURAZNO NORMAL` (`170-2`), `TELAS MANCHESTER-ATLETICA` (`170-4`) | `170-2`: Atlética o Microdurazno; `170-4`: Manchester o Atlética (confirmado 2026-10-06) |
| Valores equivalentes | `CAM-OXF` / `CAMI-OXF`; `ALG` / `ALGODÓN`; `MICROD` / `MICRODURAZNO`; `UNIC` / `UNI` / `UNICOLOR` | Confirmar si son la misma tela y unificar el valor |
| Erratas | `FRANLEA OVEJIA`, `IMPERMEBLE` / `IMPERMEBALE`, `CABLLERO`, `MICROUDURAZNO`, `PROFESONAL`, `MAUSE PAD` | Corregir en el nombre del producto o del valor; la descripción original se conserva |
| Atributo implícito | `155` y `155-1` no indican manga; `155-2` y `155-3` son manga larga | Confirmar que son manga corta |
| Descripción errónea | `119-3` dice CABALLERO | DAMA (confirmado 2026-10-04) |
| Formato de código | `88` frente a `088-1`; `89` frente a `089-1` | Se conservan tal cual (DT-01) |
| Filas repetidas a propósito | `184` y `184-2` aparecen dos veces (hasta la talla 36 / a partir de la 38) | Una combinación por código; los dos precios los carga `004` (DEC-PRD-06) |
| Tela nueva | `117-4` usa "NOVA STRECH" | Tela comercial "NOVA STRETCH"; en el inventario figura como "NOVAK QUATRO STRETCH" (confirmar) |
