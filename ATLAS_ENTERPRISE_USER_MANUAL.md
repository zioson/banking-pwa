# Atlas Bank Enterprise Console
## Enterprise User Manual

Version: 10 (Operations Console)  
Environment: Web Console + PHP API Backend  
Audience: Operations, Finance, Credit, Audit, Branch, Compliance, IT Admin

---

## 1. Purpose

This manual defines how to use the Atlas Bank Enterprise Console safely and consistently across all operational, credit, treasury, accounting, and reporting workflows.

It covers:
- Every major panel and sub-panel
- End-to-end operational workflows
- Role and branch controls
- Financial control points and accounting behavior
- Export/print/audit traceability behavior
- Current hard policy constraints

---

## 2. System Scope

The system is an enterprise banking operations console with integrated:
- Customer and account lifecycle management
- Transaction processing and approvals
- Loan origination, due diligence, disbursement, servicing
- Expense and operating fund control
- General ledger and financial statements
- Branch and staff governance
- Internal audit, policies, and document registers

### 2.1 Core UI Navigation Modules

Left navigation modules:
- Dashboard
- Customers
- Accounts
- Transactions
- Loans
- Approvals
- Expenses
- Operating Fund
- GL Accounts
- Balance Sheet
- Cash Flow
- Financial Ratios
- Internal Audit
- Branch Management
- Staff
- Settings
- Documents
- Financial Reports
- Profit & Loss

---

## 3. Access, Session, and Security

### 3.1 Authentication
- Users log in with username and password.
- System can require immediate password change for first-time/forced-reset users.
- Login returns user profile, branch scope, modules, and CSRF/session context.

### 3.2 Session Behavior
- Inactivity timeout policy: 15 minutes.
- Global data refresh cadence: 5 seconds (active authenticated session).
- Notification polling cadence: 5 seconds.

### 3.3 Forced Password Change
- Staff users under forced password change must submit:
  - Current password
  - New password
  - Confirm password
- Self-service password endpoint supports `me` route semantics for staff self updates.

### 3.4 Branch Security
- Branch scope is enforced server-side.
- Non-admin users see and act only within assigned branches.
- “All Branches” views remain constrained to authorized branch scope for non-admin users.

### 3.5 Role and Module Controls
- Navigation visibility and feature access depend on module grants.
- Sensitive operations also require role checks (Admin/Manager/Accountant/Compliance based on function).

---

## 4. Global UI Behaviors

### 4.1 Header and Global Actions
- Branch selector
- Search overlay
- Notifications
- Profile/settings access
- Global export (context-sensitive by active panel)

### 4.2 Data Loading Model
- On login, the system loads required datasets by module entitlement.
- Background sync updates in-memory state and re-renders active panel.
- Silent sync reduces UX interruption while keeping views current.

### 4.3 Modals, Drawers, and Pagination
- Most detailed records open in right-side drawers.
- Create/edit actions open modal forms with validation.
- Data-heavy tables use pagination and scoped filters.

---

## 5. Dashboard Panel

Purpose:
- Operational snapshot across customers, accounts, transactions, loans, approvals, and audit activity.

Features:
- KPI cards
- Pending approvals widget
- Recent audit events
- Customer onboarding queue
- Quick navigation buttons

Controls:
- Branch-aware metrics
- Module-aware widgets

---

## 6. Customers Panel

Purpose:
- Manage customer onboarding and profile records.

Features:
- Customer listing and search
- Create customer
- Edit customer details
- Customer drawer with linked profile details
- Customer-linked account and transaction context

Controls:
- Branch scope filtering
- Input validations on create/edit
- Audit trail for key changes

---

## 7. Accounts Panel

Purpose:
- Deposit account management and lifecycle controls.

Features:
- Account listing and filters
- Account drawer (details, balances, status, history)
- Create account for customer
- Deposit / withdraw operations
- Freeze, unfreeze, close, reactivate states
- Account statement view, print, and CSV export
- Tax/deduction exemption display and logic

Transaction behavior:
- Posted transactions affect ledger/available balances
- Withdrawal fee/tax handling supports configured fee modes
- Statement generation uses posted transaction state rules

---

## 8. Transactions Panel

Purpose:
- Enterprise transaction explorer and control surface.

Features:
- Filters: date, branch, status, module, direction, search
- Detailed transaction drawer
- Approval actions for pending items
- CSV export

Accounting behavior:
- Summary cards use posted-state accounting view for net accuracy.
- Fee/tax child transaction handling is normalized for reporting and drawer display.

### 8.1 Reversal Policy (Current)
- Generic transaction reversal remains controlled by approvals and policy.
- Loan-related transaction reversals are blocked by policy (backend-enforced).

---

## 9. Loans Panel (All Sub-tabs)

Purpose:
- Full credit workflow from application to servicing.

Sub-tabs:
- Active Loans
- Loan Applications
- Repayment Schedule
- Loan Fund Accounts

### 9.1 Active Loans
- Loan cards with status, exposure, repayment metrics
- Drawer with full loan details and action controls
- Actions include servicing operations based on status and role

### 9.2 Loan Applications
- Application records with due diligence checklist lifecycle
- Due diligence recommendation flow
- Transition to approval queue after checks are complete

### 9.3 Repayment Schedule
- Installment-level schedule rendering
- Paid/outstanding/status tracking
- Branch-aware filtering behavior

### 9.4 Loan Fund Accounts
- Database-driven loan fund and interest account display
- Branch-aware views and consistency with ledger/backing records

### 9.5 Loan Policy Constraints (Current)
- Loan rollback is disabled by policy.
- Loan reversal (loan-related transaction reversal) is disabled by policy.
- UI blocks/hides rollback and loan-reversal actions.
- Backend returns `403` for blocked loan rollback/reversal attempts.

---

## 10. Approvals Panel

Purpose:
- Maker-checker queue for controlled operations.

Features:
- Approval queue listing
- Approve/reject actions
- Entity-aware handling (transactions, loan applications, expenses, etc.)
- Branch and role controls

Controls:
- Approval limit and entitlement checks
- Server-side final authority on allowed transitions

---

## 11. Expenses Panel

Purpose:
- Capture, track, and approve expenses with fund sufficiency controls.

Features:
- Expense registration
- Expense list with status filters
- Detail drawer
- CSV/XLSX export
- Print statement

Controls:
- Insufficient-fund prevention logic
- Approval process integration
- Branch-aware expense visibility

---

## 12. Operating Fund Panel

Purpose:
- Manage branch operating liquidity and movements.

Features:
- Top-up and debit operations
- Transaction register
- Balance tracking
- CSV/XLSX export
- Print operating statement

Controls:
- Balance sufficiency checks
- Branch-scoped posting
- GL-consistent operating account treatment

---

## 13. GL Accounts Panel

Purpose:
- Enterprise chart of accounts and journal oversight.

Sections:
- Chart of Accounts table
- GL KPI cards
- General Ledger Journal
- Trial balance integrity indicators

Features:
- GL code balances (debit/credit/net)
- Entry counts and filters
- Search by reference/account/description
- CSV and trial balance XLSX export
- Print trial balance

Controls:
- Branch-filtered ledger views
- Date-aware and scope-aware computations
- Trial-balance validation display

---

## 14. Balance Sheet Panel

Purpose:
- Point-in-time Assets, Liabilities, Equity view with branch/date scope.

Features:
- As-at date filter
- Branch filter
- KPI cards:
  - Total Assets
  - Total Liabilities
  - Net Income
  - Balance Check
- Assets block
- Liabilities & Equity block
- Full GL account summary table
- Print, CSV, XLSX exports

Controls and consistency:
- Explicit branch selector governs scope for this panel.
- As-at date is applied to balance computations and exports.
- Loan portfolio fallback includes only live disbursed statuses to prevent overstated assets.

---

## 15. Cash Flow Panel

Purpose:
- Operational cash movement and flow insight.

Features:
- Cash inflow/outflow views
- Trend/summary cards
- Branch/date filters
- Print/CSV/XLSX export

Controls:
- Branch-aware data scoping
- Consistency with transaction and ledger source data

---

## 16. Financial Ratios Panel

Purpose:
- Ratio-based financial health dashboard.

Features:
- Ratio cards and supporting figures
- Branch-aware context
- CSV/XLSX export
- Print ratios report

Controls:
- Ratio inputs sourced from current financial datasets
- Scope consistency with selected branch context

---

## 17. Financial Reports Center

Purpose:
- Central launcher for all financial statement outputs.

Features:
- Cards linking to:
  - Balance Sheet
  - Profit & Loss
  - Cash Flow
  - GL/Trial Balance
  - Ratios
  - Expense/Operating report exports
- Direct export shortcuts

---

## 18. Profit & Loss Panel

Purpose:
- Revenue/expense performance and profitability analytics.

Features:
- Date/branch filtering
- KPI cards
- Trend and category breakdowns
- Trial-balance support section
- Print/CSV/XLSX exports

Controls:
- Database-driven calculations
- Branch and scope reconciliation behavior

---

## 19. Internal Audit Panel

Purpose:
- Compliance and internal control monitoring.

Features:
- Audit dashboard cards
- Findings list and detail
- Add finding workflow
- Branch trial-balance breakdowns
- GL integrity indicators
- CSV export for audit findings/logs

Controls:
- Admin/Compliance action gates for sensitive actions
- Branch-scoped visibility for non-admin users

---

## 20. Branch Management Panel

Purpose:
- Branch master governance and branch-level tracking.

Features:
- Branch list
- Create/edit branch
- Branch stats and quick health indicators
- CSV export
- Live indicators for branch metrics refresh

---

## 21. Staff Panel

Purpose:
- User, role, module, and branch scope governance.

Features:
- Staff list and detail drawer
- Create/edit staff
- Branch assignment (multi-branch)
- Module access assignment (full/view variants as configured)
- Password reset by manager/admin
- Staff profile and login history views
- CSV export

Controls:
- Role-based access checks for staff administration
- Password change/reset flow hardening
- Staff self-service profile/password operations

---

## 22. Settings Panel

Purpose:
- Bank branding, system policy, tax settings, and configuration keys.

Sections:
- Bank branding editor
- System settings cards
- Tax settings
- Policies and revision history

Features:
- Create/edit settings
- Policy create/edit/review
- Policy revision history and rollback (policy domain only)
- Audit logging for setting/policy changes

Controls:
- Settings mutation requires appropriate permissions
- Sensitive settings gated to authorized roles

---

## 23. Documents Panel

Purpose:
- Enterprise document register and print/export tracking.

Features:
- Document list and filtering
- Summary bar
- History rendering
- Print/export helpers for statements and payslips

Behavior:
- Statement/report generation actions register document metadata for traceability.

---

## 24. Notifications

Purpose:
- Operational alerts and action prompts.

Features:
- 5-second polling
- Notification type routing to relevant panel
- Read-state behavior

---

## 25. Search Overlay

Purpose:
- Fast command and navigation access.

Features:
- Recent search
- Jump to major panels/actions
- Shortcut-driven operation support

---

## 26. Export and Print Catalog

Supported panel exports (where applicable):
- Customers (CSV)
- Accounts (CSV)
- Transactions (CSV)
- Loans (CSV)
- Approvals (CSV)
- Staff (CSV)
- Audit logs/findings (CSV)
- Branches (CSV)
- Expenses (CSV/XLSX + print)
- Operating Fund (CSV/XLSX + print)
- GL Accounts (CSV)
- Trial Balance (XLSX + print)
- Balance Sheet (CSV/XLSX + print)
- Cash Flow (CSV/XLSX + print)
- Financial Ratios (CSV/XLSX + print)
- Profit & Loss (CSV/XLSX + print)

---

## 27. Enterprise Control Rules (Operational)

### 27.1 Maker-Checker
- High-risk operations route through approvals where configured.
- Approve/reject actions are auditable.

### 27.2 Approval Limits
- Staff limits and role gates constrain direct posting behavior.
- Exceeding limits routes work to approval.

### 27.3 Branch Isolation
- Branch checks are enforced server-side.
- “All branches” for non-admin remains constrained to assigned scope.

### 27.4 Accounting Integrity
- GL postings follow double-entry principles.
- Trial-balance integrity indicators are surfaced in GL/Audit contexts.
- Statement/report views are tied to posted-state accounting behavior.

### 27.5 Loan Protection Policy (Current)
- Loan rollback: disabled.
- Loan reversal: disabled.
- Any attempt is blocked by backend policy checks.

---

## 28. Standard Operating Workflows

### 28.1 Customer Onboarding to Account
1. Create customer.
2. Open customer drawer and verify profile.
3. Create account for customer.
4. Perform initial deposit if required.
5. Validate account statement availability.

### 28.2 Withdrawal with Controls
1. Open account.
2. Initiate withdrawal.
3. Review fees/tax behavior per configured mode.
4. Submit; if approval required, finalize in Approvals.
5. Verify posted transaction and updated balance.

### 28.3 Loan Application to Servicing
1. Create loan application.
2. Complete due diligence checks.
3. Recommend/route for approval.
4. Approve and disburse via valid status transition.
5. Record repayments from schedule workflow.
6. Monitor exposure, delinquency, and fund accounts.

### 28.4 Expense Approval Flow
1. Record expense.
2. Route to approvals.
3. Approver validates and approves/rejects.
4. Verify operating fund and accounting impacts.

### 28.5 Financial Reporting Cycle
1. Select branch/date scope.
2. Review GL + trial balance.
3. Generate Balance Sheet/P&L/Cash Flow/Ratios.
4. Export/print and register document evidence.

---

## 29. Troubleshooting Guide

### 29.1 Authentication / 401
- Confirm backend session/auth deployment is current.
- Confirm user branch/module permissions.
- Re-login and verify session is active.

### 29.2 403 on Sensitive Actions
- Check role/module entitlement.
- Check branch assignment for target record.
- Confirm action is not policy-disabled (loan rollback/reversal is disabled).

### 29.3 500 Server Error
- Capture endpoint + payload + backend log entry.
- Reproduce with same user/branch scope.
- Validate DB schema migrations are applied.

### 29.4 KPI/Table Mismatch
- Confirm branch/date filters are identical.
- Refresh active view after data-affecting actions.
- Validate state is based on posted/finalized transactions.

---

## 30. Administrative Governance Recommendations

- Maintain strict role segregation (maker vs checker).
- Review approval limits quarterly.
- Enforce periodic password rotation and immediate reset after risk events.
- Run periodic branch-level reconciliation:
  - GL trial balance
  - Transaction status integrity
  - Loan servicing and fund movements
- Archive export artifacts for audit retention.

---

## 31. Change Management Notes (Current Baseline)

Current baseline includes these active policy constraints:
- Loan rollback disabled by policy.
- Loan-related reversal disabled by policy.
- Session inactivity timeout enforced.
- 5-second auto-refresh and notification polling enabled.

Any future relaxation of rollback/reversal must be approved by:
- Credit Risk
- Finance Control
- Internal Audit
- System Administration

---

## 32. File Ownership and Maintenance

This manual should be updated whenever:
- A panel workflow changes
- Access controls or branch logic changes
- Financial posting logic changes
- New exports/reports are introduced
- Security/session behavior changes

Recommended owner:
- Product Operations + IT Governance + Internal Audit (joint ownership)

