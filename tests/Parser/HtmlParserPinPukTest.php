<?php

declare(strict_types=1);

namespace Krasselt\M2mDataUsage\Tests\Parser;

use Krasselt\M2mDataUsage\Model\SimCard;
use Krasselt\M2mDataUsage\Parser\HtmlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlParserPinPukTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: ?string, 2: ?string}>
     */
    public static function pinPukProvider(): iterable
    {
        yield 'labelled with <br>' => ['PIN: 1234<br>PUK: 12345678', '1234', '12345678'];
        yield 'labelled with <br/>' => ['PIN: 1234<br/>PUK: 12345678', '1234', '12345678'];
        yield 'labelled in spans' => [
            '<span class="pin">PIN 1234</span><span class="puk">PUK 87654321</span>',
            '1234',
            '87654321',
        ];
        yield 'labelled with equals and nbsp' => ['PIN=1234&nbsp;PUK=12345678', '1234', '12345678'];
        yield 'labelled lowercase' => ['pin: 1234 puk: 12345678', '1234', '12345678'];
        yield 'labelled PUK before PIN' => ['PUK: 12345678 / PIN: 1234', '1234', '12345678'];
        yield 'labelled in block tags' => ['<div>PIN: 1234</div><div>PUK: 12345678</div>', '1234', '12345678'];
        yield 'labelled 8-digit PIN is not split by the label-number rule' => ['PIN: 12345678', '12345678', null];
        yield 'labelled 5-digit PIN starting with 1' => ['PIN 12345 PUK 12345678', '12345', '12345678'];
        yield 'PIN1/PIN2/PUK1/PUK2 take the first pair' => [
            'PIN1: 4321 PIN2: 5678 PUK1: 11112222 PUK2: 33334444',
            '4321',
            '11112222',
        ];
        yield 'PIN 1 / PUK 1 with space before label number' => ['PIN 1: 4321 PUK 1: 11112222', '4321', '11112222'];
        yield 'labelled PIN, unlabelled PUK' => ['PIN: 1234 / 12345678', '1234', '12345678'];
        yield 'unlabelled slash' => ['1234 / 12345678', '1234', '12345678'];
        yield 'unlabelled pipe' => ['1234|12345678', '1234', '12345678'];
        yield 'unlabelled comma' => ['1234, 12345678', '1234', '12345678'];
        yield 'unlabelled newline' => ["1234\n12345678", '1234', '12345678'];
        yield 'unlabelled PUK first' => ['12345678 / 1234', '1234', '12345678'];
        yield 'only PIN' => ['1234', '1234', null];
        yield 'only 8-digit sequence is the PIN' => ['12345678', '12345678', null];
        yield 'two short sequences: first is PIN' => ['1234 / 5678', '1234', null];
        yield 'empty' => ['', null, null];
        yield 'whitespace only' => ['  <br> ', null, null];
        yield 'double dash' => ['--', null, null];
        yield 'single dash' => ['-', null, null];
        yield 'n/a' => ['n/a', null, null];
        yield 'k.A.' => ['k.A.', null, null];
        yield 'text without digits' => ['<span>nicht verfügbar</span>', null, null];
        yield 'digits too long or short are ignored' => ['ICCID 8949012345678901234 / 12', null, null];
    }

    #[DataProvider('pinPukProvider')]
    public function testParsePinPuk(string $html, ?string $pin, ?string $puk): void
    {
        self::assertSame(['pin' => $pin, 'puk' => $puk], HtmlParser::parsePinPuk($html));
    }

    public function testParseSimRecordFillsPinAndPuk(): void
    {
        $sim = HtmlParser::parseSimRecord([
            'checkbox' => '<input type="checkbox" value="42">',
            'iccid' => '8949012345678901234',
            'pinpuk' => 'PIN: 1234<br>PUK: 12345678',
        ]);

        self::assertInstanceOf(SimCard::class, $sim);
        self::assertSame('42', $sim->cardId);
        self::assertSame('1234', $sim->pin);
        self::assertSame('12345678', $sim->puk);
        self::assertTrue($sim->hasPin());
    }

    public function testParseSimRecordWithoutPinPukFieldIsBackwardsCompatible(): void
    {
        $sim = HtmlParser::parseSimRecord([
            'checkbox' => '<input type="checkbox" value="7">',
            'iccid' => '8949012345678901234',
            'nummer' => '+491700000000',
        ]);

        self::assertSame('7', $sim->cardId);
        self::assertSame('8949012345678901234', $sim->iccid);
        self::assertNull($sim->pin);
        self::assertNull($sim->puk);
        self::assertFalse($sim->hasPin());
    }

    public function testSimCardConstructorDefaultsPinAndPukToNull(): void
    {
        $sim = new SimCard('1', 'iccid', 'nummer', 'tarif', 'Aktiv', '', '', [], '');

        self::assertNull($sim->pin);
        self::assertNull($sim->puk);
        self::assertFalse($sim->hasPin());
    }
}
