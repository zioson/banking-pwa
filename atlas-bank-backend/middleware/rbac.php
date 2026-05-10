<?php
/**
 * Atlas Bank Enterprise Operations Console
 * RBAC (Role-Based Access Control) Middleware
 */

require_once __DIR__ . '/auth.php';

/**
 * Expand a module display name (as stored in staff_modules) to its
 * backend RBAC keys.  Mirrors the frontend getModuleRbacKeys() map.
 */
function expandModuleToRbacKeys(string $displayName): array
{
    static $map = null;
    if ($map === null) {
        $map = [
            'Dashboard'          => ['DASHBOARD'],
            'Customers'          => ['CUSTOMERS'],
            'Accounts'           => ['ACCOUNTS'],
            'Transactions'       => ['TRANSACTIONS'],
            'Loans'              => ['LOANS'],
            'Approvals'          => ['APPROVALS'],
            'Expenses'           => ['EXPENSES'],
            'Operating Fund'     => ['ACCOUNTS', 'OPERATING'],
            'GL Accounts'        => ['ACCOUNTS', 'GLACCOUNTS', 'CHART_OF_ACCOUNTS'],
            'Internal Audit'     => ['AUDIT', 'INTERNALAUDIT'],
            'Branch Management'  => ['BRANCHES'],
            'Staff'              => ['STAFF'],
            'Staff Management'   => ['STAFF'],
            'Settings'           => ['SETTINGS'],
            'Documents'          => ['DOCUMENTS'],
            'Investments'        => ['INVESTMENTS'],
            'Investment Portal'  => ['INVESTMENTS'],
            'Financial Reports'  => ['REPORTS'],
            'Profit & Loss'      => ['PROFITLOSS'],
            'Audit Log'          => ['AUDIT'],
        ];
    }
    return $map[$displayName] ?? [strtoupper($displayName)];
}

/**
 * Require the authenticated user to have access to a specific module.
 *
 * Checks if the authenticated user (obtained via requireAuth()) has the given
 * module in their staff_modules table, or has 'ALL' access.
 *
 * Supports BOTH display names (new format) and RBAC keys (legacy format)
 * stored in staff_modules.  Display names are expanded to RBAC keys via
 * expandModuleToRbacKeys() so that granting "Operating Fund" satisfies
 * requireModule('ACCOUNTS').
 *
 * If the user does not have access, sends a 403 response and exits.
 *
 * @param string     $moduleName  The module name to check (e.g., 'LOANS')
 * @param array|null $staff       Optional staff record (if not provided, calls requireAuth())
 * @return array The staff record
 */
function requireModule(string $moduleName, ?array $staff = null): array
{
    if ($staff === null) {
        $staff = requireAuth();
    }

    $modules = $staff['modules'] ?? [];

    // Admin with 'ALL' access can access any module
    if (in_array('ALL', $modules, true)) {
        return $staff;
    }

    $target = strtoupper($moduleName);

    // 1. Direct match (legacy RBAC keys stored in DB, e.g. 'ACCOUNTS')
    foreach ($modules as $m) {
        if (strtoupper($m) === $target) {
            return $staff;
        }
    }

    // 2. Expand each stored module to its RBAC keys and check
    foreach ($modules as $m) {
        $rbacKeys = expandModuleToRbacKeys($m);
        foreach ($rbacKeys as $k) {
            if (strtoupper($k) === $target) {
                return $staff;
            }
        }
    }

    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    error_log('[RBAC requireModule DENIED] module=' . $moduleName . ' staff_id=' . ($staff['staff_id'] ?? 'unknown') . ' modules=' . json_encode($modules));
    echo json_encode([
        'success' => false,
        'error'   => 'Access denied. You do not have permission to access the ' . strtoupper($moduleName) . ' module.',
        'code'    => 403
    ]);
    exit;
}

/**
 * Require the authenticated user to have access to at least one module.
 *
 * Useful for cross-cutting workflows where the action may be launched from
 * multiple panels, e.g. an expense approval from either the Expenses panel
 * or the Approvals panel.
 *
 * @param array      $moduleNames Allowed module names
 * @param array|null $staff       Optional staff record
 * @return array The staff record
 */
function requireAnyModule(array $moduleNames, ?array $staff = null): array
{
    if ($staff === null) {
        $staff = requireAuth();
    }

    foreach ($moduleNames as $moduleName) {
        if (hasModuleAccess((string)$moduleName, $staff)) {
            return $staff;
        }
    }

    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => 'Access denied. You do not have permission to access any of these modules: ' . implode(', ', $moduleNames) . '.',
        'code'    => 403
    ]);
    exit;
}

/**
 * Require the authenticated user to have a specific role.
 *
 * @param string|array $roles      Role name(s) to check
 * @param array|null   $staff      Optional staff record
 * @return array The staff record
 */
function requireRole($roles, ?array $staff = null): array
{
    if ($staff === null) {
        $staff = requireAuth();
    }

    $allowedRoles = is_array($roles) ? $roles : [$roles];
    $allowedRoles = array_map('strtoupper', $allowedRoles);

    if (!in_array(strtoupper($staff['role']), $allowedRoles, true)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'Access denied. Required role: ' . implode(' or ', $allowedRoles) . '.',
            'code'    => 403
        ]);
        exit;
    }

    return $staff;
}

/**
 * Check if the authenticated user has access to a module (does not exit).
 *
 * Supports BOTH display names (new) and RBAC keys (legacy) stored in staff_modules.
 *
 * @param string $moduleName Module name to check
 * @param array  $staff      Staff record
 * @return bool
 */
function hasModuleAccess(string $moduleName, array $staff): bool
{
    $modules = $staff['modules'] ?? [];

    if (in_array('ALL', $modules, true)) {
        return true;
    }

    $target = strtoupper($moduleName);

    // 1. Direct match (legacy RBAC keys)
    foreach ($modules as $m) {
        if (strtoupper($m) === $target) {
            return true;
        }
    }

    // 2. Expand display names to RBAC keys
    foreach ($modules as $m) {
        $rbacKeys = expandModuleToRbacKeys($m);
        foreach ($rbacKeys as $k) {
            if (strtoupper($k) === $target) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Check if the authenticated user has a specific role (does not exit).
 *
 * @param string|array $roles Role name(s) to check
 * @param array        $staff Staff record
 * @return bool
 */
function hasRole($roles, array $staff): bool
{
    $allowedRoles = is_array($roles) ? $roles : [$roles];
    $allowedRoles = array_map('strtoupper', $allowedRoles);

    return in_array(strtoupper($staff['role']), $allowedRoles, true);
}

/**
 * Check if the authenticated user has a specific branch (does not exit).
 *
 * @param string $branchName Branch name to check
 * @param array  $staff      Staff record
 * @return bool
 */
function hasBranchAccess(string $branchName, array $staff): bool
{
    // ★ FIX: Admin and Super Admin roles bypass branch access checks
    $role = strtoupper($staff['role'] ?? '');
    if (in_array($role, ['ADMIN', 'SUPER_ADMIN'], true)) {
        return true;
    }

    $branches = $staff['branches'] ?? [];
    $branchName = strtoupper(trim($branchName));
    if ($branchName === '') {
        return true;
    }

    foreach ($branches as $branch) {
        $v = strtoupper(trim((string)$branch));
        if ($v === '') {
            continue;
        }
        if (in_array($v, ['ALL BRANCHES', 'ALL_BRANCHES'], true)) {
            $v = 'ALL';
        }
        if ($v === 'ALL' || $v === $branchName) {
            return true;
        }
    }

    return false;
}

/**
 * Require the authenticated user to have a minimum approval limit.
 *
 * @param float      $amount Transaction/approval amount
 * @param array|null $staff  Optional staff record
 * @return array The staff record
 */
function requireApprovalLimit(float $amount, ?array $staff = null): array
{
    if ($staff === null) {
        $staff = requireAuth();
    }

    $limit = (float)($staff['approval_limit'] ?? 0);

    if ($limit < $amount) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'Approval limit exceeded. Your limit is ' . moneyFormat($limit) . ' but this action requires ' . moneyFormat($amount) . '.',
            'code'    => 403
        ]);
        exit;
    }

    return $staff;
}
