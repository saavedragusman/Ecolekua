<?php

use App\Http\Controllers\Audit\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Catalog\AttributeController;
use App\Http\Controllers\Catalog\AttributeValueController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\DetailLocationController;
use App\Http\Controllers\Catalog\FabricOfferedColorController;
use App\Http\Controllers\Customers\CustomerAdvisorController;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Customers\CustomerStatusController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Products\CombinationController;
use App\Http\Controllers\Products\ComboController;
use App\Http\Controllers\Products\ProductController;
use App\Http\Controllers\Products\ProductStatusController;
use App\Http\Controllers\Products\ProductStockMinimumController;
use App\Http\Controllers\Products\ProductStructureController;
use App\Http\Controllers\Roles\RoleController;
use App\Http\Controllers\Roles\RolePermissionController;
use App\Http\Controllers\Users\UserController;
use App\Http\Controllers\Users\UserPasswordController;
use App\Http\Controllers\Users\UserRoleController;
use App\Http\Controllers\Users\UserStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// Declared public by FND-026 (PublicRoutes); reachable only by guests.
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', LogoutController::class)->name('logout');

Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
Route::put('/password', [PasswordController::class, 'update'])->name('password.update');

// Users administration (spec 001, FND-010). Authorization lives in UserPolicy.
Route::get('/users', [UserController::class, 'index'])->name('users.index');
Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
Route::post('/users', [UserController::class, 'store'])->name('users.store');
Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
Route::post('/users/{user}/activate', [UserStatusController::class, 'activate'])->name('users.activate');
Route::post('/users/{user}/deactivate', [UserStatusController::class, 'deactivate'])->name('users.deactivate');
Route::put('/users/{user}/password', [UserPasswordController::class, 'update'])->name('users.password.reset');
Route::put('/users/{user}/roles', [UserRoleController::class, 'update'])->name('users.roles.update');

// Customers (spec 002, CLI-001). Authorization lives in CustomerPolicy.
Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
// `/customers/create` must be declared before `/customers/{customer}`, or "create" would be read as an id.
Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
Route::post('/customers/{customer}/activate', [CustomerStatusController::class, 'activate'])->name('customers.activate');
Route::post('/customers/{customer}/deactivate', [CustomerStatusController::class, 'deactivate'])->name('customers.deactivate');
Route::put('/customers/{customer}/advisor', [CustomerAdvisorController::class, 'update'])->name('customers.advisor.update');

// Catalog (spec 003, PRD-001). Authorization lives in CatalogPolicy; categories are never deleted.
Route::get('/catalog/categories', [CategoryController::class, 'index'])->name('catalog.categories.index');
Route::post('/catalog/categories', [CategoryController::class, 'store'])->name('catalog.categories.store');
Route::put('/catalog/categories/{category}', [CategoryController::class, 'update'])->name('catalog.categories.update');
Route::post('/catalog/categories/{category}/move', [CategoryController::class, 'move'])->name('catalog.categories.move');
Route::post('/catalog/categories/{category}/activate', [CategoryController::class, 'activate'])->name('catalog.categories.activate');
Route::post('/catalog/categories/{category}/deactivate', [CategoryController::class, 'deactivate'])->name('catalog.categories.deactivate');

// Catalog attributes and their values (PRD-002). Values are created under their attribute and
// addressed by their own id afterwards; neither is ever deleted.
Route::get('/catalog/attributes', [AttributeController::class, 'index'])->name('catalog.attributes.index');
Route::get('/catalog/attributes/{attribute}', [AttributeController::class, 'show'])->name('catalog.attributes.show');
Route::post('/catalog/attributes', [AttributeController::class, 'store'])->name('catalog.attributes.store');
Route::put('/catalog/attributes/{attribute}', [AttributeController::class, 'update'])->name('catalog.attributes.update');
Route::post('/catalog/attributes/{attribute}/move', [AttributeController::class, 'move'])->name('catalog.attributes.move');
Route::post('/catalog/attributes/{attribute}/activate', [AttributeController::class, 'activate'])->name('catalog.attributes.activate');
Route::post('/catalog/attributes/{attribute}/deactivate', [AttributeController::class, 'deactivate'])->name('catalog.attributes.deactivate');
Route::post('/catalog/attributes/{attribute}/values', [AttributeValueController::class, 'store'])->name('catalog.attributes.values.store');
Route::put('/catalog/values/{value}', [AttributeValueController::class, 'update'])->name('catalog.values.update');
Route::post('/catalog/values/{value}/move', [AttributeValueController::class, 'move'])->name('catalog.values.move');
Route::post('/catalog/values/{value}/activate', [AttributeValueController::class, 'activate'])->name('catalog.values.activate');
Route::post('/catalog/values/{value}/deactivate', [AttributeValueController::class, 'deactivate'])->name('catalog.values.deactivate');
Route::put('/catalog/values/{value}/offered-colors', [FabricOfferedColorController::class, 'update'])->name('catalog.values.offered-colors.update');

// Catalog detail locations (PRD-007). The `{location}` parameter binds `DetailLocation`; locations
// have no order in the spec (no move route) and are never deleted.
Route::get('/catalog/detail-locations', [DetailLocationController::class, 'index'])->name('catalog.detail-locations.index');
Route::post('/catalog/detail-locations', [DetailLocationController::class, 'store'])->name('catalog.detail-locations.store');
Route::put('/catalog/detail-locations/{location}', [DetailLocationController::class, 'update'])->name('catalog.detail-locations.update');
Route::post('/catalog/detail-locations/{location}/activate', [DetailLocationController::class, 'activate'])->name('catalog.detail-locations.activate');
Route::post('/catalog/detail-locations/{location}/deactivate', [DetailLocationController::class, 'deactivate'])->name('catalog.detail-locations.deactivate');

// Products (spec 003, PRD-003, PRD-015). Authorization lives in ProductPolicy. The form pages
// (`products.create`, `products.edit`) are declared with their pages in Phase 18; the `/create` route
// goes before `/{product}` when it arrives.
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
Route::post('/products', [ProductController::class, 'store'])->name('products.store');
Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
Route::post('/products/{product}/activate', [ProductStatusController::class, 'activate'])->name('products.activate');
Route::post('/products/{product}/deactivate', [ProductStatusController::class, 'deactivate'])->name('products.deactivate');
Route::put('/products/{product}/attributes', [ProductStructureController::class, 'update'])->name('products.attributes.update');
Route::put('/products/{product}/stock-minimums', [ProductStockMinimumController::class, 'update'])->name('products.stock-minimums.update');

// Combinations of a product (PRD-005). Scoped bindings: a combination of another product is a 404.
// The editor pages (`create`, `edit`) are declared with their pages in Phase 19.
Route::scopeBindings()->group(function () {
    Route::post('/products/{product}/combinations', [CombinationController::class, 'store'])->name('products.combinations.store');
    Route::put('/products/{product}/combinations/{combination}', [CombinationController::class, 'update'])->name('products.combinations.update');
    Route::post('/products/{product}/combinations/{combination}/activate', [CombinationController::class, 'activate'])->name('products.combinations.activate');
    Route::post('/products/{product}/combinations/{combination}/deactivate', [CombinationController::class, 'deactivate'])->name('products.combinations.deactivate');
    Route::delete('/products/{product}/combinations/{combination}', [CombinationController::class, 'destroy'])->name('products.combinations.destroy');
});

// Combos (spec 003, PRD-010). Authorization lives in ComboPolicy. The read pages (`combos.index`,
// `combos.show`, `combos.create`, `combos.edit`) are declared with their pages in Phase 20.
Route::post('/combos', [ComboController::class, 'store'])->name('combos.store');
Route::put('/combos/{combo}', [ComboController::class, 'update'])->name('combos.update');
Route::delete('/combos/{combo}', [ComboController::class, 'destroy'])->name('combos.destroy');
Route::post('/combos/{combo}/activate', [ComboController::class, 'activate'])->name('combos.activate');
Route::post('/combos/{combo}/deactivate', [ComboController::class, 'deactivate'])->name('combos.deactivate');

// Audit query (FND-025). Read-only: audit records are immutable (FND-024), no other route touches them.
Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');

Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
Route::get('/roles/{role}', [RoleController::class, 'show'])->name('roles.show');
Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
Route::put('/roles/{role}/permissions', [RolePermissionController::class, 'update'])->name('roles.permissions.update');
