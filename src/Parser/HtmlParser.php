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
     * Parse the "pinpuk" column HTML into PIN and PUK.
     *
     * The exact markup of the portal cell is not known, so this is deliberately
     * tolerant: labelled values ("PIN: 1234", "PUK1 12345678") win; without
     * labels the digit sequences are classified by length (PUK = 8 digits,
     * PIN = 4-7 digits). Cells without any digits ("", "--", "n/a", "k.A.")
     * yield null for both.
     *
     * @return array{pin: ?string, puk: ?string}
     */
    public static function parsePinPuk(string $html): array
    {
        $text = self::normalizePinPukText($html);

        if ($text === '' || !preg_match('/\d/', $text)) {
            return ['pin' => null, 'puk' => null];
        }

        // Labelled values. The optional "1" suffix ("PIN1", "PIN 1", "PUK1") is
        // matched lazily so that "PIN 12345" is not read as label "1" + "2345".
        $pinPattern = '/\bPIN(?:\s?1)??\s*[:=]?\s*(\d{4,8})(?!\d)/i';
        $pukPattern = '/\bPUK(?:\s?1)??\s*[:=]?\s*(\d{8})(?!\d)/i';

        $pin = null;
        $puk = null;
        $rest = $text;

        if (preg_match($pinPattern, $text, $m)) {
            $pin = $m[1];
            $rest = str_replace($m[0], ' ', $rest);
        }
        if (preg_match($pukPattern, $text, $m)) {
            $puk = $m[1];
            $rest = str_replace($m[0], ' ', $rest);
        }

        if ($pin !== null && $puk !== null) {
            return ['pin' => $pin, 'puk' => $puk];
        }

        // Fallback: unlabelled digit sequences (4-8 digits) from what is left.
        preg_match_all('/(?<!\d)\d{4,8}(?!\d)/', $rest, $m);
        $sequences = $m[0];
        $eight = array_values(array_filter($sequences, static fn(string $s): bool => strlen($s) === 8));
        $short = array_values(array_filter($sequences, static fn(string $s): bool => strlen($s) < 8));

        if ($pin === null && $puk === null) {
            if ($eight !== [] && $short !== []) {
                return ['pin' => $short[0], 'puk' => $eight[0]];
            }
            if (count($sequences) === 1) {
                return ['pin' => $sequences[0], 'puk' => null];
            }
            if ($sequences !== []) {
                // Several sequences of the same class: first is the PIN, a
                // further 8-digit one is taken as PUK.
                $pin = $sequences[0];
                $puk = null;
                foreach (array_slice($sequences, 1) as $s) {
                    if (strlen($s) === 8) {
                        $puk = $s;
                        break;
                    }
                }
                return ['pin' => $pin, 'puk' => $puk];
            }
            return ['pin' => null, 'puk' => null];
        }

        if ($pin === null) {
            // PUK was labelled, PIN not: first remaining 4-8 digit sequence.
            $pin = $sequences[0] ?? null;
        } elseif ($puk === null) {
            // PIN was labelled, PUK not: first remaining 8-digit sequence.
            $puk = $eight[0] ?? null;
        }

        return ['pin' => $pin, 'puk' => $puk];
    }

    /**
     * Reduce cell HTML to a single line of plain text: tags become spaces,
     * entities are decoded, non-breaking spaces and whitespace runs collapse.
     */
    private static function normalizePinPukText(string $html): string
    {
        $text = preg_replace('/<[^>]*>/', ' ', $html) ?? $html;
        $text = self::stripHtml($text);
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
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

        $pinPuk = self::parsePinPuk((string) ($raw['pinpuk'] ?? ''));

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
            pin: $pinPuk['pin'],
            puk: $pinPuk['puk'],
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
