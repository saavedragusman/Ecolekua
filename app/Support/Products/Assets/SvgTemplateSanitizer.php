<?php

namespace App\Support\Products\Assets;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMXPath;
use enshrined\svgSanitize\Sanitizer;

/**
 * Turns an uploaded SVG into bytes that are safe to store and serve (PRD-020, DT-03, design
 * Decision 18). Steps: pre-checks that reject (size, UTF-8, well-formed XML with an `svg` root, no
 * DOCTYPE or entity), the `enshrined/svg-sanitize` library with remote references removed and the
 * project allowlist, and a DOM post-pass that drops what the library lets through (event
 * attributes, links that leave the document, CSS that loads something). Own regex sanitizing is not
 * used: the regexes here only decide what to reject or what to drop after the library ran.
 */
final class SvgTemplateSanitizer
{
    private const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

    /**
     * @throws InvalidSvgException when the upload cannot be stored as a template
     */
    public function sanitize(string $svg): string
    {
        $this->precheck($svg);

        $library = new Sanitizer;
        $library->setAllowedTags(new TemplateAllowedTags);
        $library->removeRemoteReferences(true);

        $clean = $library->sanitize($svg);

        if (! is_string($clean) || $clean === '') {
            throw new InvalidSvgException(InvalidSvgException::FORMAT, 'The sanitizer could not process the document.');
        }

        $document = $this->load($clean);
        $this->dropUnsafe($document);

        $output = $document->saveXML();

        if ($output === false) {
            throw new InvalidSvgException(InvalidSvgException::FORMAT, 'The sanitized document could not be serialized.');
        }

        return $output;
    }

    private function precheck(string $svg): void
    {
        if (strlen($svg) > AssetLimits::MAX_BYTES) {
            throw new InvalidSvgException(InvalidSvgException::SIZE, 'The template is larger than the allowed size.');
        }

        if ($svg === '' || ! mb_check_encoding($svg, 'UTF-8')) {
            throw new InvalidSvgException(InvalidSvgException::FORMAT, 'The template is not valid UTF-8.');
        }

        // Rejected, not cleaned: a DTD is how XXE and entity expansion reach the parser.
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $svg) === 1) {
            throw new InvalidSvgException(InvalidSvgException::DOCTYPE, 'The template declares a DOCTYPE or an entity.');
        }

        $this->load($svg);
    }

    /**
     * Parses without network access or entity substitution and requires an `svg` root.
     */
    private function load(string $xml): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $loaded ? $document->documentElement : null;

        if (! $root instanceof DOMElement || $root->localName !== 'svg' || $root->namespaceURI !== self::SVG_NAMESPACE) {
            throw new InvalidSvgException(InvalidSvgException::FORMAT, 'The template is not a well-formed SVG document.');
        }

        return $document;
    }

    private function dropUnsafe(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        foreach (iterator_to_array($xpath->query('//*[local-name()="style"]') ?: []) as $style) {
            if ($style instanceof DOMElement && $this->loadsSomething($style->textContent)) {
                $style->parentNode?->removeChild($style);
            }
        }

        foreach (iterator_to_array($xpath->query('//*') ?: []) as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($element->attributes) as $attribute) {
                if ($this->isUnsafe($attribute)) {
                    $element->removeAttributeNode($attribute);
                }
            }
        }
    }

    private function isUnsafe(DOMAttr $attribute): bool
    {
        $name = strtolower($attribute->localName ?? $attribute->name);

        if (str_starts_with($name, 'on')) {
            return true;
        }

        if ($name === 'href') {
            return ! str_starts_with(trim($attribute->value), '#');
        }

        return $this->loadsSomething($attribute->value);
    }

    /**
     * True for CSS or attribute text that imports a stylesheet, hides code behind an escape or
     * references anything but a fragment of the same document.
     */
    private function loadsSomething(string $text): bool
    {
        return preg_match('/@import|\\\\|url\(\s*(?![\'"]?\s*#)/i', $text) === 1;
    }
}
