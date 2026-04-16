<?php

declare(strict_types=1);

namespace Krasselt\M2mDataUsage;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Cookie\CookieJar;
use Krasselt\M2mDataUsage\Exception\ApiException;
use Krasselt\M2mDataUsage\Exception\LoginException;
use Krasselt\M2mDataUsage\Model\MonthlyUsage;
use Krasselt\M2mDataUsage\Model\SimCard;
use Krasselt\M2mDataUsage\Parser\HtmlParser;

class Client
{
    private const BASE_URL = 'https://service.m2m-mobil.de';

    private HttpClient $http;
    private bool $loggedIn = false;

    public function __construct(
        private readonly string $username,
        private readonly string $password,
    ) {
        $this->http = new HttpClient([
            'base_uri' => self::BASE_URL,
            'cookies' => new CookieJar(),
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
            ],
            'allow_redirects' => true,
            'http_errors' => true,
        ]);
    }

    /**
     * Authenticate with the portal.
     *
     * @throws LoginException
     */
    public function login(): void
    {
        // GET login page for CSRF token
        $response = $this->http->get('/');
        $html = (string) $response->getBody();

        $token = HtmlParser::extractCsrfToken($html);
        if ($token === null) {
            throw new LoginException('Could not find CSRF token on login page.');
        }

        // POST login
        $response = $this->http->post('/public/login_check', [
            'form_params' => [
                'UserLoginType[alias]' => $this->username,
                'UserLoginType[password]' => $this->password,
                'UserLoginType[_token]' => $token,
                'UserLoginType[logindata]' => '',
            ],
        ]);

        // Visit data usage page to set session context
        $this->http->get('/mm/dataUsage');

        $this->loggedIn = true;
    }

    /**
     * Fetch all SIM cards from the DataTables API.
     *
     * @return SimCard[]
     * @throws ApiException
     */
    public function fetchSimCards(int $pageSize = 1000): array
    {
        $this->ensureLoggedIn();

        $allRecords = [];
        $start = 0;
        $draw = 1;

        do {
            $response = $this->http->post('/mm/getData/DataUsage', [
                'form_params' => [
                    'draw' => $draw,
                    'start' => $start,
                    'length' => $pageSize,
                    'columns[0][data]' => 'checkbox',
                    'columns[1][data]' => 'id',
                    'columns[2][data]' => 'iccid',
                    'columns[2][orderable]' => 'true',
                    'columns[3][data]' => 'nummer',
                    'columns[4][data]' => 'tarif',
                    'columns[5][data]' => 'pinpuk',
                    'columns[6][data]' => 'datum',
                    'columns[7][data]' => 'datum_akt',
                    'columns[8][data]' => 'status',
                    'columns[9][data]' => 'moredata',
                    'columns[10][data]' => 'tags',
                    'columns[11][data]' => 'hiddendata',
                    'order[0][column]' => '2',
                    'order[0][dir]' => 'asc',
                    'search[value]' => '',
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            if (!is_array($data) || !isset($data['data'])) {
                throw new ApiException('Invalid response from DataTables API.');
            }

            $records = $data['data'];
            $total = $data['recordsTotal'] ?? 0;
            $allRecords = array_merge($allRecords, $records);

            $start += $pageSize;
            $draw++;
        } while (count($allRecords) < $total && !empty($records));

        return array_map([HtmlParser::class, 'parseSimRecord'], $allRecords);
    }

    /**
     * Fetch monthly usage details for a single SIM card.
     *
     * @return MonthlyUsage[]
     * @throws ApiException
     */
    public function fetchUsageDetails(SimCard $sim): array
    {
        $this->ensureLoggedIn();

        if (empty($sim->cardId)) {
            return [];
        }

        $response = $this->http->post('/mm/dataUsageDetails', [
            'form_params' => ['cardId' => $sim->cardId],
        ]);

        return HtmlParser::parseDetailHtml((string) $response->getBody());
    }

    /**
     * Fetch monthly usage details for all SIM cards.
     *
     * @param SimCard[] $simCards
     * @param callable|null $onProgress Called with (int $current, int $total, SimCard $sim) after each fetch.
     * @return array<string, MonthlyUsage[]> Keyed by cardId.
     */
    public function fetchAllUsageDetails(array $simCards, ?callable $onProgress = null): array
    {
        $this->ensureLoggedIn();

        $results = [];
        $total = count($simCards);

        foreach ($simCards as $i => $sim) {
            $results[$sim->cardId] = $this->fetchUsageDetails($sim);

            if ($onProgress !== null) {
                $onProgress($i + 1, $total, $sim);
            }
        }

        return $results;
    }

    /**
     * Collect all unique months from usage details, sorted newest first.
     *
     * @param array<string, MonthlyUsage[]> $allDetails
     * @return string[] Month names (e.g. ["März 2026", "Februar 2026", ...])
     */
    public static function availableMonths(array $allDetails): array
    {
        $months = [];
        foreach ($allDetails as $cardMonths) {
            foreach ($cardMonths as $mu) {
                $months[$mu->month] = $mu->sortKey();
            }
        }

        arsort($months);
        return array_keys($months);
    }

    private function ensureLoggedIn(): void
    {
        if (!$this->loggedIn) {
            $this->login();
        }
    }
}
