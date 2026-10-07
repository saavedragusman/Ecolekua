# Products Specification

> Transcribed from `docs/specs/003-products.md` (status "Lista para Design"; §4 permissions, §5 conceptual model, §6 requirements PRD-001..PRD-020 (no PRD-006), §7 scenarios E-01..E-72 (no E-12, E-13, E-18), §9 decisions DEC-PRD-01..DEC-PRD-53, technical decisions DT-01..DT-03). Faithful transcription: no added, removed, or reinterpreted behavior. Original IDs preserved verbatim; numbering gaps are kept as gaps. User-visible strings and example data quoted from the source stay in Spanish. A scenario that cites several requirements is placed under the first cited requirement and lists the others in its `Source citation` line. Prices are out of scope of this capability (DEC-PRD-25).

## Purpose

Defines how authorized internal users maintain Ecolekua's product catalog (categories, a data-driven catalog of attributes and values, products with attribute roles, commercial combinations that keep the codes of the current price list, detail locations, customizations, supply mode and minimum stock, diaper combos, and visual assets), and the single backend authority that (a) lists the options available at each step of a partial selection (PRD-019) and (b) resolves and validates a full selection to a sellable combination (PRD-011). It also defines the one-time console load of the existing catalog (PRD-018). Prices, stock levels and quotation or order lines belong to later specs.

## Conceptual model (constraints on the requirements)

- **Category** (DEC-PRD-18): name, order, status.
- **Attribute** (DEC-PRD-03): presentation (text | image | color, DEC-PRD-30); fabric-attribute flag (yes | no; at most one in the catalog, DEC-PRD-32), which is the special use "Tela" (special use Tela | Talla | Género, at most one attribute per use in the catalog, DEC-PRD-49). **Value**: name, order, optional description (e.g., "40 a 60 kg"), status; reference tone (mandatory if the attribute presentation is "color", DEC-PRD-17); optional image (DEC-PRD-20); optional SVG layer (DEC-PRD-29); offered colors (only on the fabric attribute: a list of colors, DEC-PRD-32).
- **Detail location** (Pechera, Orilla de pechera, Orilla de mangas, Pie de cuello): name, status, optional image (drawing of the zone), SVG layer (e.g., `orilla-mangas`).
- **Product**: name, description, category, business line (uniformes | pañales, DEC-PRD-26), supply mode (bajo pedido | stock con mínimo | stock agotable | servicio, DEC-PRD-09), default minimum stock (only mode "stock con mínimo"; optional own value per stock article, DEC-PRD-10, DEC-PRD-46), supports custom color (yes | no; only mode "bajo pedido" with color, DEC-PRD-34), portal visibility (DEC-PRD-19), main image (DEC-PRD-20), status (active | inactive); 0..n product attributes (attribute, role axis | order, admitted values); 0..n admitted detail locations; 0..n admitted customizations (products of mode "servicio", DEC-PRD-08); 0..n visual templates (sanitized SVG file, optional Género value, detected layers, DEC-PRD-29); 1..n commercial combinations.
- **Commercial combination**: code (from the current list, text, unique, DEC-PRD-01, DEC-PRD-02); one or several values per product axis (DEC-PRD-33); optional description (the initial load stores the one from the list); 0..n included customizations (DEC-PRD-08); status.
- **Combo** (DEC-PRD-12): code (DEC-PRD-14), name, portal visibility, status; 1..n components, each with product, admitted values per axis (subset) and quantity.
- Statuses, supply modes and business lines MUST be implemented as PHP Enums (constitution §21.1).
- Attributes are **data**: they are created and edited from the ERP without touching code. There MUST NOT be conditional rules, formulas or dependencies between attributes (constitution §11).
- The descriptive name of a combination is composed from the product name and the values of its axes in the product's axis order. If an axis has several values they are shown joined by "o" (e.g., "Kimono completo · Drill o Gabardina · Caballero"); in a quotation or order the chosen value is shown, e.g., "Camisa corporativa · ALG-OXF Pima · Columbia especial · Manga corta · Caballero".
- The price of each combination and of each combo component is defined by `004`, using the code as reference. The price-by-size-range exception of `184` and `184-2` is also `004` (DEC-PRD-06).
- The portal catalog shows the product name; the descriptive name is used in quotations, orders and the ERP.

## Technical decisions carried by this capability

- DT-01: codes are stored as text and compared case-insensitively, without trimming zeros or suffixes (proposed in the source; confirmed in design).
- DT-02: PRD-011 is implemented as a reusable Action (`app/Actions/Products/ResolveSelection`) with no price or stock dependency (proposed in the source; confirmed in design).
- DT-03: SVG is sanitized in the backend with a maintained library, not with ad hoc regular expressions; images and templates are stored with the Laravel filesystem. Approved dependency (2026-10-07): `enshrined/svg-sanitize` (GPL-2.0-or-later, pinned version; internal use, not distributed).

## Requirements

### Requirement: §4 — Permisos `products.*` y coherencia (DEC-PRD-22, DEC-PRD-23)

The catalog is managed by internal users. Portal visitors only consult it, according to `005`. The system MUST define the following permissions, verified in the backend; code MUST check the permission, never the role name (AGENTS §7.3):

| Permission | Allows |
|---|---|
| `products.view` | View categories, attributes, products, combinations and combos |
| `products.create` | Register products, combinations and combos |
| `products.update` | Edit products, combinations, combos, details, customizations, stock parameters, image and templates of the product |
| `products.deactivate` | Deactivate and reactivate products, combinations and combos |
| `products.delete` | Delete products, combinations and combos without history (PRD-014) |
| `products.catalog` | Manage categories, attributes and their values, and detail locations, including their tones, images and layers |

Initial assignment to the roles of `001` (DEC-PRD-22):

| Permission | Administrador | Gerente | Asesora de Ventas | Finanzas | Supervisor de Producción | Responsable de Calidad | Operario |
|---|---|---|---|---|---|---|---|
| `products.view` | yes | yes | yes | yes | yes | yes | no |
| `products.create` | yes | yes | yes | no | no | no | no |
| `products.update` | yes | yes | yes | no | no | no | no |
| `products.deactivate` | yes | yes | yes | no | no | no | no |
| `products.delete` | yes | no | no | no | no | no | no |
| `products.catalog` | yes | yes | yes | no | no | no | no |

**Coherence** (DEC-PRD-23, analogous to DEC-022 of `001` and DEC-CLI-32): `products.create`, `products.update`, `products.deactivate`, `products.delete` and `products.catalog` MUST require `products.view`. An assignment that does not comply MUST be rejected and the role MUST NOT change. `design.md` defines how this matrix is applied to an existing database, as in `002`.

#### Scenario: E-33 — Coherencia de permisos

Source citation: (§4, DEC-PRD-23)

- GIVEN a user who manages roles
- WHEN the user assigns `products.update` to a role without `products.view`
- THEN the assignment is rejected and the role does not change

### Requirement: PRD-001 — Categorías

A user with `products.catalog` SHALL be able to create, edit, order, deactivate and reactivate categories. Each product MUST belong to **one** category (DEC-PRD-18). An inactive category MUST NOT be shown in the portal. Categories MUST NOT be deleted; they are deactivated. The name of a category MUST be unique, case-insensitively (DEC-PRD-45).

#### Scenario: E-64 — Nombre repetido

Source citation: (PRD-001, PRD-003, PRD-007, PRD-010, DEC-PRD-45)

- GIVEN the category "Camisas", the product "Camisa corporativa", the detail location "Pechera" and the combo "Kit Oro antiderrame"
- WHEN the user tries to create another entity of the same type with the same name in different letter case (e.g., "camisas")
- THEN the backend rejects each creation with an error on the name

### Requirement: PRD-002 — Catálogo de atributos y valores

A user with `products.catalog` SHALL be able to create attributes and their values, edit them, order them, deactivate them and reactivate them (DEC-PRD-03).

- Each value MUST have a name, an order and an optional description (e.g., size 3XG with "40 a 60 kg").
- The name of an attribute MUST be unique. The name of a value MUST be unique within its attribute.
- Each attribute MUST indicate its presentation: **text**, **image** or **color** (DEC-PRD-30). The catalog MUST have at most one attribute with presentation "color": Ecolekua's palette, from which come the garment color, the colors offered in each fabric and the colors of the details (DEC-PRD-38).
- Each value of an attribute with presentation "color" MUST have, besides the name, a **reference tone** that the user chooses with a color picker; the system stores it as a hexadecimal code. The backend MUST reject a value without a tone or with an invalid tone. The tone is a visual reference: the real color is that of the fabric available under that name (DEC-PRD-17).
- **Attributes in use (DEC-PRD-51).** Deactivating an attribute MUST be rejected while any active product declares it, and the backend MUST indicate which ones. An inactive attribute MUST NOT be declared in a product, and a product that declares an inactive attribute MUST NOT be selectable. Changing the presentation or the special use of an attribute MUST be rejected while any product declares it.
- Attributes, values and detail locations MUST NOT be deleted; they are deactivated. An inactive value MUST NOT be offered in new selections, combinations and combos that use it as an axis stop being offered, and existing quotations and orders keep it.
- The name of a value MAY be edited to correct typos. The change MUST be reflected in all combinations that use it and MUST be audited. For a different fabric or color, a new value is created.
- An attribute MAY be marked as the **fabric attribute** (DEC-PRD-32). Each value of that attribute MUST have the list of **colors that Ecolekua offers** in that fabric, chosen among the active values of the color attribute. They are the colors offered even if the fabric has to be purchased, not only those in stock. The catalog MUST have at most one fabric attribute.
- **Special use (DEC-PRD-49).** An attribute MAY have a special use: **Tela**, **Talla** or **Género**, with at most one attribute of each use in the catalog. The former fabric mark is the use "Tela". The use "Talla" identifies the size of the stock article (PRD-009) and the use "Género" identifies which template applies when the cut changes by gender (PRD-020). The initial load assigns the uses to Tela, Talla and Género, and they can be edited in the catalog with `products.catalog`.
- After the initial load, the colors and the colors offered in each fabric are managed from the ERP with `products.catalog`: create a color, edit its name or tone, deactivate it, and add it to or remove it from a fabric's list. Removing a color from a fabric affects only new selections; existing quotations and orders keep it. Every change MUST be audited.
- The initial load creates the attributes Tela (fabric attribute), Modelo, Manga, Género and Talla with the values of the current list, and the attribute Color, with presentation "color", with the palette validated by Ecolekua and the colors offered in each fabric (PRD-018).

#### Scenario: E-24 — Desactivar un valor de eje en uso

Source citation: (PRD-002, PRD-004)

- GIVEN the value Microfibra, used by combination `110-4`
- WHEN it is deactivated
- THEN combination `110-4` stops resolving with PRD-011
- AND when it is reactivated it resolves again
- AND removing Microfibra from the product's admitted values is rejected while `110-4` uses it

#### Scenario: E-36 — Color sin tono

Source citation: (PRD-002)

- GIVEN the attribute Color, with presentation "color"
- WHEN the value "Azul marino" is created without choosing the tone, or with an invalid tone
- THEN the backend rejects the value
- AND with the tone chosen in the picker, the value is created and its hexadecimal code is stored

#### Scenario: E-45 — Un solo atributo de tela

Source citation: (PRD-002)

- GIVEN the attribute Tela marked as the fabric attribute
- WHEN the user tries to mark another attribute as the fabric attribute
- THEN the backend rejects the change

#### Scenario: E-46 — Gestionar los colores de una tela

Source citation: (PRD-002, PRD-017)

- GIVEN the fabric ALG-OXF PIMA, offered in azul marino, blanco and verde
- WHEN a user with `products.catalog` creates the color Gris perla with its tone, adds it to the fabric and removes verde
- THEN combination `110` resolves with Gris perla and returns an error with Verde
- AND the changes are audited

#### Scenario: E-58 — Un solo atributo de color

Source citation: (PRD-002, DEC-PRD-38)

- GIVEN the attribute Color, with presentation "color"
- WHEN the user tries to create another attribute with presentation "color" or to change to "color" the presentation of another attribute
- THEN the backend rejects the change

#### Scenario: E-69 — Atributo en uso

Source citation: (PRD-002, DEC-PRD-51)

- GIVEN the attribute Tela, declared by the active camisa corporativa
- WHEN it is attempted to deactivate it, remove its special use "Tela" or change its presentation
- THEN the backend rejects each change, indicates the products that declare it and the attribute does not change
- AND an inactive attribute cannot be declared in a product

### Requirement: PRD-003 — Registrar producto

A user with `products.create` SHALL register a product with **name**, **category**, **business line** and **supply mode**. The description and portal visibility MUST be optional. The product MUST be created **active** and the action MUST be audited. The name of a product MUST be unique across the whole catalog, case-insensitively (DEC-PRD-45).

#### Scenario: E-01 — Registrar un producto con datos válidos

Source citation: (PRD-003, PRD-017)

- GIVEN a user with `products.create` and an active category
- WHEN the user submits a valid name, category, business line and supply mode
- THEN the product is created in active status
- AND an audit entry is recorded with the created values

#### Scenario: E-02 — Rechazar un producto sin datos obligatorios

Source citation: (PRD-003)

- GIVEN a user with `products.create`
- WHEN the user submits the form without supply mode
- THEN the backend rejects the request with a validation error on that field
- AND no product is created

### Requirement: PRD-004 — Atributos del producto

A product SHALL declare which attributes it uses, with which role and which values it admits (DEC-PRD-03):

- **Axis**: defines the commercial combination and its code. Example: in the corporate shirt, fabric, model, sleeve and gender; in adult diapers, size.
- **Order**: chosen when quoting or ordering and does not change the code. Example: size and color in the corporate shirt.
- An attribute MUST appear only once in the product and with a single role.
- If the product declares the fabric attribute, it MUST declare it as an axis. The color attribute, when declared, MUST always be an order attribute. The backend MUST reject any other role (DEC-PRD-50).
- The product MUST define the **order of its axes** (e.g., Tela → Modelo → Manga → Género). It is the order of the quoter steps (PRD-019).
- Each declared attribute MUST admit at least one active value, except the color of a product that declares the fabric attribute, which MUST NOT store admitted values (DEC-PRD-35).
- Every declared attribute MUST be mandatory when selecting the product (PRD-011).
- Garment colors are a closed list (DEC-PRD-17):
  - if the product declares the fabric attribute, the available colors are those offered in the chosen fabric and the product has no list of its own: it declares the color without admitted values (DEC-PRD-35);
  - if the product carries color but does not declare the fabric attribute (e.g., caps), the product has its own list of admitted colors, marked by the team;
  - a product without color (e.g., full sublimation, diapers) MUST NOT declare the color attribute.
- **Custom color (DEC-PRD-34).** A product of mode "bajo pedido" that carries color MUST also admit the option **Personalizado**, at the end of the color list, unless the team disables it for that product. Products of other modes MUST NOT admit it.
  - It is for the customer who does not find a color to their liking among those offered. The customer indicates the desired tone with a color picker (mandatory) and an optional note with the color name as reference (maximum length in `design.md`).
  - It is not a palette color and does not belong to any fabric. It applies only to the garment color; the colors of the details keep coming from the palette (PRD-007).
  - A selection with custom color requires an advisor's attention: the order is not processed from the web and the WhatsApp button is enabled (§10 of the source spec).
- A product MAY have no attributes (e.g., corporate lanyard: the customer only chooses the quantity).
- While combinations of the product exist, it MUST NOT be possible to add an axis or change the role of an attribute (from order to axis or from axis to order). To restructure, a new product is created and the previous one is deactivated (DEC-PRD-41).
- It MUST NOT be possible to remove an attribute with axis role while combinations of the product exist, nor to remove an admitted value used by any combination (as an axis value or in the restriction of an order attribute, DEC-PRD-37) or combo component. The backend MUST reject the change and indicate what prevents it.

#### Scenario: E-05 — Declarar atributos con su rol

Source citation: (PRD-004)

- GIVEN the product "Camisa corporativa"
- WHEN it declares Tela, Modelo, Manga and Género as axes, and Talla and Color as order attributes; Tela, Modelo, Manga, Género and Talla with their admitted values and Color without admitted values
- THEN the product stores the six attributes with their role, and the admitted values of all except Color, whose colors come from the chosen fabric (DEC-PRD-35)

#### Scenario: E-06 — Atributo repetido en el producto

Source citation: (PRD-004)

- GIVEN a product with Talla as an order attribute
- WHEN the user tries to also declare Talla as an axis
- THEN the backend rejects the change with a validation error

#### Scenario: E-43 — Colores según la tela

Source citation: (PRD-004, PRD-011)

- GIVEN the fabric ALG-OXF PIMA, offered in azul marino, blanco and verde, and the active combination `110` with that fabric
- WHEN `110` is resolved with Blanco
- THEN the operation returns the code `110`
- AND if it is resolved with Rojo, the operation returns an error on the color

#### Scenario: E-44 — Colores de un producto sin tela

Source citation: (PRD-004, PRD-011)

- GIVEN the gorra en dryfit, which carries color but does not declare the fabric attribute, with the admitted colors Negro and Blanco
- WHEN it is resolved with Negro
- THEN the selection is valid
- AND with Azul marino the operation returns an error on the color

#### Scenario: E-49 — Color personalizado

Source citation: (PRD-004, PRD-011)

- GIVEN the camisa corporativa, of mode "bajo pedido", which admits custom color
- WHEN combination `110` is resolved with color Personalizado, tone #7A9A3B and the note "verde oliva corporativo"
- THEN the operation returns the code `110`, the selection with that tone and that note, and the "requiere asesora" flag
- AND without a tone, or with an invalid tone, it returns an error on the color

#### Scenario: E-50 — Color personalizado no admitido

Source citation: (PRD-004, PRD-011)

- GIVEN the gorra en dryfit, of mode "stock con mínimo", and a "bajo pedido" product with the option disabled
- WHEN either is resolved with color Personalizado
- THEN the operation returns an error on the color
- AND a custom color in a detail location is also rejected

#### Scenario: E-57 — Retirar del producto un valor usado en una restricción

Source citation: (PRD-004, DEC-PRD-37)

- GIVEN a product that admits sizes XS to 3XL and a combination that restricts Talla to XS to XL
- WHEN XS is removed from the product's admitted sizes
- THEN the backend rejects the change, indicates the combination that prevents it, and the product does not change

#### Scenario: E-60 — Cambiar la estructura de un producto con combinaciones

Source citation: (PRD-004, DEC-PRD-41)

- GIVEN the camisa corporativa with combinations and Talla as an order attribute
- WHEN the user tries to add a new axis, change Talla to axis or change Manga to an order attribute
- THEN the backend rejects each change, indicates that the product has combinations, and the product does not change

### Requirement: PRD-005 — Combinaciones comerciales

A user with `products.create` SHALL create the combinations of a product **explicitly**, one by one, as in the current list (DEC-PRD-04). The ERP MUST allow duplicating a combination to speed up loading.

- Each combination MUST have a **code** that is **text** (it keeps leading zeros and suffixes: `088-1`, `001RN`) and is **unique** across the whole catalog, including combo codes (DEC-PRD-01, DEC-PRD-02).
- It MUST have one or several values for each axis of the product, chosen among the admitted values. Several values on an axis mean options under the same code and price: `159-1` admits Tela Drill or Gabardina, and the customer chooses one (DEC-PRD-33).
- Optionally, it restricts the values of one or several order attributes to a subset of those admitted by the product. For example, a women's combination admits only sizes XS to XL. If an order attribute has no restriction, the combination admits all the values of the product. The restriction works the same as in combo components (PRD-010) and does not apply to the color of a product with fabric, which comes from the chosen fabric (DEC-PRD-35, DEC-PRD-36).
- Two active combinations of the same product MUST NOT coincide in any possible selection: if they share at least one value in all their axes, the backend MUST reject the save.
- A product without axes MUST have a single combination.
- An active product needs at least one active combination to be shown in the portal and accepted in quotations.

#### Scenario: E-07 — Crear una combinación válida

Source citation: (PRD-005)

- GIVEN the product "Camisa corporativa" with its axes declared
- WHEN combination `110` is created with ALG-OXF PIMA, Columbia especial, Manga corta and Caballero
- THEN the combination is created active with code `110`
- AND the descriptive name of the combination is "Camisa corporativa · ALG-OXF Pima · Columbia especial · Manga corta · Caballero"

#### Scenario: E-08 — Código repetido

Source citation: (PRD-005)

- GIVEN an existing combination with code `110`
- WHEN the user tries to create another combination, or a combo, with code `110`
- THEN the backend rejects the save with an error on the code
- AND the database guarantees uniqueness even if the validation is missing

#### Scenario: E-09 — Valores de eje repetidos

Source citation: (PRD-005)

- GIVEN combination `110`
- WHEN another combination of the same product is created with the same values in all axes and a different code
- THEN the backend rejects the save

#### Scenario: E-10 — Combinación incompleta o con valor no admitido

Source citation: (PRD-005)

- GIVEN a product with four axes
- WHEN a combination is created without a Género value, or with a fabric not admitted by the product
- THEN the backend rejects the save with an error on the corresponding field

#### Scenario: E-11 — Código con ceros a la izquierda

Source citation: (PRD-005)

- GIVEN a user with `products.create`
- WHEN the user creates combinations `088-1` and `001RN`
- THEN the codes are stored and shown exactly like that, without losing zeros or suffixes

#### Scenario: E-47 — Combinación con telas alternativas

Source citation: (PRD-005, PRD-011)

- GIVEN combination `159-1` of the kimono completo with Tela Drill or Gabardina and Género Caballero
- WHEN it is resolved with Gabardina
- THEN the operation returns the code `159-1` and the normalized selection with Tela Gabardina
- AND the available colors are those offered in Gabardina

#### Scenario: E-48 — Combinaciones superpuestas

Source citation: (PRD-005)

- GIVEN combination `159-1` with Tela Drill or Gabardina and Género Caballero
- WHEN another combination of the same product is created with Tela Drill and Género Caballero
- THEN the backend rejects the save because both coincide in Drill and Caballero

#### Scenario: E-54 — Combinación que restringe las tallas

Source citation: (PRD-005, PRD-011, PRD-019)

- GIVEN a product that admits sizes XS to 3XL and a women's combination that restricts Talla to XS to XL
- WHEN the order options are requested with the axes of that combination complete
- THEN the operation returns only sizes XS to XL
- AND resolving that combination with Talla 2XL is rejected with an error on the size

#### Scenario: E-55 — Combinación sin restricción

Source citation: (PRD-005, PRD-019)

- GIVEN a product that admits sizes XS to 3XL and a combination without a Talla restriction
- WHEN the order options are requested with the axes of that combination complete
- THEN the operation returns sizes XS to 3XL

#### Scenario: E-56 — Restricción fuera de lo admitido por el producto

Source citation: (PRD-005)

- GIVEN a product that admits sizes S to XL
- WHEN a combination that restricts Talla to M and 2XL is saved
- THEN the backend rejects the save with an error on the size and the combination does not change

#### Scenario: E-59 — Reactivar una combinación superpuesta

Source citation: (PRD-005, PRD-013, DEC-PRD-40)

- GIVEN the inactive combination `110-4` (Microfibra, Dama) and an active combination of the same product with the same axis values
- WHEN `110-4` is reactivated
- THEN the backend rejects the reactivation, indicates the combination it coincides with, and `110-4` remains inactive

### Requirement: PRD-007 — Ubicaciones de detalle

A product MAY declare the detail locations it admits (pechera, orilla de pechera, orilla de mangas, pie de cuello). In each location, the customer chooses a color among the active values of the attribute with presentation "color" when quoting or ordering.

- The name of a detail location MUST be unique, case-insensitively (DEC-PRD-45).
- Details MUST be optional when selecting the product.
- Details MUST NOT affect the price and are a specification for production (DEC-PRD-07).
- Buttons are transparent and are not chosen (business rules §26).

#### Scenario: E-19 — Color de detalle inválido

Source citation: (PRD-007, PRD-011)

- GIVEN a product that admits the location Pechera
- WHEN it is resolved with Pechera and an inactive color, or with a location the product does not admit
- THEN the operation returns a validation error

### Requirement: PRD-008 — Personalizaciones

Customizations are added to the garment or included in certain combinations (DEC-PRD-08).

- A product MAY declare which products of mode **servicio** it admits as a customization added to the garment (small or large embroidery, vinyl, simple sublimation).
- A combination MAY declare customizations **included** in its price. For example, `119` "…/BORD/SUBLSENC", `158-4` "(1 BORDADO)" or `139-1` "C/VINIL". An included customization MUST NOT be charged again.
- Included and admitted customizations are independent (DEC-PRD-47): "included" describes what the combination's price covers and "admitted" is what can be added as an extra. For example, `139-1` (mono escolar "C/VINIL") includes vinyl without the product offering additional vinyl.
- Quantity, price and the relationship with the order are defined by `004` and `006`.
- An active service visible in the portal is also sold on its own (e.g., sublimation on a garment of the customer) (DEC-PRD-28).

#### Scenario: E-34 — Personalización no admitida

Source citation: (PRD-008, PRD-011)

- GIVEN a product that does not admit vinyl
- WHEN a selection with vinyl is resolved
- THEN the operation returns an error on the customization

#### Scenario: E-67 — Personalización incluida no admitida como extra

Source citation: (PRD-008, DEC-PRD-47)

- GIVEN the mono escolar, which does not admit vinyl as an additional customization
- WHEN combination `139-1` is saved with vinyl as an included customization
- THEN the backend accepts the combination
- AND when `139-1` is resolved, vinyl is not offered as an additional customization

### Requirement: PRD-009 — Modo de abastecimiento y stock mínimo

Each product MUST have one of these modes (DEC-PRD-09):

| Mode | Meaning | Examples (Anexo A) |
|---|---|---|
| Bajo pedido | Made or produced for each order. No stock | Camisas, chemises, kimonos, lanyards |
| Stock con mínimo | Sold from stock and replenished. Has minimum stock | Pañales de adulto, pantalones industriales, tazas, mouse pads, gorras |
| Stock agotable | Sold only from stock and not replenished. No minimum stock | Pañal ecológico infantil |
| Servicio | Not a garment and has no stock of its own | Bordados, vinil, sublimación, planchado |

- The **minimum stock** MUST exist only in mode "stock con mínimo". It is an integer >= 0 and applies **per stock article**, that is, per combination plus the size when the product has it as an order attribute (DEC-PRD-10).
- **Default minimum and exceptions (DEC-PRD-46).** In mode "stock con mínimo", the product MUST have a **mandatory default minimum**. When registering it, the form proposes 6. Each stock article inherits that value and MAY have an **optional own value** that replaces it.
  - When the product changes to another mode, the default minimum and the own values MUST be deleted, with audit of the previous values.
  - When returning to mode "stock con mínimo", the default minimum MUST be requested again. Previous own values are not recovered.
- Initial value: **6** for finished products and **2 per size** for industrial pants (business rules §27).
- The backend MUST reject a minimum stock in products of other modes.
- Stock levels and movements belong to `008`. How the minimum is applied in the portal and in orders belongs to `005` / `006` (DEC-PRD-11).

#### Scenario: E-20 — Stock mínimo según el modo

Source citation: (PRD-009)

- GIVEN a product of mode "bajo pedido" or "stock agotable"
- WHEN the user tries to save a minimum stock
- THEN the backend rejects the value
- AND in a product of mode "stock con mínimo" the value 6 is accepted

#### Scenario: E-65 — Mínimo por defecto obligatorio y valor propio

Source citation: (PRD-009, DEC-PRD-46)

- GIVEN a product of mode "stock con mínimo" with Talla as an order attribute
- WHEN it is saved without a default minimum
- THEN the backend rejects the save with an error on the minimum
- AND with default minimum 2, size 38 of a combination with own value 4 has minimum 4 and the other sizes have minimum 2

#### Scenario: E-66 — Cambio de modo borra los mínimos

Source citation: (PRD-009, PRD-017, DEC-PRD-46)

- GIVEN a product of mode "stock con mínimo" with default minimum 6 and an own value on an article
- WHEN it is changed to mode "bajo pedido"
- THEN the default minimum and the own value are deleted, and the audit records the previous values
- AND when returning to "stock con mínimo" a new default minimum is required and there are no own values

#### Scenario: E-71 — Quitar una talla con mínimo propio

Source citation: (PRD-009, PRD-012, DEC-PRD-52)

- GIVEN a product of mode "stock con mínimo" with an own minimum 4 in size 38 of a combination
- WHEN size 38 is removed from the admitted values of the product
- THEN the change is saved, the own minimum of size 38 is deleted and the audit records the previous value

### Requirement: PRD-010 — Combos

A user with `products.create` SHALL register combos of the diaper line (DEC-PRD-12), for example "Kit Oro antiderrame": 2 diapers, 3 absorbents and 1 bed protector.

- Each component MUST indicate a **product**, the **quantity** (integer >= 1) and, optionally, which values it admits in each attribute of the product, whether axis or order (DEC-PRD-44). In an order attribute, the chosen value MUST respect both the component's restriction and that of the resolved combination, if it has one (DEC-PRD-36). The user marks them when registering the combo. For example, when creating "Kit juvenil", the user marks only size 2XG for the absorbent. If no restriction is marked on an axis, all its active values are admitted. If an axis is left with a single admitted value, the customer chooses nothing on that axis and the system applies it directly.
- When quoting or ordering, the customer chooses the values of each component within what is admitted. The choice applies to all units of the component (DEC-PRD-12).
- Only components of products that are not of mode "servicio" are admitted.
- Combos MUST always belong to the diaper line: they admit only components of products of that line and the backend MUST reject any other. The combo does not store the line; it is implicit (DEC-PRD-43).
- The combo MUST have its own code, assigned by Ecolekua (DEC-PRD-14). Its name MUST be unique, case-insensitively (DEC-PRD-45).
- A combo with any component that cannot be resolved to an active combination (inactive product or all its admitted values inactive) MUST NOT be offered.
- The price of each item inside the combo and the total belong to `004` (§10 of the source spec).

#### Scenario: E-21 — Crear un combo

Source citation: (PRD-010)

- GIVEN the active products Pañal antiderrame (size as axis: 3XG, 4XG, 5XG), Absorbente and Protector de cama
- WHEN "Kit Oro antiderrame" is created with 2 diapers (sizes 3XG-5XG), 3 absorbents and 1 protector
- THEN the combo is created active with its three components

#### Scenario: E-22 — Componente de combo inválido

Source citation: (PRD-010)

- GIVEN a user with `products.create`
- WHEN the user creates a combo with a component of quantity 0, of mode "servicio" or with a value not admitted by the product
- THEN the backend rejects the combo

#### Scenario: E-62 — Componente de otra línea

Source citation: (PRD-010, DEC-PRD-43)

- GIVEN a diaper combo
- WHEN the user tries to add as a component a product of the uniformes line
- THEN the backend rejects the component with an error on the product and the combo does not change

#### Scenario: E-63 — Componente que restringe un atributo de pedido

Source citation: (PRD-010, PRD-011, DEC-PRD-44)

- GIVEN a combo whose component "protector de cama" restricts Color to Blanco
- WHEN the order options of that component are requested and it is resolved with Color Azul
- THEN the options return only Blanco
- AND the resolution with Azul is rejected with an error on the component's color

### Requirement: PRD-011 — Resolver una selección

The backend SHALL offer a single operation that validates a selection and returns the sellable combination. It is used by the ERP (`004`, `006`) and the portal (`005`), and no frontend reimplements it (AGENTS §7.1).

**Input**: product or combo, axis values, order attribute values, detail colors and customizations.

**Output**: combination code, the normalized selection, with the name and reference tone of each color (or the tone and note of the custom color), and a **requires advisor** flag when the color is custom. If the selection is not valid, it MUST return errors per field.

Rules:

1. The product (or the combo) is active, and so is its category.
2. Each axis and each order attribute of the product has a value, which is admitted and active. The color, in addition, is one of those offered in the chosen fabric or, if the product does not declare the fabric attribute, one of those admitted by the product. If the color is **Personalizado**, the product MUST admit it and the tone MUST be a valid color code.
3. There is **exactly one** active combination whose axis values include the chosen ones. The normalized selection stores the chosen value in each axis, even if the combination admits several. If that combination restricts an order attribute, the chosen value is within the restriction (DEC-PRD-36).
4. Each chosen detail location is admitted and its color is an active value of the attribute with presentation "color".
5. In a combo, each component is resolved with these same rules and its values respect the admitted subset.

This operation MUST NOT query prices or stock.

#### Scenario: E-14 — Resolver una selección válida

Source citation: (PRD-011)

- GIVEN the active combination `110-1` (ALG-OXF PIMA, Columbia especial, Manga corta, Dama) with Talla M and Color Blanco admitted
- WHEN that axis selection is resolved with Talla M and Color Blanco
- THEN the operation returns the code `110-1` and the normalized selection

#### Scenario: E-15 — Resolver un pantalón por talla

Source citation: (PRD-011)

- GIVEN combination `184` with admitted sizes 28 to 44
- WHEN it is resolved with Talla 38
- THEN the operation returns the code `184` and the normalized selection with Talla 38
- AND if it is resolved with Talla 46, the operation returns an error on the size

#### Scenario: E-16 — Resolver una selección inválida

Source citation: (PRD-011)

- GIVEN combination `110-1`
- WHEN it is resolved without Talla, or with a non-admitted color
- THEN the operation returns an error on that field and no combination

#### Scenario: E-17 — No resolver lo inactivo

Source citation: (PRD-011, PRD-013)

- GIVEN combination `110-1` inactive, or its product inactive
- WHEN a selection that matches it is resolved
- THEN the operation returns an error indicating the selection is not available

#### Scenario: E-23 — Resolver un combo

Source citation: (PRD-011, PRD-010)

- GIVEN the combo of E-21
- WHEN it is resolved with size 4XG for the diaper
- THEN each component resolves to its combination (`11` for the diaper)
- AND if 2XG is chosen for the diaper, the operation returns an error on that component

### Requirement: PRD-012 — Editar producto, combinación y combo

A user with `products.update` SHALL modify products, combinations, combos, details, customizations and stock parameters with the same validations as creation. Every change MUST be audited with previous and new values.

The code and axis values of a combination with history (quotations, orders or stock movements) MUST NOT be modified: a new combination is created and the previous one is deactivated (DEC-PRD-21). In `003` no history exists yet; each spec that creates history adds this condition (§10 of the source spec).

Edits that affect other records (DEC-PRD-52):

- Changing the business line of a product that is a combo component, or changing it to mode "servicio", MUST be rejected and MUST indicate the combos that use it.
- Taking a product out of mode "servicio" when other products admit it or other combinations include it as a customization MUST be rejected and MUST indicate which ones use it. The same applies to its deletion (PRD-014).
- Removing a size from the admitted values of a product MUST delete the own minimums of that size (PRD-009), with audit of the previous values. The block by stock is added by `008` (§10 of the source spec).

#### Scenario: E-68 — Editar producto y combinación

Source citation: (PRD-012, PRD-017)

- GIVEN the product "Camisa corporativa" and its combination `110-1`, without history
- WHEN a user with `products.update` changes the product name and the description of `110-1`
- THEN the changes are saved and the audit records, for each one, the previous and new values
- AND an edit that fails the creation validations (e.g., a product name that already exists) is rejected with a field error and nothing changes

#### Scenario: E-70 — Edición que rompería un combo o una personalización

Source citation: (PRD-012, PRD-014, DEC-PRD-52)

- GIVEN a diaper that is a component of a combo and the service "Bordado pequeño", admitted by the camisa corporativa
- WHEN it is attempted to move the diaper to the uniformes line, take "Bordado pequeño" out of mode "servicio" or delete it
- THEN the backend rejects each change, indicates the combo or the products that use them and nothing changes

### Requirement: PRD-013 — Desactivar y reactivar

A user with `products.deactivate` SHALL be able to deactivate and reactivate products, combinations and combos.

- Deactivating MUST require confirmation with `ConfirmDialog` (design system §7.10). Reactivating MUST NOT require it.
- Inactive items MUST NOT be offered in the portal nor accepted in new quotations or orders. Items already in progress follow their flow (`004` and `006` apply).
- Deactivating a product takes all its combinations out of the offer without changing the own status of each one. When it is reactivated, each combination returns to its previous status.
- Reactivating a combination that overlaps with another active combination of the same product (PRD-005) MUST be rejected with an error that indicates the combination it coincides with (DEC-PRD-40).
- Changing to the status already held MUST produce no changes and no audit, as in `001` and `002`.

#### Scenario: E-25 — Desactivar y reactivar un producto

Source citation: (PRD-013, PRD-017)

- GIVEN an active product with one active combination and one inactive combination
- WHEN it is deactivated and then reactivated
- THEN while it is inactive, none of its combinations resolves
- AND when it is reactivated, each combination keeps its own status
- AND both actions are audited

#### Scenario: E-26 — Desactivar sin permiso

Source citation: (PRD-013, PRD-016)

- GIVEN a user without `products.deactivate`
- WHEN the user tries to deactivate a product
- THEN the backend responds 403 and the product does not change

### Requirement: PRD-014 — Eliminación restringida

A user with `products.delete` SHALL be able to delete a product, a combination or a combo **only if they have no history**. The intended use is to correct records created by mistake or test data; what has history is deactivated (AGENTS §7.9).

- Deleting a product MUST delete its attributes, details, customizations and combinations in the same transaction, provided no combination has history or is part of a combo.
- A product or a combination that is part of a combo MUST NOT be deleted. A combination is part of a combo when its product is a component of the combo and the combination shares at least one value in each axis with the values the component admits, that is, when the customer could choose it in that combo (DEC-PRD-42).
- The deletion MUST be audited with a copy of the deleted values.
- In `003` no history records exist yet. `004`, `006` and `008` add their blocking condition and their scenario (§10 of the source spec), with restrict-on-delete foreign keys.

#### Scenario: E-27 — Eliminar sin historial

Source citation: (PRD-014, PRD-017)

- GIVEN a user with `products.delete` and a product without history that is not part of any combo
- WHEN the user deletes it
- THEN the product, its attributes, details and combinations are deleted in one transaction
- AND the audit keeps a copy of the deleted values

#### Scenario: E-28 — Eliminación bloqueada por un combo

Source citation: (PRD-014)

- GIVEN a product that is part of a combo
- WHEN deletion is attempted
- THEN the backend rejects it, indicates the combo and suggests deactivating

#### Scenario: E-29 — Eliminación bloqueada por historial ⏳

Source citation: (PRD-014)

Deferred (⏳): this scenario cannot be tested in `003`; it is tested in `004`, `006` and `008` (§10 of the source spec). No history entity exists in `003`.

- GIVEN a combination with history
- WHEN deletion is attempted
- THEN the backend rejects it and suggests deactivating it

#### Scenario: E-61 — Eliminar una combinación según el componente del combo

Source citation: (PRD-014, DEC-PRD-42)

- GIVEN a combo whose component "absorbente" admits only size 2XG, and two combinations of the absorbent without history: one with size 2XG and one with size 3XG
- WHEN deletion of each is attempted
- THEN the backend rejects deleting the one with size 2XG and indicates the combo
- AND it deletes the one with size 3XG

### Requirement: PRD-015 — Listado, búsqueda y ficha

A user with `products.view` SHALL be able to:

- list products with the views **Activos**, **Inactivos** and **Todos** (`SegmentedTabs`), with counts calculated by the backend;
- search by product name and by combination **code**; searching a code leads to the product that contains it;
- filter by category, business line and supply mode;
- consult the product detail page, with its data, attributes, combinations, details, customizations and stock parameters;
- list and consult combos;
- see the list paginated in the backend.

#### Scenario: E-30 — Buscar por código

Source citation: (PRD-015)

- GIVEN a user with `products.view`
- WHEN the user searches `147-12`
- THEN the list shows the product that contains that combination

### Requirement: PRD-016 — Autorización en backend

Every route and action of the catalog SHALL validate the corresponding permission with Policies in the backend (AGENTS §7.2).

#### Scenario: E-03 — Registrar sin permiso

Source citation: (PRD-016)

- GIVEN an authenticated user without `products.create`
- WHEN the user sends a product creation request
- THEN the backend responds 403 and no product is created

#### Scenario: E-04 — Consultar sin permiso

Source citation: (PRD-016)

- GIVEN an authenticated user without `products.view`
- WHEN the user requests the product list
- THEN the backend responds 403

### Requirement: PRD-017 — Auditoría

The system SHALL audit the creation (including import), edition, deactivation, reactivation and deletion of categories, attributes, values, products, combinations and combos, with user, action, affected record, date and time, IP, and before and after values (AGENTS §7.8).

Source note: PRD-017 has no scenario of its own in the source; it is exercised by scenarios E-01, E-25, E-27, E-31, E-35, E-46 and E-66, which cite it and are placed under their first cited requirement.

### Requirement: PRD-018 — Carga inicial del catálogo

The technical team SHALL load the existing catalog once through a console command, with no screen in the ERP (DEC-PRD-24), following the CLI-018 pattern.

- **Source**: a UTF-8 CSV prepared from the sheets "Uniformes" and "Pañales para adultos" and validated by Ecolekua. Each row is a combination and indicates product, category, line, mode, minimum stock, code, axis values, admitted values of the order attributes and the original description from the list. When the order values of a row are a subset of those of the product, they are loaded as a restriction of that combination (DEC-PRD-36). The minimum stock of each row is the product's default minimum (DEC-PRD-46): if the rows of one product carry different values, the load MUST be rejected with row, field and reason. Own values per article are loaded later from the ERP (DEC-PRD-48). Combos go in a second file with their components. A third file brings the color palette (name and tone) and the colors offered in each fabric, prepared from the fabric and color cleanup file validated by Ecolekua. The columns are defined in `design.md`.
- **Normalization** (Anexo B): fabric and value names are unified, and the corrections confirmed by Ecolekua are applied. Codes are loaded as they are.
- Each record MUST go through the same validations as creation (PRD-002 to PRD-010).
- **All or nothing**: the command MUST first validate all rows, including codes repeated within the file. If any fails, it MUST import nothing and deliver a report with row, field and reason. If all are valid, it MUST import in a single transaction.
- The command MUST require an active internal user to be indicated as author. Each record MUST be audited as a creation marked as import.
- Prices and costs MUST NOT be imported. `004` loads them using the code as reference (§10 of the source spec).
- Before running it, Ecolekua validates the data: Anexo A classification, Anexo B normalizations, fabric and color file (palette with tones and colors per fabric) and Los Cedros codes. These are data, not design decisions: they do not block Design or implementation.
- The load MUST NOT run while there are unresolved duplicate codes (DEC-PRD-02), colors without a tone or fabrics of the color file that do not exist in the catalog.

#### Scenario: E-31 — Importar un archivo válido

Source citation: (PRD-018, PRD-017)

- GIVEN a valid CSV and an active internal user as author
- WHEN the command is run
- THEN all categories, attributes, products, combinations, combos, colors and colors per fabric are created in one transaction
- AND each creation is audited as an import

#### Scenario: E-32 — Importación con errores

Source citation: (PRD-018)

- GIVEN a CSV with an invalid row, with a repeated code, with a color without a tone or with a fabric that does not exist in the catalog
- WHEN the command is run
- THEN nothing is imported
- AND the report indicates row, field and reason

### Requirement: PRD-019 — Opciones disponibles para una selección parcial

The backend SHALL offer a side-effect-free query operation that the ERP quoter (`004`) and the portal quoter (`005`) use to show at each step only the options that exist (DEC-PRD-31). No frontend computes these options on its own (AGENTS §7.1).

- **Input**: product (or combo and component) and the axis values already chosen, in the product's axis order.
- **While axes are missing**: it MUST return the active values of the next axis that lead to at least one active combination compatible with what was already chosen. A combination with several values on an axis (DEC-PRD-33) contributes all of them. In a combo component, only the values admitted by the component are returned, both in axes and in order options (DEC-PRD-44).
- **With all axes chosen**: it MUST return the combination code and the order options:
  - colors: those offered in the chosen fabric or, if the product does not declare fabric, those admitted by the product, or by the combination if it restricts them; plus the option **Personalizado** if the product admits it (PRD-004);
  - admitted values of the other order attributes (e.g., sizes), by the product or by the combination if it restricts them (DEC-PRD-36);
  - admitted detail locations, with the palette colors;
  - admitted customizations.
- Each option MUST include what is needed to display it: name, order, description, image, reference tone and SVG layer (PRD-020).
- It uses the same data and criteria as PRD-011, which remains the final validation. It MUST NOT query prices or stock.

#### Scenario: E-51 — Opciones del siguiente eje

Source citation: (PRD-019)

- GIVEN the camisa corporativa with axis order Tela → Modelo → Manga → Género, where the fabric Algodón Egipto exists only in combination `147-12` (Columbia especial, manga larga, dama)
- WHEN the options are requested with Tela = Algodón Egipto
- THEN the next axis, Modelo, offers only Columbia especial
- AND with Modelo = Columbia especial, the axis Manga offers only Manga larga

#### Scenario: E-52 — Opciones de pedido con los ejes completos

Source citation: (PRD-019)

- GIVEN the active combination `110` (ALG-OXF PIMA), whose fabric is offered in azul marino, blanco and verde, in a product that admits custom color
- WHEN the options are requested with all axes of `110`
- THEN the operation returns the code `110`, the colors Azul marino, Blanco and Verde with their tone, the option Personalizado, the admitted sizes and the admitted detail locations

#### Scenario: E-53 — Lo inactivo no se ofrece

Source citation: (PRD-019)

- GIVEN the camisa corporativa with combination `110-4` (Microfibra) inactive and no other active combination with Microfibra
- WHEN the options of the Tela axis are requested
- THEN Microfibra does not appear among the options

### Requirement: PRD-020 — Recursos visuales

A user with `products.catalog` SHALL manage the images and layers of values and detail locations. A user with `products.update` SHALL manage the image and templates of the product.

**Images (DEC-PRD-20)**

- The product MAY have an optional main image.
- Each attribute value MAY have an optional image shown in its option: the texture of a fabric, the icon of a sleeve or that of a gender.
- Each detail location MAY have an optional image with a drawing that marks the zone.
- A value with presentation "color" MUST be shown as a color swatch with its reference tone and name (PRD-002). If it also has an image, the image is shown.
- A value without an image MUST be shown as text.
- Admitted formats: JPG, PNG and WebP. The maximum size and the optimized versions generated by the backend are defined in `design.md`.
- Replacing or removing an image MUST be audited.

**Visual templates (DEC-PRD-29)**

- A product MAY have a general SVG template or, if its cut changes by gender, one template for each Género value it admits. When there are templates per gender, the preview uses the one of the chosen gender.
- An attribute value and a detail location MAY have an SVG layer assigned (e.g., Manga corta → `manga-corta`; Orilla de mangas → `orilla-mangas`).
- When uploading a template, the backend MUST:
  1. **sanitize** it: remove scripts, event attributes (`on…`), `foreignObject` and references to external files or sites (DT-03);
  2. detect groups with `id` and store them as layers;
  3. require the `cuerpo` layer;
  4. require the layer of each value admitted by the product that has an assigned layer and the layer of each admitted detail location. If any is missing, it MUST reject the template and indicate which are missing.
- A file MUST be rejected if it is not a valid SVG, exceeds the maximum size defined in `design.md`, or is associated with a gender the product does not admit.
- If a value or a location whose layer does not exist in its templates is added to the product, the backend MUST reject the change until a template that includes it is uploaded.
- Assigning or changing from the catalog the layer of a value or of a location admitted by products with a template MUST be rejected if any of those templates lacks the layer, and the backend MUST indicate the affected products (DEC-PRD-53).

**Preview** (drawn by `005`; the ERP shows it when uploading a template)

- The reference tone of the chosen color, or the tone indicated in a custom color, paints the `cuerpo` layer. While the customer has not chosen a color, the template is shown with its original colors.
- In an attribute whose values have a layer, the layer of the chosen value is shown and those of the other values are hidden (e.g., Manga corta shows `manga-corta` and hides `manga-larga`).
- Each detail location with a chosen color paints its layer with the tone of that color. Without a chosen color, the layer takes the body color.
- The `sombras` layer, if it exists, is drawn on top of all and is not painted.
- The fabric is not represented in the preview; it is shown in the thumbnail of its option.
- If the product has no template, its main image and the thumbnails of the options are shown.

#### Scenario: E-35 — Imágenes del producto y de las opciones

Source citation: (PRD-020, PRD-017)

- GIVEN a user with `products.update` and `products.catalog`
- WHEN the user uploads a JPG image for the camisa corporativa and another for the fabric value ALG-OXF PIMA
- THEN each image is associated with its product or value
- AND a PDF file or one that exceeds the maximum size is rejected
- AND the changes are audited

#### Scenario: E-37 — Subir una plantilla válida

Source citation: (PRD-020)

- GIVEN the camisa corporativa, which admits Manga corta (layer `manga-corta`), Manga larga (layer `manga-larga`) and the location Orilla de mangas (layer `orilla-mangas`)
- WHEN an SVG with the layers `cuerpo`, `manga-corta`, `manga-larga`, `orilla-mangas` and `sombras` is uploaded
- THEN the template is stored with those five layers detected

#### Scenario: E-38 — Plantilla incompleta

Source citation: (PRD-020)

- GIVEN the product of E-37
- WHEN an SVG without the `cuerpo` layer, or without the `manga-larga` layer, is uploaded
- THEN the backend rejects the template and indicates the missing layers

#### Scenario: E-39 — Plantilla con código peligroso

Source citation: (PRD-020)

- GIVEN a valid SVG that also contains a `<script>` and an `onclick` attribute
- WHEN it is uploaded as a template
- THEN it is stored without the script and without the event attribute
- AND the layers are detected on the sanitized file

#### Scenario: E-40 — Valor nuevo sin capa en la plantilla

Source citation: (PRD-020, PRD-004)

- GIVEN the camisa corporativa with the template of E-37
- WHEN a Manga value with the layer `manga-tres-cuartos`, which does not exist in the template, is added to its admitted values
- THEN the backend rejects the change and indicates the missing layer

#### Scenario: E-41 — Plantillas por género

Source citation: (PRD-020)

- GIVEN the camisa corporativa with a template for Caballero and another for Dama
- WHEN the template of combination `110-1` (Dama) is requested
- THEN the Dama template is obtained
- AND uploading a template associated with a gender the product does not admit is rejected

#### Scenario: E-42 — Recursos visuales sin permiso

Source citation: (PRD-020, PRD-016)

- GIVEN a user without `products.update`
- WHEN the user tries to upload an image or a product template
- THEN the backend responds 403

#### Scenario: E-72 — Cambiar la capa de un valor en uso

Source citation: (PRD-020, DEC-PRD-53)

- GIVEN the value "Manga 3/4" with the layer `manga-3-4`, admitted by a product whose template has that layer but not `manga-tres-cuartos`
- WHEN the layer of "Manga 3/4" is changed in the catalog to `manga-tres-cuartos`
- THEN the backend rejects the change, indicates the affected product and the value does not change

## Interface constraints (ERP, source §8)

`docs/ui/design-system.md` applies in full. Specific points of this capability:

| Screen | Components and rules |
|---|---|
| Product list | `DataTable`, `SegmentedTabs` for status views, search with `AppInput` (name or code), filters with `AppSelect` (category, line, mode), `StatusBadge` (active -> `done`, inactive -> `neutral`) |
| Product detail | Sections in `AppCard`: general data, attributes (role, values and axis order), combinations (table with code, axis values and status), details, customizations, stock and images. In "Imágenes": main image and templates (general or per gender); when uploading a template the preview and detected layers are shown. "Admite color personalizado" indicator on "bajo pedido" products with color |
| Combination editor | One multiple selection per axis, limited to admitted values; code as text; "Duplicar" action |
| Attribute catalog | List of attributes and orderable values, with status and presentation. Per value: reference tone chosen with a color picker (with swatch), image and SVG layer. On the fabric attribute, each value shows and allows editing its offered colors. Detail locations with image and layer. Visible with `products.catalog` |
| Categories | Orderable list with status |
| Combos | List and editor of components: product, quantity and admitted values per axis |
| Deactivate / Delete | `ConfirmDialog` variant `danger`. If the backend rejects the deletion, the reason is shown and deactivating is offered |

- Catalog management happens mostly on desktop, but screens remain mobile-first (constitution §14) and MUST be consultable on the phone.
- Touch targets of 44 px or more, no `dark:` classes and no hexadecimal values in styling code. Reference tones of colors are catalog data and are applied as inline style on swatches; they do not contradict this rule. Visible texts in Spanish; code, routes and identifiers in English.
