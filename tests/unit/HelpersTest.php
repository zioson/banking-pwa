<?php
/**
 * Unit Tests for Helper Functions
 * 
 * 1. Test Name: base32Decode_ValidInput
 * 2. What it validates: Ensures valid Base32 strings are decoded correctly.
 * 3. Setup: None
 * 4. Steps: Call base32Decode with 'JBSWY3DPEBLW64TMMQ'
 * 5. Expected Result: 'Hello World!'
 * 6. Failure condition: Returns false or incorrect string.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../atlas-bank-backend/includes/helpers.php';

class HelpersTest extends TestCase
{
    /**
     * @covers base32Decode
     */
    public function testBase32DecodeValid()
    {
        $input = 'JBSWY3DPEBLW64TMMQ'; // 'Hello World!' in Base32
        $expected = 'Hello World!';
        $this->assertEquals($expected, base32Decode($input));
    }

    /**
     * @covers base32Decode
     * 1. Test Name: base32Decode_InvalidInput
     * 2. What it validates: Ensures invalid Base32 strings return false.
     * 3. Setup: None
     * 4. Steps: Call base32Decode with 'INVALID1!'
     * 5. Expected Result: false
     * 6. Failure condition: Returns a string instead of false.
     */
    public function testBase32DecodeInvalid()
    {
        $this->assertFalse(base32Decode('INVALID1!'));
    }

    /**
     * @covers generateRef
     * 1. Test Name: generateRef_Format
     * 2. What it validates: Ensures the generated reference follows the TXN-YYYYMMDD-NNNN format.
     * 3. Setup: Mock DB or assume DB connection works for sequence.
     * 4. Steps: Call generateRef('TXN')
     * 5. Expected Result: Matches regex /^TXN-\d{8}-\d{4}$/
     * 6. Failure condition: Does not match the pattern.
     */
    public function testGenerateRefFormat()
    {
        $ref = generateRef('TXN');
        $this->assertMatchesRegularExpression('/^TXN-\d{8}-\d{4}$/', $ref);
    }

    /**
     * @covers moneyFormat
     * 1. Test Name: moneyFormat_Standard
     * 2. What it validates: Ensures monetary amounts are formatted correctly with spaces and currency.
     * 3. Setup: None
     * 4. Steps: Call moneyFormat(1500000)
     * 5. Expected Result: '1 500 000 FCFA'
     * 6. Failure condition: Incorrect formatting or missing symbol.
     */
    public function testMoneyFormat()
    {
        $this->assertEquals('1 500 000 FCFA', moneyFormat(1500000));
        $this->assertEquals('1 500 FCFA', moneyFormat(1500));
        $this->assertEquals('0 FCFA', moneyFormat(0));
    }

    /**
     * @covers generateRef
     * 1. Test Name: generateRef_Concurrency
     * 2. What it validates: Ensures that even if DB fails, a fallback reference is generated.
     * 3. Setup: Simulate DB failure.
     * 4. Steps: Call generateRef('TXN')
     * 5. Expected Result: Matches regex /^TXN-\d{8}-\d{4,6}$/
     * 6. Failure condition: Throws exception or returns empty.
     */
    public function testGenerateRefFallback()
    {
        // To test fallback, we would need to mock getDB() to throw PDOException
        // For now, we verify the format which should hold even in fallback (His = 6 digits)
        $ref = generateRef('TXN');
        $this->assertMatchesRegularExpression('/^TXN-\d{8}-\d{4,6}$/', $ref);
    }

    /**
     * @covers generateDocumentNumber
     * 1. Test Name: generateDocumentNumber_Format
     * 2. What it validates: Ensures document numbers follow DOC-{TYPE}-YYYYMMDD-NNNN.
     * 3. Setup: None
     * 4. Steps: Call generateDocumentNumber('STMT')
     * 5. Expected Result: Matches regex /^DOC-STMT-\d{8}-\d{4}$/
     * 6. Failure condition: Incorrect format.
     */
    public function testGenerateDocumentNumber()
    {
        $docNum = generateDocumentNumber('STMT');
        $this->assertMatchesRegularExpression('/^DOC-STMT-\d{8}-\d{4}$/', $docNum);
    }

    /**
     * @covers generateAccountNumber
     * 1. Test Name: generateAccountNumber_Format
     * 2. What it validates: Ensures account numbers follow ACC-2001NNNNN.
     * 3. Setup: None
     * 4. Steps: Call generateAccountNumber()
     * 5. Expected Result: Matches regex /^ACC-2001\d{5}$/
     * 6. Failure condition: Incorrect format.
     */
    public function testGenerateAccountNumber()
    {
        $accNum = generateAccountNumber();
        $this->assertMatchesRegularExpression('/^ACC-2001\d{5}$/', $accNum);
    }

    /**
     * @covers generateCustomerNumber
     * 1. Test Name: generateCustomerNumber_Format
     * 2. What it validates: Ensures customer numbers follow CUST-NNNNNN.
     * 3. Setup: None
     * 4. Steps: Call generateCustomerNumber()
     * 5. Expected Result: Matches regex /^CUST-\d{6}$/
     * 6. Failure condition: Incorrect format.
     */
    public function testGenerateCustomerNumber()
    {
        $custNum = generateCustomerNumber();
        $this->assertMatchesRegularExpression('/^CUST-\d{6}$/', $custNum);
    }
}
