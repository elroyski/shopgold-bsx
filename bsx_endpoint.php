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
    'preview'     => handle_preview($pdo),
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
            ot_total.value    AS order_total,
        ot_ship.value     AS shipping_total,
        ot_ship.title     AS shipping_title
        FROM orders o
        LEFT JOIN orders_total ot_total ON ot_total.orders_id = o.orders_id AND ot_total.class = 'ot_total'
        LEFT JOIN orders_total ot_ship  ON ot_ship.orders_id  = o.orders_id AND ot_ship.class  = 'ot_shipping'
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
          AND ot.class IN ('ot_coupon', 'ot_discount', 'ot_discount_coupon')
        ORDER BY ot.orders_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Forma płatności → cash / card
    // shopGold przechowuje payment_method jako nazwę modułu np. 'cash', 'przelewy24' itp.
    // Dostosuj listę modułów kartowych do swojego sklepu.
    $cardModules = ['przelewy24', 'payu', 'tpay', 'stripe', 'card', 'blik'];

    // Indeksuj po orders_id
    $productsByOrder  = group_by($products,  'orders_id');
    $discountsByOrder = group_by($discounts, 'orders_id');

    // Licznik prób wydruku (step) – potrzebny do ponownej fiskalizacji
    $stepByOrder = [];
    if (BSX_ALLOW_REPRINT) {
        $steps = $pdo->query("
            SELECT orders_id, COUNT(*) AS cnt
            FROM orders_status_history
            WHERE orders_id IN ($in) AND orders_status_id = 18
            GROUP BY orders_id
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($steps as $s) {
            $stepByOrder[$s['orders_id']] = (int) $s['cnt'];
        }
    }

    // Buduj XML
    $xml      = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><root/>');
    $receipts = $xml->addChild('receipts');

    foreach ($orders as $order) {
        $oid       = $order['orders_id'];
        $payAmount = (float) ($order['order_total'] ?? 0); // kwota do zapłaty (po rabacie)
        $isCard    = in_array($order['payment_method'], $cardModules, true);

        // Suma pozycji brutto przed rabatem – drukarka sprawdza: total - discountvalue = cash
        $itemsTotal = 0.0;
        foreach ($productsByOrder[$oid] ?? [] as $p) {
            $itemsTotal += (float) $p['item_total'];
        }
        $itemsTotal = round($itemsTotal + (float) ($order['shipping_total'] ?? 0), 2);

        // Jeśli nie ma rabatu, total = cash = payAmount (bez rabatu itemsTotal ≈ payAmount)
        $discountAmount = !empty($discountsByOrder[$oid])
            ? (float) $discountsByOrder[$oid][0]['discount_amount']
            : 0.0;
        $receiptTotal = $discountAmount > 0 ? $itemsTotal : $payAmount;

        $receipt = $receipts->addChild('receipt');
        $receipt->addAttribute('id',    (string) $oid);
        $receipt->addAttribute('step',  (string) ($stepByOrder[$oid] ?? 0));
        $receipt->addAttribute('total', fmt($receiptTotal));

        if ($isCard) {
            $receipt->addAttribute('card', fmt($payAmount));
        } else {
            $receipt->addAttribute('cash', fmt($payAmount));
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

        // Pozycje produktów
        foreach ($productsByOrder[$oid] ?? [] as $p) {
            $item = $receipt->addChild('item');
            $item->addAttribute('name',     substr($p['products_name'], 0, 40));
            $item->addAttribute('price',    fmt($p['products_price']));
            $item->addAttribute('quantity', fmt_qty($p['products_quantity']));
            $item->addAttribute('vatrate',  (string)(int)$p['vatrate']);
            $item->addAttribute('total',    fmt($p['item_total']));
        }

        // Przesyłka jako osobna pozycja (jeśli > 0)
        $shippingTotal = (float) ($order['shipping_total'] ?? 0);
        if ($shippingTotal > 0) {
            $shippingName = substr($order['shipping_title'] ?? 'Przesyłka', 0, 40);
            $ship = $receipt->addChild('item');
            $ship->addAttribute('name',     $shippingName);
            $ship->addAttribute('price',    fmt($shippingTotal));
            $ship->addAttribute('quantity', '1');
            $ship->addAttribute('vatrate',  BSX_SHIPPING_VAT);
            $ship->addAttribute('total',    fmt($shippingTotal));
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
            // Zostaw status 18 – nie wracaj do 17, żeby nie tworzyć pętli.
            // Admin musi ręcznie zmienić status na 17 żeby ponowić wydruk.
            $newStatus = 18;
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

// ─── preview (diagnostyka – bez zmiany statusu) ───────────────────────────────

function handle_preview(PDO $pdo): void
{
    $oid = (int) ($_REQUEST['order_id'] ?? 0);
    if (!$oid) {
        echo '<root><error>Brak order_id</error></root>';
        return;
    }

    $orders = $pdo->query("
        SELECT
            o.orders_id,
            o.orders_status,
            o.customers_nip,
            o.payment_method,
            ot_total.value AS order_total,
            ot_ship.value  AS shipping_total,
            ot_ship.title  AS shipping_title
        FROM orders o
        LEFT JOIN orders_total ot_total ON ot_total.orders_id = o.orders_id AND ot_total.class = 'ot_total'
        LEFT JOIN orders_total ot_ship  ON ot_ship.orders_id  = o.orders_id AND ot_ship.class  = 'ot_shipping'
        WHERE o.orders_id = $oid
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($orders)) {
        echo '<root><error>Nie znaleziono zamówienia</error></root>';
        return;
    }

    $products = $pdo->query("
        SELECT
            op.orders_id,
            op.products_name,
            ROUND(op.products_price * (1 + op.products_tax / 100), 2) AS products_price,
            op.products_quantity,
            op.products_tax AS vatrate,
            ROUND(op.final_price * (1 + op.products_tax / 100), 2) AS item_total
        FROM orders_products op
        WHERE op.orders_id = $oid
        ORDER BY op.orders_products_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $discounts = $pdo->query("
        SELECT ot.orders_id, ot.class, ot.title, ABS(ot.value) AS discount_amount
        FROM orders_total ot
        WHERE ot.orders_id = $oid AND ot.class IN ('ot_coupon', 'ot_discount', 'ot_discount_coupon')
    ")->fetchAll(PDO::FETCH_ASSOC);

    $allTotals = $pdo->query("
        SELECT class, title, value FROM orders_total WHERE orders_id = $oid ORDER BY sort_order
    ")->fetchAll(PDO::FETCH_ASSOC);

    $cardModules      = ['przelewy24', 'payu', 'tpay', 'stripe', 'card', 'blik'];
    $productsByOrder  = group_by($products, 'orders_id');
    $discountsByOrder = group_by($discounts, 'orders_id');

    $xml      = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><root/>');
    $debug    = $xml->addChild('debug');

    // orders_total raw
    $totalsNode = $debug->addChild('orders_total');
    foreach ($allTotals as $t) {
        $row = $totalsNode->addChild('row');
        $row->addAttribute('class', $t['class']);
        $row->addAttribute('title', $t['title']);
        $row->addAttribute('value', $t['value']);
    }

    $receipts = $xml->addChild('receipts');
    $order    = $orders[0];
    $ordId    = $order['orders_id'];
    $payAmount = (float) ($order['order_total'] ?? 0);
    $isCard    = in_array($order['payment_method'], $cardModules, true);

    $discountAmount = !empty($discountsByOrder[$ordId])
        ? (float) $discountsByOrder[$ordId][0]['discount_amount']
        : 0.0;

    $itemsTotal = 0.0;
    foreach ($productsByOrder[$ordId] ?? [] as $p) {
        $itemsTotal += (float) $p['item_total'];
    }
    $shippingTotal = (float) ($order['shipping_total'] ?? 0);
    $itemsTotal    = round($itemsTotal + $shippingTotal, 2);
    $receiptTotal  = $discountAmount > 0 ? $itemsTotal : $payAmount;

    $receipt = $receipts->addChild('receipt');
    $receipt->addAttribute('id',            (string) $ordId);
    $receipt->addAttribute('orders_status', (string) $order['orders_status']);
    $receipt->addAttribute('step',          '0');
    $receipt->addAttribute('total',         fmt($receiptTotal));
    $receipt->addAttribute($isCard ? 'card' : 'cash', fmt($payAmount));

    if (!empty($order['customers_nip'])) {
        $receipt->addAttribute('nip', $order['customers_nip']);
    }

    if (!empty($discountsByOrder[$ordId])) {
        $disc = $discountsByOrder[$ordId][0];
        $receipt->addAttribute('discounttype',  '1');
        $receipt->addAttribute('discountname',  $disc['title']);
        $receipt->addAttribute('discountvalue', fmt($disc['discount_amount']));
    }

    foreach ($productsByOrder[$ordId] ?? [] as $p) {
        $item = $receipt->addChild('item');
        $item->addAttribute('name',     substr($p['products_name'], 0, 40));
        $item->addAttribute('price',    fmt($p['products_price']));
        $item->addAttribute('quantity', fmt_qty($p['products_quantity']));
        $item->addAttribute('vatrate',  (string)(int)$p['vatrate']);
        $item->addAttribute('total',    fmt($p['item_total']));
    }

    $shippingTotal = (float) ($order['shipping_total'] ?? 0);
    if ($shippingTotal > 0) {
        $ship = $receipt->addChild('item');
        $ship->addAttribute('name',     substr($order['shipping_title'] ?? 'Przesyłka', 0, 40));
        $ship->addAttribute('price',    fmt($shippingTotal));
        $ship->addAttribute('quantity', '1');
        $ship->addAttribute('vatrate',  BSX_SHIPPING_VAT);
        $ship->addAttribute('total',    fmt($shippingTotal));
    }

    echo $xml->asXML();
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
