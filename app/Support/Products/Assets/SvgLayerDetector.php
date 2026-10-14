<?php

namespace App\Support\Products\Assets;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Reads the layers of a template: the `id` of every `<g>` (design Decision 18, E-37). It must be fed
 * the sanitized bytes, so a layer hidden inside removed markup never counts (E-39).
 */
final class SvgLayerDetector
{
    /**
     * @return list<string> the distinct layer ids, sorted
     */
    public function layers(string $sanitizedSvg): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($sanitizedSvg, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            return [];
        }

        $layers = [];

        foreach ((new DOMXPath($document))->query("//*[local-name()='g'][@id]") ?: [] as $group) {
            if ($group instanceof DOMElement && $group->getAttribute('id') !== '') {
                $layers[] = $group->getAttribute('id');
            }
        }

        $layers = array_values(array_unique($layers));
        sort($layers, SORT_STRING);

        return $layers;
    }
}
