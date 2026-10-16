<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Design "Declaration order": static segments under /quotations are never captured by a `{quotation}` route.

function quotationRouteName(string $method, string $uri): ?string
{
    try {
        return Route::getRoutes()->match(Request::create($uri, $method))->getName();
    } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
        return null;
    }
}

it('Decision 19 los segmentos estáticos de cotizaciones llegan a su propia ruta', function () {
    expect(quotationRouteName('GET', '/quotations/recipients'))->toBe('quotations.recipients')
        ->and(quotationRouteName('POST', '/quotations/calculate'))->toBe('quotations.calculate')
        ->and(quotationRouteName('POST', '/quotations/selection-options'))->toBe('quotations.selection-options')
        ->and(quotationRouteName('PUT', '/quotations/12'))->toBe('quotations.update');
});

it('Decision 19 las rutas con {quotation} solo aceptan números', function () {
    expect(quotationRouteName('PUT', '/quotations/recipients'))->toBeNull()
        ->and(quotationRouteName('PUT', '/quotations/create'))->toBeNull();
});
