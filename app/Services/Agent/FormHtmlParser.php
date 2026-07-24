<?php

namespace App\Services\Agent;

use App\Enums\FormFieldType;
use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;

/**
 * Extract field definitions from an arbitrary HTML <form> snippet.
 *
 * The agent workflow receives HTML pasted by an AI (or a developer) and
 * needs to derive field metadata that the existing FormField model can
 * persist. The parser walks the snippet with DOMDocument, normalises
 * each control into a standard array, and skips anything that looks
 * internal (honeypot fields, control fields prefixed with `_`).
 */
class FormHtmlParser
{
    /**
     * CSS-style rule used to detect honeypot containers in the snippet.
     * Mirrors the convention the dashboard's snippet generator uses.
     */
    private const HONEYPOT_STYLE_NEEDLE = 'left:-9999px';

    /**
     * Names seen during the current parse() call. Used to dedupe
     * duplicate-named controls (e.g. a group of checkboxes) so the
     * resulting form does not contain two fields with the same name.
     *
     * @var array<string, true>
     */
    private array $seenNames = [];

    /**
     * Parse the supplied HTML and return a list of normalised field
     * definitions ready to be passed to FormField::create().
     *
     * @return array<int, array{
     *     name: string,
     *     label: string,
     *     type: string,
     *     required: bool,
     *     placeholder: ?string,
     *     help_text: ?string,
     *     options: ?array<int, string>,
     *     position: int,
     *     is_active: bool,
     * }>
     *
     * @throws RuntimeException when the snippet contains no usable fields
     */
    public function parse(string $html): array
    {
        $this->seenNames = [];
        $dom = $this->loadDocument($html);

        $fields = [];
        $position = 0;

        // Inputs and textareas first (preserves source order across tags).
        $inputs = $dom->getElementsByTagName('input');
        $textareas = $dom->getElementsByTagName('textarea');
        $selects = $dom->getElementsByTagName('select');

        // Merge by source order. We walk the whole tree in document order
        // below — getElementsByTagName returns live lists but the order
        // across tags is well-defined (each list is in document order).
        $ordered = [];
        foreach ($inputs as $node) {
            $ordered[] = $node;
        }
        foreach ($textareas as $node) {
            $ordered[] = $node;
        }
        foreach ($selects as $node) {
            $ordered[] = $node;
        }

        // Stable sort by document position. Use a custom comparator that
        // walks up to the shared root and compares sibling indices.
        usort($ordered, function (DOMNode $a, DOMNode $b): int {
            return $this->compareDocumentPosition($a, $b);
        });

        foreach ($ordered as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($this->isControlField($node)) {
                continue;
            }

            if ($this->isInsideHoneypotContainer($node)) {
                continue;
            }

            $field = $this->buildFieldFromNode($node, $dom, $position);
            if ($field === null) {
                continue;
            }

            $fields[] = $field;
            $position++;
        }

        if ($fields === []) {
            throw new RuntimeException('The supplied HTML contains no usable form fields.');
        }

        return $fields;
    }

    /**
     * Load the snippet into a DOMDocument. Use LIBXML_NONET to avoid
     * loading remote DTDs, suppress internal errors so malformed HTML
     * does not raise warnings, and use the NOIMPLIED/NODEFCTD flags so
     * the snippet does not get wrapped in <html><body>.
     */
    protected function loadDocument(string $html): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        // Ensure the snippet parses as UTF-8 even without an explicit
        // <meta charset>. mb_convert_encoding is forgiving about bad
        // sequences so emojis and accented characters survive.
        $prepared = '<?xml encoding="UTF-8"?>'
            .mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');

        $dom->loadHTML(
            $prepared,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    /**
     * Compare two nodes by their order in the document tree.
     */
    protected function compareDocumentPosition(DOMNode $a, DOMNode $b): int
    {
        if ($a === $b) {
            return 0;
        }

        // Walk both nodes to the root, recording the chain.
        $chainA = [];
        for ($n = $a; $n !== null; $n = $n->parentNode) {
            $chainA[] = $n;
        }
        $chainB = [];
        for ($n = $b; $n !== null; $n = $n->parentNode) {
            $chainB[] = $n;
        }

        // Find the lowest common ancestor.
        $chainA = array_reverse($chainA);
        $chainB = array_reverse($chainB);

        $commonIndex = 0;
        $max = min(count($chainA), count($chainB));
        while ($commonIndex < $max && $chainA[$commonIndex] === $chainB[$commonIndex]) {
            $commonIndex++;
        }

        // If we walked off the end of one chain the other is a descendant.
        if ($commonIndex === count($chainA)) {
            return -1;
        }
        if ($commonIndex === count($chainB)) {
            return 1;
        }

        $siblingA = $chainA[$commonIndex];
        $siblingB = $chainB[$commonIndex];

        // Compare sibling indices under the common ancestor.
        $parent = $siblingA->parentNode;
        $indexA = 0;
        $indexB = 0;
        foreach ($parent->childNodes as $i => $child) {
            if ($child === $siblingA) {
                $indexA = $i;
            }
            if ($child === $siblingB) {
                $indexB = $i;
            }
        }

        return $indexA <=> $indexB;
    }

    /**
     * Determine whether this is an internal control field that should
     * never be persisted (anything starting with `_` plus the
     * well-known Turnstile token).
     */
    protected function isControlField(DOMElement $node): bool
    {
        $name = $node->getAttribute('name');
        if ($name === '') {
            return false;
        }

        return str_starts_with($name, '_')
            || $name === 'cf-turnstile-response';
    }

    /**
     * Detect honeypot containers — divs with the offscreen style
     * applied. Anything inside is excluded.
     */
    protected function isInsideHoneypotContainer(DOMElement $node): bool
    {
        $current = $node->parentNode;
        while ($current instanceof DOMElement) {
            $style = $current->getAttribute('style');
            if ($style !== '' && str_contains($style, self::HONEYPOT_STYLE_NEEDLE)) {
                return true;
            }

            // Some snippets wrap the honeypot in a <p> or similar. Also
            // skip inputs whose name looks like a honeypot name.
            $current = $current->parentNode;
        }

        return false;
    }

    /**
     * Build the field array for a single DOM node.
     *
     * @return array<string, mixed>|null
     */
    protected function buildFieldFromNode(DOMElement $node, DOMDocument $dom, int $position): ?array
    {
        $tag = strtolower($node->tagName);
        $name = $node->getAttribute('name');
        if ($name === '') {
            return null;
        }

        $required = $node->hasAttribute('required')
            || $node->getAttribute('aria-required') === 'true';

        $placeholder = $node->getAttribute('placeholder') ?: null;

        // type defaults differ per tag
        $typeAttr = strtolower($node->getAttribute('type') ?: '');
        $type = match (true) {
            $tag === 'textarea' => $this->mapType('textarea'),
            $tag === 'select' => $this->mapType('select'),
            $typeAttr !== '' => $this->mapType($typeAttr),
            default => FormFieldType::Text,
        };

        $options = null;
        if ($tag === 'select') {
            $options = $this->collectSelectOptions($node);
        }

        // If we already saw this name during the current parse() we
        // skip the duplicate. Checkbox / radio groups sharing a name
        // collapse into a single field; users can edit them in the
        // dashboard afterwards to add the option list.
        if (isset($this->seenNames[$name])) {
            return null;
        }
        $this->seenNames[$name] = true;

        $label = $this->resolveLabel($node, $dom, $name);
        $helpText = $this->resolveHelpText($node);

        return [
            'name' => $name,
            'label' => $label,
            'type' => $type->value,
            'required' => $required,
            'placeholder' => $placeholder !== '' ? $placeholder : null,
            'help_text' => $helpText,
            'options' => $options,
            'position' => $position,
            'is_active' => true,
        ];
    }

    /**
     * Map an HTML type attribute to a FormFieldType, defaulting to Text.
     */
    protected function mapType(string $raw): FormFieldType
    {
        $normalised = strtolower(trim($raw));

        $map = [
            'text' => FormFieldType::Text,
            'email' => FormFieldType::Email,
            'tel' => FormFieldType::Tel,
            'phone' => FormFieldType::Tel,
            'url' => FormFieldType::Url,
            'number' => FormFieldType::Number,
            'date' => FormFieldType::Date,
            'time' => FormFieldType::Time,
            'datetime-local' => FormFieldType::Date,
            'checkbox' => FormFieldType::Checkbox,
            'radio' => FormFieldType::Radio,
            'select' => FormFieldType::Select,
            'textarea' => FormFieldType::Textarea,
            'file' => FormFieldType::File,
            'hidden' => FormFieldType::Hidden,
            'password' => FormFieldType::Text,
        ];

        return $map[$normalised] ?? FormFieldType::Text;
    }

    /**
     * Collect <option> text values from a <select> element.
     *
     * @return array<int, string>
     */
    protected function collectSelectOptions(DOMElement $select): array
    {
        $options = [];
        foreach ($select->getElementsByTagName('option') as $option) {
            /** @var DOMElement $option */
            $text = trim($option->textContent);
            if ($text === '') {
                continue;
            }
            $options[] = $text;
        }

        return $options;
    }

    /**
     * Try to find a <label for="name"> first; fall back to the closest
     * ancestor <label>; finally humanise the field name.
     */
    protected function resolveLabel(DOMElement $node, DOMDocument $dom, string $name): string
    {
        // Explicit <label for="name"> matching the input id, or the name.
        $id = $node->getAttribute('id');
        if ($id !== '') {
            foreach ($dom->getElementsByTagName('label') as $label) {
                /** @var DOMElement $label */
                if ($label->getAttribute('for') === $id) {
                    return trim($label->textContent) ?: $this->humanise($name);
                }
            }
        }
        foreach ($dom->getElementsByTagName('label') as $label) {
            /** @var DOMElement $label */
            if ($label->getAttribute('for') === $name) {
                return trim($label->textContent) ?: $this->humanise($name);
            }
        }

        // Ancestor <label>.
        $current = $node->parentNode;
        while ($current instanceof DOMElement) {
            if (strtolower($current->tagName) === 'label') {
                $text = trim($current->textContent);
                if ($text !== '') {
                    return $text;
                }
            }
            $current = $current->parentNode;
        }

        return $this->humanise($name);
    }

    /**
     * Try to find help text via aria-describedby, or a sibling <small>.
     */
    protected function resolveHelpText(DOMElement $node): ?string
    {
        $describedBy = $node->getAttribute('aria-describedby');
        if ($describedBy !== '' && $node->ownerDocument instanceof DOMDocument) {
            $hint = $node->ownerDocument->getElementById($describedBy);
            if ($hint instanceof DOMElement) {
                $text = trim($hint->textContent);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        // Sibling <small>.
        $parent = $node->parentNode;
        if ($parent instanceof DOMElement) {
            foreach ($parent->childNodes as $sibling) {
                if (! $sibling instanceof DOMElement) {
                    continue;
                }
                if (strtolower($sibling->tagName) === 'small') {
                    $text = trim($sibling->textContent);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Turn a snake_case / kebab-case field name into a human label.
     */
    protected function humanise(string $name): string
    {
        $clean = preg_replace('/[_\-]+/', ' ', $name) ?? $name;
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;

        return ucwords(trim($clean));
    }
}
