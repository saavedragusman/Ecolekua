# Reglas de negocio de Ecolekua

> Actualizado: 2026-10-05 — conteo de piezas para el tramo por línea de negocio (§2.1); tramos de la línea de pañales (§2.3); combos y packs (§2.4); colores por tela y color personalizado (§26). 2026-10-04: traducción al español; precios por línea de producto (§2), anticipo (§3), consumos conocidos (§5), catálogo de productos (§26), stock de producto terminado (§27) y uso de prendas en stock (§28). Las secciones 1–25 conservan su numeración porque las specs las citan.

## 1. Canales de venta

Ecolekua admite al menos dos orígenes comerciales:

1. Web
2. Asesora / venta directa

El pedido debe conservar su origen de venta.

El origen debe estar disponible para los reportes y el cálculo de comisiones.

---

# 2. Precios

## 2.1 Principios generales

- Los precios se cargan como **precios finales**: ya incluyen la ganancia de Ecolekua y el IVA. El sistema no calcula precios a partir de costos ni de márgenes.
- Un precio pertenece a una combinación de producto identificada por su **código** de la lista de precios existente (ver §26).
- Cada producto usa un esquema de tramos o un precio fijo. La configuración inicial se describe en §2.2 a §2.5.
- Los límites de los tramos, los umbrales de negociación y los precios son configuración del negocio. No deben quedar fijos en el código (§25).
- El tramo se determina por el **total de piezas de la misma línea de negocio** en la cotización o el pedido: todas las piezas de uniformes cuentan juntas y todas las de pañales cuentan juntas, aunque sean productos distintos. En un pedido mixto, cada línea se cuenta por separado y los productos de precio fijo no cuentan. Ejemplo: 5 camisas + 5 chemises + 3 pantalones = 13 piezas de uniformes → tramo 13–50 para todas. Pedido mixto: 15 camisas + 10 pañales de adulto + 2 pañales infantiles → uniformes 15 piezas (tramo 13–50), pañales de adulto 10 unidades (detal), pañales infantiles a precio fijo.
- El total de la línea se aplica a cada producto dentro del esquema de tramos que tenga ese producto, aunque los productos de la línea usen esquemas distintos. Ejemplo: 4 pañales + 3 absorbentes 3XG/4XG = 7 unidades de la línea de pañales → los pañales se cotizan al precio de 7 unidades en su esquema (detal, 1–19) y los absorbentes al precio de 7 unidades en el suyo (Especial, desde 7).
- Pendiente de confirmar por Ecolekua: si las unidades de los combos cuentan para el tramo de su línea.

---

## 2.2 Uniformes y prendas

Configuración inicial:

| Tramo | Cantidad | Precio |
|---|---|---|
| 1 | 1–6 piezas | Precio al detal ("Precio 4" en la lista de precios actual) |
| 2 | 7–12 piezas | "Precio 3" |
| 3 | 13–50 piezas | "Precio 2" |
| VIP | Más de 50 piezas | Negociación directa |

El precio de cada tramo lo calcula automáticamente el sistema.

En el rango VIP:

- el sistema no debe cerrar automáticamente el precio comercial;
- el cliente debe ser derivado a una asesora de ventas a través del canal de WhatsApp configurado o de un mecanismo de contacto comercial equivalente;
- el proceso exacto de negociación es configuración del negocio.

La talla no cambia el precio, salvo en los pantalones industriales, que tienen un precio distinto hasta la talla 36 y a partir de la talla 38.

---

## 2.3 Línea de pañales: venta individual

Configuración inicial.

**Pañales de adulto**

| Cantidad | Precio |
|---|---|
| 1–19 unidades | Precio al detal ("Precio 4" en la lista de precios actual) |
| 20 o más unidades | Precio especial ("Especial" en la lista de precios actual) |

**Protector de cama 70 × 90 para adulto mayor y absorbentes 3XG/4XG**

| Cantidad | Precio |
|---|---|
| 1–6 unidades | Precio al detal ("Precio 4"). En estas cantidades se ofrecen los packs de 2, 3 y 6 (§2.4) |
| 7 o más unidades | Precio especial ("Especial") |

Los demás productos de la línea usan el esquema que Ecolekua les asigne en la configuración.

Ecolekua debe indicar si activa un umbral de negociación VIP por WhatsApp en esta línea, con el mismo comportamiento que en §2.2.

---

## 2.4 Combos y packs de la línea de pañales

Los productos de la línea de pañales también pueden venderse en combos con una composición fija de ítems y cantidades:

- **Kits** con varios productos (p. ej., Kit Oro antiderrame: pañales, absorbentes y protector de cama).
- **Packs** de 2, 3 y 6 unidades de protector de cama y de absorbentes 3XG/4XG, registrados como combos de un solo producto.

Reglas:

- Cada ítem de un combo tiene su propio precio final, que puede incluir un descuento respecto a su precio individual. Las columnas "Precio 1", "Precio 2" y "Precio 3" de la lista de precios corresponden a precios de ítems dentro de combos.
- El total del combo es la suma del precio de cada ítem multiplicado por su cantidad.
- Las "Opciones 1, 2 y 3" de la lista de precios son una referencia para calcular precios de combos y no se registran en el sistema.
- Qué columna de precio se aplica a cada kit está pendiente de definir por Ecolekua.

---

## 2.5 Productos de precio fijo

Los productos de la sección "Varios" de la lista de precios (pañales infantiles, servicios y otros artículos) tienen un único precio fijo con IVA incluido, sin tramos por cantidad.

---

# 3. Anticipo

Los pedidos cuyo precio calcula automáticamente el sistema (cualquier tramo de §2 distinto de la negociación VIP) requieren inicialmente:

```text
50 % de anticipo
```

El porcentaje de anticipo es configurable (§25). Ecolekua debe confirmar, antes de la especificación de pedidos, si el anticipo también se aplica a los productos de stock (pañales y otros productos terminados) y a los productos de precio fijo.

El anticipo debe representarse como una transacción financiera asociada al pedido.

El sistema debe distinguir:

- total del pedido;
- monto pagado;
- monto pendiente;
- anticipo requerido.

---

# 4. Confirmación del pedido

Un pedido no debe entrar en el flujo normal de producción hasta que se cumplan las condiciones comerciales requeridas.

Como mínimo, el sistema debe verificar:

- cliente válido;
- productos válidos;
- cantidades;
- precios;
- confirmación del pago;
- condición de anticipo/pago requerida;
- información de entrega requerida;
- condición de disponibilidad/reserva de materiales.

---

# 5. Requerimientos de materiales

Cuando se confirma un pedido, el sistema debe determinar los materiales requeridos.

Los requerimientos de materiales deben basarse en:

- producto;
- variante;
- cantidad;
- reglas de consumo de materiales aplicables.

Las especificaciones futuras definirán las fórmulas exactas de consumo.

Consumos conocidos, pendientes de cuantificar:

- las prendas consumen tela;
- los lanyards consumen cinta de sublimación;
- los bordados consumen solo hilo;
- los servicios de sublimación, impresión, planchado y vinil consumen papel. Ecolekua todavía está calculando el consumo de papel por servicio.

---

# 6. Reserva de materiales

Cuando hay material suficiente disponible:

```text
Material requerido ≤ Material disponible
```

el sistema debe reservar la cantidad requerida para el pedido.

Ejemplo:

```text
Requerido: 20 m
Disponible: 50 m

Reservado: 20 m
Disponible restante: 30 m
```

La reserva debe quedar asociada al pedido.

---

# 7. Faltante de materiales

Cuando el material requerido no puede reservarse por completo:

```text
Material requerido > Material disponible
```

el sistema debe:

1. identificar el material faltante;
2. registrar el faltante;
3. impedir que el pedido entre indebidamente en un estado de producción que requiera el material no disponible;
4. notificar al responsable de Finanzas según el mecanismo de notificación configurado;
5. incluir en la notificación el material y la cantidad faltante.

Ejemplo:

```text
Requerido: 50 m
Disponible: 30 m

Faltante: 20 m
```

El sistema no debe generar silenciosamente una disponibilidad negativa de material, salvo que una regla de negocio futura y explícita autorice ese comportamiento.

---

# 8. Estados de inventario

El sistema debe distinguir al menos:

```text
Stock físico
Stock reservado
Stock disponible
Stock consumido
Merma
```

Estos estados se aplican tanto a los materiales como a los productos terminados (§27).

Los valores de inventario no deben modificarse de forma arbitraria.

Los cambios deben representarse mediante movimientos de inventario.

---

# 9. Orden de producción

Un pedido comercial confirmado puede generar una o más órdenes de producción, según el diseño de producción que se defina más adelante.

Toda orden de producción debe mantener la trazabilidad con el pedido comercial.

---

# 10. Operaciones de producción

Las operaciones iniciales incluyen:

```text
Corte
Sublimación
Bordado
Costura
```

Cada operación puede estar:

- pendiente;
- asignada;
- en curso;
- en pausa;
- completada;
- rechazada / requiere reproceso.

---

# 11. Asignación de empleados

Un supervisor asigna las operaciones de producción a los empleados.

Un empleado solo puede recibir operaciones que esté autorizado a realizar.

El sistema debe conservar:

- empleado;
- operación;
- fecha de asignación;
- estado;
- orden de producción.

---

# 12. Tiempos de producción

Una operación asignada debe permitir:

```text
Iniciar
Pausar
Reanudar
Finalizar
```

El sistema debe calcular el tiempo productivo real a partir de los eventos registrados.

---

# 13. Motivos de pausa

Una pausa de producción requiere un motivo.

Ejemplos iniciales:

- avería de máquina;
- almuerzo;
- falta de material;
- espera;
- otro motivo autorizado.

La lista debe ser configurable.

---

# 14. Tiempo estándar vs. tiempo real

Cada operación de producción aplicable puede tener:

```text
Tiempo estándar
Tiempo real
```

El sistema debe conservar ambos valores.

Los reportes gerenciales podrán compararlos más adelante para determinar indicadores de productividad.

---

# 15. Control de calidad

La producción completada debe someterse a los controles de calidad aplicables.

El proceso de calidad debe registrar:

- cantidad inspeccionada;
- cantidad aprobada;
- cantidad rechazada;
- información del defecto;
- operación de producción responsable;
- empleado responsable, cuando aplique.

---

# 16. Reproceso parcial

El rechazo de calidad debe operar sobre la cantidad afectada siempre que sea posible.

Ejemplo:

```text
Producidas: 100
Defectuosas: 5
Aprobadas: 95
```

El sistema debe enviar a reproceso solo las 5 unidades defectuosas.

No debe devolver automáticamente las 100 unidades a producción.

---

# 17. Trazabilidad de los reprocesos

Toda acción de reproceso debe identificar:

- orden de producción original;
- operación original;
- defecto;
- cantidad afectada;
- empleado/operación responsable;
- estado del reproceso;
- resultado final.

---

# 18. Entrega

Los pedidos deben tener una fecha de entrega comprometida cuando aplique.

La gerencia debe poder identificar los pedidos que se acercan a su fecha límite de entrega o que ya la superaron.

Las especificaciones futuras definirán los umbrales exactos del semáforo.

---

# 19. Comisiones de asesoras

Las comisiones de las asesoras se calculan a partir de las ventas elegibles generadas por ellas.

Según las reglas de negocio iniciales, las ventas automáticas del sitio web quedan excluidas del cálculo de comisiones de las asesoras.

El porcentaje de comisión debe ser configurable.

---

# 20. Atribución de las ventas web

Los pedidos generados automáticamente por el sitio web deben tener un origen de venta propio.

No deben atribuirse a una asesora por el solo hecho de que exista una asesora en el sistema.

---

# 21. Notificaciones por WhatsApp

WhatsApp es un canal de notificación externo.

El sistema puede notificar a los usuarios responsables cuando ocurran eventos importantes.

Los eventos candidatos iniciales incluyen:

- faltante de material;
- pedido que requiere atención comercial;
- riesgo en la entrega;
- excepción de producción.

El proveedor externo de WhatsApp debe quedar desacoplado de la lógica central del negocio.

---

# 22. Estado del pedido

El estado del pedido debe reflejar su situación operativa real.

El sistema debe evitar usar un único estado genérico para representar varios procesos sin relación entre sí.

Puede ser necesario representar de forma independiente el estado comercial, el estado de producción, el estado de pago y el estado de entrega.

---

# 23. Auditabilidad

Los cambios importantes deben ser trazables.

Como mínimo, el sistema debe poder identificar:

```text
Quién
Qué
Cuándo
Qué registro
```

en las operaciones de negocio importantes.

---

# 24. Integridad de los datos

Las operaciones que afectan a varios registros relacionados deben ser transaccionales cuando se requiera.

Ejemplos:

- confirmación del pedido + reserva de materiales;
- consumo de inventario;
- finalización de la producción;
- rechazo de calidad + creación del reproceso.

---

# 25. Configuración

Los siguientes valores deben ser configurables cuando corresponda:

- porcentajes de anticipo;
- precios por volumen;
- descuentos por volumen;
- tiempos estándar;
- motivos de pausa;
- estados configurables;
- parámetros de producción;
- porcentaje de comisión;
- notificaciones;
- tiempos estándar de producción;
- consumo de materiales;
- tramos de precio y sus límites para cada línea de producto;
- umbrales de negociación VIP;
- precios, incluidos los precios de los ítems de los combos;
- stock mínimo de los productos terminados.

---

# 26. Catálogo de productos

Ecolekua tiene dos líneas de negocio:

- **Uniformes**: uniformes corporativos, escolares y de restaurante confeccionados a pedido, con bordado y sublimación. Es el volumen principal. Algunas prendas también se venden como productos terminados desde stock.
- **Pañales**: pañales de adulto y productos de incontinencia vendidos desde stock, de forma individual o en combos, y pañales ecológicos infantiles.

Reglas del catálogo:

- Deben conservarse los códigos de producto de la lista de precios existente.
- Un código identifica una combinación de los atributos que determinan el precio (p. ej., tela, modelo, tipo de manga, género).
- Los atributos de los productos y sus combinaciones deben ser configurables desde el sistema.
- La talla, el color y los detalles (color por ubicación: pechera, orilla de pechera, orilla de mangas, pie de cuello) no cambian el código. Las excepciones son los pañales, en los que cada talla tiene su propio código, y los pantalones industriales de §2.2.
- Los botones son transparentes por defecto.
- Los colores de una prenda dependen de su tela: cada tela tiene los colores que Ecolekua ofrece en ella, aunque haya que comprar la tela. Si hay tela en stock se verifica al confirmar el pedido (§6 y §7).

## 26.1 Color personalizado

Si el cliente no encuentra un color de su gusto entre los ofrecidos, puede elegir la opción **Personalizado** en el color de la prenda:

- se ofrece en los productos confeccionados a pedido, salvo que el equipo la desactive en un producto; no aplica a los productos de stock ni a los colores de los detalles;
- el cliente indica el tono que busca con un selector de colores y una nota con el nombre del color como referencia;
- el pedido no se procesa desde la web: se habilita el botón de WhatsApp y una asesora acuerda con el cliente la tela, el precio y el tiempo de entrega;
- la tela se compra en la cantidad necesaria para ese pedido y queda asociada a él, sin entrar al stock general. El sobrante entra al inventario solo si el equipo lo decide.

---

# 27. Stock de producto terminado

Algunos productos se venden desde stock en lugar de confeccionarse a pedido. Cada producto tiene un modo de abastecimiento:

| Modo | Comportamiento | Ejemplos |
|---|---|---|
| Bajo pedido | Se produce para cada pedido; sin stock | Camisas corporativas, chemises, kimonos |
| Stock con mínimo | Se vende desde stock y se repone | Pañales de adulto, monos y franelas escolares de Los Cedros, pantalones industriales, tazas, mouse pads, gorras |
| Stock agotable | Se vende desde stock hasta agotarse; no se vuelve a fabricar | Pañales ecológicos infantiles |
| Servicio | Se aplica a una prenda o artículo; no tiene stock propio | Bordado, vinil, sublimación, planchado |

Stock mínimo:

- Productos terminados: **6 unidades**.
- Pantalones industriales: **2 unidades por talla**.
- Pañales ecológicos infantiles: **sin mínimo**.

Los pedidos de los clientes nunca generan la fabricación de pañales. Los pañales se fabrican solo para reponer stock.

El cliente no ve las cantidades en stock. Cuando un pedido web de un producto de stock no puede atenderse dentro de las reglas de stock:

- el pedido no se procesa;
- se ofrece al cliente un botón para contactar a una asesora de ventas por WhatsApp.

Ejemplo: el pañal antigoteo talla 5XG tiene un stock mínimo de 6 y un cliente pide 10 unidades. El pedido no se procesa desde la web y se habilita el botón de WhatsApp.

En los pañales ecológicos infantiles, la regla se aplica cuando la cantidad pedida supera el stock disponible.

En los productos con stock mínimo, Ecolekua debe confirmar si la regla protege el mínimo (disponible − pedido < mínimo) o si solo se aplica cuando la cantidad pedida supera el stock disponible (DEC-PRD-11).

---

# 28. Uso de prendas en stock en los pedidos

Las prendas terminadas en stock (p. ej., franelas lisas listas para sublimar) pueden usarse para atender un pedido.

Los materiales se reservan solo para las unidades que no cubren esas prendas.

Ejemplo:

```text
Pedido: 15 franelas dryfit blancas de sublimación completa
En stock: 5 franelas dryfit blancas

Tela reservada para: 10 franelas
```

---

# 29. Reglas futuras

Ningún comportamiento de negocio que no esté definido explícitamente en este documento debe inventarse durante la implementación.

Debe introducirse mediante una especificación o una decisión de negocio aprobada.
