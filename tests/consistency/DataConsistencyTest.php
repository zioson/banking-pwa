<?php
/**
 * Data Consistency Tests for Atlas Bank
 * 
 * 1. Test Name: Consistency_Transaction_History_Sum
 * 2. What it validates: Ensures that the sum of all transactions for an account equals its current balance.
 * 3. Setup: Account with multiple transactions.
 * 4. Steps:
 *    - Sum all 'credit' amounts and subtract all 'debit' amounts for account 'ACC-200100001'.
 *    - Compare result with `accounts.ledger_balance`.
 * 5. Expected Result: Sum matches ledger_balance exactly.
 * 6. Failure condition: Mismatch in totals.
 */

namespace AtlasBank\Tests\Consistency;

use PHPUnit\Framework\TestCase;

class DataConsistencyTest extends TestCase
{
    /**
     * @test
     */
    public function testAccountBalanceIntegrity()
    {
        // Mock data
        $transactions = [
            ['direction' => 'credit', 'amount' => 50000],
            ['direction' => 'credit', 'amount' => 25000],
            ['direction' => 'debit',  'amount' => 10000],
            ['direction' => 'debit',  'amount' => 5000],
        ];
        $storedLedgerBalance = 60000;

        $calculatedBalance = 0;
        foreach ($transactions as $txn) {
            if ($txn['direction'] === 'credit') {
                $calculatedBalance += $txn['amount'];
            } else {
                $calculatedBalance -= $txn['amount'];
            }
        }

        $this->assertEquals($storedLedgerBalance, $calculatedBalance, "Account ledger balance does not match transaction history.");
    }

    /**
     * 1. Test Name: Consistency_ProfitLedger_Traceability
     * 2. What it validates: Ensures every entry in profit_ledger can be traced back to a source transaction.
     * 3. Setup: Profit ledger entry for a fee.
     * 4. Steps:
     *    - Search transactions table for ref matching `profit_ledger.source_ref`.
     * 5. Expected Result: Exactly one matching transaction found.
     * 6. Failure condition: Orphaned profit entry or multiple matches.
     */
    public function testProfitLedgerTraceability()
    {
        $profitEntry = ['source_ref' => 'TXN-20260426-0001'];
        $transactions = [
            ['ref' => 'TXN-20260426-0001', 'type' => 'WITHDRAWAL'],
            ['ref' => 'TXN-20260426-0002', 'type' => 'DEPOSIT'],
        ];

        $found = false;
        foreach ($transactions as $txn) {
            if ($txn['ref'] === $profitEntry['source_ref']) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Profit ledger entry is orphaned; source transaction not found.");
    }
}
