# krasselt/m2m-data-usage

PHP library to extract SIM card data usage from the [m2m-mobil.de](https://service.m2m-mobil.de) management portal.

## Installation

```bash
composer require krasselt/m2m-data-usage
```

## Library Usage

```php
use Krasselt\M2mDataUsage\Client;

$client = new Client('username', 'password');
$client->login();

// Fetch all SIM cards
$simCards = $client->fetchSimCards();

// Fetch monthly usage details for all SIMs
$allDetails = $client->fetchAllUsageDetails($simCards, function ($current, $total, $sim) {
    echo "[$current/$total] {$sim->iccid}\n";
});

// Get available months (newest first)
$months = Client::availableMonths($allDetails);

// Access data
foreach ($simCards as $sim) {
    echo "{$sim->iccid} | {$sim->rufnummer} | {$sim->status} | {$sim->tagsAsString()}\n";

    foreach ($allDetails[$sim->cardId] ?? [] as $usage) {
        echo "  {$usage->month}: {$usage->usedMb} MB / {$usage->includedMb} MB\n";
        echo "  Completed: " . ($usage->isCompleted() ? 'yes' : 'no') . "\n";
        echo "  YYYY-MM: {$usage->yearMonth()}\n";
    }
}
```

## PIN/PUK

The SIM list contains the portal's `pinpuk` column. It is parsed into the
`SimCard` fields `pin` and `puk` (`?string`); both are `null` when the portal
does not deliver a value (e.g. `--` or an empty cell).

```php
foreach ($simCards as $sim) {
    if ($sim->hasPin()) {
        // $sim->pin  e.g. "1234"
        // $sim->puk  e.g. "12345678" or null
    }
}
```

The CLI export (`bin/m2m-extract`) intentionally does not write PIN/PUK to CSV.

## CLI Usage

```bash
# Copy and edit config
cp m2m_config.ini.example m2m_config.ini

# All available months (per-month files for completed months)
php bin/m2m-extract --month all

# Only completed months
php bin/m2m-extract --month completed

# Specific month
php bin/m2m-extract --month "März 2026"

# Custom output directory
php bin/m2m-extract --month all --output-dir ./reports

# Pass credentials directly
php bin/m2m-extract -u username -p password --month all
```

## Config File

Create `m2m_config.ini`:

```ini
[credentials]
username = your_username
password = your_password
```

## Output

CSV files with semicolon delimiter containing:

| Column | Description |
|--------|-------------|
| month | Month name (e.g. "März 2026") |
| iccid | SIM card ICCID |
| rufnummer | Phone number |
| tarif | Tariff name |
| status | SIM status (Aktiv/Deaktiviert) |
| tags | Comma-separated tags |
| used_mb | Data used in MB |
| included_mb | Data included in plan in MB |

## Requirements

- PHP 8.1+
- guzzlehttp/guzzle ^7.5

## License

MIT
