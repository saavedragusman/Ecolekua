# Constitución del Proyecto — Ecolekua Micro-ERP

## 1. Propósito
Principios innegociables. Toda spec, plan y tarea debe cumplirlos.
Este documento establece los principios técnicos, arquitectónicos y de negocio que deben guiar el desarrollo del **Sistema de Gestión Empresarial a Medida para Ecolekua**.

El sistema será desarrollado específicamente para digitalizar, centralizar y optimizar los procesos internos de Ecolekua (empresa de manufactura de uniformes, bordado, sublimacion, elaboración y venta de pañales reusables para adultos) relacionados con:

- Ventas y cotizaciones.
- Clientes.
- Productos y variantes.
- Inventario y materiales.
- Reservas de materiales.
- Producción.
- Control de tiempos.
- Control de calidad.
- Reprocesos.
- Entregas.
- Comisiones.
- Notificaciones.
- Reportes.
- Gestión y toma de decisiones.

El sistema debe reflejar los procesos reales de Ecolekua y convertirse en una herramienta operativa para la empresa.

---

## 2. Naturaleza del Proyecto

Ecolekua Micro-ERP es un **sistema de gestión empresarial desarrollado a medida para Ecolekua**.

No es un producto SaaS genérico ni una plataforma destinada inicialmente a múltiples empresas.
La plataforma debe permitir a la gerencia responder preguntas como:

- ¿Qué se ha vendido?
- ¿Cuánto se ha pagado?
- ¿Qué materiales están reservados?
- ¿Qué materiales faltan?
- ¿Qué se está produciendo actualmente?
- ¿Quién realiza cada operación?
- ¿Cuánto tiempo está tardando la producción?
- ¿Qué ha fallado en el control de calidad?
- ¿Quién es el responsable de la reelaboración?
- ¿Qué debe entregarse y cuándo?
- ¿Qué asesor generó una venta?
- ¿Con qué eficiencia está funcionando la producción?

### 2.1 Alcance organizacional

El sistema está diseñado para una única empresa:

**Ecolekua**

Todas las funcionalidades, reglas de negocio, flujos y modelos de datos deben responder a las necesidades y procesos específicos de Ecolekua.

### 2.2 No es un sistema multiempresa

El proyecto **no debe implementar una arquitectura multiempresa o multi-tenant**.

No se deben introducir entidades, tablas, relaciones o mecanismos como:

- `tenant`
- `tenant_id`
- aislamiento entre empresas
- organizaciones múltiples
- cuentas empresariales independientes
- selección de empresa al iniciar sesión
- configuración independiente por empresa

salvo que exista una decisión explícita posterior del proyecto que modifique este principio.

### 2.3 No diseñar para un producto futuro inexistente

No se deben crear abstracciones, configuraciones o componentes únicamente con el propósito de convertir posteriormente el sistema en un producto comercial para otras empresas.

La prioridad es:

> **Resolver correctamente las necesidades actuales y previstas de Ecolekua con una arquitectura limpia, mantenible y extensible.**

Si en el futuro surgiera la necesidad de convertir el sistema en un producto para múltiples empresas, esa transformación deberá tratarse como una nueva iniciativa arquitectónica y evaluarse explícitamente.

---

# 3. Principios Arquitectónicos

## 3.1 Arquitectura modular

El sistema debe organizarse en módulos funcionales claramente definidos.

Los módulos iniciales incluyen:

- Autenticación y usuarios.
- Clientes.
- Productos.
- Ventas.
- Cotizaciones.
- Pedidos.
- Pagos y anticipos.
- Inventario.
- Materiales.
- Producción.
- Control de tiempos.
- Calidad.
- Reprocesos.
- Entregas.
- Comisiones.
- Notificaciones.
- Reportes.
- Dashboard gerencial.

Los módulos deben mantener responsabilidades claras y evitar dependencias innecesarias.

---

## 3.2 Backend como autoridad de negocio

El backend de Laravel se encarga de las reglas de negocio y la integridad de los datos.

Las aplicaciones frontend consumen funcionalidades del backend y no debe ser considerado una fuente confiable para aplicar reglas críticas.

Ejemplos:

- cálculo de precios;
- descuentos por volumen;
- porcentaje de anticipo;
- disponibilidad de materiales;
- reservas de materiales;
- estados de pedidos;
- asignaciones de producción;
- control de tiempos;
- reprocesos;
- comisiones;
- permisos.

El frontend debe representar las reglas y facilitar la interacción, pero no sustituir la lógica de negocio del backend.

---

## 3.3 Stack tecnológico

El proyecto utilizará inicialmente:

- **Backend:** Laravel / PHP.
- **Frontend:** Vue 3.
- **Aplicación:** Inertia.js.
- **Base de datos:** MySQL.
- **Pruebas:** Pest.
- **Control de versiones:** Git.

La arquitectura debe mantener separación razonable entre:

- dominio;
- aplicación;
- infraestructura;
- presentación.

No se requiere aplicar patrones arquitectónicos complejos si no aportan valor real al proyecto.

---

## 3.4 Preparación para API y aplicación móvil

Aunque el sistema se desarrollará inicialmente como aplicación web, la arquitectura debe evitar decisiones que impidan posteriormente exponer funcionalidades mediante una API.

Esto permitirá, si Ecolekua lo requiere en el futuro, desarrollar:

- aplicación Android;
- aplicación iOS;
- aplicaciones móviles híbridas;
- integraciones externas.

Sin embargo:

> **La existencia de una futura API o aplicación móvil no debe justificar sobreingeniería en la primera versión.**

La API se desarrollará cuando exista una necesidad concreta.

---

# 4. Fidelidad a los Procesos de Ecolekua

## 4.1 El negocio real es la fuente principal

El sistema debe representar los procesos reales de Ecolekua.

Antes de implementar una funcionalidad se debe determinar:

1. Qué proceso existe actualmente.
2. Quién participa.
3. Qué información necesita.
4. Qué decisiones se toman.
5. Qué reglas deben cumplirse.
6. Qué resultado produce.
7. Qué información debe quedar registrada.

No se deben inventar procesos simplemente porque sean comunes en otros ERPs.

---

## 4.2 No convertir el sistema en un ERP genérico

Ecolekua Micro-ERP no debe intentar competir conceptualmente con plataformas ERP generalistas.

El objetivo es resolver específicamente las operaciones de Ecolekua.

Por ejemplo, el sistema debe poder representar correctamente procesos como:

```text
Venta
  ↓
Anticipo
  ↓
Reserva de materiales
  ↓
Corte
  ↓
Sublimación / Bordado
  ↓
Costura
  ↓
Control de calidad
  ↓
Reprocesos si existen defectos
  ↓
Empaque
  ↓
Entrega
```

Este flujo puede evolucionar conforme se conozcan mejor los procesos reales de la empresa.

---

# 5. Integridad del Negocio

Las operaciones que afectan dinero, inventario, producción o compromisos con clientes deben ejecutarse de forma segura y consistente.

## 5.1 Transacciones

Las operaciones críticas deben utilizar transacciones de base de datos cuando sea necesario para garantizar atomicidad.

Ejemplos:

- confirmar un pedido;
- registrar un anticipo;
- reservar materiales;
- descontar inventario;
- registrar movimientos de inventario;
- crear órdenes de producción;
- registrar resultados de producción;
- registrar defectos;
- generar reprocesos.

Nunca debe quedar el sistema en un estado parcialmente actualizado por una operación crítica.

---

## 5.2 Inventario

El inventario debe ser trazable.

Cada movimiento relevante debe poder determinar:

- qué material o producto fue afectado;
- cantidad;
- fecha;
- usuario responsable;
- tipo de movimiento;
- referencia relacionada;
- motivo, cuando corresponda.

No se debe modificar silenciosamente el stock sin dejar trazabilidad.

---

## 5.3 Materiales y reservas

Los materiales utilizados para producción deben diferenciar, cuando corresponda:

- stock físico;
- stock reservado;
- stock disponible.

Una reserva de materiales debe afectar la disponibilidad real para otros pedidos.

Si una orden requiere materiales que no están disponibles, el sistema debe poder identificar exactamente:

- material faltante;
- cantidad faltante;
- pedido afectado;
- fecha comprometida;
- estado de la falta.

---

# 6. Producción y Trazabilidad

El sistema debe permitir conocer qué ocurrió durante la fabricación de un pedido.

Debe existir trazabilidad sobre:

- orden de producción;
- operación;
- estación;
- responsable;
- asignación;
- inicio;
- pausas;
- reanudaciones;
- finalización;
- tiempo real;
- tiempo estándar;
- cantidades producidas;
- cantidades defectuosas;
- reprocesos;
- observaciones.

La producción debe poder analizarse posteriormente para medir productividad y detectar problemas.

---

# 7. Control de Calidad

El sistema debe permitir registrar defectos de forma individual o agrupada cuando corresponda.

Un defecto no debe provocar automáticamente el reproceso de toda una orden.

Por ejemplo:

> Si se producen 100 unidades y 5 presentan defectos, el sistema debe permitir identificar y devolver únicamente esas 5 unidades al proceso correspondiente.

Debe conservarse la relación entre:

- producción original;
- unidades defectuosas;
- defecto;
- responsable;
- operación;
- reproceso;
- resultado del reproceso.

---

# 8. Tiempo y Productividad

Las operaciones de producción deben permitir registrar tiempos reales.

El sistema debe diferenciar entre:

- tiempo estándar;
- tiempo real;
- tiempo efectivo;
- tiempo pausado.

Las pausas deben poder registrar un motivo, por ejemplo:

- falla de máquina;
- falta de material;
- almuerzo;
- mantenimiento;
- espera;
- otro motivo definido por Ecolekua.

Estos datos deben utilizarse posteriormente para generar indicadores de productividad.

---

# 9. Seguridad y Autorización

El acceso a la información debe estar controlado mediante:

- autenticación;
- autorización;
- permisos basados ​​en roles;
- separación entre usuarios internos y clientes del portal: un cliente solo puede acceder a su propia información;
- validación;
- protección contra el acceso no autorizado a los datos;
- Manejo seguro de credenciales y secretos.

Los permisos deben verificarse en el servidor.

El frontend no debe ser considerado suficiente para proteger una operación.

Las acciones sensibles deben registrar quién las ejecutó cuando corresponda.

---

# 10. Auditoría

Las operaciones relevantes deben mantener trazabilidad.

Cuando sea necesario, el sistema debe registrar:

- usuario;
- acción;
- entidad afectada;
- fecha y hora;
- valores relevantes;
- resultado de la operación.

La auditoría debe utilizarse especialmente para operaciones sensibles como:

- cambios de precios;
- cambios de inventario;
- ajustes;
- modificaciones de pedidos;
- cambios de estados;
- acciones administrativas;
- operaciones relacionadas con pagos.

---

# 11. Configuración

Las reglas que razonablemente puedan cambiar dentro de Ecolekua deben evitar quedar innecesariamente codificadas como valores rígidos.

Ejemplos:
- porcentajes de anticipo;
- precios por Volumen
- descuentos por volumen;
- tiempos estándar;
- motivos de pausa;
- estados configurables;
- parámetros de producción;
- porcentaje de comisión;
- notificaciones;
- tiempos estándar de producción;
- consumo de materiales.

Sin embargo:

> **Configurabilidad no significa construir un motor genérico de reglas.**

Una configuración debe existir cuando aporte valor real a Ecolekua.

No se deben crear sistemas de configuración complejos para escenarios hipotéticos.

---

# 12. Pruebas

Las funcionalidades críticas deben contar con pruebas automatizadas.

Se dará especial prioridad a:

- cálculo de precios;
- descuentos;
- anticipos;
- reservas de materiales;
- disponibilidad de inventario;
- movimientos de inventario;
- estados de pedidos;
- producción;
- tiempos;
- defectos;
- reprocesos;
- comisiones;
- permisos;
- notificaciones.

Las reglas de negocio importantes deben poder modificarse con seguridad sin introducir regresiones.

---

# 13. Simplicidad sobre Sobreingeniería

El proyecto debe preferir soluciones simples, claras y mantenibles.

No se deben introducir:

- patrones innecesarios;
- capas artificiales;
- microservicios;
- motores genéricos;
- abstracciones prematuras;
- sistemas de plugins internos;
- arquitectura multiempresa.

cuando no exista una necesidad concreta.

La complejidad debe justificarse por una necesidad real del negocio o de la arquitectura.

---

# 14. Experiencia de usuario

Diseño Mobile-First Estricto:** El sistema debe ser accesible y estar optimizado prioritariamente para teléfonos móviles y tablets, ya que será el medio principal de uso para las asesoras de ventas y los operarios en planta.
**Reglas de UI/UX:** Se debe forzar el uso de objetivos táctiles grandes con una altura mínima de 44px, diseños apilables (w-full en móviles) y tablas responsivas. Es obligatorio aplicar bloqueos para prevenir el zoom automático en los inputs al usar dispositivos iOS.
**Estrategia Omnicanal de Notificaciones:** Se debe implementar un sistema de alertas sin saturar (spam) al usuario.
 **Push / WebSockets (Laravel Reverb):** Se utilizarán para el día a día operativo interno, como actualizar los tableros Kanban en tiempo real o notificar asignaciones de tareas a los operarios sin recargar la página.
**WhatsApp (API):** Se reservará exclusivamente para alertas críticas internas (ej. requisición de materia prima a Administración y Finanzas) y notificaciones de alto valor para el cliente (ej. cotizaciones VIP o avisos de pedido listo para retiro).

La interfaz debe priorizar la claridad operativa.

Los empleados de fábrica no deberían tener que navegar por complejas pantallas administrativas para realizar las operaciones de producción.

Los paneles de control de gestión deben hacer hincapié en:

- estado;
- plazos;
- excepciones;
- productividad;
- acciones pendientes.

La experiencia del cliente debe hacer hincapié en:

- claridad;
- estado del pedido;
- cotización;
- pago;
- información de entrega;
- perfil.

---

# 15. Integraciones Externas

Las integraciones externas deben mantenerse desacopladas del núcleo del negocio cuando sea razonable.

Entre las posibles integraciones se encuentran:

- WhatsApp;
- servicios de correo;
- servicios de pago;
- almacenamiento de archivos;
- servicios externos;
- futuras aplicaciones móviles;
- facturacion;
- Chatbot.
El sistema debe evitar que una integración externa domine la lógica principal del dominio.

---

# 16. WhatsApp

WhatsApp será considerado un canal de comunicación e integración, no el núcleo del sistema.

Las reglas de negocio deben funcionar independientemente de WhatsApp.

Por ejemplo, si se requiere notificar una falta de material:

```text
Sistema detecta faltante
        ↓
Registra faltante
        ↓
Notifica al responsable
        ↓
WhatsApp puede ser uno de los canales utilizados
```

La existencia o ausencia temporal de WhatsApp no debe comprometer la integridad del proceso.

---

# 17. Dashboard y Reportes

Los dashboards y reportes deben construirse a partir de información real registrada por los módulos operativos.

No deben existir indicadores que dependan de información introducida manualmente cuando esta pueda obtenerse de los procesos existentes.

Ejemplos:

- ventas por asesora;
- comisiones;
- productividad;
- tiempos de producción;
- defectos;
- reprocesos;
- desperdicio de materiales;
- pedidos en produccion;
- pedidos atrasados;
- pedidos próximos a vencer.

Los indicadores deben tener una definición clara y trazable.

---

# 18. Evolución del Sistema

El sistema debe poder crecer dentro de las necesidades de Ecolekua.

Las nuevas funcionalidades deben evaluarse considerando:

1. impacto en procesos existentes;
2. impacto en datos;
3. impacto en permisos;
4. impacto en inventario;
5. impacto en producción;
6. impacto en reportes;
7. impacto en integraciones;
8. compatibilidad con funcionalidades existentes.

Las nuevas necesidades deben incorporarse mediante el proceso SDD definido para el proyecto.

---

# 19. Documentación como Fuente de Verdad

La documentación funcional y técnica debe mantenerse alineada con el sistema.

Las decisiones importantes deben quedar documentadas.

La documentación principal incluye:

- `AGENTS.md`
- `CLAUDE.md`
- `docs/ui/design-system.md`
- `docs/constitution.md`
- `docs/business/glossary.md`
- `docs/business/business-rules.md`
- especificaciones dentro de `/docs/specs/`

Cuando una decisión contradiga una documentación existente, la documentación debe actualizarse como parte del cambio.

## 19.1. Autoridad de la Especificación (La Spec Manda)

La spec manda: ningún comportamiento funcional debe implementarse si no está definido en la spec activa. Si falta una decisión de negocio, un requisito funcional o existe una ambigüedad que pueda afectar el comportamiento del sistema, se debe detener la implementación y solicitar aclaración antes de continuar.

Las tareas y el diseño deben derivarse de la spec activa y no introducir comportamiento funcional que esta no contemple.

Las decisiones técnicas menores que no alteren el comportamiento funcional pueden resolverse durante el diseño o implementación, siempre que respeten la Constitución, las reglas de negocio y la spec activa.
---

# 20. Definition of Done

Una funcionalidad se considera terminada cuando:

- cumple los requisitos definidos;
- respeta las reglas de negocio;
- está autorizada correctamente;
- mantiene la integridad de los datos;
- incluye pruebas apropiadas;
- no rompe funcionalidades existentes;
- tiene documentación suficiente;
- puede ser utilizada por el usuario final;
- ha sido verificada según el proceso SDD correspondiente.

----

# 21. Convenciones de Código y Documentación

**Idioma de documentación:** La documentación funcional, reglas de negocio, especificaciones, criterios de aceptación y decisiones arquitectónicas del proyecto deben redactarse en español. El código fuente debe seguir las convenciones del ecosistema tecnológico utilizado, incluyendo nomenclatura en inglés cuando corresponda.

El código generado o modificado mediante IA debe respetar las convenciones oficiales y las buenas prácticas establecidas por las tecnologías utilizadas, especialmente Laravel, PHP y Vue 3.

Las convenciones definidas en esta sección son obligatorias salvo que exista una razón técnica documentada para apartarse de ellas.

## 21.1 Estados mediante Enums

Los estados que representen ciclos de vida o transiciones de negocio deben implementarse mediante **Enums fuertemente tipados de PHP 8.1+**.

Esto aplica, entre otros, a:

- estados de pedidos;
- estados de producción;
- estados de operaciones de producción;
- estados de pagos;
- estados de control de calidad;
- estados de entregas.

Ejemplo:

```php
$order->status = OrderStatus::PENDING;
```

No se deben utilizar strings arbitrarios o valores literales dispersos por el código para representar estados de negocio.

Los Enums deben centralizar los valores válidos y, cuando corresponda, las operaciones relacionadas con su comportamiento.

---

## 21.2 Tipado y Documentación Backend

El código PHP debe utilizar tipos nativos siempre que sea posible.

Las clases, métodos y propiedades deben utilizar:

- tipos de parámetros;
- tipos de retorno;
- propiedades tipadas;
- Enums;
- estructuras de datos claramente definidas.

Debe utilizarse **PHPDoc** cuando sea necesario para documentar información que no pueda expresarse adecuadamente mediante los tipos nativos del lenguaje.

El PHPDoc debe utilizarse especialmente cuando sea necesario aclarar:

- propósito de una clase o método;
- estructuras de datos complejas;
- tipos genéricos;
- valores de retorno no evidentes;
- excepciones que puedan producirse;
- contratos importantes;
- comportamiento no evidente.

No se debe generar PHPDoc redundante que simplemente repita lo que ya expresa claramente el código.

---

## 21.3 Documentación Frontend

Los componentes Vue 3 deben mantener una estructura clara y autodocumentada.

Las `props`, `emits` y estados reactivos deben utilizar nombres y tipos que permitan comprender su propósito.

Debe utilizarse documentación mediante comentarios o JSDoc cuando el propósito o comportamiento no sea evidente.

Los componentes complejos deben documentar suficientemente:

- responsabilidad del componente;
- props relevantes;
- eventos emitidos;
- estado reactivo principal;
- interacciones importantes;
- comportamiento no evidente.

No se deben agregar comentarios redundantes que simplemente describan código cuyo comportamiento ya resulta evidente.

---

## 21.4 Comentarios sobre Decisiones de Negocio

Los comentarios deben explicar el **por qué** cuando exista una decisión de negocio, restricción técnica o comportamiento complejo que no resulte evidente directamente del código.

Ejemplos de situaciones que pueden requerir documentación:

- fórmula de reserva de inventario;
- cálculo de mermas;
- reglas de reproceso;
- descuentos por volumen;
- cálculo de anticipos;
- cálculo de comisiones;
- transiciones de estados;
- restricciones de producción.

Ejemplo:

```php
// Only reserve the material required by the confirmed order.
// Available stock must remain usable for other confirmed orders.
$reservedQuantity = $requiredQuantity;
```

Debe evitarse documentar mediante comentarios aquello que ya resulta evidente por el propio código.

Evitar:

```php
// Get the order.
$order = Order::find($orderId);
```

El objetivo de los comentarios es preservar **decisiones y contexto**, no narrar línea por línea lo que hace el código.

---

## 21.5 Principio de Mantenibilidad

Las convenciones de código y documentación deben facilitar el mantenimiento futuro del sistema.

La IA no debe generar documentación excesiva, comentarios redundantes o estructuras artificiales únicamente para cumplir formalmente esta Constitución.

La prioridad es:

**código claro → tipos explícitos → nombres descriptivos → documentación cuando aporte contexto.**

---

# 22. Principio Fundamental

El principio rector del proyecto es:

> **Construir el mejor sistema posible para los procesos reales de Ecolekua, evitando complejidad que no aporte valor al negocio.**

El proyecto no debe intentar anticipar todos los posibles negocios, empresas o escenarios futuros.

Debe priorizar:

**claridad + fidelidad al negocio + integridad de datos + trazabilidad + seguridad + mantenibilidad + simplicidad.**

Cualquier futura expansión significativa del alcance deberá ser evaluada como una decisión explícita del proyecto y no asumida automáticamente por la arquitectura.
