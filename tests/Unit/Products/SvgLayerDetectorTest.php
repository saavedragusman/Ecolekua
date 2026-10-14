<?php

use App\Support\Products\Assets\SvgLayerDetector;
use App\Support\Products\Assets\SvgTemplateSanitizer;
use Tests\Support\FictitiousSvg;

it('E-37 lists every g with an id as a sorted list', function () {
    $svg = FictitiousSvg::template(['sombras', 'cuerpo', 'orilla-mangas', 'manga-corta', 'manga-larga']);

    expect((new SvgLayerDetector)->layers($svg))
        ->toBe(['cuerpo', 'manga-corta', 'manga-larga', 'orilla-mangas', 'sombras']);
});

it('E-37 reads nested groups and ignores groups without id and other elements with an id', function () {
    $svg = FictitiousSvg::template(['cuerpo'], '<g><g id="interior"><rect id="no-es-capa" width="1" height="1"/></g></g><path id="tampoco" d="M0 0"/>');

    expect((new SvgLayerDetector)->layers($svg))->toBe(['cuerpo', 'interior']);
});

it('E-37 lists each layer once', function () {
    $svg = FictitiousSvg::template(['cuerpo', 'cuerpo']);

    expect((new SvgLayerDetector)->layers($svg))->toBe(['cuerpo']);
});

it('E-39 gives an empty list for a document without layers', function () {
    expect((new SvgLayerDetector)->layers(FictitiousSvg::template([])))->toBe([]);
});

it('E-39 detects the layers from the sanitized bytes, not from the upload', function () {
    $upload = FictitiousSvg::template(['cuerpo'], '<script><g id="fantasma"/></script>');
    $sanitized = (new SvgTemplateSanitizer)->sanitize($upload);

    expect((new SvgLayerDetector)->layers($sanitized))->toBe(['cuerpo']);
});
