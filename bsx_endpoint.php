<?php
/**
 * Endpoint dla bsxPrinter – metoda "Odpytywanie HTTP/HTTPS"
 *
 * Konfiguracja w bsxPrinter:
 *   Start → Ustawienia → Monitorowanie → Odpytywanie HTTP/HTTPS
 *   URL: https://sklep.piwo.org/bsx_endpoint.php
 *   Hasło: wartość BSX_PASSWORD poniżej
 */

require __DIR__ . '/config.php';

// ─── bootstrap ────────────────────────────────────────────────────────────────

header('Content-Type: text/xml; charset=utf-8');

$cmd      = $_REQUEST['cmd']      ?? '';
$password = $_REQUEST['password'] ?? '';

if ($password !== BSX_PASSWORD) {
    echo '<root><error>Unauthorized</error></root>';
    exit;
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ─── routing ──────────────────────────────────────────────────────────────────

match ($cmd) {
    'getreceipts' => handle_getreceipts($pdo),
    'results'     => handle_results($pdo),
    default       => print('<root><error>Unknown command</error></root>'),
};

// ─── getreceipts ──────────────────────────────────────────────────────────────

function handle_getreceipts(PDO $pdo): void
{
    // Pobierz dokumenty sprzedaży (typ_id=17) ze statusem 'aktualny'
    $sql = "
        SELECT
            d.id,
            d.total,
            d.cash,
            d.card,
            d.nip,
            d.discount_type,
            d.discount_name,
            d.discount_value,
            p.name              AS item_name,
            p.price             AS item_price,
            p.quantity          AS item_quantity,
            p.vatrate           AS item_vatrate,
            p.total             AS item_total,
            p.discount          AS item_discount,
            p.discount_value    AS item_discount_value,
            p.discount_value_proc AS item_discount_value_proc
        FROM dokumenty d
        JOIN pozycje_dokumentu p ON p.dokument_id = d.id
        WHERE d.typ_id = 17
          AND d.status = 'aktualny'
        ORDER BY d.id
    ";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // Zgrupuj pozycje po id dokumentu
    $docs = [];
    foreach ($rows as $row) {
        $id = $row['id'];
        if (!isset($docs[$id])) {
            $docs[$id] = [
                'id'             => $id,
                'total'          => $row['total'],
                'cash'           => $row['cash'],
                'card'           => $row['card'],
                'nip'            => $row['nip'],
                'discount_type'  => $row['discount_type'],
                'discount_name'  => $row['discount_name'],
                'discount_value' => $row['discount_value'],
                'items'          => [],
            ];
        }
        $docs[$id]['items'][] = [
            'name'                => $row['item_name'],
            'price'               => $row['item_price'],
            'quantity'            => $row['item_quantity'],
            'vatrate'             => $row['item_vatrate'],
            'total'               => $row['item_total'],
            'discount'            => $row['item_discount'],
            'discount_value'      => $row['item_discount_value'],
            'discount_value_proc' => $row['item_discount_value_proc'],
        ];
    }

    // Zbuduj XML
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><root/>');
    $receipts = $xml->addChild('receipts');

    foreach ($docs as $doc) {
        $receipt = $receipts->addChild('receipt');
        $receipt->addAttribute('id',            (string) $doc['id']);
        $receipt->addAttribute('step',          '0');
        $receipt->addAttribute('total',         format_amount($doc['total']));
        if ($doc['cash'] !== null) {
            $receipt->addAttribute('cash', format_amount($doc['cash']));
        }
        if ($doc['card'] !== null) {
            $receipt->addAttribute('card', format_amount($doc['card']));
        }
        if (!empty($doc['nip'])) {
            $receipt->addAttribute('nip', $doc['nip']);
        }
        if ($doc['discount_value'] > 0) {
            $receipt->addAttribute('discounttype',  (string) $doc['discount_type']);
            $receipt->addAttribute('discountname',  (string) $doc['discount_name']);
            $receipt->addAttribute('discountvalue', format_amount($doc['discount_value']));
        }

        foreach ($doc['items'] as $item) {
            $el = $receipt->addChild('item');
            $el->addAttribute('name',     substr($item['name'], 0, 40));
            $el->addAttribute('price',    format_amount($item['price']));
            $el->addAttribute('quantity', format_quantity($item['quantity']));
            $el->addAttribute('vatrate',  (string) $item['vatrate']);
            $el->addAttribute('total',    format_amount($item['total']));

            if (!empty($item['discount'])) {
                $el->addAttribute('discount', (string) $item['discount']);
                if ($item['discount_value'] > 0) {
                    $el->addAttribute('discountvalue', format_amount($item['discount_value']));
                } elseif ($item['discount_value_proc'] > 0) {
                    $el->addAttribute('discountvalueproc', format_amount($item['discount_value_proc']));
                }
            }
        }
    }

    // Oznacz dokumenty jako wysłane do drukarki (żeby nie wysyłać ponownie)
    if (!empty($docs)) {
        $ids = implode(',', array_map('intval', array_keys($docs)));
        $pdo->exec("UPDATE dokumenty SET status = 'wysłany_do_drukarki' WHERE id IN ($ids)");
    }

    echo $xml->asXML();
}

// ─── results ──────────────────────────────────────────────────────────────────

function handle_results(PDO $pdo): void
{
    $resultsXml = $_POST['results'] ?? '';

    if (empty($resultsXml)) {
        echo '<root>OK</root>';
        return;
    }

    $xml = simplexml_load_string($resultsXml);
    if ($xml === false) {
        echo '<root>OK</root>';
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE dokumenty
        SET status     = :status,
            nodoc      = :nodoc,
            print_date = :print_date,
            error_msg  = :error_msg
        WHERE id = :id
    ");

    foreach ($xml->receipt as $receipt) {
        $id        = (string) $receipt['id'];
        $status    = (int)    $receipt['status'];
        $nodoc     = (string) $receipt['nodoc'];
        $date      = (string) $receipt['date'];
        $errorcode = (int)    $receipt['errorcode'];
        $errorstr  = (string) $receipt['errorstr'];

        if ($errorcode !== 0) {
            $dbStatus = 'błąd_druku';
            $errorMsg = "[$errorcode] $errorstr";
        } elseif ($status === 2) {
            $dbStatus = 'wydrukowany';
            $errorMsg = null;
        } else {
            // status 0 lub 1 – jeszcze w kolejce / w trakcie, nie aktualizuj
            continue;
        }

        $stmt->execute([
            ':status'     => $dbStatus,
            ':nodoc'      => $nodoc ?: null,
            ':print_date' => $date  ?: null,
            ':error_msg'  => $errorMsg,
            ':id'         => $id,
        ]);
    }

    echo '<root>OK</root>';
}

// ─── helpers ──────────────────────────────────────────────────────────────────

function format_amount(mixed $value): string
{
    return number_format((float) $value, 2, '.', '');
}

function format_quantity(mixed $value): string
{
    $f = (float) $value;
    return (floor($f) === $f) ? (string)(int)$f : number_format($f, 3, '.', '');
}
