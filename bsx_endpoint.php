<?php
/**
 * Endpoint dla bsxPrinter – metoda "Odpytywanie HTTP/HTTPS"
 * Baza: shopGold
 *
 * Konfiguracja w bsxPrinter:
 *   Start → Ustawienia → Monitorowanie → Odpytywanie HTTP/HTTPS
 *   URL: https://sklep.piwo.org/bsx_endpoint.php
 *   Hasło: wartość BSX_PASSWORD z config.php
 *
 * Statusy zamówień (orders_status):
 *   17 = "Dokument sprzedaży" – aktualny, do wydruku paragonu
 *   18 = wysłany do drukarki  (ustawiany przez ten skrypt)
 *   19 = wydrukowany          (ustawiany po potwierdzeniu z bsxPrinter)
 *   Dostosuj numery statusów do swojej konfiguracji shopGold.
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
    // Pobierz zamówienia ze statusem 17 (Dokument sprzedaży – aktualny)
    $orders = $pdo->query("
        SELECT
            o.orders_id,
            o.customers_nip,
            o.payment_method,
            ot.value AS order_total
        FROM orders o
        LEFT JOIN orders_total ot
               ON ot.orders_id = o.orders_id AND ot.class = 'ot_total'
        WHERE o.orders_status = 17
        ORDER BY o.orders_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($orders)) {
        // Brak zamówień do druku – odpowiedź z pustą listą paragonów
        echo '<?xml version="1.0" encoding="utf-8"?><root><receipts></receipts></root>';
        return;
    }

    $orderIds = array_column($orders, 'orders_id');
    $in       = implode(',', array_map('intval', $orderIds));

    // Pozycje zamówień
    $products = $pdo->query("
        SELECT
            op.orders_id,
            op.products_name,
            ROUND(op.products_price * (1 + op.products_tax / 100), 2) AS products_price,
            op.products_quantity,
            op.products_tax        AS vatrate,
            ROUND(op.final_price   * (1 + op.products_tax / 100), 2) AS item_total
        FROM orders_products op
        WHERE op.orders_id IN ($in)
        ORDER BY op.orders_id, op.orders_products_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Rabaty/kupony z orders_total
    // class = 'ot_coupon'   → kupon rabatowy (wartość ujemna)
    // class = 'ot_discount' → rabat grupowy  (wartość ujemna)
    $discounts = $pdo->query("
        SELECT
            ot.orders_id,
            ot.class,
            ot.title,
            ABS(ot.value) AS discount_amount
        FROM orders_total ot
        WHERE ot.orders_id IN ($in)
          AND ot.class IN ('ot_coupon', 'ot_discount')
        ORDER BY ot.orders_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Forma płatności → cash / card
    // shopGold przechowuje payment_method jako nazwę modułu np. 'cash', 'przelewy24' itp.
    // Dostosuj listę modułów kartowych do swojego sklepu.
    $cardModules = ['przelewy24', 'payu', 'tpay', 'stripe', 'card', 'blik'];

    // Indeksuj po orders_id
    $productsByOrder  = group_by($products,  'orders_id');
    $discountsByOrder = group_by($discounts, 'orders_id');

    // Buduj XML
    $xml      = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><root/>');
    $receipts = $xml->addChild('receipts');

    foreach ($orders as $order) {
        $oid     = $order['orders_id'];
        $total   = (float) ($order['order_total'] ?? 0);
        $isCard  = in_array($order['payment_method'], $cardModules, true);

        $receipt = $receipts->addChild('receipt');
        $receipt->addAttribute('id',    (string) $oid);
        $receipt->addAttribute('step',  '0');
        $receipt->addAttribute('total', fmt($total));

        if ($isCard) {
            $receipt->addAttribute('card', fmt($total));
        } else {
            $receipt->addAttribute('cash', fmt($total));
        }

        if (!empty($order['customers_nip'])) {
            $receipt->addAttribute('nip', $order['customers_nip']);
        }

        // Rabat z orders_total (bierzemy pierwszy znaleziony)
        if (!empty($discountsByOrder[$oid])) {
            $disc = $discountsByOrder[$oid][0];
            $receipt->addAttribute('discounttype',  '1');          // 1 = kwotowo
            $receipt->addAttribute('discountname',  $disc['title']);
            $receipt->addAttribute('discountvalue', fmt($disc['discount_amount']));
        }

        // Pozycje
        foreach ($productsByOrder[$oid] ?? [] as $p) {
            $item = $receipt->addChild('item');
            $item->addAttribute('name',     substr($p['products_name'], 0, 40));
            $item->addAttribute('price',    fmt($p['products_price']));
            $item->addAttribute('quantity', fmt_qty($p['products_quantity']));
            $item->addAttribute('vatrate',  (string)(int)$p['vatrate']);
            $item->addAttribute('total',    fmt($p['item_total']));
        }
    }

    // Oznacz zamówienia jako wysłane do drukarki (status 18)
    $pdo->exec("UPDATE orders SET orders_status = 18 WHERE orders_id IN ($in)");

    // Zapisz historię zmiany statusu
    $histStmt = $pdo->prepare("
        INSERT INTO orders_status_history
            (orders_id, orders_status_id, date_added, customer_notified, comments)
        VALUES
            (:oid, 18, NOW(), 0, 'Wysłano do drukarki fiskalnej (bsxPrinter)')
    ");
    foreach ($orderIds as $oid) {
        $histStmt->execute([':oid' => $oid]);
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

    // Zaktualizuj orders_status i wpisz paragon do tabeli receipts
    $stmtOrder = $pdo->prepare("
        UPDATE orders
        SET orders_status = :status
        WHERE orders_id = :oid
    ");

    $stmtHist = $pdo->prepare("
        INSERT INTO orders_status_history
            (orders_id, orders_status_id, date_added, customer_notified, comments)
        VALUES
            (:oid, :status, NOW(), 0, :comment)
    ");

    // Tabela receipts w shopGold
    $stmtReceipt = $pdo->prepare("
        INSERT INTO receipts
            (orders_id, receipts_language_id, receipts_nr,
             receipts_date_sell, receipts_date_generated, receipts_date_modified,
             receipts_payment_type, receipts_comments)
        SELECT
            o.orders_id,
            1,
            :nodoc,
            :date_sell,
            NOW(), NOW(),
            o.payment_method,
            :comments
        FROM orders o
        WHERE o.orders_id = :oid
        ON DUPLICATE KEY UPDATE
            receipts_nr            = VALUES(receipts_nr),
            receipts_date_sell     = VALUES(receipts_date_sell),
            receipts_date_modified = NOW(),
            receipts_comments      = VALUES(receipts_comments)
    ");

    foreach ($xml->receipt as $r) {
        $oid       = (int)    $r['id'];
        $bsxStatus = (int)    $r['status'];
        $nodoc     = (string) $r['nodoc'];
        $date      = (string) $r['date'];
        $errorcode = (int)    $r['errorcode'];
        $errorstr  = (string) $r['errorstr'];

        if ($errorcode !== 0) {
            $newStatus = 17; // błąd – wróć do "aktualny" żeby ponowić
            $comment   = "Błąd druku bsxPrinter [$errorcode]: $errorstr";
        } elseif ($bsxStatus === 2) {
            $newStatus = 19; // wydrukowany
            $comment   = "Wydrukowano paragon fiskalny. Numer: $nodoc";
        } else {
            continue; // status 0/1 – jeszcze w trakcie, pomijamy
        }

        $stmtOrder->execute([':status' => $newStatus, ':oid' => $oid]);
        $stmtHist->execute([':oid' => $oid, ':status' => $newStatus, ':comment' => $comment]);

        if ($bsxStatus === 2) {
            $stmtReceipt->execute([
                ':oid'      => $oid,
                ':nodoc'    => $nodoc,
                ':date_sell'=> $date ?: date('Y-m-d H:i:s'),
                ':comments' => "Paragon fiskalny nr $nodoc (bsxPrinter)",
            ]);
        }
    }

    echo '<root>OK</root>';
}

// ─── helpers ──────────────────────────────────────────────────────────────────

function fmt(mixed $v): string
{
    return number_format((float)$v, 2, '.', '');
}

function fmt_qty(mixed $v): string
{
    $f = (float)$v;
    return (floor($f) === $f) ? (string)(int)$f : number_format($f, 3, '.', '');
}

function group_by(array $rows, string $key): array
{
    $result = [];
    foreach ($rows as $row) {
        $result[$row[$key]][] = $row;
    }
    return $result;
}
