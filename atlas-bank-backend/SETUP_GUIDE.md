# Atlas Bank Enterprise Operations Console — Backend Setup Guide

## Architecture Overview

```
Frontend (HTML/JS)          PHP Backend (API)           MySQL Database
┌──────────────┐     ┌──────────────────┐     ┌──────────────┐
│ atlas-bank-  │────▶│ /api/auth        │────▶│ staff        │
│ enterprise-  │     │ /api/staff       │────▶│ branches     │
│ console-     │     │ /api/customers   │────▶│ customers    │
│ v10.html     │     │ /api/accounts    │────▶│ accounts     │
│              │     │ /api/transactions│────▶│ transactions │
│ (fetch API)  │◀────│ /api/loans       │◀────│ loans        │
│              │     │ /api/approvals   │────▶│ approvals    │
│              │     │ /api/settings    │────▶│ settings     │
│              │     │ /api/branding    │────▶│ bank_branding│
│              │     │ /api/audit       │────▶│ audit_logs   │
│              │     │ + 10 more        │────▶│ + 20 tables  │
└──────────────┘     └──────────────────┘     └──────────────┘
     Port 80/443          Apache/Nginx            Port 3306
```

## Prerequisites

- **PHP 8.0+** with PDO and pdo_mysql extensions
- **MySQL 8.0+** or MariaDB 10.5+
- **Apache** with mod_rewrite enabled (or Nginx with equivalent config)
- **Web browser** for the frontend

## Installation Steps

### Step 1: Database Setup

```bash
# Login to MySQL
mysql -u root -p

# Create the database and import schema
source /path/to/atlas-bank-backend/database/atlas_bank_schema.sql

# Import seed data (demo accounts, settings, etc.)
source /path/to/atlas-bank-backend/database/atlas_bank_seed_data.sql

# Verify tables were created
USE atlas_bank;
SHOW TABLES;
```

### Step 2: Configure Database Connection

Edit `/path/to/atlas-bank-backend/config/database.php`:

```php
define('DB_HOST', 'localhost');     // Your MySQL host
define('DB_NAME', 'atlas_bank');    // Database name
define('DB_USER', 'root');          // Your MySQL username
define('DB_PASS', '');              // Your MySQL password
```

### Step 3: Deploy Backend Files

Copy the entire `atlas-bank-backend/` directory to your web server:

**Apache (recommended):**
```bash
# Copy to Apache document root
cp -r atlas-bank-backend/ /var/www/html/atlas-bank-backend/

# Ensure .htaccess is read
# In your Apache config or .htaccess:
# AllowOverride All

# Set permissions
chmod -R 755 /var/www/html/atlas-bank-backend/
```

**Nginx alternative:**
```nginx
server {
    listen 80;
    server_name your-bank-domain.com;
    root /var/www/atlas-bank-backend;
    index index.php;

    location /api/ {
        try_files $uri $uri/ /router.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### Step 4: Configure Frontend API URL

In `atlas-bank-enterprise-console-v10.html`, find line ~1858:

```javascript
const API_BASE = '/api/';  // Change to full URL if API is on different server
```

If the backend is on a different server/port, change to:
```javascript
const API_BASE = 'http://localhost/atlas-bank-backend/api/';
// or
const API_BASE = 'https://your-bank-domain.com/api/';
```

### Step 5: Test the Setup

1. **Health Check:** Visit `http://localhost/atlas-bank-backend/` in your browser. You should see:
   ```json
   {"service":"Atlas Bank Enterprise API","version":"1.0.0","status":"operational",...}
   ```

2. **Login Test:** Open the HTML console and open `atlas-bank-enterprise-console-v10.html`. Login with:
   - **Username:** `admin` | **Password:** `admin123` (Full access)
   - **Username:** `auditor` | **Password:** `auditor123` (Audit access)

3. **Verify Data Sync:** After login, check browser DevTools → Network tab. You should see 15+ API calls to `/api/...` endpoints.

## API Endpoints Reference

| Endpoint | Method | Description | Auth Required |
|----------|--------|-------------|---------------|
| `/api/auth` | POST | Login (username, password) | No |
| `/api/auth` | DELETE | Logout | Yes |
| `/api/staff` | GET | List all staff | Yes |
| `/api/staff` | POST | Create staff member | Yes |
| `/api/staff/{id}` | GET | Get staff details | Yes |
| `/api/staff/{id}` | PUT | Update staff member | Yes |
| `/api/customers` | GET | List customers (paginated) | Yes |
| `/api/customers` | POST | Create customer | Yes |
| `/api/customers/{id}` | GET | Customer details + products | Yes |
| `/api/customers/{id}` | PUT | Update customer | Yes |
| `/api/accounts` | GET | List accounts (paginated) | Yes |
| `/api/accounts` | POST | Open new account | Yes |
| `/api/accounts/{id}` | GET | Account details + exemptions | Yes |
| `/api/accounts/{id}` | PUT | Update account status | Yes |
| `/api/transactions` | GET | List transactions (paginated, filterable) | Yes |
| `/api/transactions` | POST | Create transaction | Yes |
| `/api/transactions/{id}` | GET | Transaction + deductions | Yes |
| `/api/loans` | GET | List loans | Yes |
| `/api/loans` | POST | Create loan | Yes |
| `/api/loans/{id}` | GET | Loan + schedule | Yes |
| `/api/loans/{id}` | PUT | Update loan | Yes |
| `/api/approvals` | GET | List approvals | Yes |
| `/api/approvals` | POST | Submit for approval | Yes |
| `/api/approvals/{id}` | PUT | Approve/reject | Yes |
| `/api/documents` | GET | List generated documents | Yes |
| `/api/documents` | POST | Register document | Yes |
| `/api/branches` | GET | List branches | Yes |
| `/api/settings` | GET | List settings (by category) | Yes |
| `/api/settings/{id}` | PUT | Update setting | Yes |
| `/api/branding` | GET | Get bank branding | Yes |
| `/api/branding` | PUT | Update branding | Yes |
| `/api/expenses` | GET | List expenses | Yes |
| `/api/expenses` | POST | Record expense | Yes |
| `/api/reports` | GET | Dashboard/profit-loss/balance reports | Yes |
| `/api/audit` | GET | Audit logs + findings | Yes |
| `/api/notifications` | GET | User notifications | Yes |
| `/api/notifications/{id}` | PUT | Mark read/archived | Yes |
| `/api/policies` | GET | System policies | Yes |
| `/api/search` | GET | Global search | Yes |
| `/api/deductions` | GET/POST/PUT | Tax/fee deductions | Yes |
| `/api/chart-of-accounts` | GET | Chart of accounts | Yes |

## Database Tables (30)

| # | Table | Purpose |
|---|-------|---------|
| 1 | `bank_branding` | Bank name, logo, address, SWIFT, license |
| 2 | `branches` | Branch locations and codes |
| 3 | `staff` | Employee records with bcrypt passwords |
| 4 | `staff_branches` | M:N staff-to-branch assignments |
| 5 | `staff_modules` | M:N staff-to-module RBAC |
| 6 | `customers` | Individual and business customers |
| 7 | `customer_products` | M:N customer-to-product |
| 8 | `accounts` | Bank accounts (12 product types) |
| 9 | `account_tax_exemptions` | Per-account tax/fee exemptions |
| 10 | `transactions` | All transaction records |
| 11 | `transaction_deductions` | Tax/fee breakdown per transaction |
| 12 | `loans` | Active and historical loans |
| 13 | `loan_applications` | Loan application pipeline |
| 14 | `loan_application_checks` | KYC, credit, collateral checks |
| 15 | `loan_schedule` | Amortization schedule |
| 16 | `approvals` | Maker-checker approval queue |
| 17 | `audit_logs` | Complete audit trail |
| 18 | `login_history` | Login attempts and results |
| 19 | `notifications` | In-app notifications |
| 20 | `settings` | 36+ configurable system settings |
| 21 | `chart_of_accounts` | GL account structure |
| 22 | `expenses` | Bank operating expenses |
| 23 | `operating_account` | Bank's own operating fund |
| 24 | `operating_account_transactions` | Operating fund transactions |
| 25 | `generated_documents` | Payslips, statements, receipts |
| 26 | `profit_ledger` | P&L accounting ledger |
| 27 | `policies` | System policy versions |
| 28 | `audit_findings` | Compliance audit findings |
| 29 | `sessions` | User session tokens |
| 30 | `balance_trends` | Daily balance snapshots |

## Security Features

- **Password Hashing:** bcrypt via PHP's `password_hash()`
- **JWT-style Sessions:** Token-based auth with expiry
- **RBAC:** 16 module-level permissions per staff member
- **Account Lockout:** 5 failed attempts → 30-minute lock
- **Prepared Statements:** All SQL queries use PDO parameterized queries
- **CORS:** Configurable cross-origin protection
- **Security Headers:** X-Frame-Options, CSP, XSS Protection via .htaccess
- **Audit Trail:** Every write operation logged

## Offline/Fallback Mode

The frontend automatically falls back to offline mode if the API is unavailable. Set `API_CONNECTED = false` in the JavaScript to force offline mode. In offline mode, the app uses the in-memory DB cache (populated from the last successful API sync).
