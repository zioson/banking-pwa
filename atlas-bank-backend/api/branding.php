<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Branding
 *
 * Singleton pattern: one row in bank_branding table.
 * GET auto-initializes with defaults if no record exists.
 * PUT uses UPSERT to create or update the record.
 */
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff = requireAuth();
$method = $_ROUTE['method'];

// GET: Any authenticated user can read branding (needed for the enterprise loader).
// PUT: Requires ADMIN role (only admins can change branding).
if ($method === 'PUT') {
    requireRole(['ADMIN'], $staff);
}

$db = getDB();
$db->exec("CREATE TABLE IF NOT EXISTS `bank_branding` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `bank_name` VARCHAR(255) NOT NULL DEFAULT '',
    `bank_name_short` VARCHAR(100) DEFAULT NULL,
    `tagline` VARCHAR(255) DEFAULT NULL,
    `logo` LONGTEXT DEFAULT NULL,
    `primary_color` VARCHAR(20) DEFAULT NULL,
    `accent_color` VARCHAR(20) DEFAULT NULL,
    `head_office_address` TEXT DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `phone_alt` VARCHAR(50) DEFAULT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `website` VARCHAR(255) DEFAULT NULL,
    `swift_code` VARCHAR(20) DEFAULT NULL,
    `cbn_license_number` VARCHAR(100) DEFAULT NULL,
    `registration_number` VARCHAR(100) DEFAULT NULL,
    `tax_identification_number` VARCHAR(100) DEFAULT NULL,
    `slogan` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Default branding values ──
$defaults = [
    'bank_name'                => 'ATLAS BANK',
    'bank_name_short'          => 'Atlas Bank',
    'tagline'                  => 'Enterprise Operations Console',
    'logo'                     => null,
    'primary_color'            => '#58b7ff',
    'accent_color'             => '#67e8b5',
    'head_office_address'      => 'Avenue Ahmadou Ahidjo, Immeuble Atlas, Centre-Ville, Douala, Cameroun',
    'phone'                    => '+237 233 42 18 00',
    'phone_alt'                => '+237 699 000 000',
    'email'                    => 'info@atlasbank.cm',
    'website'                  => 'www.atlasbank.cm',
    'swift_code'               => 'ATLSCMCM',
    'cbn_license_number'       => 'BEAC-2025-CM-0047',
    'registration_number'      => 'RCCM-DL-2025-A-1234',
    'tax_identification_number'=> 'NIF-1234567890A',
    'slogan'                   => 'Your Trusted Financial Partner',
];

// ── All updatable fields ──
$updatableFields = [
    'bank_name', 'bank_name_short', 'tagline', 'logo', 'primary_color', 'accent_color',
    'head_office_address', 'phone', 'phone_alt', 'email', 'website',
    'swift_code', 'cbn_license_number', 'registration_number',
    'tax_identification_number', 'slogan'
];

switch ($method) {
    case 'GET':
        try {
            $db = getDB();
            $stmt = $db->query('SELECT id, bank_name, bank_name_short, tagline, logo, primary_color, accent_color, head_office_address, phone, phone_alt, email, website, swift_code, cbn_license_number, registration_number, tax_identification_number, slogan, created_at, updated_at FROM bank_branding ORDER BY id LIMIT 1');
            $branding = $stmt->fetch();

            // Auto-initialize with defaults if no record exists
            if (!$branding) {
                $insStmt = $db->prepare(
                    'INSERT INTO bank_branding (bank_name, bank_name_short, tagline, primary_color, accent_color,
                         head_office_address, phone, phone_alt, email, website, swift_code,
                         cbn_license_number, registration_number, tax_identification_number, slogan)
                     VALUES (:bn, :bns, :tag, :pc, :ac, :addr, :ph, :pha, :em, :web, :swift,
                         :cbn, :reg, :tax, :slogan)'
                );
                $insStmt->execute([
                    ':bn'    => $defaults['bank_name'],
                    ':bns'   => $defaults['bank_name_short'],
                    ':tag'   => $defaults['tagline'],
                    ':pc'    => $defaults['primary_color'],
                    ':ac'    => $defaults['accent_color'],
                    ':addr'  => $defaults['head_office_address'],
                    ':ph'    => $defaults['phone'],
                    ':pha'   => $defaults['phone_alt'],
                    ':em'    => $defaults['email'],
                    ':web'   => $defaults['website'],
                    ':swift' => $defaults['swift_code'],
                    ':cbn'   => $defaults['cbn_license_number'],
                    ':reg'   => $defaults['registration_number'],
                    ':tax'   => $defaults['tax_identification_number'],
                    ':slogan'=> $defaults['slogan'],
                ]);

                // Re-fetch after insert
                $stmt = $db->query('SELECT id, bank_name, bank_name_short, tagline, logo, primary_color, accent_color, head_office_address, phone, phone_alt, email, website, swift_code, cbn_license_number, registration_number, tax_identification_number, slogan, created_at, updated_at FROM bank_branding ORDER BY id LIMIT 1');
                $branding = $stmt->fetch();
            }

            if ($branding) {
                successResponse($branding);
            } else {
                serverErrorResponse('Failed to initialize bank branding.');
            }
        } catch (PDOException $e) { serverErrorResponse('Database error.'); }
        break;

    case 'PUT':
        requireRole(['ADMIN']);
        $input = getRequestInput();
        try {
            $db = getDB();

            // Check if a record exists
            $checkStmt = $db->query('SELECT COUNT(*) as cnt FROM bank_branding');
            $count = (int)$checkStmt->fetch()['cnt'];

            // Build fields and params
            $fields = [];
            $params = [];
            foreach ($updatableFields as $f) {
                if (isset($input[$f])) {
                    $fields[] = "`$f` = :$f";
                    $params[":$f"] = sanitize($input[$f]);
                }
            }
            if (empty($fields)) { errorResponse('No fields to update.'); }

            if ($count === 0) {
                // INSERT new record with provided values + defaults for missing ones
                $insertFields = [];
                $insertParams = [];
                $insertPlaceholders = [];
                foreach ($updatableFields as $f) {
                    $insertFields[] = "`$f`";
                    $insertPlaceholders[] = ':' . $f;
                    // Use input value if provided, otherwise use default
                    $insertParams[':' . $f] = isset($input[$f]) ? sanitize($input[$f]) : ($defaults[$f] ?? null);
                }
                $insSql = 'INSERT INTO bank_branding (' . implode(', ', $insertFields) . ') VALUES (' . implode(', ', $insertPlaceholders) . ')';
                $insStmt = $db->prepare($insSql);
                $insStmt->execute($insertParams);
            } else {
                // UPDATE existing record (id = 1 or first row)
                $sql = 'UPDATE bank_branding SET ' . implode(', ', $fields) . ' WHERE id = (SELECT MIN(id) FROM bank_branding)';
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
            }

            logAudit($staff['full_name'], 'BRANDING_UPDATE', 'BRANDING', '1', 'SUCCESS',
                'Updated bank branding', $staff['department'], getClientIp());

            // Return the updated record
            $fetchStmt = $db->query('SELECT id, bank_name, bank_name_short, tagline, logo, primary_color, accent_color, head_office_address, phone, phone_alt, email, website, swift_code, cbn_license_number, registration_number, tax_identification_number, slogan, created_at, updated_at FROM bank_branding ORDER BY id LIMIT 1');
            $updated = $fetchStmt->fetch();
            if ($updated) {
                successResponse($updated, 'Branding updated successfully.');
            } else {
                successMessage('Branding updated successfully.');
            }
        } catch (PDOException $e) { serverErrorResponse('Failed to update branding.'); }
        break;

    default:
        errorResponse('Method not allowed.', 405);
}