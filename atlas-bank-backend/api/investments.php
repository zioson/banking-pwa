<?php
/**
 * Atlas Bank Enterprise Console
 * API: Investments
 *
 * Database-driven investment portal module:
 * - Share price policy
 * - Max shares per investor
 * - 1-year cycle and dividend rule
 * - Bank operating-fund reserve top-up up to cycle target
 */

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff = requireAuth();
$db = getDB();
$method = $_ROUTE['method'];
$id = $_ROUTE['id'] ?? '';
$sub = $_ROUTE['subResource'] ?? '';
$staffRole = strtoupper((string)($staff['role'] ?? ''));
$staffBranch = trim((string)($staff['department'] ?? ''));

requireModule('INVESTMENTS', $staff);
$nonBankRoles = ['CUSTOMER', 'CLIENT', 'SHAREHOLDER', 'MEMBER'];
if (in_array($staffRole, $nonBankRoles, true)) {
    errorResponse('Access denied. Investment portal is for bank staff operations only.', 403);
}

function invNormalizeBranch(string $branch): string
{
    $v = trim($branch);
    if ($v === '') return '';
    $u = strtoupper($v);
    if (in_array($u, ['ALL', 'ALL BRANCHES', 'ALL_BRANCHES', 'ALLBRANCHES', 'ALL MY BRANCHES', 'ALL_MY_BRANCHES'], true)) {
        return '';
    }
    return $v;
}

function invStaffBranchScope(array $staff): array
{
    $out = [];
    $arr = $staff['branches'] ?? [];
    if (is_array($arr)) {
        foreach ($arr as $b) {
            $v = invNormalizeBranch((string)$b);
            if ($v !== '') $out[] = $v;
        }
    }
    $norm = [];
    foreach ($out as $b) $norm[strtoupper($b)] = $b;
    return array_values($norm);
}

function invResolveBranchContext(PDO $db, array $staff, string $requestedBranch = ''): array
{
    $requested = invNormalizeBranch($requestedBranch);
    $isAdmin = in_array(strtoupper((string)($staff['role'] ?? '')), ['ADMIN', 'MANAGER', 'ACCOUNTANT'], true);
    $staffScope = invStaffBranchScope($staff);
    $rows = [];
    $hasBranchesTable = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'branches'")->fetch();

    if ($hasBranchesTable) {
        if ($isAdmin) {
            $stmt = $db->query("SELECT code, name, status FROM branches WHERE status = 'ACTIVE' ORDER BY name ASC");
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } elseif (!empty($staffScope)) {
            $ph = [];
            $params = [];
            foreach (array_values($staffScope) as $i => $b) {
                $k = ':b' . $i;
                $ph[] = $k;
                $params[$k] = $b;
            }
            $sql = "SELECT code, name, status FROM branches WHERE status = 'ACTIVE' AND name IN (" . implode(',', $ph) . ") ORDER BY name ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    if (empty($rows)) {
        foreach ($staffScope as $b) {
            $rows[] = ['code' => strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $b), 0, 8)), 'name' => $b, 'status' => 'ACTIVE'];
        }
    }

    $allowed = [];
    foreach ($rows as $r) {
        $name = invNormalizeBranch((string)($r['name'] ?? ''));
        if ($name === '') continue;
        $allowed[strtoupper($name)] = [
            'code' => (string)($r['code'] ?? ''),
            'name' => $name,
            'status' => (string)($r['status'] ?? 'ACTIVE')
        ];
    }
    $branches = array_values($allowed);

    $selected = '';
    if ($requested !== '' && isset($allowed[strtoupper($requested)])) {
        $selected = $allowed[strtoupper($requested)]['name'];
    } elseif (!empty($branches)) {
        $selected = (string)$branches[0]['name'];
    }

    return [
        'selectedBranch' => $selected,
        'branches' => $branches
    ];
}

function invRequireAdmin(array $staff): void
{
    requireRole(['ADMIN', 'MANAGER', 'ACCOUNTANT'], $staff);
}

function invModuleAccessLevel(array $staff): string
{
    $moduleAccess = $staff['module_access'] ?? [];
    if (!is_array($moduleAccess)) return 'FULL';

    foreach ($moduleAccess as $moduleName => $accessLevel) {
        $moduleName = (string)$moduleName;
        $keys = expandModuleToRbacKeys($moduleName);
        foreach ($keys as $k) {
            if (strtoupper((string)$k) === 'INVESTMENTS') {
                $access = strtoupper((string)$accessLevel);
                return $access === 'VIEW_ONLY' ? 'VIEW_ONLY' : 'FULL';
            }
        }
    }
    return 'FULL';
}

function invCanManage(array $staff): bool
{
    if (invModuleAccessLevel($staff) === 'VIEW_ONLY') return false;
    return in_array(strtoupper((string)($staff['role'] ?? '')), ['ADMIN', 'MANAGER', 'ACCOUNTANT'], true);
}

function invRequireWrite(array $staff): void
{
    if (invModuleAccessLevel($staff) === 'VIEW_ONLY') {
        errorResponse('Access denied. Your investment module access is view-only.', 403);
    }
}

function invAddCol(PDO $db, string $table, string $col, string $def): void
{
    $r = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?");
    $r->execute([$table, $col]);
    if (!$r->fetch()) {
        $db->exec("ALTER TABLE \"$table\" ADD COLUMN \"$col\" $def");
    }
}

function invEnsureSchema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS investment_settings (
        id SERIAL PRIMARY KEY,
        share_price DECIMAL(20,2) NOT NULL DEFAULT 25000,
        max_shares_per_person INT NOT NULL DEFAULT 10,
        default_dividend_rate DECIMAL(10,4) NOT NULL DEFAULT 12.0000,
        max_investment_target DECIMAL(20,2) NOT NULL DEFAULT 50000000,
        min_bank_reserve DECIMAL(20,2) NOT NULL DEFAULT 5000000,
        updated_by INT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS investment_shareholders (
        id SERIAL PRIMARY KEY,
        shareholder_code VARCHAR(30) NOT NULL UNIQUE,
        name VARCHAR(200) NOT NULL,
        email VARCHAR(200) NOT NULL,
        phone VARCHAR(50) DEFAULT '',
        branch VARCHAR(100) DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        linked_staff_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ish_branch ON investment_shareholders (branch)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ish_email ON investment_shareholders (email)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ish_linked_staff ON investment_shareholders (linked_staff_id)");

    $db->exec("CREATE TABLE IF NOT EXISTS investment_cycles (
        id SERIAL PRIMARY KEY,
        cycle_code VARCHAR(30) NOT NULL UNIQUE,
        name VARCHAR(200) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        max_target DECIMAL(20,2) NOT NULL,
        projected_div_rate DECIMAL(10,4) NOT NULL DEFAULT 12.0000,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        dividend_paid BOOLEAN NOT NULL DEFAULT 0,
        actual_div_rate DECIMAL(10,4) DEFAULT NULL,
        bank_reserved_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
        branch VARCHAR(100) DEFAULT '',
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ic_status ON investment_cycles (status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ic_branch ON investment_cycles (branch)");

    $db->exec("CREATE TABLE IF NOT EXISTS investment_holdings (
        id SERIAL PRIMARY KEY,
        cycle_id INT NOT NULL,
        shareholder_id INT NOT NULL,
        shares INT NOT NULL DEFAULT 0,
        total_invested DECIMAL(20,2) NOT NULL DEFAULT 0,
        first_purchase_date DATE DEFAULT NULL,
        last_purchase_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (cycle_id, shareholder_id),
        CONSTRAINT fk_ih_cycle FOREIGN KEY (cycle_id) REFERENCES investment_cycles(id) ON DELETE CASCADE,
        CONSTRAINT fk_ih_shareholder FOREIGN KEY (shareholder_id) REFERENCES investment_shareholders(id) ON DELETE CASCADE
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ih_shareholder ON investment_holdings (shareholder_id)");

    $db->exec("CREATE TABLE IF NOT EXISTS investment_transactions (
        id SERIAL PRIMARY KEY,
        txn_ref VARCHAR(60) NOT NULL UNIQUE,
        txn_date DATE NOT NULL,
        shareholder_id INT NULL,
        cycle_id INT NULL,
        action VARCHAR(30) NOT NULL,
        shares INT NOT NULL DEFAULT 0,
        amount DECIMAL(20,2) NOT NULL DEFAULT 0,
        description TEXT,
        branch VARCHAR(100) DEFAULT '',
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_it_cycle FOREIGN KEY (cycle_id) REFERENCES investment_cycles(id) ON DELETE SET NULL,
        CONSTRAINT fk_it_shareholder FOREIGN KEY (shareholder_id) REFERENCES investment_shareholders(id) ON DELETE SET NULL
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_it_cycle ON investment_transactions (cycle_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_it_shareholder ON investment_transactions (shareholder_id)");

    $db->exec("CREATE TABLE IF NOT EXISTS investment_dividend_payments (
        id SERIAL PRIMARY KEY,
        cycle_id INT NOT NULL,
        shareholder_id INT NOT NULL,
        shares INT NOT NULL DEFAULT 0,
        div_rate DECIMAL(10,4) NOT NULL DEFAULT 0,
        payout_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
        paid_date DATE NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Paid',
        txn_ref VARCHAR(60) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_idp_cycle FOREIGN KEY (cycle_id) REFERENCES investment_cycles(id) ON DELETE CASCADE,
        CONSTRAINT fk_idp_shareholder FOREIGN KEY (shareholder_id) REFERENCES investment_shareholders(id) ON DELETE CASCADE
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_idp_cycle ON investment_dividend_payments (cycle_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_idp_shareholder ON investment_dividend_payments (shareholder_id)");

    $db->exec("CREATE TABLE IF NOT EXISTS investment_purchase_lots (
        id BIGSERIAL PRIMARY KEY,
        cycle_id INT NOT NULL,
        shareholder_id INT NOT NULL,
        shares INT NOT NULL DEFAULT 0,
        amount DECIMAL(20,2) NOT NULL DEFAULT 0,
        purchase_date DATE NOT NULL,
        branch VARCHAR(100) DEFAULT '',
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_ipl_cycle FOREIGN KEY (cycle_id) REFERENCES investment_cycles(id) ON DELETE CASCADE,
        CONSTRAINT fk_ipl_shareholder FOREIGN KEY (shareholder_id) REFERENCES investment_shareholders(id) ON DELETE CASCADE
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ipl_cycle_shareholder ON investment_purchase_lots (cycle_id, shareholder_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ipl_purchase_date ON investment_purchase_lots (purchase_date)");

    invAddCol($db, 'investment_cycles', 'branch', "VARCHAR(100) DEFAULT ''");
    invAddCol($db, 'investment_transactions', 'branch', "VARCHAR(100) DEFAULT ''");
    invAddCol($db, 'investment_shareholders', 'branch', "VARCHAR(100) DEFAULT ''");
    try {
        $db->exec("UPDATE investment_shareholders SET branch = st.department
                   FROM staff st
                   WHERE investment_shareholders.linked_staff_id = st.id
                   AND (investment_shareholders.branch IS NULL OR investment_shareholders.branch = '') AND st.department IS NOT NULL AND st.department <> ''");
    } catch (Throwable $_) {}

    // Backfill legacy aggregated holdings into one purchase-lot per cycle/shareholder
    // only when no lots exist yet for that pair.
    try {
        $db->exec("INSERT INTO investment_purchase_lots (cycle_id, shareholder_id, shares, amount, purchase_date, branch, created_by)
                   SELECT h.cycle_id,
                          h.shareholder_id,
                          h.shares,
                          h.total_invested,
                          COALESCE(h.first_purchase_date, c.start_date, CURRENT_DATE),
                          COALESCE(c.branch, ''),
                          NULL
                   FROM investment_holdings h
                   INNER JOIN investment_cycles c ON c.id = h.cycle_id
                   LEFT JOIN (
                        SELECT cycle_id, shareholder_id, COUNT(*) AS lot_count
                        FROM investment_purchase_lots
                        GROUP BY cycle_id, shareholder_id
                   ) lp ON lp.cycle_id = h.cycle_id AND lp.shareholder_id = h.shareholder_id
                   WHERE h.shares > 0
                     AND COALESCE(lp.lot_count, 0) = 0");
    } catch (Throwable $_) {}

    $seed = $db->query("SELECT id FROM investment_settings LIMIT 1")->fetch();
    if (!$seed) {
        $db->prepare("INSERT INTO investment_settings (share_price, max_shares_per_person, default_dividend_rate, max_investment_target, min_bank_reserve, updated_by)
                      VALUES (25000, 10, 12, 50000000, 5000000, :uid)")
           ->execute([':uid' => (int)($GLOBALS['staff']['id'] ?? 0)]);
    }

    try {
        $db->prepare("INSERT INTO chart_of_accounts (code, name, type, category, description, is_active)
                      VALUES ('1450', 'Investment Reserve Fund', 'ASSET', 'Current Assets', 'Bank reserve for investment cycle commitments', TRUE)
                      ON CONFLICT (code) DO NOTHING")
           ->execute();
    } catch (Throwable $_) {}
}

function invConfig(PDO $db): array
{
    $cfg = $db->query("SELECT * FROM investment_settings ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$cfg) {
        return [
            'share_price' => 25000,
            'max_shares_per_person' => 10,
            'default_dividend_rate' => 12,
            'max_investment_target' => 50000000,
            'min_bank_reserve' => 5000000
        ];
    }
    return $cfg;
}

function invEnsureOperatingAccountArtifacts(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS operating_account (
        id SERIAL PRIMARY KEY,
        account_number VARCHAR(30) NOT NULL UNIQUE,
        account_name VARCHAR(200),
        balance DECIMAL(20,2) DEFAULT 0,
        currency VARCHAR(5) DEFAULT 'XAF',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS operating_account_transactions (
        id SERIAL PRIMARY KEY,
        ref VARCHAR(50),
        operating_account_id INT NOT NULL,
        date DATE NOT NULL,
        type VARCHAR(20) NOT NULL,
        description TEXT,
        amount DECIMAL(20,2) NOT NULL,
        balance_after DECIMAL(20,2) NOT NULL,
        operator VARCHAR(200),
        contra_account VARCHAR(100) DEFAULT '',
        transaction_type VARCHAR(50) DEFAULT '',
        branch VARCHAR(100) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("INSERT INTO operating_account (account_number, account_name, balance, currency)
               VALUES ('BANK-OP-0001', 'Bank Operating Fund', 0, 'XAF')
               ON CONFLICT (account_number) DO NOTHING");
}

function invGetOperatingBalance(PDO $db, string $branch = ''): float
{
    $sql = "SELECT COALESCE(SUM(debit),0)-COALESCE(SUM(credit),0) AS bal
            FROM general_ledger
            WHERE account_code = '1400'";
    $params = [];
    if ($branch !== '') {
        $sql .= " AND branch = :br";
        $params[':br'] = $branch;
    }
    try {
        $s = $db->prepare($sql);
        $s->execute($params);
        return (float)$s->fetchColumn();
    } catch (Throwable $_) {
        return 0.0;
    }
}

function invCurrentShareholder(PDO $db, array $staff): array
{
    $staffId = (int)($staff['id'] ?? 0);
    $email = trim((string)($staff['email'] ?? ''));
    $name = trim((string)($staff['full_name'] ?? ($staff['username'] ?? 'Staff User')));
    $phone = trim((string)($staff['phone'] ?? ''));
    $branch = invNormalizeBranch((string)($staff['department'] ?? ''));

    $q = $db->prepare("SELECT * FROM investment_shareholders WHERE linked_staff_id = :sid LIMIT 1");
    $q->execute([':sid' => $staffId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    if ($email !== '') {
        $q = $db->prepare("SELECT * FROM investment_shareholders WHERE email = :em LIMIT 1");
        $q->execute([':em' => $email]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $db->prepare("UPDATE investment_shareholders SET linked_staff_id = :sid WHERE id = :id")
               ->execute([':sid' => $staffId, ':id' => (int)$row['id']]);
            $row['linked_staff_id'] = $staffId;
            if (empty($row['branch']) && $branch !== '') {
                $db->prepare("UPDATE investment_shareholders SET branch = :br WHERE id = :id")
                   ->execute([':br' => $branch, ':id' => (int)$row['id']]);
                $row['branch'] = $branch;
            }
            return $row;
        }
    }

    $code = 'SH' . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);
    $ins = $db->prepare("INSERT INTO investment_shareholders (shareholder_code, name, email, phone, branch, status, linked_staff_id)
                         VALUES (:code, :name, :email, :phone, :br, 'active', :sid)");
    $ins->execute([
        ':code' => $code,
        ':name' => $name,
        ':email' => $email !== '' ? $email : (strtolower(str_replace(' ', '.', $name)) . '@atlas.local'),
        ':phone' => $phone,
        ':br' => $branch,
        ':sid' => $staffId
    ]);
    $id = (int)$db->lastInsertId();
    $sel = $db->prepare("SELECT * FROM investment_shareholders WHERE id = :id");
    $sel->execute([':id' => $id]);
    return (array)$sel->fetch(PDO::FETCH_ASSOC);
}

function invRecomputeCycleStatus(PDO $db): void
{
    $db->prepare("UPDATE investment_cycles
                  SET status = CASE
                    WHEN status = 'cancelled' THEN 'cancelled'
                    WHEN end_date < CURRENT_DATE THEN 'completed'
                    WHEN start_date <= CURRENT_DATE AND end_date >= CURRENT_DATE THEN 'active'
                    ELSE status
                  END")
       ->execute();
}

function invCycleSummary(PDO $db, int $cycleId): array
{
    $c = $db->prepare("SELECT * FROM investment_cycles WHERE id = :id");
    $c->execute([':id' => $cycleId]);
    $cycle = $c->fetch(PDO::FETCH_ASSOC);
    if (!$cycle) return [];
    $h = $db->prepare("SELECT COALESCE(SUM(total_invested),0) FROM investment_holdings WHERE cycle_id = :id");
    $h->execute([':id' => $cycleId]);
    $shareholderTotal = (float)$h->fetchColumn();
    $bankContribution = max(0.0, (float)$cycle['max_target'] - $shareholderTotal);
    return [
        'shareholder_total' => $shareholderTotal,
        'bank_contribution' => $bankContribution
    ];
}

function invCycleDurationDays(string $startDate, string $endDate): int
{
    $s = strtotime($startDate);
    $e = strtotime($endDate);
    if ($s === false || $e === false) return 0;
    return (int)floor(($e - $s) / 86400);
}

function invLoadState(PDO $db, array $staff, string $requestedBranch = ''): array
{
    invRecomputeCycleStatus($db);
    $cfg = invConfig($db);
    $me = invCurrentShareholder($db, $staff);
    $branchCtx = invResolveBranchContext($db, $staff, $requestedBranch);
    $selectedBranch = (string)($branchCtx['selectedBranch'] ?? '');
    $branches = (array)($branchCtx['branches'] ?? []);

    $cycles = [];
    if ($selectedBranch !== '') {
        $cs = $db->prepare("SELECT * FROM investment_cycles WHERE branch = :br ORDER BY start_date DESC, id DESC");
        $cs->execute([':br' => $selectedBranch]);
        $cycles = $cs->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $activeCycleId = 0;
    foreach ($cycles as $c) {
        if (($c['status'] ?? '') === 'active') { $activeCycleId = (int)$c['id']; break; }
    }

    $shareholders = [];
    if ($selectedBranch !== '') {
        $shareholdersSql = "SELECT s.*,
            COALESCE(h.shares,0) AS shares,
            COALESCE(h.total_invested,0) AS invested_amount,
            h.first_purchase_date AS join_date,
            h.cycle_id,
            c.cycle_code
          FROM investment_shareholders s
          LEFT JOIN investment_holdings h ON h.shareholder_id = s.id
          LEFT JOIN investment_cycles c ON c.id = h.cycle_id
          WHERE s.branch = :br
          ORDER BY s.name ASC, c.start_date DESC, h.id DESC";
        $shStmt = $db->prepare($shareholdersSql);
        $shStmt->execute([':br' => $selectedBranch]);
        $shareholders = $shStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $cycleMetrics = [];
    if ($selectedBranch !== '') {
        $metricsStmt = $db->prepare("SELECT
                                        c.id AS cycle_internal_id,
                                        c.cycle_code,
                                        c.max_target,
                                        COALESCE(SUM(h.total_invested),0) AS shareholder_total,
                                        COALESCE(SUM(h.shares),0) AS total_shares,
                                        COUNT(DISTINCT CASE WHEN COALESCE(h.shares,0) > 0 THEN h.shareholder_id END) AS shareholder_count
                                     FROM investment_cycles c
                                     LEFT JOIN investment_holdings h ON h.cycle_id = c.id
                                     WHERE c.branch = :br
                                     GROUP BY c.id
                                     ORDER BY c.start_date DESC, c.id DESC");
        $metricsStmt->execute([':br' => $selectedBranch]);
        $metricRows = $metricsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($metricRows as $mr) {
            $code = (string)($mr['cycle_code'] ?? '');
            if ($code === '') continue;
            $shareholderTotal = (float)($mr['shareholder_total'] ?? 0);
            $maxTarget = (float)($mr['max_target'] ?? 0);
            $cycleMetrics[$code] = [
                'cycleId' => $code,
                'internalId' => (int)($mr['cycle_internal_id'] ?? 0),
                'shareholderTotal' => $shareholderTotal,
                'bankContribution' => max(0.0, $maxTarget - $shareholderTotal),
                'totalShares' => (int)($mr['total_shares'] ?? 0),
                'shareholderCount' => (int)($mr['shareholder_count'] ?? 0),
                'maxTarget' => $maxTarget
            ];
        }
    }

    $tx = [];
    if ($selectedBranch !== '') {
        $txStmt = $db->prepare("SELECT it.*, s.name AS shareholder_name, c.cycle_code
                                FROM investment_transactions it
                                LEFT JOIN investment_shareholders s ON s.id = it.shareholder_id
                                LEFT JOIN investment_cycles c ON c.id = it.cycle_id
                                WHERE it.branch = :br
                                ORDER BY it.txn_date DESC, it.id DESC
                                LIMIT 500");
        $txStmt->execute([':br' => $selectedBranch]);
        $tx = $txStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $div = [];
    if ($selectedBranch !== '') {
        $divStmt = $db->prepare("SELECT c.cycle_code, c.start_date, c.end_date, c.max_target, c.actual_div_rate, c.dividend_paid,
                                        COALESCE(SUM(d.payout_amount),0) AS total_payout
                                 FROM investment_cycles c
                                 LEFT JOIN investment_dividend_payments d ON d.cycle_id = c.id
                                 WHERE c.dividend_paid = TRUE AND c.branch = :br
                                 GROUP BY c.id
                                 ORDER BY c.end_date DESC");
        $divStmt->execute([':br' => $selectedBranch]);
        $div = $divStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $meShares = 0;
    if ($activeCycleId > 0) {
        $ms = $db->prepare("SELECT COALESCE(shares,0) FROM investment_holdings WHERE cycle_id = :cid AND shareholder_id = :sid LIMIT 1");
        $ms->execute([':cid' => $activeCycleId, ':sid' => (int)$me['id']]);
        $meShares = (int)$ms->fetchColumn();
    }

    $summary = [];
    if ($activeCycleId > 0) {
        $summary = invCycleSummary($db, $activeCycleId);
    }

    $myHoldingsStmt = $db->prepare("SELECT h.*, c.cycle_code, c.name AS cycle_name, c.start_date, c.end_date, c.status AS cycle_status, c.projected_div_rate, c.dividend_paid
                                    FROM investment_holdings h
                                    INNER JOIN investment_cycles c ON c.id = h.cycle_id
                                    WHERE h.shareholder_id = :sid" . ($selectedBranch !== '' ? " AND c.branch = :br" : "") . "
                                    ORDER BY c.start_date DESC, h.id DESC");
    $mhParams = [':sid' => (int)$me['id']];
    if ($selectedBranch !== '') $mhParams[':br'] = $selectedBranch;
    $myHoldingsStmt->execute($mhParams);
    $myHoldings = $myHoldingsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $myDivStmt = $db->prepare("SELECT d.*, c.cycle_code, c.name AS cycle_name, c.start_date, c.end_date
                               FROM investment_dividend_payments d
                               INNER JOIN investment_cycles c ON c.id = d.cycle_id
                               WHERE d.shareholder_id = :sid" . ($selectedBranch !== '' ? " AND c.branch = :br" : "") . "
                               ORDER BY d.paid_date DESC, d.id DESC");
    $mdParams = [':sid' => (int)$me['id']];
    if ($selectedBranch !== '') $mdParams[':br'] = $selectedBranch;
    $myDivStmt->execute($mdParams);
    $myDividends = $myDivStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $canManage = invCanManage($staff);
    $moduleAccessLevel = invModuleAccessLevel($staff);
    return [
        'config' => [
            'sharePrice' => (float)$cfg['share_price'],
            'maxSharesPerPerson' => (int)$cfg['max_shares_per_person'],
            'maxInvestmentTarget' => (float)$cfg['max_investment_target'],
            'defaultDividendRate' => (float)$cfg['default_dividend_rate'],
            'minBankReserve' => (float)$cfg['min_bank_reserve']
        ],
        'currentUser' => [
            'id' => $me['shareholder_code'],
            'internalId' => (int)$me['id'],
            'name' => $me['name'],
            'email' => $me['email'],
            'phone' => $me['phone'],
            'branch' => (string)($me['branch'] ?? ''),
            'status' => $me['status'],
            'shares' => $meShares,
            'role' => strtoupper((string)($staff['role'] ?? ''))
        ],
        'summary' => [
            'activeCycleId' => $activeCycleId,
            'shareholderTotal' => (float)($summary['shareholder_total'] ?? 0),
            'bankContribution' => (float)($summary['bank_contribution'] ?? 0),
            'operatingFundBalance' => invGetOperatingBalance($db, $selectedBranch),
            'branch' => $selectedBranch
        ],
        'bankDefaultShareholder' => [
            'id' => 'BANK-DEFAULT',
            'name' => 'Atlas Bank (Default Cover)',
            'sharesEquivalent' => $cfg['share_price'] > 0 ? round(((float)($summary['bank_contribution'] ?? 0)) / (float)$cfg['share_price'], 2) : 0.0,
            'investedAmount' => (float)($summary['bank_contribution'] ?? 0),
            'status' => 'active'
        ],
        'branches' => array_map(static function(array $b): array {
            return [
                'code' => (string)($b['code'] ?? ''),
                'name' => (string)($b['name'] ?? ''),
                'status' => (string)($b['status'] ?? 'ACTIVE')
            ];
        }, $branches),
        'selectedBranch' => $selectedBranch,
        'cycleMetrics' => array_values($cycleMetrics),
        'permissions' => [
            'moduleAccess' => $moduleAccessLevel,
            'canBuyShares' => $moduleAccessLevel !== 'VIEW_ONLY',
            'canViewAdminPanels' => in_array(strtoupper((string)($staff['role'] ?? '')), ['ADMIN', 'MANAGER', 'ACCOUNTANT'], true),
            'canManageShareholders' => $canManage,
            'canAssignSharePurchases' => $canManage,
            'canManageCycles' => $canManage,
            'canManageSettings' => $canManage,
            'canProcessDividends' => $canManage
        ],
        'cycles' => array_map(static function(array $c): array {
            return [
                'id' => $c['cycle_code'],
                'internalId' => (int)$c['id'],
                'name' => $c['name'],
                'startDate' => $c['start_date'],
                'endDate' => $c['end_date'],
                'maxTarget' => (float)$c['max_target'],
                'projectedDivRate' => (float)$c['projected_div_rate'],
                'status' => $c['status'],
                'dividendPaid' => (int)$c['dividend_paid'] === 1,
                'actualDivRate' => $c['actual_div_rate'] !== null ? (float)$c['actual_div_rate'] : null,
                'branch' => (string)($c['branch'] ?? '')
            ];
        }, $cycles),
        'shareholders' => array_map(static function(array $s): array {
            return [
                'id' => $s['shareholder_code'],
                'internalId' => (int)$s['id'],
                'name' => $s['name'],
                'email' => $s['email'],
                'phone' => $s['phone'],
                'shares' => (int)($s['shares'] ?? 0),
                'joinDate' => $s['join_date'],
                'status' => $s['status'],
                'cycleId' => (string)($s['cycle_code'] ?? ''),
                'investedAmount' => (float)($s['invested_amount'] ?? 0)
            ];
        }, $shareholders),
        'transactions' => array_map(static function(array $t): array {
            return [
                'date' => $t['txn_date'],
                'shareholderId' => (int)($t['shareholder_id'] ?? 0),
                'shareholder' => $t['shareholder_name'] ?? 'Bank',
                'action' => ucfirst(strtolower((string)$t['action'])),
                'shares' => (int)$t['shares'],
                'amount' => (float)$t['amount'],
                'cycle' => $t['cycle_code'] ?? '',
                'ref' => $t['txn_ref']
            ];
        }, $tx),
        'dividendPayments' => array_map(static function(array $d): array {
            return [
                'cycleId' => (string)$d['cycle_code'],
                'period' => ($d['start_date'] ?? '') . ' - ' . ($d['end_date'] ?? ''),
                'totalPool' => (float)($d['max_target'] ?? 0),
                'divRate' => (float)($d['actual_div_rate'] ?? 0),
                'totalPayout' => (float)($d['total_payout'] ?? 0),
                'paidDate' => $d['end_date'] ?? '',
                'status' => ((int)($d['dividend_paid'] ?? 0) === 1) ? 'Paid' : 'Pending'
            ];
        }, $div),
        'myHoldings' => array_map(static function(array $h): array {
            return [
                'cycleId' => (string)$h['cycle_code'],
                'cycleName' => (string)($h['cycle_name'] ?? $h['cycle_code']),
                'cycleStatus' => (string)($h['cycle_status'] ?? ''),
                'startDate' => (string)($h['start_date'] ?? ''),
                'endDate' => (string)($h['end_date'] ?? ''),
                'shares' => (int)($h['shares'] ?? 0),
                'investedAmount' => (float)($h['total_invested'] ?? 0),
                'firstPurchaseDate' => (string)($h['first_purchase_date'] ?? ''),
                'lastPurchaseDate' => (string)($h['last_purchase_date'] ?? ''),
                'projectedDivRate' => (float)($h['projected_div_rate'] ?? 0),
                'dividendPaid' => (int)($h['dividend_paid'] ?? 0) === 1
            ];
        }, $myHoldings),
        'myDividendPayments' => array_map(static function(array $d): array {
            return [
                'cycleId' => (string)$d['cycle_code'],
                'cycleName' => (string)($d['cycle_name'] ?? $d['cycle_code']),
                'period' => ($d['start_date'] ?? '') . ' - ' . ($d['end_date'] ?? ''),
                'shares' => (int)($d['shares'] ?? 0),
                'divRate' => (float)($d['div_rate'] ?? 0),
                'payoutAmount' => (float)($d['payout_amount'] ?? 0),
                'paidDate' => (string)($d['paid_date'] ?? ''),
                'status' => (string)($d['status'] ?? 'Paid')
            ];
        }, $myDividends),
        'role' => strtoupper((string)($staff['role'] ?? ''))
    ];
}

invEnsureSchema($db);

if ($method === 'GET') {
    $requestedBranch = trim((string)($_GET['branch'] ?? ''));
    successResponse(invLoadState($db, $staff, $requestedBranch));
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

if ($method === 'POST' && $id === 'purchase') {
    invRequireWrite($staff);
    $shareCount = (int)($input['shares'] ?? 0);
    if ($shareCount <= 0) {
        validationError(['shares' => 'Shares must be greater than zero.'], 'Validation failed');
    }

    $cfg = invConfig($db);
    $sharePrice = (float)$cfg['share_price'];
    $maxSharesPer = (int)$cfg['max_shares_per_person'];
    $branchCtx = invResolveBranchContext($db, $staff, (string)($input['branch'] ?? ($_GET['branch'] ?? '')));
    $branch = (string)($branchCtx['selectedBranch'] ?? '');
    if ($branch === '') {
        errorResponse('No branch context available for investment purchase.', 422);
    }

    invEnsureOperatingAccountArtifacts($db);
    invRecomputeCycleStatus($db);
    $activeStmt = $db->prepare("SELECT * FROM investment_cycles WHERE status = 'active' AND branch = :br ORDER BY id DESC LIMIT 1");
    $activeStmt->execute([':br' => $branch]);
    $active = $activeStmt->fetch(PDO::FETCH_ASSOC);
    if (!$active) {
        errorResponse('No active investment cycle.', 422);
    }

    $cycleId = (int)$active['id'];
    $requestedShareholderCode = trim((string)($input['shareholderId'] ?? ''));
    $targetShareholder = null;
    if ($requestedShareholderCode !== '') {
        invRequireAdmin($staff);
        $sh = $db->prepare("SELECT * FROM investment_shareholders
                            WHERE shareholder_code = :code
                              AND branch = :br
                              AND status = 'active'
                            LIMIT 1");
        $sh->execute([':code' => $requestedShareholderCode, ':br' => $branch]);
        $targetShareholder = $sh->fetch(PDO::FETCH_ASSOC);
        if (!$targetShareholder) {
            validationError(['shareholderId' => 'Selected shareholder is invalid for this branch.'], 'Validation failed');
        }
    } else {
        $targetShareholder = invCurrentShareholder($db, $staff);
        $targetBranch = invNormalizeBranch((string)($targetShareholder['branch'] ?? ''));
        if ($targetBranch !== '' && strcasecmp($targetBranch, $branch) !== 0) {
            validationError(['branch' => 'Your shareholder profile is not linked to the selected branch.'], 'Validation failed');
        }
    }
    $sid = (int)($targetShareholder['id'] ?? 0);
    if ($sid <= 0) {
        errorResponse('No valid shareholder profile found for this purchase.', 422);
    }
    $holdingStmt = $db->prepare("SELECT * FROM investment_holdings WHERE cycle_id = :cid AND shareholder_id = :sid LIMIT 1");
    $holdingStmt->execute([':cid' => $cycleId, ':sid' => $sid]);
    $holding = $holdingStmt->fetch(PDO::FETCH_ASSOC);
    $existingShares = (int)($holding['shares'] ?? 0);
    if (($existingShares + $shareCount) > $maxSharesPer) {
        validationError(['shares' => 'Maximum ' . $maxSharesPer . ' shares allowed per user.'], 'Validation failed');
    }

    $summary = invCycleSummary($db, $cycleId);
    $purchaseAmount = round($shareCount * $sharePrice, 2);
    $remainingCapacity = max(0.0, (float)$active['max_target'] - (float)$summary['shareholder_total']);
    if ($purchaseAmount > $remainingCapacity) {
        validationError(['shares' => 'Purchase exceeds remaining cycle capacity.'], 'Validation failed');
    }

    $ref = 'INV-PUR-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    $staffId = (int)($staff['id'] ?? 0);
    $staffName = (string)($staff['full_name'] ?? $staff['username'] ?? 'SYSTEM');
    $today = date('Y-m-d');
    $opBefore = invGetOperatingBalance($db, $branch);
    $opAfter = $opBefore + $purchaseAmount;

    try {
        $db->beginTransaction();

        if ($holding) {
            $db->prepare("UPDATE investment_holdings
                          SET shares = shares + :sh,
                              total_invested = total_invested + :amt,
                              last_purchase_date = :dt
                          WHERE id = :id")
               ->execute([
                   ':sh' => $shareCount,
                   ':amt' => $purchaseAmount,
                   ':dt' => $today,
                   ':id' => (int)$holding['id']
               ]);
        } else {
            $db->prepare("INSERT INTO investment_holdings (cycle_id, shareholder_id, shares, total_invested, first_purchase_date, last_purchase_date)
                          VALUES (:cid, :sid, :sh, :amt, :dt_first, :dt_last)")
               ->execute([
                   ':cid' => $cycleId,
                   ':sid' => $sid,
                   ':sh' => $shareCount,
                   ':amt' => $purchaseAmount,
                   ':dt_first' => $today,
                   ':dt_last' => $today
               ]);
        }

        $db->prepare("INSERT INTO investment_purchase_lots
                      (cycle_id, shareholder_id, shares, amount, purchase_date, branch, created_by)
                      VALUES (:cid, :sid, :sh, :amt, :dt, :br, :uid)")
           ->execute([
               ':cid' => $cycleId,
               ':sid' => $sid,
               ':sh' => $shareCount,
               ':amt' => $purchaseAmount,
               ':dt' => $today,
               ':br' => $branch,
               ':uid' => $staffId
           ]);

        $db->prepare("INSERT INTO investment_transactions (txn_ref, txn_date, shareholder_id, cycle_id, action, shares, amount, description, branch, created_by)
                      VALUES (:ref, :dt, :sid, :cid, 'PURCHASE', :sh, :amt, :desc, :br, :uid)")
           ->execute([
               ':ref' => $ref,
               ':dt' => $today,
               ':sid' => $sid,
               ':cid' => $cycleId,
               ':sh' => $shareCount,
               ':amt' => $purchaseAmount,
               ':desc' => 'Share purchase for active cycle (' . (string)($targetShareholder['name'] ?? 'Shareholder') . ')',
               ':br' => $branch,
               ':uid' => $staffId
           ]);

        $db->prepare("UPDATE investment_cycles
                      SET bank_reserved_amount = GREATEST(0, bank_reserved_amount - :amt)
                      WHERE id = :cid")
           ->execute([':amt' => $purchaseAmount, ':cid' => $cycleId]);

        postToGLStrict('1400', '1450', $purchaseAmount, $ref, 'Investment purchase reduces bank reserve exposure', 'INVESTMENT_PURCHASE', $branch, $staffId);

        $oaId = (int)$db->query("SELECT id FROM operating_account WHERE account_number = 'BANK-OP-0001' LIMIT 1")->fetchColumn();
        $db->prepare("INSERT INTO operating_account_transactions
                      (ref, operating_account_id, date, type, description, amount, balance_after, operator, contra_account, transaction_type, branch)
                      VALUES (:ref, :oaid, :dt, 'CREDIT', :desc, :amt, :bal, :op, '1450', 'INVESTMENT_PURCHASE_RELEASE', :br)")
           ->execute([
               ':ref' => $ref,
               ':oaid' => $oaId,
               ':dt' => $today,
               ':desc' => 'Investment purchase inflow release to operating fund',
               ':amt' => $purchaseAmount,
               ':bal' => $opAfter,
               ':op' => $staffName,
               ':br' => $branch
           ]);

        $db->prepare("UPDATE operating_account SET balance = :bal WHERE account_number = 'BANK-OP-0001'")
           ->execute([':bal' => $opAfter]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        errorResponse('Failed to complete investment purchase: ' . $e->getMessage(), 500);
    }

    successResponse(invLoadState($db, $staff, $branch), 'Share purchase completed.');
}

if ($method === 'POST' && $id === 'shareholders') {
    invRequireAdmin($staff);
    invRequireWrite($staff);
    $branchCtx = invResolveBranchContext($db, $staff, (string)($input['branch'] ?? ($_GET['branch'] ?? '')));
    $branch = (string)($branchCtx['selectedBranch'] ?? '');
    if ($branch === '') {
        errorResponse('No branch context available for shareholder operation.', 422);
    }
    $name = trim((string)($input['name'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));
    $shares = (int)($input['shares'] ?? 0);
    if ($name === '' || $email === '') {
        validationError(['name' => 'Name required', 'email' => 'Email required'], 'Validation failed');
    }
    $cfg = invConfig($db);
    if ($shares < 0 || $shares > (int)$cfg['max_shares_per_person']) {
        validationError(['shares' => 'Invalid initial shares.'], 'Validation failed');
    }
    $code = 'SH' . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);
    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO investment_shareholders (shareholder_code, name, email, phone, branch, status)
                      VALUES (:code, :name, :email, :phone, :br, 'active')")
           ->execute([
               ':code' => $code,
               ':name' => $name,
               ':email' => $email,
               ':phone' => $phone,
               ':br' => $branch
           ]);
        $sid = (int)$db->lastInsertId();

        if ($shares > 0) {
            invEnsureOperatingAccountArtifacts($db);
            invRecomputeCycleStatus($db);
            $activeStmt = $db->prepare("SELECT * FROM investment_cycles WHERE status = 'active' AND branch = :br ORDER BY id DESC LIMIT 1");
            $activeStmt->execute([':br' => $branch]);
            $active = $activeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$active) {
                throw new RuntimeException('No active investment cycle for initial share allocation.');
            }
            $purchaseAmount = round($shares * (float)$cfg['share_price'], 2);
            $summary = invCycleSummary($db, (int)$active['id']);
            $remaining = max(0.0, (float)$active['max_target'] - (float)$summary['shareholder_total']);
            if ($purchaseAmount > $remaining) {
                throw new RuntimeException('Initial shares exceed remaining cycle capacity.');
            }
            $today = date('Y-m-d');
            $db->prepare("INSERT INTO investment_holdings (cycle_id, shareholder_id, shares, total_invested, first_purchase_date, last_purchase_date)
                          VALUES (:cid, :sid, :sh, :amt, :dt_first, :dt_last)")
               ->execute([
                   ':cid' => (int)$active['id'],
                   ':sid' => $sid,
                   ':sh' => $shares,
                   ':amt' => $purchaseAmount,
                   ':dt_first' => $today,
                   ':dt_last' => $today
               ]);
            $db->prepare("INSERT INTO investment_purchase_lots
                          (cycle_id, shareholder_id, shares, amount, purchase_date, branch, created_by)
                          VALUES (:cid, :sid, :sh, :amt, :dt, :br, :uid)")
               ->execute([
                   ':cid' => (int)$active['id'],
                   ':sid' => $sid,
                   ':sh' => $shares,
                   ':amt' => $purchaseAmount,
                   ':dt' => $today,
                   ':br' => $branch,
                   ':uid' => (int)($staff['id'] ?? 0)
               ]);
            $ref = 'INV-PUR-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            $db->prepare("INSERT INTO investment_transactions (txn_ref, txn_date, shareholder_id, cycle_id, action, shares, amount, description, branch, created_by)
                          VALUES (:ref, :dt, :sid, :cid, 'PURCHASE', :sh, :amt, :desc, :br, :uid)")
               ->execute([
                   ':ref' => $ref,
                   ':dt' => $today,
                   ':sid' => $sid,
                   ':cid' => (int)$active['id'],
                   ':sh' => $shares,
                   ':amt' => $purchaseAmount,
                   ':desc' => 'Initial share allocation during shareholder registration',
                   ':br' => $branch,
                   ':uid' => (int)($staff['id'] ?? 0)
               ]);
            $db->prepare("UPDATE investment_cycles SET bank_reserved_amount = GREATEST(0, bank_reserved_amount - :amt) WHERE id = :cid")
               ->execute([':amt' => $purchaseAmount, ':cid' => (int)$active['id']]);
            postToGLStrict('1400', '1450', $purchaseAmount, $ref, 'Initial shareholder investment reduces bank reserve exposure', 'INVESTMENT_PURCHASE', $branch, (int)($staff['id'] ?? 0));
            $opBefore = invGetOperatingBalance($db, $branch);
            $opAfter = $opBefore + $purchaseAmount;
            $oaId = (int)$db->query("SELECT id FROM operating_account WHERE account_number = 'BANK-OP-0001' LIMIT 1")->fetchColumn();
            $db->prepare("INSERT INTO operating_account_transactions
                          (ref, operating_account_id, date, type, description, amount, balance_after, operator, contra_account, transaction_type, branch)
                          VALUES (:ref, :oaid, :dt, 'CREDIT', :desc, :amt, :bal, :op, '1450', 'INVESTMENT_PURCHASE_RELEASE', :br)")
               ->execute([
                   ':ref' => $ref,
                   ':oaid' => $oaId,
                   ':dt' => $today,
                   ':desc' => 'Initial shareholder purchase inflow release to operating fund',
                   ':amt' => $purchaseAmount,
                   ':bal' => $opAfter,
                   ':op' => (string)($staff['full_name'] ?? $staff['username'] ?? 'SYSTEM'),
                   ':br' => $branch
               ]);
            $db->prepare("UPDATE operating_account SET balance = :bal WHERE account_number = 'BANK-OP-0001'")
               ->execute([':bal' => $opAfter]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        errorResponse('Failed to add shareholder: ' . $e->getMessage(), 500);
    }
    successResponse(invLoadState($db, $staff, $branch), 'Shareholder added.');
}

if ($method === 'POST' && $id === 'cycles' && $sub === '') {
    invRequireAdmin($staff);
    invRequireWrite($staff);
    $branchCtx = invResolveBranchContext($db, $staff, (string)($input['branch'] ?? ($_GET['branch'] ?? '')));
    $branch = (string)($branchCtx['selectedBranch'] ?? '');
    if ($branch === '') {
        errorResponse('No branch context available for cycle creation.', 422);
    }
    invEnsureOperatingAccountArtifacts($db);
    invRecomputeCycleStatus($db);
    $activeStmt = $db->prepare("SELECT id, name FROM investment_cycles WHERE status = 'active' AND branch = :br LIMIT 1");
    $activeStmt->execute([':br' => $branch]);
    $active = $activeStmt->fetch(PDO::FETCH_ASSOC);
    if ($active) {
        errorResponse('Active cycle exists. Close it before creating another.', 422);
    }

    $cfg = invConfig($db);
    $name = trim((string)($input['name'] ?? ''));
    $start = trim((string)($input['startDate'] ?? $input['start_date'] ?? ''));
    $end = trim((string)($input['endDate'] ?? $input['end_date'] ?? ''));
    $target = (float)($input['maxTarget'] ?? $input['max_target'] ?? 0);
    $rate = (float)($input['projectedDivRate'] ?? $input['projected_div_rate'] ?? $cfg['default_dividend_rate']);
    if ($name === '' || $start === '' || $end === '') {
        validationError(['name' => 'Name required', 'dates' => 'Start and end date required'], 'Validation failed');
    }
    if (strtotime($end) <= strtotime($start)) {
        validationError(['endDate' => 'End date must be date.'], 'Validation failed');
    }
    $days = invCycleDurationDays($start, $end);
    if ($days < 365 || $days > 366) {
        validationError(['endDate' => 'Investment cycle must be exactly one year (365-366 days).'], 'Validation failed');
    }
    if ($target <= 0) {
        validationError(['maxTarget' => 'Max target must be greater than zero.'], 'Validation failed');
    }
    $configuredCap = (float)($cfg['max_investment_target'] ?? 0);
    if ($configuredCap > 0 && $target > $configuredCap) {
        validationError(['maxTarget' => 'Cycle allocation cannot exceed configured investment cap (' . number_format($configuredCap, 0, '.', ',') . ').'], 'Validation failed');
    }
    $opBal = invGetOperatingBalance($db, $branch);
    $minReserve = (float)$cfg['min_bank_reserve'];
    if (($opBal - $target) < $minReserve) {
        errorResponse('Insufficient operating fund to reserve this cycle target while preserving minimum reserve.', 422);
    }

    $ref = 'INV-CYC-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    $staffId = (int)($staff['id'] ?? 0);
    $staffName = (string)($staff['full_name'] ?? $staff['username'] ?? 'SYSTEM');
    $today = date('Y-m-d');
    $after = $opBal - $target;

    try {
        $db->beginTransaction();
        $code = 'CYC-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        $db->prepare("INSERT INTO investment_cycles
                      (cycle_code, name, start_date, end_date, max_target, projected_div_rate, status, dividend_paid, bank_reserved_amount, branch, created_by)
                      VALUES (:code, :name, :s, :e, :target, :r, 'active', 0, :reserved, :br, :uid)")
           ->execute([
               ':code' => $code,
               ':name' => $name,
               ':s' => $start,
               ':e' => $end,
               ':target' => $target,
               ':reserved' => $target,
               ':r' => $rate,
               ':br' => $branch,
               ':uid' => $staffId
           ]);
        $cid = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO investment_transactions
                      (txn_ref, txn_date, shareholder_id, cycle_id, action, shares, amount, description, branch, created_by)
                      VALUES (:ref, :dt, NULL, :cid, 'BANK_RESERVE', 0, :amt, :desc, :br, :uid)")
           ->execute([
               ':ref' => $ref,
               ':dt' => $today,
               ':cid' => $cid,
               ':amt' => $target,
               ':desc' => 'Bank operating fund reserve for new investment cycle',
               ':br' => $branch,
               ':uid' => $staffId
           ]);

        postToGLStrict('1450', '1400', $target, $ref, 'Reserve operating fund for investment cycle target', 'INVESTMENT_BANK_RESERVE', $branch, $staffId);

        $oaId = (int)$db->query("SELECT id FROM operating_account WHERE account_number = 'BANK-OP-0001' LIMIT 1")->fetchColumn();
        $db->prepare("INSERT INTO operating_account_transactions
                      (ref, operating_account_id, date, type, description, amount, balance_after, operator, contra_account, transaction_type, branch)
                      VALUES (:ref, :oaid, :dt, 'DEBIT', :desc, :amt, :bal, :op, '1450', 'INVESTMENT_BANK_RESERVE', :br)")
           ->execute([
               ':ref' => $ref,
               ':oaid' => $oaId,
               ':dt' => $today,
               ':desc' => 'Reserve for investment cycle target',
               ':amt' => $target,
               ':bal' => $after,
               ':op' => $staffName,
               ':br' => $branch
           ]);
        $db->prepare("UPDATE operating_account SET balance = :bal WHERE account_number = 'BANK-OP-0001'")
           ->execute([':bal' => $after]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        errorResponse('Failed to create investment cycle: ' . $e->getMessage(), 500);
    }

    successResponse(invLoadState($db, $staff, $branch), 'Investment cycle created.');
}

if ($method === 'POST' && $id === 'cycles' && $sub !== '') {
    invRequireAdmin($staff);
    invRequireWrite($staff);
    $branchCtx = invResolveBranchContext($db, $staff, (string)($input['branch'] ?? ($_GET['branch'] ?? '')));
    $branch = (string)($branchCtx['selectedBranch'] ?? '');
    $action = strtoupper(trim((string)($input['action'] ?? '')));
    if ($action !== 'CLOSE') {
        validationError(['action' => 'Unsupported cycle action.'], 'Validation failed');
    }
    $u = $db->prepare("UPDATE investment_cycles
                       SET status = 'completed'
                       WHERE cycle_code = :code AND status <> 'cancelled'" . ($branch !== '' ? " AND branch = :br" : ""));
    $uParams = [':code' => trim((string)$sub)];
    if ($branch !== '') $uParams[':br'] = $branch;
    $u->execute($uParams);
    if ($u->rowCount() < 1) {
        errorResponse('Cycle not found or already closed.', 404);
    }
    successResponse(invLoadState($db, $staff, $branch), 'Cycle closed successfully.');
}

if ($method === 'POST' && $id === 'dividends') {
    invRequireAdmin($staff);
    invRequireWrite($staff);
    $branchCtx = invResolveBranchContext($db, $staff, (string)($input['branch'] ?? ($_GET['branch'] ?? '')));
    $branch = (string)($branchCtx['selectedBranch'] ?? '');
    $staffBranch = invNormalizeBranch((string)($staff['department'] ?? ''));
    $cycleCode = trim((string)$sub);
    if ($cycleCode === '') {
        validationError(['cycle' => 'Cycle id is required.'], 'Validation failed');
    }
    $c = $db->prepare("SELECT * FROM investment_cycles WHERE cycle_code = :code" . ($branch !== '' ? " AND branch = :br" : "") . " LIMIT 1");
    $cParams = [':code' => $cycleCode];
    if ($branch !== '') $cParams[':br'] = $branch;
    $c->execute($cParams);
    $cycle = $c->fetch(PDO::FETCH_ASSOC);
    if (!$cycle) {
        errorResponse('Cycle not found.', 404);
    }
    if ((int)$cycle['dividend_paid'] === 1) {
        errorResponse('Dividend already paid for this cycle.', 422);
    }
    $startTs = strtotime((string)$cycle['start_date']);
    $endTs = strtotime((string)$cycle['end_date']);
    $todayTs = strtotime(date('Y-m-d'));
    $cycleDays = invCycleDurationDays((string)$cycle['start_date'], (string)$cycle['end_date']);
    if ($todayTs < $endTs || $cycleDays < 365 || $cycleDays > 366) {
        errorResponse('Dividends can only be paid full one-year cycle has completed.', 422);
    }
    $rate = (float)($input['rate'] ?? $cycle['projected_div_rate']);
    if ($rate < 0 || $rate > 100) {
        validationError(['rate' => 'Dividend rate must be between 0 and 100.'], 'Validation failed');
    }
    $lotsStmt = $db->prepare("SELECT shareholder_id, shares, amount, purchase_date
                              FROM investment_purchase_lots
                              WHERE cycle_id = :cid AND shares > 0
                              ORDER BY shareholder_id ASC, purchase_date ASC, id ASC");
    $lotsStmt->execute([':cid' => (int)$cycle['id']]);
    $lots = $lotsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rowsByShareholder = [];
    foreach ($lots as $lot) {
        $sid = (int)($lot['shareholder_id'] ?? 0);
        if ($sid <= 0) continue;
        $shares = (int)($lot['shares'] ?? 0);
        $amount = (float)($lot['amount'] ?? 0);
        if ($shares <= 0 || $amount <= 0) continue;
        $purchaseDate = (string)($lot['purchase_date'] ?? '');
        $purchaseTs = strtotime($purchaseDate);
        $effectiveStartTs = max($startTs, ($purchaseTs === false ? $startTs : $purchaseTs));
        $heldDays = (int)floor(($endTs - $effectiveStartTs) / 86400) + 1;
        if ($heldDays < 0) $heldDays = 0;
        $timeFactor = min(1.0, max(0.0, $heldDays / 365.0));
        $eligibleAmount = round($amount * $timeFactor, 2);
        if (!isset($rowsByShareholder[$sid])) {
            $rowsByShareholder[$sid] = [
                'shareholder_id' => $sid,
                'shares' => 0,
                'eligible_invested' => 0.0
            ];
        }
        $rowsByShareholder[$sid]['shares'] += $shares;
        $rowsByShareholder[$sid]['eligible_invested'] += $eligibleAmount;
    }
    $rows = array_values($rowsByShareholder);
    $refBase = 'INV-DIV-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
    $today = date('Y-m-d');
    try {
        $db->beginTransaction();
        $total = 0.0;
        foreach ($rows as $r) {
            $eligibleInvested = round((float)($r['eligible_invested'] ?? 0.0), 2);
            $payout = round($eligibleInvested * ($rate / 100), 2);
            if ($payout <= 0) continue;
            $total += $payout;
            $ref = $refBase . '-' . (int)$r['shareholder_id'];
            $db->prepare("INSERT INTO investment_dividend_payments
                          (cycle_id, shareholder_id, shares, div_rate, payout_amount, paid_date, status, txn_ref)
                          VALUES (:cid, :sid, :sh, :r, :amt, :dt, 'Paid', :ref)")
               ->execute([
                   ':cid' => (int)$cycle['id'],
                   ':sid' => (int)$r['shareholder_id'],
                   ':sh' => (int)$r['shares'],
                   ':r' => $rate,
                   ':amt' => $payout,
                   ':dt' => $today,
                   ':ref' => $ref
               ]);
            $db->prepare("INSERT INTO investment_transactions
                          (txn_ref, txn_date, shareholder_id, cycle_id, action, shares, amount, description, branch, created_by)
                          VALUES (:ref, :dt, :sid, :cid, 'DIVIDEND', :sh, :amt, :desc, :br, :uid)")
               ->execute([
                   ':ref' => $ref,
                   ':dt' => $today,
                   ':sid' => (int)$r['shareholder_id'],
                   ':cid' => (int)$cycle['id'],
                   ':sh' => (int)$r['shares'],
                   ':amt' => $payout,
                   ':desc' => 'Dividend payout for completed annual cycle',
                   ':br' => ($branch !== '' ? $branch : $staffBranch),
                   ':uid' => (int)($staff['id'] ?? 0)
               ]);
        }
        $db->prepare("UPDATE investment_cycles SET dividend_paid = TRUE, actual_div_rate = :r, status = 'completed' WHERE id = :id")
           ->execute([':r' => $rate, ':id' => (int)$cycle['id']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        errorResponse('Failed to process dividend: ' . $e->getMessage(), 500);
    }
    successResponse(invLoadState($db, $staff, $branch), 'Dividend processed.');
}

if ($method === 'PUT' && $id === 'settings') {
    invRequireAdmin($staff);
    invRequireWrite($staff);
    $branchCtx = invResolveBranchContext($db, $staff, (string)($input['branch'] ?? ($_GET['branch'] ?? '')));
    $branch = (string)($branchCtx['selectedBranch'] ?? '');
    $cfg = invConfig($db);
    $sharePrice = (float)($input['sharePrice'] ?? $cfg['share_price']);
    $maxShares = (int)($input['maxSharesPerPerson'] ?? $cfg['max_shares_per_person']);
    $target = (float)($input['maxInvestmentTarget'] ?? $cfg['max_investment_target']);
    $rate = (float)($input['defaultDividendRate'] ?? $cfg['default_dividend_rate']);
    $reserve = (float)($input['minBankReserve'] ?? $cfg['min_bank_reserve']);
    if ($sharePrice <= 0 || $maxShares < 1 || $target <= 0 || $rate < 0 || $rate > 100 || $reserve < 0) {
        validationError(['settings' => 'Invalid settings values provided.'], 'Validation failed');
    }
    $db->prepare("UPDATE investment_settings
                  SET share_price = :p,
                      max_shares_per_person = :m,
                      max_investment_target = :t,
                      default_dividend_rate = :r,
                      min_bank_reserve = :b,
                      updated_by = :u")
       ->execute([
           ':p' => $sharePrice,
           ':m' => $maxShares,
           ':t' => $target,
           ':r' => $rate,
           ':b' => $reserve,
           ':u' => (int)($staff['id'] ?? 0)
       ]);

    successResponse(invLoadState($db, $staff, $branch), 'Investment settings updated.');
}

errorResponse('Unsupported investments endpoint.', 404);
