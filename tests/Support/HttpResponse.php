<?php

declare(strict_types=1);

namespace Tests\Support;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        private readonly array $headers,
        public readonly string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** Redirect target without scheme/host, e.g. '/login'; null when the response is not a redirect. */
    public function location(): ?string
    {
        $location = $this->header('Location');
        if ($location === null) {
            return null;
        }
        $path = (string) (parse_url($location, PHP_URL_PATH) ?? '/');
        $query = parse_url($location, PHP_URL_QUERY);
        return $path . ($query !== null ? '?' . $query : '');
    }

    public function isRedirectTo(string $path): bool
    {
        return in_array($this->status, [301, 302, 303], true) && $this->location() === $path;
    }

    /** Rows of every HTML table in the page (header rows included), each a list of cell texts. */
    public function tableRows(): array
    {
        preg_match_all('#<tr\b[^>]*>(.*?)</tr>#su', $this->body, $rows);
        return array_map(function (string $row): array {
            preg_match_all('#<t[hd]\b[^>]*>(.*?)</t[hd]>#su', $row, $cells);
            return array_map(fn($c) => self::clean($c), $cells[1]);
        }, $rows[1]);
    }

    /** First table row whose first cell equals $firstCell, keyed by the header labels of the first table. */
    public function tableRow(string $firstCell): ?array
    {
        $rows   = $this->tableRows();
        $header = $rows[0] ?? [];
        foreach ($rows as $row) {
            if (($row[0] ?? null) === $firstCell) {
                return count($header) === count($row) ? array_combine($header, $row) : $row;
            }
        }
        return null;
    }

    /** Rows of the SpreadsheetML (.xls) export, each a list of cell values. */
    public function spreadsheetRows(): array
    {
        preg_match_all('#<Row>(.*?)</Row>#su', $this->body, $rows);
        return array_map(function (string $row): array {
            preg_match_all('#<Data ss:Type="String">(.*?)</Data>#su', $row, $cells);
            return array_map(fn($c) => html_entity_decode($c, ENT_QUOTES | ENT_XML1, 'UTF-8'), $cells[1]);
        }, $rows[1]);
    }

    /** The page's #form-feedback payload (layout/base.php): config + values/fields of a rejected form, if any. */
    public function formFeedback(): ?array
    {
        if (!preg_match('#<script type="application/json" id="form-feedback">(.*?)</script>#su', $this->body, $m)) {
            return null;
        }
        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    /** XPath over the page, for structure the table helpers can't express (e.g. the weekly plan's day columns). */
    public function xpath(): \DOMXPath
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $this->body);
        libxml_clear_errors();
        return new \DOMXPath($dom);
    }

    /** Whitespace-collapsed text of a DOM node. */
    public static function nodeText(\DOMNode $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }

    private static function clean(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Body text with tags stripped and whitespace collapsed — for asserting on what a user sees. */
    public function text(): string
    {
        return self::clean($this->body);
    }
}
