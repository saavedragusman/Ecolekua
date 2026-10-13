<?php

use App\Support\Products\Assets\AssetLimits;
use App\Support\Products\Assets\InvalidSvgException;
use App\Support\Products\Assets\SvgTemplateSanitizer;
use Tests\Support\FictitiousSvg;

function sanitizeSvg(string $svg): string
{
    return (new SvgTemplateSanitizer)->sanitize($svg);
}

function svgRejectedReason(string $bytes): ?string
{
    try {
        (new SvgTemplateSanitizer)->sanitize($bytes);
    } catch (InvalidSvgException $exception) {
        return $exception->reason;
    }

    return null;
}

it('E-39 keeps the layers and the shapes of a clean template', function () {
    $clean = sanitizeSvg(FictitiousSvg::template(['cuerpo', 'sombras']));

    expect($clean)->toContain('id="cuerpo"')
        ->and($clean)->toContain('id="sombras"')
        ->and($clean)->toContain('<rect');
});

it('E-39 removes active content from the template', function (string $hostile, string $forbidden) {
    $clean = sanitizeSvg(FictitiousSvg::template(['cuerpo'], $hostile));

    expect(stripos($clean, $forbidden))->toBeFalse()
        ->and($clean)->toContain('id="cuerpo"');
})->with([
    'script element' => ['<script>alert(1)</script>', 'script'],
    'script with src' => ['<script xlink:href="http://evil.example/x.js"/>', 'evil.example'],
    'onclick attribute' => ['<rect width="5" height="5" onclick="alert(1)"/>', 'onclick'],
    'onload on the root group' => ['<g onload="alert(1)"><rect width="1" height="1"/></g>', 'onload'],
    'foreignObject' => ['<foreignObject width="10" height="10"><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>', 'foreignObject'],
    'remote xlink:href' => ['<image xlink:href="http://evil.example/p.png" width="5" height="5"/>', 'evil.example'],
    'remote href' => ['<image href="https://evil.example/p.png" width="5" height="5"/>', 'evil.example'],
    'javascript href' => ['<a xlink:href="javascript:alert(1)"><rect width="5" height="5"/></a>', 'javascript:'],
    'css import in a style element' => ['<style>@import url(http://evil.example/a.css);</style>', 'evil.example'],
    'remote url in a style attribute' => ['<rect width="5" height="5" style="fill:url(http://evil.example/f)"/>', 'evil.example'],
    'remote url in a fill attribute' => ['<rect width="5" height="5" fill="url(http://evil.example/f)"/>', 'evil.example'],
]);

it('E-39 keeps a reference to a fragment of the same document', function () {
    $svg = FictitiousSvg::template(['cuerpo'], '<defs><linearGradient id="degradado"><stop offset="0" stop-color="#fff"/></linearGradient></defs>')
        .'';
    $withUse = str_replace('</svg>', '<rect width="5" height="5" fill="url(#degradado)"/></svg>', $svg);

    expect(sanitizeSvg($withUse))->toContain('url(#degradado)');
});

it('E-39 rejects a template with a DOCTYPE or an entity declaration', function (string $prolog) {
    $svg = '<?xml version="1.0" encoding="UTF-8"?>'.$prolog.'<svg xmlns="http://www.w3.org/2000/svg"><g id="cuerpo"><text>&xxe;</text></g></svg>';

    expect(svgRejectedReason($svg))->toBe(InvalidSvgException::DOCTYPE);
})->with([
    'doctype with an external entity' => ['<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'],
    'doctype with an entity value' => ['<!DOCTYPE svg [<!ENTITY xxe "boom">]>'],
    'plain doctype' => ['<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'],
    'lowercase doctype' => ['<!doctype svg>'],
]);

it('E-39 rejects a lone entity declaration without a DOCTYPE keyword', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><!ENTITY xxe "boom"><g id="cuerpo"/></svg>';

    expect(svgRejectedReason($svg))->toBe(InvalidSvgException::DOCTYPE);
});

it('PRD-020 rejects bytes that are not UTF-8 or not well-formed XML', function (string $bytes) {
    expect(svgRejectedReason($bytes))->toBe(InvalidSvgException::FORMAT);
})->with([
    'latin-1 bytes' => ["<svg xmlns=\"http://www.w3.org/2000/svg\"><text>\xE9\xE8</text></svg>"],
    'plain text' => ['not a drawing'],
    'empty file' => [''],
    'unclosed element' => ['<svg xmlns="http://www.w3.org/2000/svg"><g id="cuerpo">'],
    'a PNG signature' => ["\x89PNG\r\n\x1a\n\0\0\0"],
]);

it('PRD-020 rejects a document whose root is not svg', function () {
    $html = '<html xmlns="http://www.w3.org/1999/xhtml"><body><g id="cuerpo"/></body></html>';

    expect(svgRejectedReason($html))->toBe(InvalidSvgException::FORMAT)
        ->and(svgRejectedReason('<g id="cuerpo"><rect width="1" height="1"/></g>'))->toBe(InvalidSvgException::FORMAT);
});

it('PRD-020 rejects a template larger than 2 MB before parsing it', function () {
    $padding = str_repeat(' ', AssetLimits::MAX_BYTES + 1);

    expect(svgRejectedReason(FictitiousSvg::template().$padding))->toBe(InvalidSvgException::SIZE);
});

it('E-39 returns UTF-8 bytes that parse again as an svg document', function () {
    $clean = sanitizeSvg(FictitiousSvg::template(['cuerpo', 'sombras'], '<script>alert(1)</script><text x="1" y="1">Cuerpo áéí</text>'));
    $document = new DOMDocument;

    expect($document->loadXML($clean, LIBXML_NONET))->toBeTrue()
        ->and($document->documentElement->localName)->toBe('svg')
        ->and($clean)->toContain('Cuerpo áéí');
});
