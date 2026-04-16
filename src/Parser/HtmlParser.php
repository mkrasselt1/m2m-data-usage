<?php

declare(strict_types=1);

namespace Krasselt\M2mDataUsage\Parser;

use Krasselt\M2mDataUsage\Model\MonthlyUsage;
use Krasselt\M2mDataUsage\Model\SimCard;

class HtmlParser
{
    /**
     * Extract CSRF token from login page HTML.
     */
    public static function extractCsrfToken(string $html): ?string
    {
        if (preg_match('/id="UserLoginType__token"[^>]*value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Extract tag names from the tags column HTML.
     * Input: <div class="tag-list"><span class="tag tag-4">Name</span>...</div>
     */
    public static function parseTags(string $html): array
    {
        if (empty($html)) {
            return [];
        }
        preg_match_all('/<span[^>]*class="tag[^"]*"[^>]*>(.*?)<\/span>/s', $html, $matches);
        return array_map('trim', $matches[1] ?? []);
    }

    /**
     * Strip HTML tags and return clean text.
     */
    public static function stripHtml(string $html): string
    {
        return trim(strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /**
     * Parse a raw DataTables JSON row into a SimCard.
     */
    public static function parseSimRecord(array $raw): SimCard
    {
        $cardId = '';
        if (preg_match('/value="(\d+)"/', $raw['checkbox'] ?? '', $m)) {
            $cardId = $m[1];
        }

        return new SimCard(
            cardId: $cardId,
            iccid: self::stripHtml((string) ($raw['iccid'] ?? '')),
            rufnummer: self::stripHtml((string) ($raw['nummer'] ?? '')),
            tarif: self::stripHtml((string) ($raw['tarif'] ?? '')),
            status: self::stripHtml((string) ($raw['status'] ?? '')),
            bestellt: self::stripHtml((string) ($raw['datum'] ?? '')),
            aktivierung: self::stripHtml((string) ($raw['datum_akt'] ?? '')),
            tags: self::parseTags((string) ($raw['tags'] ?? '')),
            currentUsage: self::stripHtml((string) ($raw['moredata'] ?? '')),
        );
    }

    /**
     * Parse the /mm/dataUsageDetails response into MonthlyUsage objects.
     *
     * The response is a JSON string containing escaped HTML like:
     * "<div class=\"data-row\"><span class=\"date\">März 2026</span>
     *  <span class=\"verbrauch\">...<span class=\"text\">62 MB / 1 MB</span></span></div>"
     *
     * Deactivated SIMs return: "<div class=\"cost-row\">--</div>..."
     *
     * @return MonthlyUsage[]
     */
    public static function parseDetailHtml(string $jsonResponse): array
    {
        // The API returns a JSON-encoded string containing HTML
        $html = json_decode($jsonResponse, true);
        if (is_string($html)) {
            // Already decoded to string
        } elseif ($html === null) {
            $html = $jsonResponse;
        } else {
            return [];
        }

        if (empty($html) || (str_contains($html, '--') && !str_contains($html, 'data-row'))) {
            return [];
        }

        $results = [];

        // Match each data-row block
        if (!preg_match_all(
            '/<div[^>]*class="data-row"[^>]*>(.*?)<\/div>/s',
            $html,
            $rows
        )) {
            return [];
        }

        foreach ($rows[1] as $rowHtml) {
            // Extract month name
            $month = '';
            if (preg_match('/<span[^>]*class="date"[^>]*>(.*?)<\/span>/s', $rowHtml, $m)) {
                $month = trim(strip_tags($m[1]));
            }

            // Extract usage text (e.g. "62 MB / 1 MB")
            $usageText = '';
            if (preg_match('/<span[^>]*class="text"[^>]*>(.*?)<\/span>/s', $rowHtml, $m)) {
                $usageText = trim(strip_tags($m[1]));
            }

            if (empty($month) || empty($usageText)) {
                continue;
            }

            $parts = explode('/', $usageText);
            $usedMb = self::parseUsageToMb(trim($parts[0] ?? '0'));
            $includedMb = self::parseUsageToMb(trim($parts[1] ?? '0'));

            $results[] = new MonthlyUsage($month, $usedMb, $includedMb);
        }

        return $results;
    }

    /**
     * Convert a data usage string like "62 MB" to float MB.
     */
    public static function parseUsageToMb(string $text): float
    {
        $text = str_replace(',', '.', trim($text));
        if (!preg_match('/([\d.]+)\s*(KB|MB|GB|TB|B)/i', $text, $m)) {
            // Try as plain number
            $num = preg_replace('/[^\d.]/', '', $text);
            return $num !== '' ? (float) $num : 0.0;
        }

        $value = (float) $m[1];
        return match (strtoupper($m[2])) {
            'B' => $value / (1024 * 1024),
            'KB' => $value / 1024,
            'MB' => $value,
            'GB' => $value * 1024,
            'TB' => $value * 1024 * 1024,
            default => $value,
        };
    }
}
