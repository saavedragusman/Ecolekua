# Ecolekua — Design System (UI)

> Ubicación: `docs/ui/design-system.md`
> Sistema visual: **"Dynamic Care & Corporate System"** (tokens Material 3).
> Fuente de verdad visual: `docs/portal/html-original/desktop.html`.
> Aplica a: Micro-ERP interno (Vue 3 + Inertia + TypeScript + Tailwind) y portal público.

**Directiva para agentes de IA:** este documento es obligatorio al crear o modificar cualquier archivo en `resources/js/`, `resources/css/` o `resources/views/portal/`. Está subordinado a `docs/constitution.md` y al `AGENTS.md`. Si encuentras una contradicción con ellos, o entre este documento y el prototipo del portal, detente y repórtala con el formato `[DEC-PENDIENTE]`.

---

## 0. Jerarquía y alcance

1. **El prototipo manda sobre la identidad visual.** Los valores de color, tipografía, espaciado y forma de este documento se extrajeron de `desktop.html`. Si difieren, gana el prototipo y este documento se corrige.
2. **El tablero de diseño** (paleta con colores semilla `#081A72`, `#00C2F3`, `#F0F9FF`, `#1E293B`) muestra los colores **semilla** con los que se generó la paleta. **No se usan en código.** En código solo se usan los **roles** de la sección 2.
3. **Portal público:** se migra conservando su aspecto (AGENTS.md §9). Las únicas alteraciones permitidas son las listadas en la sección 9.
4. **Micro-ERP:** usa los mismos tokens, más los patrones de la sección 7, que el prototipo no cubre (tablas, badges de estado, timer, navegación inferior, formularios).
5. **Modo claro por defecto; modo oscuro opcional solo en el ERP (UI-06).** El prototipo no define tema oscuro; la paleta oscura es una **derivación propuesta** (§2) pendiente de aprobación del cliente. El modo oscuro se implementa **exclusivamente** redefiniendo bajo `.dark` los tokens semánticos de la sección 2: el prefijo `dark:` sigue **prohibido** en componentes y páginas, de modo que los componentes no cambian por tema. El portal público conserva su identidad clara y no aplica modo oscuro.

---

## 1. Principios obligatorios

### 1.1 Mobile-first estricto

- Las clases **sin prefijo** definen la vista móvil. `md:` (≥768px, tablets) y `lg:` (≥1024px, escritorio) solo **alteran** el diseño en pantallas mayores.
- En planta se trabaja con tablets: **`md:` es un dispositivo táctil**. Nunca reduzcas objetivos táctiles en `md:`. Si hace falta densidad, hazlo en `lg:`.

### 1.2 Objetivos táctiles

- Todo elemento interactivo (botón, enlace de acción, ítem de navegación, selector, checkbox con su etiqueta) mide **al menos 44×44px**: usa `min-h-11` y, en botones de solo icono, `size-11`.
- Separación mínima entre objetivos adyacentes: `gap-space-sm` (8px).

### 1.3 Formularios en iOS

- Todo `<input>`, `<select>` y `<textarea>` usa como mínimo `text-body-md` (16px) para evitar el zoom automático de iOS.
- Nunca usar `maximum-scale=1` ni `user-scalable=no` en el viewport.

### 1.4 Color

- **Solo se usan los tokens de la sección 2.** Prohibido usar hexadecimales arbitrarios (`bg-[#...]`) y la paleta por defecto de Tailwind (`gray-*`, `blue-*`, `amber-*`, `green-*`, `red-*`, `slate-*`…).
- Las opacidades sobre tokens están permitidas (`bg-primary/60`, `bg-secondary-container/20`).

### 1.5 Tablas

Una tabla nunca rompe el layout en móvil: o va dentro de `overflow-x-auto`, o se transforma en pila de tarjetas por debajo de `md` (sección 7.7).

### 1.6 Componentes, no cadenas copiadas

Los patrones de la sección 7 se implementan **una vez** como componentes TypeScript con variantes en `resources/js/components/`. Las páginas consumen componentes; no repiten cadenas largas de clases.

---

## 2. Tokens (Tailwind CSS v4)

Se declaran en `resources/css/app.css` mediante `@theme`. Los nombres son **idénticos** a los del prototipo, para que el HTML del portal migre sin cambiar clases.

> El modo oscuro (UI-06) redefine **todos** los tokens de color bajo `.dark`; los tokens que no son de color (tipografía, espaciado, sombras) no cambian. `@custom-variant dark` habilita la clase `.dark` como selector de tema (no se usa `prefers-color-scheme`).

> Si `app.css` ya define `--color-primary` u otros tokens del starter kit (por ejemplo, variables estilo shadcn), se sustituyen por estos. No debe haber dos sistemas de color paralelos.

```css
@import 'tailwindcss';

@custom-variant dark (&:where(.dark, .dark *));

@theme {
  /* ---------- Color: primario (azul corporativo) ---------- */
  --color-primary: #000845;
  --color-on-primary: #ffffff;
  --color-primary-container: #081a72;
  --color-on-primary-container: #7a87e0;
  --color-primary-fixed: #dee0ff;
  --color-primary-fixed-dim: #bbc3ff;
  --color-on-primary-fixed: #000e5e;
  --color-on-primary-fixed-variant: #313f93;
  --color-inverse-primary: #bbc3ff;
  --color-surface-tint: #4957ac;

  /* ---------- Color: secundario (cian / petróleo) ---------- */
  --color-secondary: #006782;
  --color-on-secondary: #ffffff;
  --color-secondary-container: #2acdff;
  --color-on-secondary-container: #00546b;
  --color-secondary-fixed: #bbe9ff;
  --color-secondary-fixed-dim: #5fd4ff;
  --color-on-secondary-fixed: #001f29;
  --color-on-secondary-fixed-variant: #004d63;

  /* ---------- Color: terciario (grafito) ---------- */
  --color-tertiary: #0b1317;
  --color-on-tertiary: #ffffff;
  --color-tertiary-container: #1f282c;
  --color-on-tertiary-container: #868f95;
  --color-tertiary-fixed: #dbe4ea;
  --color-tertiary-fixed-dim: #bfc8ce;
  --color-on-tertiary-fixed: #141d21;
  --color-on-tertiary-fixed-variant: #3f484d;

  /* ---------- Color: error ---------- */
  --color-error: #ba1a1a;
  --color-on-error: #ffffff;
  --color-error-container: #ffdad6;
  --color-on-error-container: #93000a;

  /* ---------- Color: superficies y texto ---------- */
  --color-background: #f9f9ff;
  --color-on-background: #111c2d;
  --color-surface: #f9f9ff;
  --color-surface-bright: #f9f9ff;
  --color-surface-dim: #cfdaf2;
  --color-surface-container-lowest: #ffffff;
  --color-surface-container-low: #f0f3ff;
  --color-surface-container: #e7eeff;
  --color-surface-container-high: #dee8ff;
  --color-surface-container-highest: #d8e3fb;
  --color-surface-variant: #d8e3fb;
  --color-on-surface: #111c2d;
  --color-on-surface-variant: #454651;
  --color-inverse-surface: #263143;
  --color-inverse-on-surface: #ecf1ff;
  --color-outline: #767683;
  --color-outline-variant: #c6c5d3;

  /* ---------- Color: estados del ERP (PROPUESTA — ver §10) ---------- */
  --color-success: #1b6d2f;
  --color-on-success: #ffffff;
  --color-success-container: #a3f6a4;
  --color-on-success-container: #002107;
  --color-warning: #7a5900;
  --color-on-warning: #ffffff;
  --color-warning-container: #ffdea3;
  --color-on-warning-container: #261900;

  /* ---------- Familias tipográficas ---------- */
  --font-sans: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
  --font-display-lg: 'Outfit', ui-sans-serif, system-ui, sans-serif;
  --font-display-lg-mobile: 'Outfit', ui-sans-serif, system-ui, sans-serif;
  --font-headline-xl: 'Outfit', ui-sans-serif, system-ui, sans-serif;
  --font-headline-xl-mobile: 'Outfit', ui-sans-serif, system-ui, sans-serif;
  --font-headline-md: 'Outfit', ui-sans-serif, system-ui, sans-serif;
  --font-headline-sm: 'Outfit', ui-sans-serif, system-ui, sans-serif;
  --font-body-lg: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
  --font-body-md: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
  --font-body-sm: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
  --font-label-lg: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
  --font-label-md: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
  --font-label-sm: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;

  /* ---------- Escala tipográfica (px del prototipo convertidos a rem) ---------- */
  --text-display-lg: 3rem;                      /* 48px */
  --text-display-lg--line-height: 3.5rem;
  --text-display-lg--letter-spacing: -0.02em;
  --text-display-lg--font-weight: 800;

  --text-display-lg-mobile: 2rem;               /* 32px */
  --text-display-lg-mobile--line-height: 2.375rem;
  --text-display-lg-mobile--letter-spacing: -0.01em;
  --text-display-lg-mobile--font-weight: 800;

  --text-headline-xl: 2.25rem;                  /* 36px */
  --text-headline-xl--line-height: 2.75rem;
  --text-headline-xl--letter-spacing: -0.015em;
  --text-headline-xl--font-weight: 700;

  --text-headline-xl-mobile: 1.625rem;          /* 26px */
  --text-headline-xl-mobile--line-height: 2rem;
  --text-headline-xl-mobile--font-weight: 700;

  --text-headline-md: 1.5rem;                   /* 24px */
  --text-headline-md--line-height: 1.875rem;
  --text-headline-md--font-weight: 700;

  --text-headline-sm: 1.25rem;                  /* 20px */
  --text-headline-sm--line-height: 1.625rem;
  --text-headline-sm--font-weight: 600;

  --text-body-lg: 1.125rem;                     /* 18px */
  --text-body-lg--line-height: 1.75rem;
  --text-body-lg--font-weight: 400;

  --text-body-md: 1rem;                         /* 16px */
  --text-body-md--line-height: 1.5rem;
  --text-body-md--font-weight: 400;

  --text-body-sm: 0.875rem;                     /* 14px */
  --text-body-sm--line-height: 1.25rem;
  --text-body-sm--font-weight: 400;

  --text-label-lg: 0.9375rem;                   /* 15px */
  --text-label-lg--line-height: 1.25rem;
  --text-label-lg--letter-spacing: 0.01em;
  --text-label-lg--font-weight: 700;

  --text-label-md: 0.8125rem;                   /* 13px */
  --text-label-md--line-height: 1.125rem;
  --text-label-md--font-weight: 600;

  --text-label-sm: 0.6875rem;                   /* 11px */
  --text-label-sm--line-height: 0.875rem;
  --text-label-sm--letter-spacing: 0.04em;
  --text-label-sm--font-weight: 700;

  /* ---------- Espaciado ---------- */
  --spacing-space-xs: 0.25rem;   /* 4px  */
  --spacing-space-sm: 0.5rem;    /* 8px  */
  --spacing-space-md: 1rem;      /* 16px */
  --spacing-space-lg: 1.5rem;    /* 24px */
  --spacing-space-xl: 2.5rem;    /* 40px */
  --spacing-space-2xl: 4rem;     /* 64px */
  --spacing-gutter: 1.5rem;
  --spacing-gutter-mobile: 1rem;
  --spacing-margin: 2rem;
  --spacing-margin-mobile: 1rem;

  /* ---------- Sombras de marca ---------- */
  --shadow-header: 0 1px 8px rgb(8 26 114 / 0.06);
  --shadow-glow: 0 4px 16px rgb(42 205 255 / 0.35);
  --shadow-glow-lg: 0 4px 20px rgb(42 205 255 / 0.4);
}

/* ---------- Tema oscuro opcional (UI-06, PROPUESTA): solo redefine los tokens de color ---------- */
.dark {
  /* Color: primario */
  --color-primary: #dee0ff;
  --color-on-primary: #0f1c78;
  --color-primary-container: #0a1454;
  --color-on-primary-container: #dee0ff;
  --color-primary-fixed: #26338a;
  --color-primary-fixed-dim: #bbc3ff;
  --color-on-primary-fixed: #dee0ff;
  --color-on-primary-fixed-variant: #bbc3ff;
  --color-inverse-primary: #4957ac;
  --color-surface-tint: #bbc3ff;

  /* Color: secundario */
  --color-secondary: #5fd4ff;
  --color-on-secondary: #003546;
  --color-secondary-container: #006c8a;
  --color-on-secondary-container: #bbe9ff;
  --color-secondary-fixed: #003a4c;
  --color-secondary-fixed-dim: #005f7a;
  --color-on-secondary-fixed: #bbe9ff;
  --color-on-secondary-fixed-variant: #bbe9ff;

  /* Color: terciario */
  --color-tertiary: #d6dfe5;
  --color-on-tertiary: #141d21;
  --color-tertiary-container: #bfc8ce;
  --color-on-tertiary-container: #1f282c;
  --color-tertiary-fixed: #3f484d;
  --color-tertiary-fixed-dim: #2a3338;
  --color-on-tertiary-fixed: #dbe4ea;
  --color-on-tertiary-fixed-variant: #bfc8ce;

  /* Color: error */
  --color-error: #ffb4ab;
  --color-on-error: #690005;
  --color-error-container: #93000a;
  --color-on-error-container: #ffdad6;

  /* Color: superficies y texto */
  --color-background: #0d1421;
  --color-on-background: #dce2f7;
  --color-surface: #0d1421;
  --color-surface-bright: #333a4b;
  --color-surface-dim: #0d1421;
  --color-surface-container-lowest: #080f1c;
  --color-surface-container-low: #151c2a;
  --color-surface-container: #192030;
  --color-surface-container-high: #242b3b;
  --color-surface-container-highest: #2f3646;
  --color-surface-variant: #2f3646;
  --color-on-surface: #dce2f7;
  --color-on-surface-variant: #c5c6d4;
  --color-inverse-surface: #dce2f7;
  --color-inverse-on-surface: #263143;
  --color-outline: #8f909d;
  --color-outline-variant: #454651;

  /* Color: estados del ERP */
  --color-success: #88d98a;
  --color-on-success: #003911;
  --color-success-container: #005319;
  --color-on-success-container: #a3f6a4;
  --color-warning: #e9c16c;
  --color-on-warning: #412d00;
  --color-warning-container: #5d4200;
  --color-on-warning-container: #ffdea3;
}

/* ---------- Utilidades propias ---------- */
@utility bg-dots {
  background-image: radial-gradient(var(--color-secondary-container) 1px, transparent 1px);
  background-size: 16px 16px;
}

@utility pb-safe {
  padding-bottom: env(safe-area-inset-bottom);
}

@utility scrollbar-none {
  scrollbar-width: none;
  &::-webkit-scrollbar {
    display: none;
  }
}

@layer base {
  :root {
    color-scheme: light;
  }

  .dark {
    color-scheme: dark;
  }

  body {
    @apply bg-surface text-on-surface font-body-md text-body-md antialiased;
  }
}
```

**Radios:** el prototipo usa `rounded` (0.25rem), `rounded-lg` (0.5rem), `rounded-xl` (0.75rem) y `rounded-full`. Estos valores coinciden con los de Tailwind por defecto, así que no se redefinen.

---

## 3. Uso semántico del color

| Rol | Uso | Ejemplo |
|---|---|---|
| `primary` / `on-primary` | Acción principal, texto de titulares, ítem activo | Botón "Guardar", H1–H4 |
| `primary-container` | Bloques oscuros de marca, sidebar del ERP, hover de `primary` | Banda de estadísticas del portal |
| `secondary-container` | **CTA** y acento de marca (siempre con texto `primary`) | "Solicitar Presupuesto", "Cotizar" |
| `secondary` | Texto de acento legible sobre fondo claro, iconos destacados | Etiquetas, enlaces secundarios |
| `tertiary` / `tertiary-container` | Elementos neutros oscuros, botones de herramienta | Botón de edición |
| `surface` | Fondo general de la aplicación | `<body>` |
| `surface-container-lowest` | Tarjetas | Cards de catálogo, Kanban |
| `surface-container-low` … `highest` | Capas y fondos de controles, de más claro a más oscuro | Inputs, cabeceras de tabla, botón secundario |
| `on-surface` / `on-surface-variant` | Texto principal / texto secundario | Párrafos / metadatos |
| `outline` | Bordes de controles (inputs, botón outlined) | — |
| `outline-variant` | Solo divisores decorativos (no bordes de controles) | Líneas entre filas |
| `inverse-surface` | Botón invertido, toasts | — |
| `error` / `error-container` | Errores, acciones destructivas, pausa, retrabajo | "Eliminar", badge "Retrabajo" |

### 3.1 Reglas de contraste (verificadas, WCAG AA) — modo claro

| Combinación | Contraste | Veredicto |
|---|---|---|
| `primary` sobre `surface` | 17.9:1 | ✅ |
| `on-surface-variant` sobre `surface` | 8.9:1 | ✅ |
| `secondary` sobre `surface` | 6.1:1 | ✅ |
| `primary` sobre `secondary-container` | 10.1:1 | ✅ (combinación del CTA) |
| `on-primary` sobre `primary-container` | 15.0:1 | ✅ |
| `secondary-container` sobre `primary-container` | 8.0:1 | ✅ (cian sobre fondo oscuro) |
| `on-primary-container` sobre `primary-container` | 4.5:1 | ⚠️ solo texto ≥14px; nunca `label-sm` |
| `outline` sobre `surface` | 4.3:1 | ✅ para bordes de controles (mín. 3:1) |
| `outline-variant` sobre `surface` | 1.6:1 | ❌ solo decorativo |
| **`secondary-container` sobre `surface`** | **1.8:1** | ❌ **prohibido como texto o icono informativo** |

### 3.2 Reglas de contraste — modo oscuro (UI-06, propuesta)

Ratios calculados con la fórmula de luminancia relativa de WCAG 2.x sobre los valores del bloque `.dark` de §2. Mínimos: 4.5:1 texto normal; 3:1 texto grande, iconos y bordes de controles.

| Combinación | Contraste | Veredicto |
|---|---|---|
| `primary` sobre `surface` | 14.2:1 | ✅ |
| `on-surface` sobre `surface` | 14.3:1 | ✅ |
| `on-surface-variant` sobre `surface` | 10.9:1 | ✅ |
| `on-surface-variant` sobre `surface-container-high` | 8.4:1 | ✅ |
| `on-surface-variant` sobre `surface-container-highest` | 7.1:1 | ✅ |
| `on-surface` sobre `surface-container-lowest` (tarjetas) | 14.9:1 | ✅ |
| `on-surface-variant` sobre `surface-container-lowest` | 11.3:1 | ✅ |
| `secondary` sobre `surface` | 10.8:1 | ✅ |
| `primary` sobre `secondary-container` | 4.6:1 | ✅ (combinación del CTA) |
| `primary` sobre `secondary-fixed-dim` | 5.6:1 | ✅ (hover del CTA) |
| `on-primary` sobre `primary` | 11.1:1 | ✅ |
| `on-primary-container` sobre `primary-container` | 13.1:1 | ✅ |
| `secondary` sobre `primary-container` | 9.9:1 | ✅ |
| `on-secondary` sobre `secondary` | 7.7:1 | ✅ |
| `on-tertiary` sobre `tertiary` | 12.7:1 | ✅ |
| `on-primary` sobre `tertiary-container` (`IconButton` `tool`) | 8.5:1 | ✅ |
| `inverse-on-surface` sobre `inverse-surface` | 10.2:1 | ✅ |
| `inverse-on-surface` sobre `tertiary-container` (hover `inverted`) | 7.7:1 | ✅ |
| `on-error` sobre `error` | 7.7:1 | ✅ |
| `on-error` sobre `on-error-container` (hover `danger`) | 10.1:1 | ✅ |
| `error` sobre `surface` | 10.9:1 | ✅ |
| `on-primary-fixed` sobre `primary-fixed` (badge `active`) | 8.4:1 | ✅ |
| `on-success-container` sobre `success-container` (badge `done`) | 7.2:1 | ✅ |
| `on-warning-container` sobre `warning-container` (badge `pending`) | 7.2:1 | ✅ |
| `on-error-container` sobre `error-container` (badge `critical`) | 7.2:1 | ✅ |
| `outline` sobre `surface` | 5.8:1 | ✅ (bordes de controles, mín. 3:1) |
| `outline-variant` sobre `surface` | 2.0:1 | ❌ solo decorativo |
| `secondary-container` sobre `surface` | 3.1:1 | ✅ solo elementos gráficos (≥3:1); no como texto |
| `secondary-container` sobre `primary-container` | 2.8:1 | ⚠️ indicador de ítem activo del sidebar: el texto `primary` (4.6:1 sobre `secondary-container`) y `aria-current` sostienen el estado; el color no es el único indicador |
| `on-primary` sobre `primary/90` (hover de `AppButton` `primary`) | 9.1:1 (claro: 15.1:1) | ✅ (UI-06: el hover usa `primary/90` en ambos temas) |
| `on-primary` sobre `primary-container` | 1.2:1 | ❌ no usar esta combinación en oscuro |

Reglas específicas del modo oscuro:

- En modo oscuro `secondary-container` es un teal oscuro (no el cian de marca): el CTA pasa a ser teal con texto `primary` claro. El cian de marca solo existe en modo claro. Como consecuencia, decoraciones como `bg-dots` o el "!" del logotipo quedan poco visibles sobre fondos oscuros; son decorativas y no transmiten información.
- `tertiary` y `tertiary-container` se invierten a grises claros para que los botones "de herramienta" mantengan contraste con `on-primary`.

Reglas derivadas (modo claro):

- `text-secondary-container` **solo** se usa sobre fondos oscuros (`primary`, `primary-container`, `tertiary`) o como decoración (el "!" del logotipo, subrayados, puntos de fondo).
- El texto sobre `bg-secondary-container` siempre es `text-primary`. Nunca blanco.
- El color nunca es el único indicador de un estado: siempre va acompañado de texto o icono.

---

## 4. Tipografía

- **Outfit** para titulares (`display-*`, `headline-*`). **Plus Jakarta Sans** para cuerpo, etiquetas, tablas y formularios.
- **Regla del prototipo:** familia y tamaño se aplican en pareja con el mismo nombre, por ejemplo `font-headline-md text-headline-md` o `font-label-md text-label-md`.

### 4.1 Titulares responsivos

El prototipo define tamaños `*-mobile` pero no los usa. En este proyecto son obligatorios:

```html
<h1 class="font-display-lg text-display-lg-mobile md:text-display-lg text-primary">…</h1>
<h2 class="font-headline-xl text-headline-xl-mobile md:text-headline-xl text-primary">…</h2>
```

### 4.2 Escala en el Micro-ERP

| Elemento | Clases |
|---|---|
| Título de página | `font-headline-md text-headline-md text-primary` |
| Título de sección / tarjeta | `font-headline-sm text-headline-sm text-primary` |
| Texto general | `font-body-md text-body-md text-on-surface` |
| Celdas de tabla, metadatos | `font-body-sm text-body-sm` |
| Etiquetas de formulario, cabeceras de tabla, badges | `font-label-md text-label-md` |
| `label-sm` (11px) | Solo marcas sobre imágenes o metadatos no esenciales; nunca en datos que el operario deba leer |

---

## 5. Iconografía

- Librería única: **Material Symbols Outlined** (la del prototipo). No se mezclan otras librerías de iconos.
- Se usa siempre a través de `resources/js/components/AppIcon.vue`:

```html
<span class="material-symbols-outlined" aria-hidden="true">search</span>
```

- Un icono decorativo lleva `aria-hidden="true"`. Un botón que solo tiene icono lleva `aria-label` en español.
- Iconos de referencia del prototipo: `chat`, `check_circle`, `verified`, `local_shipping`, `recycling`, `precision_manufacturing`, `checkroom`, `styler`, `palette`, `straighten`, `factory`, `water_drop`.

---

## 6. Layouts

### 6.1 Viewport (`resources/views/app.blade.php`)

```html
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
```

`viewport-fit=cover` es necesario para que `pb-safe` funcione en iPhone.

### 6.2 Micro-ERP (`resources/js/layouts/`)

- **Móvil y tablet (`< lg`): navegación inferior** (Bottom Nav, sección 7.8), con un máximo de **4 destinos** más un botón **"Más"**. "Más" abre un panel inferior (bottom sheet, sección 7.8) con el resto de destinos agrupados. El panel inferior no es un menú hamburguesa: los destinos principales siempre están visibles y el panel solo agrupa los restantes. El menú de usuario (Cambiar contraseña, Cerrar sesión) vive en el encabezado, no en la navegación.
- **Escritorio (`lg:`): sidebar** fijo, con `bg-primary-container`, el logotipo en imagen arriba (blanco: solo sobre esta superficie, oscura en ambos temas) y secciones por grupo con encabezado (los ítems sin grupo, como Inicio, primero). Ítems inactivos en `text-on-primary-container`; ítem activo en `bg-secondary-container text-primary rounded-lg`.
- Contenedor de contenido: `bg-surface min-h-dvh px-gutter-mobile md:px-gutter pt-space-md pb-28 lg:pb-space-xl`. El `pb-28` evita que la navegación inferior tape el contenido.

### 6.3 Portal público (`resources/views/portal/`)

- Header fijo: `fixed inset-x-0 top-0 z-50 bg-surface/90 backdrop-blur-xl shadow-header`, con altura `h-20` y contenido `max-w-7xl mx-auto px-gutter`.
- Secciones: `w-full py-space-2xl`, alternando `bg-surface`, `bg-surface-container-low` y bandas `bg-primary-container text-on-primary`.

### 6.4 Grillas y espaciado

- Las grillas empiezan siempre en `grid-cols-1`, luego `md:grid-cols-2` y `lg:grid-cols-3` o `lg:grid-cols-4` (catálogo, Kanban, métricas). Separación: `gap-space-md md:gap-gutter`.
- Padding de tarjetas: `p-space-md md:p-space-lg`. Bloques destacados: `p-space-xl`.

### 6.5 Tema oscuro y alternador (UI-06)

- **Clave:** `appearance` en `localStorage`. Valores: `'light'` | `'dark'`. Valor ausente o inválido = claro. **No** se usa `prefers-color-scheme`.
- **Por defecto:** claro. El modo oscuro se activa solo con el alternador manual del ERP y se recuerda por navegador (la persistencia por usuario en BD queda para más adelante).
- **Sin destello (no-flash):** un `<script>` en línea en `<head>` de `resources/views/app.blade.php`, antes de `@vite`, lee `localStorage` dentro de `try/catch` y añade la clase `dark` a `<html>` solo si el valor es `'dark'`.
- **Aplicación:** el composable `resources/js/composables/useAppearance.ts` lee/escribe `appearance` y alterna `.dark` en `<html>`; el control del alternador vive en el layout del ERP, con `aria-label` en español y objetivo táctil ≥44px.
- **Alcance:** solo el ERP. Las vistas de `resources/views/portal/` no cargan el script ni exponen el alternador.

---

## 7. Componentes

Todos llevan el foco visible de la sección 8. Nombres y ubicación: `resources/js/components/<Nombre>.vue`, en TypeScript.

### 7.1 `AppButton.vue`

Base común:

```
inline-flex items-center justify-center gap-space-xs w-full md:w-auto min-h-11
px-space-lg py-space-sm font-label-lg text-label-lg transition-colors
disabled:opacity-50 disabled:pointer-events-none
```

Forma: `rounded-lg` en el ERP (como el tablero de diseño) y `rounded-full` en el portal (como el prototipo). Se controla con la prop `shape`.

| Variante | Clases | Uso |
|---|---|---|
| `primary` | `bg-primary text-on-primary hover:bg-primary/90` | Guardar, Aceptar, Enviar |
| `cta` | `bg-secondary-container text-primary shadow-glow hover:bg-secondary-fixed-dim` | Cotizar, Solicitar presupuesto, acción destacada |
| `secondary` | `bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest` | Cancelar, acciones menores |
| `inverted` | `bg-inverse-surface text-inverse-on-surface hover:bg-tertiary-container` | Acciones sobre fondos claros intensos |
| `outlined` | `border border-outline bg-transparent text-on-surface-variant hover:bg-surface-container-low` | Filtros, acciones alternativas |
| `danger` | `bg-error text-on-error hover:bg-on-error-container` | Eliminar, anular |

### 7.2 `IconButton.vue`

`size-11 inline-flex items-center justify-center rounded-full`, con las variantes `primary` (`bg-primary text-on-primary`), `secondary` (`bg-secondary text-on-secondary`), `tertiary` (`bg-tertiary text-on-tertiary`), `danger` (`bg-error text-on-error`) y `tool` (`bg-tertiary-container text-on-primary rounded-lg`). `aria-label` es obligatorio.

### 7.3 `AppCard.vue`

```
bg-surface-container-lowest rounded-xl shadow-sm p-space-md md:p-space-lg flex flex-col gap-space-sm
```

Si la tarjeta es interactiva, se añade `hover:shadow-xl transition-shadow`. El elevado con `hover:-translate-y-1` queda reservado al portal.

### 7.4 Campos de formulario (`AppInput.vue`, `AppSelect.vue`, `AppTextarea.vue`)

```
w-full min-h-11 rounded-lg border border-outline bg-surface-container-lowest
px-space-md font-body-md text-body-md text-on-surface placeholder:text-on-surface-variant
focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/20
```

- Etiqueta: `font-label-md text-label-md text-on-surface-variant`, siempre visible. Nunca solo placeholder.
- Error: `border-error` en el campo y un mensaje debajo en `font-body-sm text-body-sm text-error`, enlazado con `aria-describedby`.
- Búsqueda (tablero): igual, con `bg-surface-container-low` e icono `search` a la izquierda (`pl-11`).

### 7.5 `StatusBadge.vue`

Base: `inline-flex items-center gap-space-xs rounded-full px-space-sm py-1 font-label-md text-label-md whitespace-nowrap`.

Este componente define **categorías visuales**, no estados de negocio. La lista real de estados y su categoría la fija cada spec; no se inventan estados.

| Categoría | Clases | Ejemplos (según specs) |
|---|---|---|
| `pending` | `bg-warning-container text-on-warning-container` | Pendiente, retenido |
| `active` | `bg-primary-fixed text-on-primary-fixed` | En producción, activo |
| `done` | `bg-success-container text-on-success-container` | Completado, QA aprobado |
| `critical` | `bg-error-container text-on-error-container` | Retrabajo, urgente |
| `neutral` | `bg-surface-container-high text-on-surface-variant` | Borrador, cancelado |

Opcionalmente, un icono antes del texto; nunca un icono sin texto.

### 7.6 `ProgressBar.vue`

Pista: `h-2 w-full rounded-full bg-surface-container-high overflow-hidden`. Relleno: `h-full rounded-full bg-primary` (variantes `bg-secondary` y `bg-tertiary`). El ancho se pasa como estilo dinámico (`:style="{ width: `${pct}%` }"`). Lleva `role="progressbar"` con `aria-valuenow`, `aria-valuemin` y `aria-valuemax`.

### 7.7 `DataTable.vue`

- `lg:` (y `md:` cuando quepa): tabla dentro de `overflow-x-auto rounded-xl bg-surface-container-lowest shadow-sm`.
  - `thead`: `bg-surface-container-low font-label-md text-label-md text-on-surface-variant text-left`.
  - Filas: `border-t border-outline-variant`. Celdas: `px-space-md py-space-sm font-body-sm text-body-sm`.
- `< md`: pila de `AppCard`, una por registro, con la información clave arriba y el badge de estado visible.
- En el ERP **no** se ocultan las barras de desplazamiento.

### 7.8 `BottomNav.vue` (ERP, `< lg`)

Contenedor: `fixed inset-x-0 bottom-0 z-40 lg:hidden px-space-md pb-safe`.
Barra (píldora del tablero): `mb-space-sm flex items-center justify-around rounded-full bg-surface-container-high shadow-md p-space-xs`.
Ítem: `inline-flex flex-col items-center justify-center min-h-12 min-w-12 rounded-full text-on-surface-variant`. Ítem activo: `bg-primary text-on-primary`, con `aria-current="page"`.

**Máximo de 4 destinos** en la barra. Si existen más destinos visibles para el usuario, se añade un quinto botón "Más" (icono `more_horiz`, `aria-haspopup="dialog"`) que abre el panel inferior `MoreSheet.vue`. Los destinos de la barra son los que no pertenecen al grupo Administración, ordenados por prioridad; el resto va al panel. La configuración vive en un único lugar (`resources/js/navigation.ts`) y la comparten Bottom Nav, panel y sidebar.

**`MoreSheet.vue`** (panel inferior, `< lg`): elemento nativo `<dialog>` abierto con `showModal()` (foco atrapado y cierre con Esc). `aria-labelledby` apunta al título "Más opciones". Se cierra al pulsar el fondo y al navegar. Destinos agrupados con encabezado de grupo, objetivos de al menos 44px, `pb-safe`, sin dependencias nuevas. Animaciones solo con `motion-safe:`.

**`UserMenu.vue`** (encabezado del ERP): botón con avatar de iniciales y nombre (`aria-haspopup="menu"`, `aria-expanded`); el menú ofrece "Cambiar contraseña" y "Cerrar sesión" y se cierra con Esc y al pulsar fuera. El botón de tema se mantiene a su lado.

### 7.9 `ProductionTimer.vue` (botón de tiempo del operario)

- Tamaño: `size-16 rounded-full shadow-lg inline-flex items-center justify-center`.
- **El color y el icono indican la acción disponible**, no el estado actual:

| Situación | Acción que muestra | Clases | Icono | `aria-label` |
|---|---|---|---|---|
| Tarea detenida o no iniciada | Iniciar / Reanudar | `bg-secondary-container text-primary shadow-glow` | `play_arrow` | "Iniciar tarea" / "Reanudar tarea" |
| Tarea en curso | Pausar | `bg-error text-on-error` | `pause` | "Pausar tarea" |

- Junto al botón siempre se muestra el estado en texto y el tiempo transcurrido (por ejemplo, "En curso · 00:12:34"). El color no basta.
- Posición: dentro de la tarjeta de tarea, alineado abajo a la derecha (`self-end`). Si se usa flotante, `fixed right-space-md bottom-28 lg:bottom-space-lg`, para no chocar con la navegación inferior.
- **El comportamiento** (motivos de pausa, finalización, validaciones) lo define la spec de producción. El componente solo emite eventos; el backend registra los tiempos.

---

### 7.10 `ConfirmDialog.vue` (confirmación de acciones destructivas)

**Regla**: toda acción que cierre sesiones, invalide credenciales, desactive algo o elimine algo de forma irreversible requiere esta confirmación antes de enviarse (por ejemplo desactivar un usuario, restablecer su contraseña o eliminar un rol). Las ediciones reversibles y sin efecto sobre sesiones (activar una cuenta, editar datos, cambiar roles) no la requieren. Decisión del usuario (2026-09-29, UI-07).

- Elemento nativo `<dialog>` abierto con `showModal()`: foco atrapado y cierre con Esc, que equivale a "Cancelar". `aria-labelledby` apunta al título y `aria-describedby` al texto de consecuencia.
- Contenido: título, texto que explica la consecuencia en español neutro y dos botones. "Cancelar" (`secondary`) y el de confirmar en variante `danger` con el verbo de la acción ("Desactivar", "Restablecer").
- El foco inicial va a "Cancelar". Se cierra también al pulsar el fondo. Al cerrarse, el foco vuelve al elemento que la abrió.
- Objetivos de al menos 44px. En `< md` los botones ocupan todo el ancho. Solo tokens del sistema, sin `dark:` ni hexadecimales.
- Animaciones solo con `motion-safe:`. Sin dependencias nuevas.
- Puede mostrar estado de procesamiento (botones deshabilitados mientras se envía). El componente solo emite `confirm` y `cancel`; el envío lo hace la página.

### 7.11 `SegmentedTabs.vue` (vistas de filtro de un listado)

Selector de vistas para filtrar un listado (por ejemplo Activos / Inactivos / Todos en Usuarios, DEC-023).

- Son **enlaces** (`Link` de Inertia) dentro de un `<nav>` con nombre accesible (prop `label`), no un `tablist` ARIA: cada vista es una URL con su propio estado en la consulta. La vista seleccionada lleva `aria-current="page"`.
- La vista seleccionada y los conteos los calcula el backend; el componente solo los muestra (por ejemplo "Activos (12)").
- Contenedor: `flex w-full gap-space-xs rounded-lg bg-surface-container-high p-space-xs`; en `md:` se ajusta al contenido (`md:w-fit`). Ítem: `min-h-11 flex-1 rounded-lg px-space-md font-label-lg text-label-lg`; en `md:` `flex-none`. Seleccionado: `bg-primary text-on-primary`; reposo: `text-on-surface-variant hover:bg-surface-container-highest`.
- En `< md` ocupa todo el ancho como control segmentado, con objetivos de al menos 44px. Foco visible según §8.1. Solo tokens del sistema, sin `dark:` ni hexadecimales.

---

---

## 8. Accesibilidad

1. **Foco visible** en todo elemento interactivo:
   `focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface`.
   Sobre fondos oscuros (`primary`, `primary-container`): `focus-visible:ring-secondary-container`.
2. Los botones de solo icono llevan `aria-label` en español.
3. Los formularios usan `<label>` asociado, y los errores van enlazados con `aria-describedby`.
4. Animaciones: `motion-safe:` en transformaciones decorativas (`motion-safe:hover:scale-[1.02]` en el portal); nada esencial depende de una animación.
5. Idioma del documento: `lang="es"`.

---

## 9. Migración del prototipo al proyecto

Cambios **permitidos y obligatorios** al llevar `desktop.html` a `resources/views/portal/`. Ninguno altera la identidad visual:

| Prototipo | En el proyecto | Motivo |
|---|---|---|
| `cdn.tailwindcss.com` + `tailwind.config` en `<script>` | Tokens en `app.css` (§2), compilados con Vite | El CDN no es apto para producción |
| Imágenes de `lh3.googleusercontent.com/aida…` | Archivos en `public/images/portal/` o fotos reales del taller | Son URLs temporales externas |
| `::-webkit-scrollbar{display:none}` global | Clase `scrollbar-none` solo en el layout del portal | En el ERP oculta el scroll de las tablas |
| `overscroll-behavior:none` en `body` | Solo en el layout del portal | No se impone al ERP |
| `shadow-[…rgba(42,205,255,0.3/0.35/0.4)]` | `shadow-glow` / `shadow-glow-lg` | Unifica tres opacidades casi idénticas en un token |
| `shadow-[0_1px_8px_rgba(8,26,114,0.06)]` | `shadow-header` | Token |
| `shadow-[0_0_0_2px_rgba(42,205,255,0.4)]` | `ring-2 ring-secondary-container/40` | Utilidad estándar |
| `bg-[radial-gradient(#2acdff…)] [background-size:16px_16px]` | `bg-dots` | Sin hex arbitrario |
| Navegación `hidden lg:flex` **sin alternativa móvil** | Menú móvil del portal (pendiente, §10) | Hoy en móvil el portal no tiene navegación |
| Titulares sin tamaño móvil | Patrón §4.1 | Los tokens `*-mobile` ya existían |
| Botones con `py-space-sm` (~36px de alto) | Añadir `min-h-11` | Objetivo táctil de 44px |
| Sin estados de foco | Patrón §8.1 | Accesibilidad por teclado |
| Fuentes desde `fonts.googleapis.com` | Ver §10 | Carga desde el build |

---

## 10. Decisiones abiertas

| ID | Decisión | Estado | Recomendación |
|---|---|---|---|
| UI-01 | Colores de estado `success` y `warning` (el prototipo no los define) | Propuesta | Usar los valores de §2 (roles M3 estándar, contraste verificado ≥13:1 en badges) |
| UI-02 | Forma de botones en el ERP: `rounded-lg` (tablero) o `rounded-full` (portal) | Propuesta | `rounded-lg` en el ERP y `rounded-full` en el portal |
| UI-03 | Carga de fuentes e iconos | Pendiente de aprobar dependencias | `@fontsource-variable/outfit`, `@fontsource-variable/plus-jakarta-sans` y `material-symbols` vía pnpm. Mientras no se aprueben, se mantiene el `<link>` de Google Fonts del prototipo. Vigilar el peso de Material Symbols en móviles |
| UI-04 | Menú móvil del portal público | Pendiente | Diseñarlo con los tokens existentes (el prototipo no lo tiene) |
| UI-05 | Palabra "calidad" del H1 del portal en `text-secondary-container` (contraste 1.8:1) | Consultar con el cliente | No cambiar sin aprobación; proponer `text-secondary` o fondo oscuro |
| UI-06 | Modo oscuro opcional en el ERP | Propuesta | Alternador manual, persistido en `localStorage` (`appearance`), claro por defecto, sin detección de `prefers-color-scheme`. Paleta oscura derivada de los tokens de marca (§2, contrastes en §3.2), pendiente de aprobación del cliente. El portal público queda excluido. Se implementa solo con tokens bajo `.dark`; `dark:` sigue prohibido. Hover de `AppButton` `primary` cambiado de `hover:bg-primary-container` (1.2:1 en oscuro) a `hover:bg-primary/90` en ambos temas (decidido 2026-09-28) |
| UI-07 | Confirmación de acciones destructivas | Confirmada (usuario, 2026-09-29) | `ConfirmDialog.vue` (§7.10) para acciones que cierran sesiones, invalidan credenciales, desactivan algo o eliminan algo de forma irreversible |

Hasta que una decisión pase a **Confirmada**, los agentes aplican la recomendación solo si la decisión figura como "Propuesta". Si figura como "Pendiente" o "Consultar", se detienen en esa parte.

---

## 11. Checklist de verificación (en cada change con UI)

- [ ] `sail pnpm build` compila sin errores y las utilidades de §2 se generan (en especial `p-space-md`, `px-gutter`, `text-label-md`, `shadow-glow`, `bg-dots`).
- [ ] No hay hexadecimales arbitrarios ni paleta por defecto de Tailwind en el diff.
- [ ] No hay clases `dark:`; ambos temas verificados (claro por defecto, oscuro con el alternador) y sin destello al cargar.
- [ ] Vista probada a 375px, 768px y 1280px de ancho.
- [ ] Objetivos táctiles ≥44px; inputs ≥16px.
- [ ] Foco visible navegando con teclado.
- [ ] Las acciones destructivas usan `ConfirmDialog` (§7.10): Esc cancela, el foco inicial está en "Cancelar" y vuelve al disparador.
- [ ] Ningún texto en `text-secondary-container` sobre fondo claro.
