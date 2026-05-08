<?php
/**
 * Atlas Bank Enterprise - Client Statement Signed Download API
 * View-only, customer-scoped, signed URL statements.
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/Response.php';
require_once __DIR__ . '/../middleware/client_auth.php';

$db = getDB();
ensureClientPortalTables($db);

$method = $_ROUTE['method'] ?? 'GET';
$id = $_ROUTE['id'] ?? null;

function escHtmlClientStmt(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function fmtMoneyClientStmt(float $v): string
{
    return number_format($v, 2, '.', ',') . ' FCFA';
}

function fetchClientAccountContext(PDO $db, int $customerId, string $accountNumber): array
{
    $stmt = $db->prepare(
        "SELECT a.id, a.account_number, a.customer_id, a.customer_name, a.product_type, a.branch, a.status, a.currency, a.opened_at, a.ledger_balance,
                c.customer_number, c.customer_type, c.phone, c.email, c.risk_rating
         FROM accounts a
         LEFT JOIN customers c ON c.id = a.customer_id
         WHERE a.customer_id = :cid AND TRIM(UPPER(a.account_number)) = TRIM(UPPER(:acc))
         LIMIT 1"
    );
    $stmt->execute([':cid' => $customerId, ':acc' => $accountNumber]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        errorResponse('Account not found for this customer.', 404);
    }
    return $row;
}

function fetchAllPostedClientStatementRows(PDO $db, string $accountNumber): array
{
    $sql = "SELECT ref, type, direction, category, description, amount, status, created_at
            FROM transactions
            WHERE account = :acc AND UPPER(status) IN ('POSTED','COMPLETED')
            ORDER BY created_at ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute([':acc' => $accountNumber]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function loadBankBrandingClientStmt(PDO $db): array
{
    $defaults = [
        'bank_name' => (string)getSetting($db, 'branding.bank_name', 'Atlas Bank'),
        'bank_name_short' => (string)getSetting($db, 'branding.bank_name_short', (string)getSetting($db, 'branding.bank_name', 'Atlas Bank')),
        'logo' => (string)getSetting($db, 'branding.logo', ''),
        'head_office_address' => (string)getSetting($db, 'branding.head_office_address', 'Head Office'),
        'phone' => (string)getSetting($db, 'branding.phone', 'N/A'),
        'email' => (string)getSetting($db, 'branding.email', 'N/A'),
        'website' => (string)getSetting($db, 'branding.website', 'atlasbank.com'),
        'swift_code' => (string)getSetting($db, 'branding.swift_code', 'N/A'),
        'cbn_license_number' => (string)getSetting($db, 'branding.cbn_license_number', 'N/A'),
        'registration_number' => (string)getSetting($db, 'branding.registration_number', 'N/A'),
    ];
    try {
        $stmt = $db->query(
            "SELECT bank_name, bank_name_short, logo, head_office_address, phone, email, website, swift_code, cbn_license_number, registration_number
             FROM bank_branding
             ORDER BY id
             LIMIT 1"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            foreach ($defaults as $k => $v) {
                if (isset($row[$k]) && trim((string)$row[$k]) !== '') {
                    $defaults[$k] = (string)$row[$k];
                }
            }
        }
    } catch (Throwable $e) {
        // keep defaults
    }
    return $defaults;
}

function fetchBranchSortCodeClientStmt(PDO $db, string $branchName): string
{
    if ($branchName === '') return 'N/A';
    try {
        $stmt = $db->prepare('SELECT code FROM branches WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $branchName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['code'])) {
            return strtoupper((string)$row['code']) . '-001';
        }
    } catch (Throwable $e) {
        // ignore
    }
    return 'N/A';
}

function computeClientStatementMetrics(array $allRows, float $ledgerBalance, ?string $fromDate, ?string $toDate): array
{
    $periodRows = [];
    $afterNet = 0.0;
    foreach ($allRows as $r) {
        $dt = substr((string)($r['created_at'] ?? ''), 0, 10);
        $amt = (float)($r['amount'] ?? 0);
        $net = (strtolower((string)($r['direction'] ?? '')) === 'credit') ? $amt : -$amt;
        if ($toDate !== null && $toDate !== '' && $dt > $toDate) {
            $afterNet += $net;
        }
        if ($fromDate !== null && $fromDate !== '' && $dt < $fromDate) {
            continue;
        }
        if ($toDate !== null && $toDate !== '' && $dt > $toDate) {
            continue;
        }
        $periodRows[] = $r;
    }
    $closingBalance = round($ledgerBalance - $afterNet, 2);
    $duringNet = 0.0;
    $totalCredits = 0.0;
    $totalDebits = 0.0;
    foreach ($periodRows as $r) {
        $amt = (float)($r['amount'] ?? 0);
        $isCredit = strtolower((string)($r['direction'] ?? '')) === 'credit';
        $duringNet += $isCredit ? $amt : -$amt;
        if ($isCredit) {
            $totalCredits += $amt;
        } else {
            $totalDebits += $amt;
        }
    }
    $openingBalance = round($closingBalance - $duringNet, 2);
    $interestEarned = 0.0;
    $feeCharges = 0.0;
    foreach ($periodRows as $r) {
        $cat = strtoupper((string)($r['category'] ?? ''));
        $type = strtoupper((string)($r['type'] ?? ''));
        $amt = (float)($r['amount'] ?? 0);
        if ($cat === 'INTEREST CREDIT') {
            $interestEarned += $amt;
        }
        if ($type === 'FEE' || $type === 'TAX_DEDUCTION' || $cat === 'SOFTWARE EXPENSE') {
            $feeCharges += $amt;
        }
    }
    return [
        'period_rows' => $periodRows,
        'opening_balance' => $openingBalance,
        'closing_balance' => $closingBalance,
        'total_credits' => round($totalCredits, 2),
        'total_debits' => round($totalDebits, 2),
        'net_movement' => round($totalCredits - $totalDebits, 2),
        'average_balance' => round(count($periodRows) > 0 ? (($openingBalance + $closingBalance) / 2) : $closingBalance, 2),
        'interest_earned' => round($interestEarned, 2),
        'fee_charges' => round($feeCharges, 2),
    ];
}

function pdfEscapeClientStmt(string $text): string
{
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace('(', '\\(', $text);
    $text = str_replace(')', '\\)', $text);
    return preg_replace('/[^\x20-\x7E]/', ' ', $text) ?? '';
}

function wrapPdfLineClientStmt(string $text, int $max = 95): array
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($text === '') return [''];
    if (strlen($text) <= $max) return [$text];
    $words = explode(' ', $text);
    $lines = [];
    $current = '';
    foreach ($words as $w) {
        if ($current === '') {
            $current = $w;
            continue;
        }
        if (strlen($current) + 1 + strlen($w) <= $max) {
            $current .= ' ' . $w;
        } else {
            $lines[] = $current;
            $current = $w;
        }
    }
    if ($current !== '') $lines[] = $current;
    return $lines;
}

function emitSimplePdfClientStmt(array $lines, string $filename): void
{
    $pageWidth = 595;
    $pageHeight = 842;
    $marginX = 36;
    $marginY = 36;
    $lineHeight = 12;
    $usableHeight = $pageHeight - ($marginY * 2);
    $maxLinesPerPage = max(1, (int)floor($usableHeight / $lineHeight));
    $pages = array_chunk($lines, $maxLinesPerPage);
    if (!$pages) $pages = [['']];

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    $pageObjectIds = [];
    $nextId = 4;
    foreach ($pages as $pageLines) {
        $contentId = $nextId++;
        $pageId = $nextId++;
        $pageObjectIds[] = $pageId;

        $stream = "BT\n/F1 10 Tf\n{$lineHeight} TL\n{$marginX} " . ($pageHeight - $marginY) . " Td\n";
        foreach ($pageLines as $line) {
            $stream .= '(' . pdfEscapeClientStmt($line) . ") Tj\nT*\n";
        }
        $stream .= "ET\n";
        $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";
        $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] "
            . "/Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
    }

    $kids = '';
    foreach ($pageObjectIds as $pid) {
        $kids .= $pid . ' 0 R ';
    }
    $objects[2] = '<< /Type /Pages /Kids [' . trim($kids) . '] /Count ' . count($pageObjectIds) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $id => $content) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $content . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $size = max(array_keys($objects)) + 1;
    $pdf .= "xref\n0 {$size}\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i < $size; $i++) {
        $off = $offsets[$i] ?? 0;
        $pdf .= str_pad((string)$off, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit;
}

function streamStatementPrintDocument(
    array $ctx,
    array $metrics,
    ?string $fromDate,
    ?string $toDate,
    string $stmtRef,
    array $branding,
    string $sortCode
): void {
    $safeAcc = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$ctx['account_number']);
    $filename = 'statement-' . $safeAcc . '-' . date('Ymd-His') . '.pdf';
    $printDate = date('d M Y, H:i');
    $fromLabel = $fromDate ? date('d M Y', strtotime($fromDate)) : '';
    $toLabel = $toDate ? date('d M Y', strtotime($toDate)) : '';
    $periodLabel = ($fromDate && $toDate) ? ($fromLabel . ' — ' . $toLabel) : 'All Transactions';
    $daysInPeriod = ($fromDate && $toDate) ? max(1, (int)ceil((strtotime($toDate) - strtotime($fromDate)) / 86400)) : 0;
    $running = (float)$metrics['opening_balance'];
    $lines = [];
    $push = function (string $line = '') use (&$lines): void {
        foreach (wrapPdfLineClientStmt($line, 96) as $wrapped) {
            $lines[] = $wrapped;
        }
    };
    $push(strtoupper((string)$branding['bank_name']) . ' - ACCOUNT STATEMENT');
    $push('Statement Ref: ' . $stmtRef . ' | Generated: ' . $printDate . ' | Source: Client Portal (Signed)');
    $push('Address: ' . (string)$branding['head_office_address']);
    $push('SWIFT: ' . (string)$branding['swift_code'] . ' | License: ' . (string)$branding['cbn_license_number']);
    $push(str_repeat('-', 96));
    $push('ACCOUNT HOLDER INFORMATION');
    $push('Account Holder: ' . (string)($ctx['customer_name'] ?? ''));
    $push('Customer Ref: ' . (string)($ctx['customer_number'] ?? 'N/A') . ' | Customer Type: ' . (string)($ctx['customer_type'] ?? 'N/A'));
    $push('Account Number: ' . (string)($ctx['account_number'] ?? '') . ' | Product: ' . (string)($ctx['product_type'] ?? '') . ' | Currency: ' . (string)($ctx['currency'] ?? 'XAF'));
    $push('Branch: ' . (string)($ctx['branch'] ?? '') . ' | Sort Code: ' . $sortCode . ' | Status: ' . (string)($ctx['status'] ?? 'ACTIVE'));
    $push('Phone: ' . (string)($ctx['phone'] ?? 'N/A') . ' | Email: ' . (string)($ctx['email'] ?? 'N/A'));
    $push(str_repeat('-', 96));
    $push('STATEMENT PERIOD SUMMARY');
    $push('Period: ' . $periodLabel . ' | Days: ' . ($daysInPeriod > 0 ? (string)$daysInPeriod : 'N/A') . ' | Entries: ' . (string)count($metrics['period_rows']));
    $push(str_repeat('-', 96));
    $push('BALANCE SUMMARY');
    $push('Opening Balance: ' . fmtMoneyClientStmt((float)$metrics['opening_balance']));
    $push('Total Credits: +' . fmtMoneyClientStmt((float)$metrics['total_credits']));
    $push('Total Debits: -' . fmtMoneyClientStmt((float)$metrics['total_debits']));
    $push('Net Movement: ' . ((float)$metrics['net_movement'] >= 0 ? '+' : '') . fmtMoneyClientStmt((float)$metrics['net_movement']));
    $push('Average Balance: ' . fmtMoneyClientStmt((float)$metrics['average_balance']));
    $push('Closing Balance: ' . fmtMoneyClientStmt((float)$metrics['closing_balance']));
    if (((string)($ctx['product_type'] ?? '') === 'SAVINGS')) {
        $push('Interest Earned: +' . fmtMoneyClientStmt((float)$metrics['interest_earned']) . ' | Savings Rate (p.a.): 4.20%');
    }
    if ((float)$metrics['fee_charges'] > 0) {
        $push('Fees & Charges: -' . fmtMoneyClientStmt((float)$metrics['fee_charges']));
    }
    $push(str_repeat('-', 96));
    $push('TRANSACTION DETAIL');
    $push('Date        | Value Date  | Description/Reference                         | Category      | Credit      | Debit       | Balance');
    $push(str_repeat('-', 96));
    foreach ($metrics['period_rows'] as $r) {
        $amt = (float)($r['amount'] ?? 0);
        $isCredit = strtolower((string)($r['direction'] ?? '')) === 'credit';
        $running += $isCredit ? $amt : -$amt;
        $credit = $isCredit ? fmtMoneyClientStmt($amt) : '—';
        $debit = $isCredit ? '—' : fmtMoneyClientStmt($amt);
        $desc = trim(((string)($r['description'] ?? '')) . ' ' . ((string)($r['ref'] ?? '')));
        $rowLine = sprintf(
            "%-11s | %-11s | %-43s | %-13s | %-11s | %-11s | %-11s",
            date('d M Y', strtotime((string)$r['created_at'])),
            date('d M Y', strtotime((string)$r['created_at'] . ($isCredit ? '' : ' +1 day'))),
            substr($desc, 0, 43),
            substr((string)($r['category'] ?? ''), 0, 13),
            substr($credit, 0, 11),
            substr($debit, 0, 11),
            substr(fmtMoneyClientStmt($running), 0, 11)
        );
        $push($rowLine);
    }
    if (count($metrics['period_rows']) === 0) {
        $push('No transactions in the selected period.');
    }
    $push(str_repeat('-', 96));
    $push('CERTIFICATION');
    $push('This statement is a true and complete reflection of all transactions posted to the above-referenced account during the stated period. The balances shown have been verified against ' . (string)$branding['bank_name_short'] . ''s core banking system records.');
    $push('CONFIDENTIALITY NOTICE');
    $push('This document contains privileged and confidential information intended solely for the named account holder. Unauthorized use, disclosure, copying, or distribution is strictly prohibited.');
    $push(str_repeat('-', 96));
    $push((string)$branding['bank_name_short'] . ' Limited | Reg No: ' . (string)$branding['registration_number'] . ' | BEAC: ' . (string)$branding['cbn_license_number']);
    $push('Registered Office: ' . (string)$branding['head_office_address']);
    $push('SWIFT: ' . (string)$branding['swift_code'] . ' | Tel: ' . (string)$branding['phone'] . ' | Email: ' . (string)$branding['email']);

    emitSimplePdfClientStmt($lines, $filename);
}

if ($method === 'GET' && $id === 'download') {
    $token = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_GET['token'] ?? ''));
    if ($token === '') {
        errorResponse('Signed token is required.', 400);
    }
    $link = resolveClientStatementLink($db, $token);
    if (!$link) {
        errorResponse('Invalid or expired signed URL.', 401);
    }
    $format = strtolower(trim((string)($_GET['format'] ?? 'pdf')));
    $ctx = fetchClientAccountContext($db, (int)$link['customer_id'], (string)$link['account_number']);
    $allRows = fetchAllPostedClientStatementRows($db, (string)$ctx['account_number']);
    $metrics = computeClientStatementMetrics(
        $allRows,
        (float)($ctx['ledger_balance'] ?? 0),
        $link['period_start'] ?: null,
        $link['period_end'] ?: null
    );
    $stmtRef = 'STMT-' . date('Ymd') . '-' . strtoupper(substr(hash('sha256', (string)$token), 0, 6));
    $branding = loadBankBrandingClientStmt($db);
    $sortCode = fetchBranchSortCodeClientStmt($db, (string)($ctx['branch'] ?? ''));
    logAudit(
        'CLIENT#' . (int)$link['customer_id'],
        'CLIENT_STATEMENT_DOWNLOAD_' . strtoupper($format),
        'DOCUMENT',
        (string)$link['id'],
        'SUCCESS',
        'Signed statement download (' . $format . ') for account ' . $link['account_number'],
        '',
        getClientIp()
    );
    streamStatementPrintDocument(
        $ctx,
        $metrics,
        $link['period_start'] ?: null,
        $link['period_end'] ?: null,
        $stmtRef,
        $branding,
        $sortCode
    );
}

if ($method === 'POST' && $id === 'sign') {
    $client = requireClientAuth();
    $input = getRequestInput();
    $accountId = (int)($input['account_id'] ?? 0);
    $accountNumber = trim((string)($input['account_number'] ?? ''));
    $fromDate = trim((string)($input['from_date'] ?? ''));
    $toDate = trim((string)($input['to_date'] ?? ''));
    if ($accountNumber === '') {
        validationError(['account_number' => 'Account number is required.']);
    }
    if (!preg_match('/^[A-Za-z0-9_-]{3,50}$/', $accountNumber)) {
        validationError(['account_number' => 'Account number format is invalid.']);
    }
    if ($fromDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
        validationError(['from_date' => 'Invalid date format, expected YYYY-MM-DD.']);
    }
    if ($toDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
        validationError(['to_date' => 'Invalid date format, expected YYYY-MM-DD.']);
    }
    if ($fromDate !== '' && $toDate !== '' && strtotime($fromDate) > strtotime($toDate)) {
        validationError(['date_range' => 'from_date cannot be greater than to_date.']);
    }

    // Ownership check before signing (prefer account_id when supplied).
    $canonicalAccount = '';
    if ($accountId > 0) {
        $accById = $db->prepare('SELECT account_number FROM accounts WHERE id = :id AND customer_id = :cid LIMIT 1');
        $accById->execute([':id' => $accountId, ':cid' => (int)$client['customer_id']]);
        $row = $accById->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $canonicalAccount = trim((string)$row['account_number']);
        }
    }
    if ($canonicalAccount === '') {
        $accStmt = $db->prepare(
            'SELECT account_number FROM accounts
             WHERE customer_id = :cid AND TRIM(UPPER(account_number)) = TRIM(UPPER(:acc))
             LIMIT 1'
        );
        $accStmt->execute([':cid' => (int)$client['customer_id'], ':acc' => $accountNumber]);
        $row = $accStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $canonicalAccount = trim((string)$row['account_number']);
        }
    }
    if ($canonicalAccount === '') {
        error_log('[Client Statement Sign] ownership denied customer_id=' . (int)$client['customer_id'] . ' account=' . $accountNumber . ' account_id=' . $accountId);
        forbiddenResponse('Account does not belong to authenticated customer. Refresh and retry.');
    }

    $token = issueClientStatementLink(
        $db,
        (int)$client['customer_id'],
        $canonicalAccount,
        $fromDate !== '' ? $fromDate : null,
        $toDate !== '' ? $toDate : null,
        300
    );
    $signedUrl = '/atlas-bank-backend/api/client-statements/download?token=' . rawurlencode($token) . '&format=pdf';
    logAudit(
        'CLIENT:' . ($client['full_name'] ?? $client['username']),
        'CLIENT_STATEMENT_SIGN_URL',
        'DOCUMENT',
        (string)$client['customer_id'],
        'SUCCESS',
        'Issued signed statement URL for account ' . $canonicalAccount,
        (string)$client['branch'],
        getClientIp()
    );
    successResponse([
        'signed_url' => $signedUrl,
        'expires_in_seconds' => 300
    ]);
}

errorResponse('Method not allowed.', 405);
