#!/usr/bin/env python3
"""
MySQL to PostgreSQL Conversion Script for Atlas Bank Backend
Handles all MySQL-specific syntax conversion to PostgreSQL equivalents.
"""
import os
import re
import sys

BASE_DIR = '/home/z/my-project/download/atlas-bank-backend-pg'

# Skip these files
SKIP_FILES = {
    'config/database.php',  # Already converted
    'convert_mysql_to_pg.py',  # This script
}

# Table name to sequence name mapping for lastInsertId()
TABLE_SEQUENCES = {
    'transactions': 'transactions_id_seq',
    'operating_account_transactions': 'operating_account_transactions_id_seq',
    'loan_applications': 'loan_applications_id_seq',
    'loan_application_checks': 'loan_application_checks_id_seq',
    'loan_schedule': 'loan_schedule_id_seq',
    'loans': 'loans_id_seq',
    'loan_auto_pay_log': 'loan_auto_pay_log_id_seq',
    'loan_fund_transactions': 'loan_fund_transactions_id_seq',
    'customers': 'customers_id_seq',
    'accounts': 'accounts_id_seq',
    'staff': 'staff_id_seq',
    'expenses': 'expenses_id_seq',
    'audit_logs': 'audit_logs_id_seq',
    'audit_findings': 'audit_findings_id_seq',
    'notifications': 'notifications_id_seq',
    'approvals': 'approvals_id_seq',
    'branches': 'branches_id_seq',
    'settings': 'settings_id_seq',
    'general_ledger': 'general_ledger_id_seq',
    'gl_journal': 'gl_journal_id_seq',
    'chart_of_accounts': 'chart_of_accounts_id_seq',
    'profit_ledger': 'profit_ledger_id_seq',
    'generated_documents': 'generated_documents_id_seq',
    'balance_trends': 'balance_trends_id_seq',
    'transaction_deductions': 'transaction_deductions_id_seq',
    'customer_products': 'customer_products_id_seq',
    'operating_account': 'operating_account_id_seq',
    'sessions': 'sessions_id_seq',
    'policies': 'policies_id_seq',
    'idempotency_keys': 'idempotency_keys_id_seq',
    'investment_settings': 'investment_settings_id_seq',
    'investment_shareholders': 'investment_shareholders_id_seq',
    'investment_cycles': 'investment_cycles_id_seq',
    'investment_holdings': 'investment_holdings_id_seq',
    'investment_transactions': 'investment_transactions_id_seq',
    'investment_dividend_payments': 'investment_dividend_payments_id_seq',
    'investment_purchase_lots': 'investment_purchase_lots_id_seq',
    'customer_portal_users': 'customer_portal_users_id_seq',
    'staff_branches': 'staff_branches_id_seq',
    'audit_findings': 'audit_findings_id_seq',
}

def convert_file(filepath):
    """Convert a single PHP file from MySQL to PostgreSQL syntax."""
    with open(filepath, 'r', encoding='utf-8', errors='replace') as f:
        content = f.read()
    
    original = content
    changes = []
    
    # ============================================================
    # 1. SHOW COLUMNS FROM table LIKE 'column' → information_schema
    # ============================================================
    # Pattern: SHOW COLUMNS FROM `table` LIKE 'col'
    # → SELECT column_name FROM information_schema.columns WHERE table_name = 'table' AND column_name = 'col'
    def replace_show_columns(m):
        table = m.group(1)
        col = m.group(2)
        changes.append(f"SHOW COLUMNS → information_schema for {table}.{col}")
        return f"SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = '{table}' AND column_name = '{col}'"
    
    content = re.sub(
        r"SHOW COLUMNS FROM `([^`]+)` LIKE '([^']+)'",
        replace_show_columns,
        content
    )
    # Also handle without backticks
    content = re.sub(
        r"SHOW COLUMNS FROM (\w+) LIKE '([^']+)'",
        replace_show_columns,
        content
    )
    # Handle with double quotes
    content = re.sub(
        r'SHOW COLUMNS FROM "([^"]+)" LIKE \'([^\']+)\'',
        replace_show_columns,
        content
    )

    # ============================================================
    # 2. SHOW COLUMNS FROM table (without LIKE) → Get all columns
    # ============================================================
    def replace_show_columns_all(m):
        table = m.group(1)
        changes.append(f"SHOW COLUMNS FROM {table} → information_schema")
        return f"SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = '{table}'"
    
    content = re.sub(
        r"SHOW COLUMNS FROM `([^`]+)`",
        replace_show_columns_all,
        content
    )
    content = re.sub(
        r"SHOW COLUMNS FROM (\w+)",
        replace_show_columns_all,
        content
    )

    # ============================================================
    # 3. SHOW TABLES LIKE 'table' → information_schema.tables
    # ============================================================
    def replace_show_tables(m):
        table = m.group(1)
        changes.append(f"SHOW TABLES → information_schema for {table}")
        return f"SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = '{table}'"
    
    content = re.sub(
        r"SHOW TABLES LIKE '([^']+)'",
        replace_show_tables,
        content
    )
    content = re.sub(
        r'SHOW TABLES LIKE "([^"]+)"',
        replace_show_tables,
        content
    )
    # Also handle parameterized: SHOW TABLES LIKE ?
    content = re.sub(
        r"SHOW TABLES LIKE \?",
        "SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?",
        content
    )

    # ============================================================
    # 4. SHOW INDEX FROM table WHERE Key_name = 'name' → pg_indexes
    # ============================================================
    def replace_show_index(m):
        table = m.group(1)
        idx_name = m.group(2)
        changes.append(f"SHOW INDEX → pg_indexes for {table}.{idx_name}")
        return f"SELECT indexname FROM pg_indexes WHERE tablename = '{table}' AND indexname = '{idx_name}'"
    
    content = re.sub(
        r"SHOW INDEX FROM `?(\w+)`? WHERE Key_name\s*=\s*'([^']+)'",
        replace_show_index,
        content
    )

    # ============================================================
    # 5. ALTER TABLE ... CHANGE `old` `new` type → RENAME COLUMN
    # ============================================================
    def replace_alter_change(m):
        table = m.group(1)
        old_col = m.group(2)
        new_col = m.group(3)
        col_type = m.group(4)
        changes.append(f"ALTER TABLE CHANGE → RENAME COLUMN {old_col} → {new_col}")
        return f'ALTER TABLE "{table}" RENAME COLUMN "{old_col}" TO "{new_col}"'
    
    content = re.sub(
        r'ALTER TABLE `?(\w+)`? CHANGE `([^`]+)` `([^`]+)` (\S+)',
        replace_alter_change,
        content
    )
    # Also handle with double quotes
    content = re.sub(
        r'ALTER TABLE "?(\w+)"? CHANGE "([^"]+)" "([^"]+)" (\S+)',
        replace_alter_change,
        content
    )

    # ============================================================
    # 6. ALTER TABLE ... MODIFY COLUMN → ALTER COLUMN TYPE
    # ============================================================
    def replace_alter_modify(m):
        table = m.group(1)
        col = m.group(2)
        col_type = m.group(3)
        changes.append(f"ALTER TABLE MODIFY COLUMN → ALTER COLUMN TYPE for {table}.{col}")
        # Convert ENUM to VARCHAR with CHECK
        enum_match = re.match(r"ENUM\(([^)]+)\)", col_type)
        if enum_match:
            return f'ALTER TABLE "{table}" ALTER COLUMN "{col}" TYPE VARCHAR(30) USING "{col}"::VARCHAR(30)'
        return f'ALTER TABLE "{table}" ALTER COLUMN "{col}" TYPE {col_type} USING "{col}"::{col_type}'
    
    content = re.sub(
        r'ALTER TABLE `?(\w+)`? MODIFY COLUMN `?(\w+)`? (\S+(?:\([^)]+\))?)',
        replace_alter_modify,
        content
    )

    # ============================================================
    # 7. Remove AFTER `column` from ALTER TABLE ADD COLUMN
    # PostgreSQL doesn't support positional column addition
    # ============================================================
    content = re.sub(
        r'(ALTER TABLE\s+(?:`[^`]+`|"\w+"|\w+)\s+ADD COLUMN\s+(?:`[^`]+`|"\w+"|\w+)\s+\S+)\s+AFTER\s+(?:`[^`]+`|"\w+"|\w+)',
        r'\1',
        content
    )

    # ============================================================
    # 8. ENUM('val1','val2',...) in CREATE TABLE → VARCHAR(30) with CHECK
    # ============================================================
    def replace_enum_in_create(m):
        col_name = m.group(1)
        enum_vals = m.group(2)
        changes.append(f"ENUM → VARCHAR+CHECK for {col_name}")
        # Extract values
        vals = re.findall(r"'([^']*)'", enum_vals)
        check_vals = ', '.join(f"'{v}'" for v in vals)
        return f'"{col_name}" VARCHAR(30) CHECK ("{col_name}" IN ({check_vals}))'
    
    content = re.sub(
        r'"(\w+)"\s+ENUM\(([^)]+)\)',
        replace_enum_in_create,
        content
    )
    # Also handle ENUM without column name preceding (already in double-quoted context)
    def replace_enum_bare(m):
        enum_vals = m.group(1)
        changes.append(f"ENUM → VARCHAR(30) CHECK")
        vals = re.findall(r"'([^']*)'", enum_vals)
        check_vals = ', '.join(f"'{v}'" for v in vals)
        return f'VARCHAR(30) CHECK IN ({check_vals})'
    
    def _enum_not_null_default(m):
        vals = m.group(1)
        first_val = vals.split(',')[0].strip().strip("'")
        return f"VARCHAR(30) NOT NULL DEFAULT '{first_val}'"
    
    content = re.sub(
        r"ENUM\(([^)]+)\)\s+NOT NULL(?:\s+DEFAULT\s+'[^']*')?",
        _enum_not_null_default,
        content
    )
    # Remaining ENUM without specific handling
    content = re.sub(
        r"ENUM\(([^)]+)\)",
        lambda m: f"VARCHAR(30)",
        content
    )

    # ============================================================
    # 9. COMMENT 'text' in column definitions → Remove (PG uses COMMENT ON)
    # ============================================================
    content = re.sub(
        r"\s+COMMENT\s+'[^']*'",
        '',
        content
    )
    content = re.sub(
        r'\s+COMMENT\s+"[^"]*"',
        '',
        content
    )

    # ============================================================
    # 10. CURDATE() → CURRENT_DATE
    # ============================================================
    content = content.replace('CURDATE()', 'CURRENT_DATE')
    changes.append("CURDATE() → CURRENT_DATE")

    # ============================================================
    # 11. SUBSTRING_INDEX(str, delim, count) → split_part(str, delim, count)
    # ============================================================
    def replace_substring_index(m):
        args = m.group(1)
        changes.append(f"SUBSTRING_INDEX → split_part")
        return f'split_part({args})'
    
    content = re.sub(
        r'SUBSTRING_INDEX\(([^)]+)\)',
        replace_substring_index,
        content
    )

    # ============================================================
    # 12. FIELD(column, val1, val2, ...) → CASE WHEN
    # ============================================================
    def replace_field(m):
        args = m.group(1)
        parts = [p.strip() for p in args.split(',')]
        if len(parts) < 2:
            return m.group(0)
        col = parts[0]
        changes.append(f"FIELD → CASE WHEN for {col}")
        case_parts = []
        for i, val in enumerate(parts[1:], 1):
            case_parts.append(f'WHEN {col} = {val} THEN {i}')
        return f'CASE {"".join(case_parts)} ELSE 0 END'
    
    content = re.sub(
        r'FIELD\(([^)]+)\)',
        replace_field,
        content
    )

    # ============================================================
    # 13. DATABASE() → current_schema()
    # ============================================================
    content = content.replace('DATABASE()', "current_schema()")
    if 'DATABASE()' not in original and 'current_schema()' in content:
        changes.append("DATABASE() → current_schema()")

    # ============================================================
    # 14. DATE_ADD(NOW(), INTERVAL X UNIT) → NOW() + INTERVAL 'X units'
    # ============================================================
    def replace_date_add(m):
        val = m.group(1)
        unit = m.group(2).upper()
        # Map MySQL units to PostgreSQL
        unit_map = {
            'DAY': 'days', 'DAYS': 'days',
            'HOUR': 'hours', 'HOURS': 'hours',
            'MINUTE': 'minutes', 'MINUTES': 'minutes',
            'SECOND': 'seconds', 'SECONDS': 'seconds',
            'MONTH': 'months', 'MONTHS': 'months',
            'YEAR': 'years', 'YEARS': 'years',
            'WEEK': 'weeks', 'WEEKS': 'weeks',
        }
        pg_unit = unit_map.get(unit, unit.lower() + 's')
        changes.append(f"DATE_ADD → NOW() + INTERVAL")
        return f"NOW() + INTERVAL '{val} {pg_unit}'"
    
    content = re.sub(
        r"DATE_ADD\(\s*NOW\(\)\s*,\s*INTERVAL\s+(\d+)\s+(\w+)\s*\)",
        replace_date_add,
        content,
        flags=re.IGNORECASE
    )

    # ============================================================
    # 15. ON DUPLICATE KEY UPDATE → ON CONFLICT DO UPDATE
    # ============================================================
    # Pattern: INSERT INTO table (...) VALUES (...) ON DUPLICATE KEY UPDATE col=val
    def replace_on_duplicate(m):
        prefix = m.group(1)
        update_clause = m.group(2)
        changes.append(f"ON DUPLICATE KEY UPDATE → ON CONFLICT DO UPDATE")
        # Parse the update clause to extract conflict columns
        # For simplicity, assume the unique column is the first one or 'id'
        return f'{prefix} ON CONFLICT (id) DO UPDATE SET {update_clause}'
    
    content = re.sub(
        r'(INSERT\s+INTO\s+\S+\s*\([^)]+\)\s*VALUES\s*\([^)]+\))\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+(.+?)(?:\s*(?:;|\)|$))',
        replace_on_duplicate,
        content,
        flags=re.IGNORECASE
    )

    # ============================================================
    # 16. INSERT IGNORE INTO → INSERT INTO ... ON CONFLICT DO NOTHING
    # ============================================================
    content = re.sub(
        r'INSERT\s+IGNORE\s+INTO',
        'INSERT INTO',
        content,
        flags=re.IGNORECASE
    )
    # Note: This removes IGNORE; the ON CONFLICT DO NOTHING needs to be added per-table
    # For now, just remove IGNORE since most tables have unique constraints that would fail

    # ============================================================
    # 17. lastInsertId() → lastInsertId('table_id_seq')
    # ============================================================
    # Pattern: $db->lastInsertId() without argument
    def replace_last_insert_id(m):
        prefix = m.group(1)
        suffix = m.group(2)
        # Try to find the table name from context - look backwards for INSERT INTO
        # For now, we'll need to handle this per-file. Use a generic approach:
        # Look for the most recent INSERT INTO in the same execute block
        changes.append("lastInsertId() - needs sequence name (manual review)")
        # Return unchanged - will handle per-file
        return m.group(0)
    
    # We'll handle lastInsertId in a second pass per-file

    # ============================================================
    # 18. Backticks → Double quotes in SQL contexts
    # ============================================================
    # Only replace backticks that are used as SQL identifiers, not PHP string delimiters
    # Pattern: backtick-quoted identifiers in SQL query strings
    def replace_backticks(m):
        ident = m.group(1)
        changes.append(f"Backtick → Double quote: `{ident}` → \"{ident}\"")
        return f'"{ident}"'
    
    content = re.sub(r'`(\w+)`', replace_backticks, content)

    # ============================================================
    # 19. REGEXP → ~ (PostgreSQL regex operator)
    # ============================================================
    content = re.sub(
        r'\bREGEXP\b',
        '~',
        content,
        flags=re.IGNORECASE
    )
    if '~' in content and 'REGEXP' not in original:
        changes.append("REGEXP → ~")

    # ============================================================
    # 20. INNER JOIN in UPDATE statements → PostgreSQL UPDATE FROM syntax
    # ============================================================
    # Pattern: UPDATE t1 INNER JOIN t2 ON ... SET t1.col = t2.col WHERE ...
    def replace_update_join(m):
        table1 = m.group(1)
        alias1 = m.group(2) or ''
        table2 = m.group(3)
        alias2 = m.group(4) or ''
        on_clause = m.group(5)
        set_clause = m.group(6)
        where_clause = m.group(7) or ''
        changes.append(f"UPDATE INNER JOIN → UPDATE FROM for {table1}")
        a1 = alias1.strip() if alias1.strip() else table1
        a2 = alias2.strip() if alias2.strip() else table2
        return f'UPDATE "{table1}" {alias1} SET {set_clause} FROM "{table2}" {alias2} WHERE {on_clause} {where_clause}'
    
    content = re.sub(
        r'UPDATE\s+"?(\w+)"?\s+(\w+)\s+INNER\s+JOIN\s+"?(\w+)"?\s+(\w+)\s+ON\s+(.+?)\s+SET\s+(.+?)(?:\s+WHERE\s+(.+?))?(?:\s*;)',
        replace_update_join,
        content,
        flags=re.IGNORECASE
    )

    # ============================================================
    # 21. GROUP_CONCAT → STRING_AGG
    # ============================================================
    def replace_group_concat(m):
        inner = m.group(1)
        # Parse: col SEPARATOR ','
        sep_match = re.search(r"SEPARATOR\s+'([^']*)'", inner)
        if sep_match:
            col_part = inner[:sep_match.start()].strip().rstrip(',').strip()
            sep = sep_match.group(1)
            changes.append(f"GROUP_CONCAT → STRING_AGG")
            return f"STRING_AGG({col_part}::text, '{sep}')"
        else:
            changes.append(f"GROUP_CONCAT → STRING_AGG")
            return f"STRING_AGG({inner}::text, ',')"
    
    content = re.sub(
        r'GROUP_CONCAT\(([^)]+)\)',
        replace_group_concat,
        content,
        flags=re.IGNORECASE
    )

    # ============================================================
    # 22. IFNULL → COALESCE
    # ============================================================
    content = re.sub(
        r'\bIFNULL\s*\(',
        'COALESCE(',
        content,
        flags=re.IGNORECASE
    )
    if 'COALESCE(' in content and 'IFNULL' not in original:
        changes.append("IFNULL → COALESCE")

    # ============================================================
    # 23. BOOLEAN DEFAULT TRUE/FALSE (already mostly compatible)
    # ============================================================
    content = re.sub(r'BOOLEAN DEFAULT TRUE', 'BOOLEAN DEFAULT TRUE', content)
    content = re.sub(r'BOOLEAN DEFAULT FALSE', 'BOOLEAN DEFAULT FALSE', content)

    # ============================================================
    # 24. SERIAL PRIMARY KEY already correct for PG
    # ============================================================
    # No change needed - SERIAL is already PostgreSQL-compatible

    # ============================================================
    # 25. PRIMARY KEY,PRIMARY KEY → PRIMARY KEY (fix duplicate)
    # ============================================================
    content = content.replace('PRIMARY KEY,PRIMARY KEY', 'PRIMARY KEY')

    if content != original:
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)
        return changes
    return []


def fix_last_insert_id(content, filename):
    """Second pass: Fix lastInsertId() calls by inferring the table from context."""
    changes = []
    
    # Find all lastInsertId() calls without sequence name
    pattern = r'\$db->lastInsertId\(\s*\)'
    
    # For each occurrence, look backwards for the INSERT INTO table
    offset = 0
    result = content
    
    while True:
        match = re.search(pattern, result[offset:])
        if not match:
            break
        
        abs_pos = offset + match.start()
        
        # Look backwards from the match position for INSERT INTO table
        before = result[:abs_pos]
        insert_match = None
        
        # Search for the most recent INSERT INTO
        for im in re.finditer(r'INSERT\s+INTO\s+"?(\w+)"?', before, re.IGNORECASE):
            insert_match = im
        
        if insert_match:
            table = insert_match.group(1)
            seq_name = TABLE_SEQUENCES.get(table, f'{table}_id_seq')
            old = match.group(0)
            new = f"$db->lastInsertId('{seq_name}')"
            result = result[:abs_pos] + new + result[abs_pos + len(old):]
            offset = abs_pos + len(new)
            changes.append(f"lastInsertId() → lastInsertId('{seq_name}') for table {table}")
        else:
            offset = abs_pos + match.end()
    
    return result, changes


def fix_backtick_in_build_orderby(content):
    """Fix backticks in buildOrderBy function - already handled by main pass."""
    # The main backtick replacement should have caught these
    return content, []


def main():
    """Main conversion function."""
    php_files = []
    
    # Collect all PHP files
    for root, dirs, files in os.walk(BASE_DIR):
        # Skip vendor, node_modules, storage directories
        dirs[:] = [d for d in dirs if d not in ('vendor', 'node_modules', 'storage', '.git')]
        for f in files:
            if f.endswith('.php'):
                rel_path = os.path.relpath(os.path.join(root, f), BASE_DIR)
                if rel_path not in SKIP_FILES:
                    php_files.append(os.path.join(root, f))
    
    print(f"Found {len(php_files)} PHP files to process")
    
    total_changes = []
    
    for filepath in sorted(php_files):
        rel = os.path.relpath(filepath, BASE_DIR)
        
        # First pass: regex conversions
        changes = convert_file(filepath)
        
        # Second pass: lastInsertId fixes
        with open(filepath, 'r', encoding='utf-8', errors='replace') as f:
            content = f.read()
        content, lid_changes = fix_last_insert_id(content, rel)
        if lid_changes:
            with open(filepath, 'w', encoding='utf-8') as f:
                f.write(content)
            changes.extend(lid_changes)
        
        if changes:
            print(f"\n✓ {rel} ({len(changes)} changes):")
            for c in changes[:10]:  # Show first 10
                print(f"  - {c}")
            if len(changes) > 10:
                print(f"  ... and {len(changes) - 10} more")
            total_changes.extend([(rel, c) for c in changes])
    
    print(f"\n{'='*60}")
    print(f"Total changes: {len(total_changes)}")
    print(f"Files modified: {len(set(r for r, c in total_changes))}")
    

if __name__ == '__main__':
    main()
