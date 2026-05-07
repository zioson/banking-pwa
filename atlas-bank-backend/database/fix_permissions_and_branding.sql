-- ============================================================
-- Atlas Bank — Fix missing branding + staff module permissions
-- Run in phpMyAdmin or MySQL CLI
-- ============================================================

USE atlas_bank;

-- 1. Ensure bank_branding has at least one row (fixes 404 on branding)
INSERT INTO bank_branding (
  bank_name, bank_name_short, tagline, logo,
  primary_color, accent_color,
  head_office_address, phone, phone_alt, email, website,
  swift_code, cbn_license_number, registration_number,
  tax_identification_number, slogan
) VALUES (
  'ATLAS BANK',
  'Atlas Bank',
  'Enterprise Operations Console',
  NULL,
  '#58b7ff',
  '#67e8b5',
  '45 Avenue de la Liberte, BP 12345, Douala, Littoral, Cameroon',
  '+237 233 42 15 00',
  '+237 699 00 00 01',
  'info@atlasbank.cm',
  'www.atlasbank.cm',
  'ATLSCMCD',
  'CBN-LIC-2024-0847',
  'RC-DC-2024-B0012',
  'TIN-CM-91000-ATLAS',
  'Building Trust, Securing Futures'
) ON DUPLICATE KEY UPDATE bank_name = bank_name;

-- 2. Grant ALL modules to ALL existing staff (fixes 403 on all endpoints)
-- This gives every staff member full access.
-- If you prefer to restrict specific users, run individual inserts instead.
INSERT IGNORE INTO staff_modules (staff_id, module_name)
SELECT s.id, 'ALL'
FROM staff s
WHERE s.employment_status = 'ACTIVE'
AND NOT EXISTS (
  SELECT 1 FROM staff_modules sm WHERE sm.staff_id = s.id AND sm.module_name = 'ALL'
);

-- 3. Also ensure specific modules are granted (belt-and-suspenders)
INSERT IGNORE INTO staff_modules (staff_id, module_name)
SELECT s.id, m.module_name
FROM staff s
CROSS JOIN (
  SELECT 'DASHBOARD' AS module_name UNION SELECT 'CUSTOMERS' UNION SELECT 'ACCOUNTS'
  UNION SELECT 'TRANSACTIONS' UNION SELECT 'LOANS' UNION SELECT 'TRANSFERS'
  UNION SELECT 'REPORTS' UNION SELECT 'BRANCHES' UNION SELECT 'STAFF'
  UNION SELECT 'SETTINGS' UNION SELECT 'APPROVALS' UNION SELECT 'AUDIT'
  UNION SELECT 'NOTIFICATIONS' UNION SELECT 'DOCUMENTS' UNION SELECT 'CHART_OF_ACCOUNTS'
  UNION SELECT 'EXPENSES' UNION SELECT 'POLICIES'
) m
WHERE s.employment_status = 'ACTIVE';

-- 4. Ensure all staff have branch access
INSERT IGNORE INTO staff_branches (staff_id, branch_name)
SELECT s.id, b.name
FROM staff s
CROSS JOIN branches b
WHERE s.employment_status = 'ACTIVE';

-- 5. Verify: Show current staff module assignments
SELECT s.id, s.username, s.full_name, s.role,
  GROUP_CONCAT(sm.module_name ORDER BY sm.module_name) AS modules
FROM staff s
LEFT JOIN staff_modules sm ON sm.staff_id = s.id
GROUP BY s.id, s.username, s.full_name, s.role;
