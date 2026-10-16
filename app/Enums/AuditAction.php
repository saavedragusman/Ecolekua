<?php

namespace App\Enums;

/**
 * Audited events of spec 001 (FND-022), spec 002 (CLI-016), spec 003 (PRD-017) and spec 004 (COT-023). The backed value is stored in `audit_logs.action`.
 */
enum AuditAction: string
{
    case LoginSucceeded = 'auth.login_succeeded';
    case LoginFailed = 'auth.login_failed';
    case Lockout = 'auth.lockout';
    case Logout = 'auth.logout';
    case PasswordChanged = 'auth.password_changed';
    case UserCreated = 'users.created';
    case UserUpdated = 'users.updated';
    case UserActivated = 'users.activated';
    case UserDeactivated = 'users.deactivated';
    case UserPasswordReset = 'users.password_reset';
    case UserRolesAssigned = 'users.roles_assigned';
    case UserRolesRemoved = 'users.roles_removed';
    case RoleCreated = 'roles.created';
    case RoleUpdated = 'roles.updated';
    case RoleDeleted = 'roles.deleted';
    case RolePermissionsGranted = 'roles.permissions_granted';
    case RolePermissionsRevoked = 'roles.permissions_revoked';
    case AuthorizationDenied = 'authorization.denied';
    case CustomerCreated = 'customers.created';
    case CustomerUpdated = 'customers.updated';
    case CustomerDeactivated = 'customers.deactivated';
    case CustomerActivated = 'customers.activated';
    case CustomerDeleted = 'customers.deleted';
    case CustomerAdvisorAssigned = 'customers.advisor_assigned';
    case CatalogCreated = 'catalog.created';
    case CatalogUpdated = 'catalog.updated';
    case CatalogDeactivated = 'catalog.deactivated';
    case CatalogActivated = 'catalog.activated';
    case CatalogFabricColorsUpdated = 'catalog.fabric_colors_updated';
    case ProductCreated = 'products.created';
    case ProductUpdated = 'products.updated';
    case ProductDeactivated = 'products.deactivated';
    case ProductActivated = 'products.activated';
    case ProductDeleted = 'products.deleted';
    case ProductAttributesUpdated = 'products.attributes_updated';
    case ProductStockMinimumsUpdated = 'products.stock_minimums_updated';
    case ProductTemplateUploaded = 'products.template_uploaded';
    case ProductTemplateRemoved = 'products.template_removed';
    case CombinationCreated = 'products.combination_created';
    case CombinationUpdated = 'products.combination_updated';
    case CombinationDeactivated = 'products.combination_deactivated';
    case CombinationActivated = 'products.combination_activated';
    case CombinationDeleted = 'products.combination_deleted';
    case ComboCreated = 'products.combo_created';
    case ComboUpdated = 'products.combo_updated';
    case ComboDeactivated = 'products.combo_deactivated';
    case ComboActivated = 'products.combo_activated';
    case ComboDeleted = 'products.combo_deleted';
    case PriceSchemeCreated = 'pricing.scheme_created';
    case PriceSchemeUpdated = 'pricing.scheme_updated';
    case PriceSchemeDeactivated = 'pricing.scheme_deactivated';
    case PriceSchemeActivated = 'pricing.scheme_activated';
    case ProductSchemeAssigned = 'pricing.product_scheme_assigned';
    case PriceCreated = 'pricing.price_created';
    case PriceUpdated = 'pricing.price_updated';
    case PriceDeleted = 'pricing.price_deleted';
    case SizeGroupsUpdated = 'pricing.size_groups_updated';
    case ComboItemPriceCreated = 'pricing.combo_item_price_created';
    case ComboItemPriceUpdated = 'pricing.combo_item_price_updated';
    case ComboItemPriceDeleted = 'pricing.combo_item_price_deleted';
    case PricingParametersUpdated = 'pricing.parameters_updated';
    case ExchangeRateRecorded = 'pricing.exchange_rate_recorded';
    case PriceImportApplied = 'pricing.import_applied';
    case PriceImportDiscarded = 'pricing.import_discarded';
    case QuotationCreated = 'quotations.created';
    case QuotationIssued = 'quotations.issued';
    case QuotationDuplicated = 'quotations.duplicated';
    case QuotationCancelled = 'quotations.cancelled';
    case QuotationDiscarded = 'quotations.discarded';
    case QuotationExpired = 'quotations.expired';
    case QuotationNegotiatedPriceSet = 'quotations.negotiated_price_set';
    case QuotationAgreedAdvanceSet = 'quotations.agreed_advance_set';
    case QuotationAdvisorChanged = 'quotations.advisor_changed';
    case QuotationRecipientChanged = 'quotations.recipient_changed';

    /**
     * Spanish label shown in the audit query.
     */
    public function label(): string
    {
        return match ($this) {
            self::LoginSucceeded => 'Inicio de sesión exitoso',
            self::LoginFailed => 'Inicio de sesión fallido',
            self::Lockout => 'Bloqueo por intentos fallidos',
            self::Logout => 'Cierre de sesión',
            self::PasswordChanged => 'Cambio de contraseña propia',
            self::UserCreated => 'Usuario creado',
            self::UserUpdated => 'Usuario modificado',
            self::UserActivated => 'Usuario activado',
            self::UserDeactivated => 'Usuario desactivado',
            self::UserPasswordReset => 'Contraseña de usuario restablecida',
            self::UserRolesAssigned => 'Roles asignados a un usuario',
            self::UserRolesRemoved => 'Roles retirados de un usuario',
            self::RoleCreated => 'Rol creado',
            self::RoleUpdated => 'Rol modificado',
            self::RoleDeleted => 'Rol eliminado',
            self::RolePermissionsGranted => 'Permisos asignados a un rol',
            self::RolePermissionsRevoked => 'Permisos retirados de un rol',
            self::AuthorizationDenied => 'Acceso denegado',
            self::CustomerCreated => 'Cliente creado',
            self::CustomerUpdated => 'Cliente modificado',
            self::CustomerDeactivated => 'Cliente desactivado',
            self::CustomerActivated => 'Cliente reactivado',
            self::CustomerDeleted => 'Cliente eliminado',
            self::CustomerAdvisorAssigned => 'Asesora de cliente asignada',
            self::CatalogCreated => 'Elemento del catálogo creado',
            self::CatalogUpdated => 'Elemento del catálogo modificado',
            self::CatalogDeactivated => 'Elemento del catálogo desactivado',
            self::CatalogActivated => 'Elemento del catálogo reactivado',
            self::CatalogFabricColorsUpdated => 'Colores de una tela modificados',
            self::ProductCreated => 'Producto creado',
            self::ProductUpdated => 'Producto modificado',
            self::ProductDeactivated => 'Producto desactivado',
            self::ProductActivated => 'Producto reactivado',
            self::ProductDeleted => 'Producto eliminado',
            self::ProductAttributesUpdated => 'Atributos de un producto modificados',
            self::ProductStockMinimumsUpdated => 'Stock mínimo por artículo modificado',
            self::ProductTemplateUploaded => 'Plantilla de producto subida',
            self::ProductTemplateRemoved => 'Plantilla de producto retirada',
            self::CombinationCreated => 'Combinación creada',
            self::CombinationUpdated => 'Combinación modificada',
            self::CombinationDeactivated => 'Combinación desactivada',
            self::CombinationActivated => 'Combinación reactivada',
            self::CombinationDeleted => 'Combinación eliminada',
            self::ComboCreated => 'Combo creado',
            self::ComboUpdated => 'Combo modificado',
            self::ComboDeactivated => 'Combo desactivado',
            self::ComboActivated => 'Combo reactivado',
            self::ComboDeleted => 'Combo eliminado',
            self::PriceSchemeCreated => 'Esquema de precio creado',
            self::PriceSchemeUpdated => 'Esquema de precio modificado',
            self::PriceSchemeDeactivated => 'Esquema de precio desactivado',
            self::PriceSchemeActivated => 'Esquema de precio reactivado',
            self::ProductSchemeAssigned => 'Esquema de precio asignado a un producto',
            self::PriceCreated => 'Precio de combinación registrado',
            self::PriceUpdated => 'Precio de combinación modificado',
            self::PriceDeleted => 'Precio de combinación eliminado',
            self::SizeGroupsUpdated => 'Grupos de talla modificados',
            self::ComboItemPriceCreated => 'Precio de ítem de combo registrado',
            self::ComboItemPriceUpdated => 'Precio de ítem de combo modificado',
            self::ComboItemPriceDeleted => 'Precio de ítem de combo eliminado',
            self::PricingParametersUpdated => 'Parámetros comerciales modificados',
            self::ExchangeRateRecorded => 'Tasa de cambio registrada manualmente',
            self::PriceImportApplied => 'Actualización masiva de precios aplicada',
            self::PriceImportDiscarded => 'Actualización masiva de precios descartada',
            self::QuotationCreated => 'Cotización creada',
            self::QuotationIssued => 'Cotización emitida',
            self::QuotationDuplicated => 'Cotización duplicada',
            self::QuotationCancelled => 'Cotización anulada',
            self::QuotationDiscarded => 'Cotización descartada',
            self::QuotationExpired => 'Cotización vencida',
            self::QuotationNegotiatedPriceSet => 'Precio negociado o confirmado en una cotización',
            self::QuotationAgreedAdvanceSet => 'Anticipo acordado registrado',
            self::QuotationAdvisorChanged => 'Asesora responsable cambiada',
            self::QuotationRecipientChanged => 'Destinatario de cotización cambiado',
        };
    }
}
