# Ecolekua - Micro-ERP SaaS 🏭

Plataforma integral web para la gestión empresarial, ventas, control de inventario y producción a medida para **Ecolekua**. Este sistema está diseñado para digitalizar la línea de producción textil, eliminando el uso de papel y proporcionando métricas en tiempo real sobre el rendimiento operativo.

## 🚀 Stack Tecnológico

El proyecto está construido bajo una arquitectura API-Ready y Single Page Application (SPA):

* **Backend / Core:** Laravel 11 (PHP 8.2+)

* **Base de Datos:** MySQL / MariaDB

* **Autenticación:** Laravel Sanctum

* **Frontend:** Vue.js 3 (Composition API) + Inertia.js

* **Estilos:** Tailwind CSS

* **Tiempo Real:** Laravel Reverb (WebSockets para tableros Kanban y notificaciones)

## 🧠 Metodología de Desarrollo: SDD (Spec-Driven Development)

Este proyecto se desarrolla utilizando asistencia de IA (Gentle-IA / Claude Code) bajo el paradigma SDD. La estructura del proyecto refleja este flujo de trabajo para mantener el contexto y las reglas de negocio estrictamente documentadas.

### Estructura de Directorios Clave

```
ecolekua/
├── AGENTS.md               # Definición de agentes y roles de IA
├── CLAUDE.md               # Redirección de contexto (@AGENTS.md)
├── docs/                   # Documentación core y reglas inquebrantables
│   └── constitution.md     # Reglas arquitectónicas y de negocio (Inertia, Sanctum, etc.)
├── specs/                  # Especificaciones formales por módulo (SDD)
│   ├── 001-core-database/
│   ├── 002-portal-ventas/
│   ├── 003-inventario/
│   └── 004-planta-kanban/
├── app/                    # Lógica de Laravel (Models, Controllers, Services)
├── resources/js/           # Componentes de Vue 3 e Inertia
└── odd/                    # Tareas ligeras (Organic Driven Development)

```

## ⚙️ Módulos Principales

1. **Portal Web y Cotizador Inteligente:** Catálogo, cotizador dinámico por volumen, y área de cliente.

2. **Centro de Ventas:** Recepción de anticipos, generación de pedidos y ruteo.

3. **Control de Inventario (Reserva Inmediata):** Gestión de telas (insumos directos) con reserva automática, y control de suministros menores (hilos, agujas) por punto de reorden.

4. **Centro de Producción (Kanban):** Tableros de trabajo para Corte, Sublimación, Bordado y Costura.

5. **Control de Tiempos y Rendimiento:** Cronómetros de trabajo por lote para medir eficiencia del personal (Tiempo Real vs. Tiempo Estándar).

6. **Dashboard Gerencial:** KPIs de ventas, embudos de producción y control de calidad (QA).

## 🛠️ Instalación y Configuración Local

1. Clonar el repositorio:

   ```
   git clone <url-del-repo>
   cd ecolekua
   
   ```

2. Instalar dependencias de PHP y Node:

   ```
   composer install
   pnpm install
   
   ```

3. Configurar entorno:

   ```
   cp .env.example .env
   php artisan key:generate
   
   ```

4. Configurar la base de datos en el archivo `.env` y ejecutar migraciones:

   ```
   php artisan migrate --seed
   
   ```

5. Iniciar servidores de desarrollo:

   ```
   php artisan serve
   npm run dev
   
   ```

   *(Si se configuran WebSockets con Reverb, ejecutar también `php artisan reverb:start`)*

*Desarrollado con 💚 para la transformación digital de Ecolekua.*
