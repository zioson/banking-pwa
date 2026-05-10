<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Loan Fund Accounts (Loans & Advances Fund + Loan Interest Income)
 *
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  PATTERN 1: GL as Sole Source of Truth                        ║
 * ║                                                              ║
 * ║  BANK-LF-0001 balance = GL 1200 net balance                  ║
 * ║    (SUM(debit) - SUM(credit) from general_ledger WHERE 1200) ║
 * ║                                                              ║
 * ║  BANK-LI-0001 balance = GL 4200 net balance                  ║
 * ║    (SUM(credit) - SUM(debit) from general_ledger WHERE 4200) ║
 * ║                                                              ║
 * ║  loan_fund_accounts.balance = CACHE (never authoritative)    ║
 * ║  loan_fund_transactions = REPORTING ARTIFACT (audit trail)   ║
 * ║                                                              ║
 * ║  All fund mutations post GL entries FIRST.                   ║
 * ║  GET always recomputes from GL — divergence is impossible.   ║
 * ╚══════════════════════════════════════════════════════════════════╝
 *
 * GET  — fetch fund accounts with GL-derived balances + transactions
 * POST — record fund mutation, post GL entries atomically
 */
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff = requireAuth();
$method = $_ROUTE['method'];

// GET: Any authenticated user can read loan fund account data.
// POST: Requires ADMIN/ACCOUNTANT/MANAGER role.
if ($method === 'POST') {
    requireRole(['ADMIN', 'ACCOUNTANT', 'MANAGER'], $staff);
}

/**
 * Safely add a column if it doesn't exist (MySQL/MariaDB compatible)
 */
function lfAddCol(PDO $db, string $table, string $col, string $def): void {
    $r = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?");
    $r->execute([$table, $col]);
    if (!$r) $db->exec("ALTER TABLE $table ADD COLUMN $col $def");
}

function lfNormalizeBranches(array $branches): array {
    return array_values(array_unique(array_filter(array_map(function ($b) {
        $v = strtoupper(trim((string)$b));
        if (in_array($v, ['ALL BRANCHES', 'ALL_BRANCHES'], true)) return 'ALL';
        return $v;
    }, $branches))));
}

function lfCanAccessBranch(array $staff, string $branch): bool {
    $branch = strtoupper(trim($branch));
    if ($branch === '') return true;
    if (strtoupper($staff['role'] ?? '') === 'ADMIN') return true;
    $staffBranches = lfNormalizeBranches($staff['branches'] ?? []);
    if (in_array('ALL', $staffBranches, true)) return true;
    return empty($staffBranches) ? false : in_array($branch, $staffBranches, true);
}

/**
 * Ensure all required tables exist and are seeded.
 * Also ensures GL tables exist (needed for Pattern 1 balance computation).
 */
function lfEnsureSchema(PDO $db): void {
    // ── 1. General Ledger table (needed for balance computation) ──
    $db->exec("CREATE TABLE IF NOT EXISTS general_ledger (
        id SERIAL PRIMARY KEY,
        account_code VARCHAR(10) NOT NULL,
        account_name VARCHAR(200) DEFAULT '',
        debit DECIMAL(20,2) NOT NULL DEFAULT 0,
        credit DECIMAL(20,2) NOT NULL DEFAULT 0,
        date DATE NOT NULL,
        reference VARCHAR(100) DEFAULT '',
        description TEXT,
        posted_by INT DEFAULT NULL,
        transaction_type VARCHAR(50) DEFAULT '',
        contra_account VARCHAR(50) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_account_code ON general_ledger (account_code)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_date ON general_ledger (date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_transaction_type ON general_ledger (transaction_type)");
    // Safe migration: ensure branch column exists (required by lfPostGL inserts)
    lfAddCol($db, 'general_ledger', 'transaction_type', "VARCHAR(50) DEFAULT ''");
    lfAddCol($db, 'general_ledger', 'contra_account', "VARCHAR(50) DEFAULT ''");
    lfAddCol($db, 'general_ledger', 'branch', "VARCHAR(100) DEFAULT ''");
    $brIdx = $db->query("SELECT indexname FROM pg_indexes WHERE tablename = 'general_ledger' WHERE indexname = 'idx_branch'")->fetch();
    if (!$brIdx) {
        $db->exec("CREATE INDEX IF NOT EXISTS idx_branch ON general_ledger (branch)");
    }

    // ── 2. Chart of Accounts (needed for GL code validation) ──
    $db->exec("CREATE TABLE IF NOT EXISTS chart_of_accounts (
        id SERIAL PRIMARY KEY,
        code VARCHAR(10) NOT NULL UNIQUE,
        name VARCHAR(200) NOT NULL,
        type VARCHAR(20) NOT NULL,
        category VARCHAR(100),
        description TEXT,
        is_active BOOLEAN DEFAULT 1
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_code ON chart_of_accounts (code)");

    // Ensure critical GL codes exist
    $criticalGLCodes = [
        ['1200', 'Loans and Advances', 'ASSET', 'Current Assets', 'Loans & Advances Fund pool — linked to BANK-LF-0001.'],
        ['1201', 'Loans Receivable', 'ASSET', 'Current Assets', 'Outstanding loan principal. DR when disbursed, CR when repaid.'],
        ['5900', 'Miscellaneous Expense', 'EXPENSE', 'Operating Expenses', 'Other operating costs'],
        ['4200', 'Loan Interest Income', 'INCOME', 'Interest Income', 'Interest from loan facilities — linked to BANK-LI-0001.']
    ];
    foreach ($criticalGLCodes as $c) {
        $chk = $db->prepare("SELECT id FROM chart_of_accounts WHERE code = ?");
        $chk->execute([$c[0]]);
        if (!$chk->fetch()) {
            $db->prepare("INSERT INTO chart_of_accounts (code, name, type, category, description, is_active) VALUES (?, ?, ?, ?, ?, 1)")
              ->execute($c);
        }
    }

    // ── 3. loan_fund_accounts table ──────────────────────────────
    $db->exec("CREATE TABLE IF NOT EXISTS loan_fund_accounts (
        id SERIAL PRIMARY KEY,
        account_number VARCHAR(30) NOT NULL UNIQUE,
        account_name VARCHAR(200) NOT NULL,
        fund_type VARCHAR(20) NOT NULL,
        balance DECIMAL(20,2) DEFAULT 0,
        currency VARCHAR(5) DEFAULT 'XAF',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // ── 4. loan_fund_transactions table (reporting artifact) ───
    $db->exec("CREATE TABLE IF NOT EXISTS loan_fund_transactions (
        id SERIAL PRIMARY KEY,
        ref VARCHAR(50),
        loan_fund_account_id INT NOT NULL,
        loan_id INT DEFAULT NULL,
        transaction_ref VARCHAR(50) DEFAULT NULL,
        date DATE NOT NULL,
        type VARCHAR(20) NOT NULL,
        description TEXT,
        amount DECIMAL(20,2) NOT NULL,
        balance_after DECIMAL(20,2) NOT NULL,
        branch VARCHAR(20) DEFAULT NULL,
        operator VARCHAR(200),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (loan_fund_account_id) REFERENCES loan_fund_accounts(id)
    )");
    lfAddCol($db, 'loan_fund_transactions', 'branch', "VARCHAR(20) DEFAULT NULL");

    // Backfill branch for existing loan_fund_transactions where missing
    try {
        $db->exec(
            "UPDATE loan_fund_transactions SET branch = l.branch
             FROM loans l
             WHERE loan_fund_transactions.loan_id = l.id
               AND (loan_fund_transactions.branch IS NULL OR loan_fund_transactions.branch = '')
               AND loan_fund_transactions.loan_id IS NOT NULL
               AND COALESCE(l.branch,'') <> ''"
        );
    } catch (PDOException $e) {
        error_log('[LoanFund Migration] Branch backfill on loan_fund_transactions failed: ' . $e->getMessage());
    }

    // Backfill branch on general_ledger for existing fund refs where branch is missing
    try {
        $db->exec(
            "UPDATE general_ledger SET branch = t.branch
             FROM loan_fund_transactions t
             WHERE general_ledger.reference = t.ref
               AND (general_ledger.branch IS NULL OR general_ledger.branch = '')
               AND COALESCE(t.branch,'') <> ''"
        );
    } catch (PDOException $e) {
        error_log('[LoanFund Migration] Branch backfill on general_ledger failed: ' . $e->getMessage());
    }

    // ── 5. Seed default rows if empty ───────────────────────────
    $check = $db->query("SELECT COUNT(*) AS c FROM loan_fund_accounts")->fetch();
    if ((int)$check['c'] === 0) {
        $db->exec("INSERT INTO loan_fund_accounts (account_number, account_name, fund_type, balance, currency)
                   VALUES ('BANK-LF-0001', 'Loans & Advances Fund', 'LOAN_FUND', 0, 'XAF')");
        $db->exec("INSERT INTO loan_fund_accounts (account_number, account_name, fund_type, balance, currency)
                   VALUES ('BANK-LI-0001', 'Loan Interest Income', 'LOAN_INTEREST', 0, 'XAF')");
    }

    // ── 6. AUTO-MIGRATION: Fix corrupted loan numbers with scientific notation ──
    //    Loan IDs that exceeded JS Number.MAX_SAFE_INTEGER were stored as
    //    strings like "LN-2026-9.2233720368548E+18-65" or "LN-2026-9.2233720368548E+18-e2"
    //    Detect and reassign clean sequential numbers.
    try {
        $corruptedRows = $db->query(
            "SELECT id, loan_number FROM loans WHERE loan_number ~ 'E\\+|e\\+' AND loan_number LIKE 'LN-%'"
        )->fetchAll(PDO::FETCH_ASSOC);
        if (count($corruptedRows) > 0) {
            error_log('[LoanFund Migration] Found ' . count($corruptedRows) . ' corrupted loan numbers — repairing...');
            // Get the current max valid sequence for each year
            $yearSeq = [];
            $validRows = $db->query(
                "SELECT loan_number FROM loans WHERE loan_number ~ '^LN-[0-9]{4}-[0-9]{3}$'"
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($validRows as $vn) {
                if (preg_match('/^LN-(\d{4})-(\d{3})$/', $vn, $m)) {
                    if (!isset($yearSeq[$m[1]]) || (int)$m[2] > $yearSeq[$m[1]]) {
                        $yearSeq[$m[1]] = (int)$m[2];
                    }
                }
            }
            // Also check corrupted rows' years
            foreach ($corruptedRows as $cr) {
                if (preg_match('/^LN-(\d{4})-/', $cr['loan_number'], $m)) {
                    if (!isset($yearSeq[$m[1]])) $yearSeq[$m[1]] = 0;
                }
            }
            // Reassign clean numbers
            foreach ($corruptedRows as $cr) {
                if (preg_match('/^LN-(\d{4})-/', $cr['loan_number'], $m)) {
                    $yr = $m[1];
                    if (!isset($yearSeq[$yr])) $yearSeq[$yr] = 0;
                    $yearSeq[$yr]++;
                    $newNum = 'LN-' . $yr . '-' . str_pad($yearSeq[$yr], 3, '0', STR_PAD_LEFT);
                    $oldNum = $cr['loan_number'];
                    // Update loans table
                    $upd = $db->prepare("UPDATE loans SET loan_number = ? WHERE id = ?");
                    $upd->execute([$newNum, (int)$cr['id']]);
                    // Update loan_fund_transactions descriptions (replace old number with new)
                    $updTxn = $db->prepare("UPDATE loan_fund_transactions SET description = REPLACE(description, ?, ?) WHERE description LIKE ?");
                    $updTxn->execute([$oldNum, $newNum, '%' . $oldNum . '%']);
                    // Update loan_schedule descriptions if they exist
                    try {
                        $db->exec("UPDATE loan_schedule SET description = REPLACE(description, '" . addslashes($oldNum) . "', '" . addslashes($newNum) . "') WHERE description LIKE '%" . addslashes($oldNum) . "%'");
                    } catch (PDOException $e) { /* table may not have description column */ }
                    // Update general_ledger descriptions
                    $updGL = $db->prepare("UPDATE general_ledger SET description = REPLACE(description, ?, ?) WHERE description LIKE ?");
                    $updGL->execute([$oldNum, $newNum, '%' . $oldNum . '%']);
                    error_log('[LoanFund Migration] Fixed loan #' . $cr['id'] . ': ' . $oldNum . ' → ' . $newNum);
                }
            }
        }
    } catch (PDOException $e) {
        error_log('[LoanFund Migration] Error during corrupted loan number cleanup: ' . $e->getMessage());
    }

    // ── 7. AUTO-MIGRATION: Backfill GL 4200 entries for existing BANK-LI-0001 transactions ──
    //    BUG HISTORY: Before the GL-posting fix, BANK-LI-0001 POST handler only created
    //    reporting artifacts (loan_fund_transactions) without posting GL 4200 entries.
    //    This caused lfGetGL4200Balance() to always return 0 since the GL table was empty.
    //    This migration retroactively creates the missing GL 4200 paired entries.
    //    Idempotent: checks for existing GL 4200 entry per transaction ref before inserting.
    try {
        // Get BANK-LI-0001 fund account ID
        $liAcct = $db->prepare("SELECT id FROM loan_fund_accounts WHERE account_number = 'BANK-LI-0001' LIMIT 1");
        $liAcct->execute();
        $liAcctRow = $liAcct->fetch(PDO::FETCH_ASSOC);
        if ($liAcctRow) {
            $liFundId = (int)$liAcctRow['id'];

            // Fetch all existing BANK-LI-0001 CREDIT transactions (reporting artifacts)
            $existingTxns = $db->prepare(
                "SELECT id, ref, loan_id, date, description, amount, branch, operator
                 FROM loan_fund_transactions
                 WHERE loan_fund_account_id = ? AND type = 'CREDIT' AND amount > 0
                 ORDER BY id ASC"
            );
            $existingTxns->execute([$liFundId]);
            $txns = $existingTxns->fetchAll(PDO::FETCH_ASSOC);

            if (count($txns) > 0) {
                $backfilled = 0;
                $backfilledAmount = 0.0;
                $db->beginTransaction();
                try {
                    foreach ($txns as $txn) {
                        $txnRef = $txn['ref'];
                        // Check if GL 4200 CREDIT entry already exists for this ref
                        $glCheck = $db->prepare(
                            "SELECT id FROM general_ledger WHERE account_code = '4200' AND reference = ? LIMIT 1"
                        );
                        $glCheck->execute([$txnRef]);
                        if (!$glCheck->fetch()) {
                            // Missing GL entry — create the paired entry:
                            // Credit 4200 (Loan Interest Income) / Debit 1201 (Loans Receivable)
                            $amt = (float)$txn['amount'];
                            $desc = $txn['description'];
                            // Strip any old suffix and append the correct GL notation
                            $desc = preg_replace('/\s*\[.*?\]\s*$/', '', $desc);
                            $fullDesc = $desc . ' [LOAN_INTEREST_CREDIT_BACKFILL — DR 1201 Loans Receivable / CR 4200 Loan Interest Income]';
                            // ★ FIX (GLA-013): Added branch column to backfill GL inserts.
                            // Without branch, these entries are invisible when filtering by branch.
                            $txnBranch = $txn['branch'] ?? '';
                            $db->prepare("INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)")
                              ->execute(['1201', 'Loans Receivable', $amt, 0, $txn['date'], $txnRef, $fullDesc, $txnBranch, 'LOAN_INTEREST_CREDIT_BACKFILL', '4200']);
                            $db->prepare("INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)")
                              ->execute(['4200', 'Loan Interest Income', 0, $amt, $txn['date'], $txnRef, $fullDesc, $txnBranch, 'LOAN_INTEREST_CREDIT_BACKFILL', '1201']);

                            $backfilled++;
                            $backfilledAmount += $amt;
                        }
                    }
                    $db->commit();
                    if ($backfilled > 0) {
                        error_log('[LoanFund Migration] Backfilled ' . $backfilled . ' GL 4200 entries for BANK-LI-0001 totaling ' . number_format($backfilledAmount, 2) . ' XAF.');
                    }
                } catch (PDOException $bfErr) {
                    $db->rollBack();
                    error_log('[LoanFund Migration] GL 4200 backfill failed: ' . $bfErr->getMessage());
                }
            }
        }
    } catch (PDOException $e) {
        error_log('[LoanFund Migration] Error during GL 4200 backfill check: ' . $e->getMessage());
    }

    try {
        $liAcct = $db->prepare("SELECT id FROM loan_fund_accounts WHERE account_number = 'BANK-LI-0001' LIMIT 1");
        $liAcct->execute();
        $liFundId = (int)($liAcct->fetchColumn() ?: 0);
        if ($liFundId > 0) {
            $glRows = $db->query(
                "SELECT id, date, reference, description, credit, branch, posted_by, transaction_type
                   FROM general_ledger
                  WHERE account_code = '4200'
                    AND COALESCE(credit,0) > 0
                    AND (
                      COALESCE(transaction_type,'') LIKE 'LOAN_INTEREST%'
                      OR COALESCE(description,'') LIKE 'Interest%payment%'
                      OR COALESCE(description,'') LIKE '%Interest payment%'
                      OR COALESCE(description,'') LIKE '%interest payment%'
                    )
                  ORDER BY id ASC"
            )->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($glRows)) {
                $exists = $db->prepare("SELECT id FROM loan_fund_transactions WHERE loan_fund_account_id = ? AND transaction_ref = ? AND type = 'CREDIT' LIMIT 1");
                $ins = $db->prepare("INSERT INTO loan_fund_transactions (ref, loan_fund_account_id, loan_id, transaction_ref, date, type, description, amount, balance_after, branch, operator)
                                     VALUES (?, ?, ?, ?, ?, 'CREDIT', ?, ?, ?, ?, ?)");
                $loanLookup = $db->prepare("SELECT id FROM loans WHERE loan_number = ? LIMIT 1");
                $staffLookup = $db->prepare("SELECT full_name FROM staff WHERE id = ? LIMIT 1");

                $db->beginTransaction();
                try {
                    foreach ($glRows as $g) {
                        $txnRef = (string)($g['reference'] ?? '');
                        if ($txnRef === '') continue;

                        $exists->execute([$liFundId, $txnRef]);
                        if ($exists->fetchColumn()) continue;

                        $loanId = null;
                        $desc = (string)($g['description'] ?? '');
                        if (preg_match('/LN-[0-9]{4}-[0-9]{3}/', $desc, $m)) {
                            $loanLookup->execute([$m[0]]);
                            $lid = $loanLookup->fetchColumn();
                            if ($lid) $loanId = (int)$lid;
                        }

                        $operatorName = '';
                        $postedBy = (int)($g['posted_by'] ?? 0);
                        if ($postedBy > 0) {
                            $staffLookup->execute([$postedBy]);
                            $operatorName = (string)($staffLookup->fetchColumn() ?: '');
                        }

                        $ins->execute([
                            $txnRef,
                            $liFundId,
                            $loanId,
                            $txnRef,
                            (string)($g['date'] ?? date('Y-m-d')),
                            $desc,
                            (float)($g['credit'] ?? 0),
                            0,
                            (string)($g['branch'] ?? ''),
                            $operatorName
                        ]);
                    }
                    $db->commit();
                } catch (PDOException $e) {
                    $db->rollBack();
                }
            }
        }
    } catch (PDOException $e) {
    }

    // ── 8. AUTO-MIGRATION: Backfill missing disbursement fund debits for existing loans ──
    // If a loan was marked ACTIVE/DISBURSED but the loan-fund debit failed earlier
    // (most commonly because general_ledger was missing the 'branch' column),
    // the system ends up with active loans but BANK-LF-0001 shows 0. This backfill
    // posts the missing GL entries (DR 1201 / CR 1200) and creates the reporting
    // artifact row in loan_fund_transactions. Idempotent per-loan.
    try {
        $loans = $db->query(
            "SELECT id, loan_number, branch, principal, disbursed_at
               FROM loans
              WHERE disbursed_at IS NOT NULL
                AND status IN ('ACTIVE','DELINQUENT','DEFAULTED','SETTLED')
              ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($loans)) {
            foreach ($loans as $loan) {
                $loanId = (int)($loan['id'] ?? 0);
                if ($loanId <= 0) continue;

                $dup = $db->prepare(
                    "SELECT id FROM loan_fund_transactions
                      WHERE loan_id = ? AND type = 'DEBIT' AND (description LIKE '%disbursement%' OR description LIKE '%Loan disbursement%')
                      LIMIT 1"
                );
                $dup->execute([$loanId]);
                if ($dup->fetch()) continue;

                $ref = 'LF-DISB-' . $loanId;
                $glDup = $db->prepare("SELECT id FROM general_ledger WHERE reference = ? AND account_code IN ('1200','1201') LIMIT 1");
                $glDup->execute([$ref]);
                if ($glDup->fetch()) continue;

                $amount = (float)($loan['principal'] ?? 0);
                if ($amount <= 0) continue;

                $branch = sanitize((string)($loan['branch'] ?? ''));
                $loanNumber = sanitize((string)($loan['loan_number'] ?? ('LOAN#' . $loanId)));
                $date = (string)($loan['disbursed_at'] ?? '');
                $desc = 'Loan disbursement backfill — ' . $loanNumber;

                // Resolve fund account id
                $acctRow = $db->query("SELECT id FROM loan_fund_accounts WHERE account_number = 'BANK-LF-0001' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if (!$acctRow) continue;
                $acctId = (int)$acctRow['id'];

                $db->beginTransaction();
                try {
                    // GL Entry: Credit 1200 (fund decreases) / Debit 1201 (receivable increases)
                    lfPostGL($db, '1201', 'Loans Receivable', '1200', 'Loans and Advances',
                        $amount, $ref, $desc, 0, 'LOAN_FUND_DEBIT_BACKFILL', $branch);

                    $gl1200After = lfGetGL1200Balance($db);

                    $db->prepare(
                        "INSERT INTO loan_fund_transactions
                            (ref, loan_fund_account_id, loan_id, transaction_ref, date, type, description, amount, balance_after, branch, operator)
                         VALUES (?, ?, ?, ?, ?, 'DEBIT', ?, ?, ?, ?, ?)"
                    )->execute([
                        $ref, $acctId, $loanId, $ref, substr($date, 0, 10),
                        $desc . ' [GL: CR 1200 / DR 1201]',
                        $amount, $gl1200After, $branch, 'SYSTEM_BACKFILL'
                    ]);

                    // Cache update (not authoritative)
                    $db->prepare("UPDATE loan_fund_accounts SET balance = ? WHERE id = ?")->execute([$gl1200After, $acctId]);

                    $db->commit();
                } catch (PDOException $txErr) {
                    $db->rollBack();
                    error_log('[LoanFund Backfill] Disbursement backfill failed for loan ' . $loanId . ': ' . $txErr->getMessage());
                }
            }
        }
    } catch (PDOException $e) {
        error_log('[LoanFund Migration] Error during loan disbursement backfill: ' . $e->getMessage());
    }
}

/**
 * Compute GL 1200 net balance (Loans & Advances Fund).
 * ASSET account: normal balance = SUM(debit) - SUM(credit)
 */
function lfGetGL1200Balance(PDO $db, array $branches = []): float {
    try {
        $sql = "SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS net_balance
                  FROM general_ledger
                 WHERE account_code = '1200'";
        $params = [];
        if (!empty($branches)) {
            $placeholders = implode(',', array_fill(0, count($branches), '?'));
            $sql .= " AND UPPER(TRIM(COALESCE(branch,''))) IN ($placeholders)";
            $params = array_map(fn($b) => strtoupper(trim((string)$b)), $branches);
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[LoanFund] GL 1200 query failed: ' . $e->getMessage());
        return 0.0;
    }
}

/**
 * Compute GL 4200 net balance (Loan Interest Income).
 * INCOME account: normal balance = SUM(credit) - SUM(debit)
 */
function lfGetGL4200Balance(PDO $db, array $branches = []): float {
    try {
        $sql = "SELECT COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) AS net_balance
                  FROM general_ledger
                 WHERE account_code = '4200'";
        $params = [];
        if (!empty($branches)) {
            $placeholders = implode(',', array_fill(0, count($branches), '?'));
            $sql .= " AND UPPER(TRIM(COALESCE(branch,''))) IN ($placeholders)";
            $params = array_map(fn($b) => strtoupper(trim((string)$b)), $branches);
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[LoanFund] GL 4200 query failed: ' . $e->getMessage());
        return 0.0;
    }
}

/**
 * Compute GL 1400 net balance (Operating Account / Operating Fund).
 * ASSET account: normal balance = SUM(debit) - SUM(credit)
 */
function lfGetGL1400Balance(PDO $db, array $branches = []): float {
    try {
        $sql = "SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS net_balance
                  FROM general_ledger
                 WHERE account_code = '1400'";
        $params = [];
        if (!empty($branches)) {
            $placeholders = implode(',', array_fill(0, count($branches), '?'));
            $sql .= " AND UPPER(TRIM(COALESCE(branch,''))) IN ($placeholders)";
            $params = array_map(fn($b) => strtoupper(trim((string)$b)), $branches);
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[LoanFund] GL 1400 query failed: ' . $e->getMessage());
        return 0.0;
    }
}

/**
 * Post a paired GL entry (debit + credit) and return the reference.
 */
function lfPostGL(PDO $db, string $drCode, string $drName, string $crCode, string $crName,
                  float $amount, string $reference, string $description, int $operatorId,
                  string $transactionType, string $branch = ''): string {
    $descSuffix = ' [' . $transactionType . ' — DR ' . $drCode . ' ' . $drName . ' / CR ' . $crCode . ' ' . $crName . ']';
    $fullDesc = $description . $descSuffix;

    $db->prepare("INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)")
      ->execute([$drCode, $drName, $amount, 0, $reference, $fullDesc, $branch, $operatorId > 0 ? $operatorId : null, $transactionType, $crCode]);
    $db->prepare("INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)")
      ->execute([$crCode, $crName, 0, $amount, $reference, $fullDesc, $branch, $operatorId > 0 ? $operatorId : null, $transactionType, $drCode]);

    return $reference;
}

switch ($method) {

    /* ═══════════════════════════════════════════════════════════
       GET: Fetch fund accounts with GL-derived balances
       Pattern 1: Balance is ALWAYS computed from GL, never cached.
       ═══════════════════════════════════════════════════════════ */
    case 'GET':
        try {
            $db = getDB();
            lfEnsureSchema($db);
            $requestedBranch = sanitize($_GET['branch'] ?? '');
            if ($requestedBranch !== '' && !lfCanAccessBranch($staff, $requestedBranch)) {
                errorResponse('Access denied. You cannot view loan-fund activity for a branch outside your assignment.', 403);
            }
            $effectiveBranches = strtoupper($staff['role'] ?? '') === 'ADMIN'
                ? []
                : lfNormalizeBranches($staff['branches'] ?? []);
            if (in_array('ALL', $effectiveBranches, true)) {
                $effectiveBranches = [];
            }
            if (!empty($requestedBranch)) {
                $effectiveBranches = [strtoupper(trim($requestedBranch))];
            }

            // ★ Compute GL-derived balances (THE source of truth), branch-scoped when applicable
            $gl1200Balance = lfGetGL1200Balance($db, $effectiveBranches);  // BANK-LF-0001
            $gl4200Balance = lfGetGL4200Balance($db, $effectiveBranches);  // BANK-LI-0001

            // Update cache only for global (otherwise branch-scoped views would overwrite shared cache)
            if (empty($effectiveBranches)) {
                $db->prepare("UPDATE loan_fund_accounts SET balance = ? WHERE account_number = 'BANK-LF-0001'")->execute([$gl1200Balance]);
                $db->prepare("UPDATE loan_fund_accounts SET balance = ? WHERE account_number = 'BANK-LI-0001'")->execute([$gl4200Balance]);
            }

            $stmt = $db->query("SELECT * FROM loan_fund_accounts ORDER BY id");
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($accounts as $acct) {
                $id = (int)$acct['id'];
                try {
                    if (!empty($effectiveBranches)) {
                        $branchPlaceholders = implode(',', array_fill(0, count($effectiveBranches), '?'));
                        $tStmt = $db->prepare(
                            "SELECT * FROM loan_fund_transactions
                             WHERE loan_fund_account_id = ?
                               AND UPPER(TRIM(COALESCE(branch,''))) IN ($branchPlaceholders)
                             ORDER BY created_at DESC LIMIT 100"
                        );
                        $tStmt->execute(array_merge([$id], $effectiveBranches));
                    } else {
                        $tStmt = $db->prepare("SELECT * FROM loan_fund_transactions WHERE loan_fund_account_id = ? ORDER BY created_at DESC LIMIT 100");
                        $tStmt->execute([$id]);
                    }
                    $txns = $tStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    $txns = [];
                }

                // Use GL-derived balance instead of stored balance
                $glBalance = ($acct['account_number'] === 'BANK-LF-0001') ? $gl1200Balance : $gl4200Balance;

                $result[] = [
                    'id'            => $id,
                    'accountNumber' => $acct['account_number'],
                    'accountName'   => $acct['account_name'],
                    'fundType'      => $acct['fund_type'],
                    'balance'       => $glBalance,  // ★ Always from GL
                    'currency'      => $acct['currency'],
                    'glSource'      => ($acct['account_number'] === 'BANK-LF-0001') ? '1200' : '4200',
                    'transactions'  => $txns
                ];
            }

            successResponse($result);

        } catch (PDOException $e) {
            error_log('[LoanFund GET] Error: ' . $e->getMessage());
            successResponse([
                [
                    'id' => 1, 'accountNumber' => 'BANK-LF-0001',
                    'accountName' => 'Loans & Advances Fund', 'fundType' => 'LOAN_FUND',
                    'balance' => 0, 'currency' => 'XAF', 'glSource' => '1200', 'transactions' => []
                ],
                [
                    'id' => 2, 'accountNumber' => 'BANK-LI-0001',
                    'accountName' => 'Loan Interest Income', 'fundType' => 'LOAN_INTEREST',
                    'balance' => 0, 'currency' => 'XAF', 'glSource' => '4200', 'transactions' => []
                ]
            ]);
        }
        break;

    /* ═══════════════════════════════════════════════════════════
       POST: Record fund mutation → posts GL entries atomically
       Pattern 1: Every mutation goes through GL. The stored
       balance is updated as a cache side-effect (not authority).
       ═══════════════════════════════════════════════════════════ */
    case 'POST':
        requireRole(['ADMIN', 'ACCOUNTANT', 'MANAGER'], $staff);
        try {
            $db = getDB();
            lfEnsureSchema($db);

            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                errorResponse('Invalid JSON body.', 400);
                break;
            }

            $accountNumber = trim($input['account_number'] ?? '');
            $type          = strtoupper(trim($input['type'] ?? ''));         // CREDIT or DEBIT
            $amount        = (float)($input['amount'] ?? 0);
            $description   = trim($input['description'] ?? '');
            $ref           = trim($input['ref'] ?? '');
            $loanId        = isset($input['loan_id']) ? (int)$input['loan_id'] : null;
            $txnRef        = trim($input['transaction_ref'] ?? '');
            $operator      = $staff['full_name'] ?? 'System';
            $operatorId    = (int)($staff['id'] ?? 0);
            $staffBranches = lfNormalizeBranches($staff['branches'] ?? []);
            $operatorBranch = trim((string)($input['branch'] ?? ($staffBranches[0] ?? ($staff['branch'] ?? ''))));

            if (!$accountNumber || !in_array($type, ['CREDIT','DEBIT']) || $amount <= 0) {
                errorResponse('Missing required fields: account_number, type (CREDIT/DEBIT), amount > 0.', 400);
                break;
            }
            if ($operatorBranch !== '' && !lfCanAccessBranch($staff, $operatorBranch)) {
                errorResponse('Access denied. You cannot post loan-fund activity for a branch outside your assignment.', 403);
            }
            if ($loanId) {
                $loanScopeStmt = $db->prepare("SELECT branch FROM loans WHERE id = :id LIMIT 1");
                $loanScopeStmt->execute([':id' => $loanId]);
                $loanBranch = sanitize((string)($loanScopeStmt->fetchColumn() ?: ''));
                if ($loanBranch === '') {
                    errorResponse('Loan branch could not be resolved for this fund transaction.', 400);
                    break;
                }
                if (!lfCanAccessBranch($staff, $loanBranch)) {
                    errorResponse('Access denied. You cannot post a fund transaction for a loan outside your branch assignment.', 403);
                }
                $operatorBranch = $loanBranch;
            }

            // Find the fund account (config record, not authority)
            $aStmt = $db->prepare("SELECT * FROM loan_fund_accounts WHERE account_number = ? LIMIT 1");
            $aStmt->execute([$accountNumber]);
            $acct = $aStmt->fetch(PDO::FETCH_ASSOC);
            if (!$acct) {
                errorResponse("Fund account '$accountNumber' not found.", 404);
                break;
            }
            $acctId = (int)$acct['id'];

            // ════════════════════════════════════════════════════
            // BANK-LF-0001: Loans & Advances Fund
            // Authority: GL 1200 (Loans and Advances)
            // ════════════════════════════════════════════════════
            if ($accountNumber === 'BANK-LF-0001') {

                // Compute GL 1200 balance (THE source of truth for balance check)
                $currentGLBalance = lfGetGL1200Balance($db);

                if ($type === 'DEBIT') {
                    // ── DEBIT: Fund outflow ──
                    // Determine action from description context
                    $isWriteoff = (stripos($description, 'write-off') !== false || stripos($description, 'writeoff') !== false);
                    $isDisbursement = (stripos($description, 'disbursement') !== false);

                    if ($isWriteoff) {
                        // ── WRITE-OFF: CR 1201 (receivable removed) / DR 5900 (loss) ──
                        // Fund pool (GL 1200) is NOT affected — it was already decreased at disbursement time

                        if (empty($ref)) {
                            $count = (int)$db->query("SELECT COUNT(*) FROM loan_fund_transactions WHERE loan_fund_account_id = " . $acctId)->fetchColumn();
                            $ref = 'LF-' . str_replace('-', '', date('Y-m-d')) . '-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
                        }

                        $db->beginTransaction();
                        try {
                            // GL Entry: Credit 1201 (Loans Receivable) / Debit 5900 (Misc Expense)
                            lfPostGL($db, '5900', 'Miscellaneous Expense', '1201', 'Loans Receivable',
                                $amount, $ref, $description, $operatorId, 'LOAN_WRITEOFF', $operatorBranch);

                            // Reporting artifact
                            $db->prepare("INSERT INTO loan_fund_transactions (ref, loan_fund_account_id, loan_id, transaction_ref, date, type, description, amount, balance_after, branch, operator) VALUES (?, ?, ?, ?, CURRENT_DATE, 'DEBIT', ?, ?, ?, ?, ?)")
                              ->execute([$ref, $acctId, $loanId, $txnRef ?: null,
                                  $description . ' [GL: CR 1201 / DR 5900 — fund pool unchanged]',
                                  $amount, $currentGLBalance, $operatorBranch, $operator]);

                            $db->commit();
                        } catch (PDOException $txErr) {
                            $db->rollBack();
                            error_log('[LoanFund POST] Write-off failed: ' . $txErr->getMessage());
                            errorResponse('Write-off GL posting failed — changes rolled back.', 500);
                            break;
                        }

                        logAudit($operator, 'LOAN_FUND_DEBIT_WRITEOFF', 'LOAN_FUND_ACCOUNT', (string)$acctId, 'SUCCESS',
                            'Write-off ' . number_format($amount, 2) . ' XAF. GL: CR 1201 / DR 5900. Fund pool (GL 1200) unchanged: ' . number_format($currentGLBalance, 2) . ' XAF. Ref=' . $ref,
                            $staff['department'] ?? '', getClientIp());

                        successResponse([
                            'id' => $db->lastInsertId(), 'ref' => $ref,
                            'accountNumber' => $accountNumber, 'type' => $type,
                            'amount' => $amount,
                            'balanceAfter' => $currentGLBalance,  // Unchanged
                            'previousBalance' => $currentGLBalance,
                            'glAction' => 'LOAN_WRITEOFF',
                            'glEntriesRecorded' => 2
                        ]);

                    } else {
                        // ── DISBURSEMENT: CR 1200 (fund decreases) / DR 1201 (receivable increases) ──

                        // ★ IDEMPOTENCY CHECK: Prevent duplicate fund debit for the same loan
                        if ($loanId) {
                            $dupCheck = $db->prepare(
                                "SELECT id, ref, transaction_ref, amount, created_at
                                   FROM loan_fund_transactions
                                  WHERE loan_id = :lid AND type = 'DEBIT' AND description LIKE '%disbursement%'
                                  ORDER BY id DESC LIMIT 1"
                            );
                            $dupCheck->execute([':lid' => $loanId]);
                            $dupRow = $dupCheck->fetch(PDO::FETCH_ASSOC);
                            if ($dupRow) {
                                successResponse([
                                    'duplicate' => true,
                                    'message' => 'Duplicate disbursement detected: fund debit already exists for this loan. Fund will not be debited again.',
                                    'existing' => [
                                        'id' => (int)($dupRow['id'] ?? 0),
                                        'ref' => (string)($dupRow['ref'] ?? ''),
                                        'transaction_ref' => (string)($dupRow['transaction_ref'] ?? ''),
                                        'amount' => (float)($dupRow['amount'] ?? 0),
                                        'created_at' => (string)($dupRow['created_at'] ?? '')
                                    ]
                                ]);
                                break;
                            }
                        }

                        // ★ BEST-PRACTICE ENFORCEMENT:
                        // Loan disbursement must consume branch operating liquidity (GL 1400),
                        // then move through the loan fund pipeline (GL 1200 -> GL 1201).
                        // This keeps branch operating-fund reporting aligned with real cash outflow.
                        $branchScope = $operatorBranch !== '' ? [strtoupper($operatorBranch)] : [];
                        $currentOperatingBalance = lfGetGL1400Balance($db, $branchScope);
                        if ($amount > $currentOperatingBalance) {
                            errorResponse(
                                'Insufficient operating fund balance for branch ' . ($operatorBranch ?: 'N/A') .
                                '. GL 1400 available: ' . number_format($currentOperatingBalance, 2) .
                                ' XAF, attempted disbursement: ' . number_format($amount, 2) . ' XAF.',
                                400
                            );
                            break;
                        }

                        if (empty($ref)) {
                            $count = (int)$db->query("SELECT COUNT(*) FROM loan_fund_transactions WHERE loan_fund_account_id = " . $acctId)->fetchColumn();
                            $ref = 'LF-' . str_replace('-', '', date('Y-m-d')) . '-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
                        }
                        if (empty($txnRef)) $txnRef = $ref;

                        $newOperatingBalance = $currentOperatingBalance - $amount;

                        $db->beginTransaction();
                        try {
                            // Step 1) Transfer liquidity from Operating Fund into Loan Fund pool
                            //         DR 1200 / CR 1400 (branch-scoped).
                            lfPostGL(
                                $db,
                                '1200',
                                'Loans and Advances',
                                '1400',
                                'Operating Account',
                                $amount,
                                $ref,
                                'Branch operating-fund allocation for loan disbursement',
                                $operatorId,
                                'LOAN_DISBURSEMENT_OPERATING_TRANSFER',
                                $operatorBranch
                            );

                            // Record operating-fund transaction artifact (best-effort).
                            try {
                                $opTable = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'operating_account_transactions'")->fetch(PDO::FETCH_NUM);
                                if ($opTable) {
                                    $opAcct = $db->query("SELECT id FROM operating_account WHERE account_number = 'BANK-OP-0001' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                                    if ($opAcct && isset($opAcct['id'])) {
                                        $db->prepare(
                                            "INSERT INTO operating_account_transactions
                                                (ref, operating_account_id, date, type, description, amount, balance_after, operator, contra_account, transaction_type, branch)
                                             VALUES (?, ?, CURRENT_DATE, 'DEBIT', ?, ?, ?, ?, '1200', 'LOAN_DISBURSEMENT_TRANSFER', ?)"
                                        )->execute([
                                            $ref,
                                            (int)$opAcct['id'],
                                            'Loan disbursement operating-fund allocation — ' . $description,
                                            $amount,
                                            $newOperatingBalance,
                                            $operator,
                                            $operatorBranch
                                        ]);
                                    }
                                }
                            } catch (PDOException $opArtifactErr) {
                                error_log('[LoanFund POST] Operating-fund artifact insert skipped: ' . $opArtifactErr->getMessage());
                            }

                            // GL Entry: Credit 1200 (Loans & Advances Fund) / Debit 1201 (Loans Receivable)
                            lfPostGL($db, '1201', 'Loans Receivable', '1200', 'Loans and Advances',
                                $amount, $ref, $description, $operatorId, 'LOAN_FUND_DEBIT', $operatorBranch);

                            // Recompute GL 1200 entries above; this is the authoritative post-state.
                            $newGLBalance = lfGetGL1200Balance($db);

                            // Reporting artifact
                            $db->prepare("INSERT INTO loan_fund_transactions (ref, loan_fund_account_id, loan_id, transaction_ref, date, type, description, amount, balance_after, branch, operator) VALUES (?, ?, ?, ?, CURRENT_DATE, 'DEBIT', ?, ?, ?, ?, ?)")
                              ->execute([$ref, $acctId, $loanId, $txnRef,
                                  $description . ' [GL: DR 1200 / CR 1400 then CR 1200 / DR 1201]',
                                  $amount, $newGLBalance, $operatorBranch, $operator]);

                            // Cache update (not authoritative — GL 1200 is)
                            $db->prepare("UPDATE loan_fund_accounts SET balance = ? WHERE id = ?")->execute([$newGLBalance, $acctId]);
                            // Keep operating_account cache aligned with GL 1400 (global cache, branch impact already in GL).
                            try {
                                $globalOperatingBalance = lfGetGL1400Balance($db);
                                $db->prepare("UPDATE operating_account SET balance = ? WHERE account_number = 'BANK-OP-0001'")->execute([$globalOperatingBalance]);
                            } catch (PDOException $opCacheErr) {
                                error_log('[LoanFund POST] operating_account cache update skipped: ' . $opCacheErr->getMessage());
                            }

                            $db->commit();
                        } catch (PDOException $txErr) {
                            $db->rollBack();
                            error_log('[LoanFund POST] Disbursement GL failed: ' . $txErr->getMessage());
                            errorResponse('Loan fund debit failed — changes rolled back.', 500);
                            break;
                        }

                        logAudit($operator, 'LOAN_FUND_DEBIT', 'LOAN_FUND_ACCOUNT', (string)$acctId, 'SUCCESS',
                            'DEBIT ' . number_format($amount, 2) . ' XAF on BANK-LF-0001 with branch operating-fund impact. Ref=' . $ref .
                            ' GL 1400: ' . number_format($currentOperatingBalance, 2) . ' -> ' . number_format($newOperatingBalance, 2) .
                            ' | GL 1200: ' . number_format($currentGLBalance, 2) . ' -> ' . number_format($newGLBalance, 2),
                            $staff['department'] ?? '', getClientIp());

                        successResponse([
                            'id' => $db->lastInsertId(), 'ref' => $ref,
                            'accountNumber' => $accountNumber, 'type' => $type,
                            'amount' => $amount,
                            'balanceAfter' => $newGLBalance,
                            'previousBalance' => $currentGLBalance,
                            'glAction' => 'LOAN_FUND_DEBIT',
                            'glEntriesRecorded' => 2
                        ]);
                    }

                } else {
                    // ── CREDIT: Fund inflow ──
                    $isRepayment = (stripos($description, 'repayment') !== false || stripos($description, 'AUTO-DEDUCT') !== false || stripos($description, 'payoff') !== false || stripos($description, 'early') !== false);

                    if ($isRepayment) {
                        // ── REPAYMENT: DR 1200 (fund increases) / CR 1201 (receivable decreases) ──

                        if (empty($ref)) {
                            $count = (int)$db->query("SELECT COUNT(*) FROM loan_fund_transactions WHERE loan_fund_account_id = " . $acctId)->fetchColumn();
                            $ref = 'LF-' . str_replace('-', '', date('Y-m-d')) . '-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
                        }
                        if (empty($txnRef)) $txnRef = $ref;

                        $newGLBalance = $currentGLBalance + $amount;

                        $db->beginTransaction();
                        try {
                            // GL Entry: Debit 1200 (Loans & Advances) / Credit 1201 (Loans Receivable)
                            lfPostGL($db, '1200', 'Loans and Advances', '1201', 'Loans Receivable',
                                $amount, $ref, $description, $operatorId, 'LOAN_REPAYMENT_PRINCIPAL', $operatorBranch);

                            // Reporting artifact
                            $db->prepare("INSERT INTO loan_fund_transactions (ref, loan_fund_account_id, loan_id, transaction_ref, date, type, description, amount, balance_after, branch, operator) VALUES (?, ?, ?, ?, CURRENT_DATE, 'CREDIT', ?, ?, ?, ?, ?)")
                              ->execute([$ref, $acctId, $loanId, $txnRef,
                                  $description . ' [GL: DR 1200 / CR 1201]',
                                  $amount, $newGLBalance, $operatorBranch, $operator]);

                            // Cache update
                            $db->prepare("UPDATE loan_fund_accounts SET balance = ? WHERE id = ?")->execute([$newGLBalance, $acctId]);

                            $db->commit();
                        } catch (PDOException $txErr) {
                            $db->rollBack();
                            error_log('[LoanFund POST] Repayment GL failed: ' . $txErr->getMessage());
                            errorResponse('Loan repayment GL posting failed — changes rolled back.', 500);
                            break;
                        }

                        logAudit($operator, 'LOAN_FUND_CREDIT_REPAYMENT', 'LOAN_FUND_ACCOUNT', (string)$acctId, 'SUCCESS',
                            'CREDIT ' . number_format($amount, 2) . ' XAF on BANK-LF-0001 via GL 1200 (repayment). Ref=' . $ref . ' GL 1200: ' . number_format($currentGLBalance, 2) . ' -> ' . number_format($newGLBalance, 2),
                            $staff['department'] ?? '', getClientIp());

                        successResponse([
                            'id' => $db->lastInsertId(), 'ref' => $ref,
                            'accountNumber' => $accountNumber, 'type' => $type,
                            'amount' => $amount,
                            'balanceAfter' => $newGLBalance,
                            'previousBalance' => $currentGLBalance,
                            'glAction' => 'LOAN_REPAYMENT_PRINCIPAL',
                            'glEntriesRecorded' => 2
                        ]);

                    } else {
                        // ── GENERAL CREDIT: Not a repayment ──
                        // This should go through /api/general-ledger action=LOAN_FUND_CREDIT
                        // for proper double-entry with a source account.
                        errorResponse('Direct fund credit to BANK-LF-0001 without a source account is not permitted under Pattern 1. Use /api/general-ledger with action=LOAN_FUND_CREDIT to fund the loan pool (which debits GL 1200 and credits the source GL account).', 403);
                        break;
                    }
                }

            // ════════════════════════════════════════════════════
            // BANK-LI-0001: Loan Interest Income
            // Authority: GL 4200 (Loan Interest Income)
            // ★ FIXED: Now posts GL 4200 entries atomically (same
            // pattern as BANK-LF-0001 with GL 1200). Previously only
            // created reporting artifacts, causing balance to always
            // show 0 since GL 4200 was never credited.
            // ════════════════════════════════════════════════════
            } elseif ($accountNumber === 'BANK-LI-0001') {

                $currentGLBalance = lfGetGL4200Balance($db);

                if (empty($ref)) {
                    $count = (int)$db->query("SELECT COUNT(*) FROM loan_fund_transactions WHERE loan_fund_account_id = " . $acctId)->fetchColumn();
                    $ref = 'LI-' . str_replace('-', '', date('Y-m-d')) . '-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
                }
                if (empty($txnRef)) $txnRef = $ref;

                $db->beginTransaction();
                try {
                    if ($type === 'CREDIT') {
                        // ── INTEREST RECEIVED: CR 4200 (income) / DR 1201 (receivable) ──
                        // ★ IDEMPOTENCY: Prevent duplicate GL entry for same loan_id
                        if ($loanId) {
                            $dupCheck = $db->prepare(
                                "SELECT id FROM loan_fund_transactions WHERE loan_fund_account_id = :acct_id AND loan_id = :lid AND type = 'CREDIT' AND description LIKE :desc_pattern LIMIT 1"
                            );
                            $dupCheck->execute([
                                ':acct_id' => $acctId,
                                ':lid' => $loanId,
                                ':desc_pattern' => '%' . substr($description, 0, 80) . '%'
                            ]);
                            if ($dupCheck->fetch()) {
                                $db->rollBack();
                                successResponse([
                                    'duplicate' => true,
                                    'message' => 'Duplicate interest credit detected for this loan. Interest will not be recorded twice.'
                                ]);
                                break;
                            }
                        }

                        $newBalance = $currentGLBalance + $amount;

                        // Post GL: Credit 4200 (Loan Interest Income) / Debit 1201 (Loans Receivable)
                        lfPostGL($db, '1201', 'Loans Receivable', '4200', 'Loan Interest Income',
                            $amount, $ref, $description, $operatorId, 'LOAN_INTEREST_CREDIT', $operatorBranch);

                        // Reporting artifact
                        $db->prepare("INSERT INTO loan_fund_transactions (ref, loan_fund_account_id, loan_id, transaction_ref, date, type, description, amount, balance_after, branch, operator) VALUES (?, ?, ?, ?, CURRENT_DATE, 'CREDIT', ?, ?, ?, ?, ?)")
                          ->execute([$ref, $acctId, $loanId, $txnRef,
                              $description . ' [GL: DR 1201 / CR 4200]', $amount, $newBalance, $operatorBranch, $operator]);
                        // Cache update
                        $db->prepare("UPDATE loan_fund_accounts SET balance = ? WHERE id = ?")->execute([$newBalance, $acctId]);

                        $db->commit();

                        logAudit($operator, 'LOAN_INTEREST_CREDIT', 'LOAN_FUND_ACCOUNT', (string)$acctId, 'SUCCESS',
                            'CREDIT ' . number_format($amount, 2) . ' XAF on BANK-LI-0001 via GL 4200. Ref=' . $ref . ' GL 4200: ' . number_format($currentGLBalance, 2) . ' -> ' . number_format($newBalance, 2),
                            $staff['department'] ?? '', getClientIp());

                        successResponse([
                            'id' => $db->lastInsertId(), 'ref' => $ref,
                            'accountNumber' => $accountNumber, 'type' => $type,
                            'amount' => $amount,
                            'balanceAfter' => $newBalance,
                            'previousBalance' => $currentGLBalance,
                            'glAction' => 'LOAN_INTEREST_CREDIT',
                            'glEntriesRecorded' => 2
                        ]);
                    } else {
                        // ── DEBIT: Interest reversal / correction ──
                        $newBalance = max(0, $currentGLBalance - $amount);

                        // Post GL: Debit 4200 / Credit 1201
                        lfPostGL($db, '4200', 'Loan Interest Income', '1201', 'Loans Receivable',
                            $amount, $ref, $description, $operatorId, 'LOAN_INTEREST_DEBIT', $operatorBranch);

                        // Reporting artifact
                        $db->prepare("INSERT INTO loan_fund_transactions (ref, loan_fund_account_id, loan_id, transaction_ref, date, type, description, amount, balance_after, operator) VALUES (?, ?, ?, ?, CURRENT_DATE, 'DEBIT', ?, ?, ?, ?)")
                          ->execute([$ref, $acctId, $loanId, $txnRef ?: null,
                              $description . ' [GL: DR 4200 / CR 1201]',
                              $amount, $newBalance, $operator]);

                        // Cache update
                        $db->prepare("UPDATE loan_fund_accounts SET balance = ? WHERE id = ?")->execute([$newBalance, $acctId]);

                        $db->commit();

                        logAudit($operator, 'LOAN_INTEREST_DEBIT', 'LOAN_FUND_ACCOUNT', (string)$acctId, 'SUCCESS',
                            'DEBIT ' . number_format($amount, 2) . ' XAF on BANK-LI-0001 via GL 4200. Ref=' . $ref . ' GL 4200: ' . number_format($currentGLBalance, 2) . ' -> ' . number_format($newBalance, 2),
                            $staff['department'] ?? '', getClientIp());

                        successResponse([
                            'id' => $db->lastInsertId(), 'ref' => $ref,
                            'accountNumber' => $accountNumber, 'type' => $type,
                            'amount' => $amount,
                            'balanceAfter' => $newBalance,
                            'previousBalance' => $currentGLBalance,
                            'glAction' => 'LOAN_INTEREST_DEBIT',
                            'glEntriesRecorded' => 2
                        ]);
                    }
                } catch (PDOException $e) {
                    $db->rollBack();
                    throw $e;
                }

            } else {
                errorResponse("Fund account '$accountNumber' is not managed under Pattern 1 GL governance.", 400);
            }

        } catch (PDOException $e) {
            error_log('[LoanFund POST] Database error: ' . $e->getMessage());
            errorResponse('Database error.', 500);
        }
        break;

    default:
        errorResponse('Method not allowed.', 405);
}
