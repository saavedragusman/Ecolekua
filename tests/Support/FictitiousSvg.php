<?php

namespace Tests\Support;

/**
 * Generates fictitious SVG templates for the upload tests (PRD-020, E-37..E-39). Nothing here comes
 * from a real product: flat shapes inside one `<g id>` per layer.
 */
final class FictitiousSvg
{
    /**
     * @param  list<string>  $layers  ids of the `<g>` layers, in document order
     * @param  string  $extra  markup placed before the layers, e.g. a hostile element
     * @param  string  $rootAttributes  extra attributes of the `<svg>` root
     */
    public static function template(array $layers = ['cuerpo'], string $extra = '', string $rootAttributes = ''): string
    {
        $groups = '';

        foreach ($layers as $layer) {
            $groups .= '<g id="'.$layer.'"><rect x="0" y="0" width="10" height="10" fill="#336699"/></g>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 100 100" '.$rootAttributes.'>'
            .$extra.$groups.'</svg>';
    }
}
