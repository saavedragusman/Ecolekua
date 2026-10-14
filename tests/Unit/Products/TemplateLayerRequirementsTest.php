<?php

use App\Support\Products\Assets\TemplateLayerRequirements;

it('E-37 always requires the cuerpo layer plus the layer of every value and location', function () {
    expect(TemplateLayerRequirements::required(['manga-corta', 'manga-larga'], ['orilla-mangas']))
        ->toBe(['cuerpo', 'manga-corta', 'manga-larga', 'orilla-mangas']);
});

it('E-37 requires only cuerpo when nothing carries a layer', function () {
    expect(TemplateLayerRequirements::required([], []))->toBe(['cuerpo']);
});

it('E-37 ignores empty names and repeated layers', function () {
    expect(TemplateLayerRequirements::required(['manga-corta', '', 'manga-corta'], ['cuerpo']))
        ->toBe(['cuerpo', 'manga-corta']);
});

it('E-38 returns the required layers that the template lacks, sorted', function () {
    $required = ['cuerpo', 'manga-larga', 'manga-corta'];

    expect(TemplateLayerRequirements::missing(['cuerpo', 'sombras'], $required))
        ->toBe(['manga-corta', 'manga-larga']);
});

it('E-38 returns nothing when the template has every required layer', function () {
    expect(TemplateLayerRequirements::missing(['cuerpo', 'manga-corta', 'sombras'], ['cuerpo', 'manga-corta']))
        ->toBe([]);
});

it('E-40 checks a single layer against a template', function () {
    expect(TemplateLayerRequirements::missing(['cuerpo'], ['manga-tres-cuartos']))->toBe(['manga-tres-cuartos'])
        ->and(TemplateLayerRequirements::missing(['cuerpo', 'manga-tres-cuartos'], ['manga-tres-cuartos']))->toBe([]);
});
