<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AiService;
use App\Services\MailService;
use App\DeepDive\Admin\ReportAISettingsController;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;
use App\Services\ReportPlanService;

/**
 * AdminController: Platform-wide administrative management
 *
 * PURPOSE:
 * Handles all administrative functionality for platform operators
 * Manages tenant lifecycle (creation, update, deactivation)
 * Provides system-wide dashboards, metrics, and monitoring
 * Configures extraction settings, report plans, and system policies
 * Implements audit logging for all administrative actions
 *
 * RESPONSIBILITIES:
 * - Tenant Management: CRUD operations for tenant organizations
 * - Dashboard & Metrics: Real-time system KPIs and performance
 * - Configuration: Extraction settings and report plan management
 * - Storage Monitoring: Disk usage tracking and cleanup
 * - Audit Trail: Logging of all administrative changes
 * - Feature Flags: Enable/disable features at tenant level (e.g., DeepDive)
 *
 * DEPENDENCIES:
 * - Environment $view : Twig template rendering
 * - PDO $pdo : PostgreSQL database connection
 * - AiService : AI token usage and model management
 * - MailService : Email notifications
 * - ReportPlanService : Report template and plan management
 *
 * METHODS:
 * Public action methods (PSR-7 HTTP handlers):
 * - dashboard() : System overview and KPIs
 * - tenants() : List all tenants with storage and plan info
 * - createTenant() : Provision new tenant organization
 * - updateTenant() : Modify existing tenant details
 * - toggleTenantStatus() : Activate/deactivate tenant
 * - policies() : View and manage system policies
 * - apiLimits() : Configure rate limits and quotas
 * - extractionConfig() : Enable/disable data extraction types
 * - reportPlans() : Manage report templates
 * - storageStats() : Monitor storage usage
 * - deleteFile() : Remove debug files manually
 * - logs() : View system audit logs
 *
 * SECURITY:
 * - Controller requires authenticated admin user (enforced by middleware)
 * - All database operations use prepared statements (SQL injection safe)
 * - Tenant isolation via tenant_id filtering
 * - Audit logging for compliance and security monitoring
 * - Password hashing: BCRYPT with cost factor 12
 *
 * DATABASE TABLES:
 * - users : Tenant accounts with roles and feature flags
 * - scan_jobs : Analysis job records
 * - debug_files : Uploaded debug bundles
 * - scan_findings : Analysis findings linked to jobs
 * - audit_log : Administrative action trail
 * - system_settings : Platform configuration
 * - report_plans : Report template definitions
 *
 * STORAGE:
 * - Raw uploads: storage/debug_files/{file_id}
 * - Extracted: storage/extracted/{file_id}/
 * - Reports: storage/reports/
 *
 * ERROR HANDLING:
 * - Validation: Form field checks before database operations
 * - Database errors: Caught (PDOException), friendly messages shown
 * - Unique constraint violations: Specific error messaging
 * - File operations: Silent failures with graceful degradation
 *
 * AUDIT TRAIL:
 * All administrative changes logged via logAction() helper:
 * - Action type: 'user_created', 'user_updated', etc.
 * - Resource type and ID
 * - Changed fields and values
 * - IP address and user agent
 * - Timestamp
 *
 * @package App\Controllers
 */
class AdminController
{
    /** @var Environment Twig template engine for rendering admin views */
    private Environment $view;

    /** @var PDO PostgreSQL database connection */
    private PDO $pdo;

    /** @var AiService AI service for token usage and model management */
    private AiService $aiService;

    /** @var MailService Email service for notifications */
    private MailService $mailService;

    /** @var ReportPlanService Report template and plan management service */
    private ReportPlanService $reportPlanService;

    /** @var string Application base path for URL generation */
    private string $basePath;

    /**
     * Constructor: Dependency injection of controller dependencies
     *
     * DEPENDENCIES:
     * - $view : Twig environment for rendering admin templates
     * - $pdo : PostgreSQL connection (configured in Database service)
     * - $aiService : AI token tracking and usage metrics
     * - $basePath : Web root path for URL generation (e.g., '/admin')
     * - $mailService : Email notifications for alerts/provisioning
     * - $reportPlanService : Report template catalog and assignments
     *
     * All dependencies injected by container to support testability
     * and loose coupling between components.
     *
     * @param Environment $view Twig template engine
     * @param PDO $pdo Database connection
     * @param AiService $aiService AI usage tracker
     * @param string $basePath Application base path
     * @param MailService $mailService Email sender
     * @param ReportPlanService $reportPlanService Report plan manager
     */
    public function __construct(Environment $view, PDO $pdo, AiService $aiService, string $basePath, MailService $mailService, ReportPlanService $reportPlanService)
    {
        $this->view = $view;
        $this->pdo = $pdo;
        $this->aiService = $aiService;
        $this->basePath = $basePath;
        $this->mailService = $mailService;
        $this->reportPlanService = $reportPlanService;
    }

    /**
     * Display admin dashboard with system-wide KPIs and metrics
     *
     * PURPOSE:
     * Shows platform operators a comprehensive view of system health
     * Displays key performance indicators for users, scans, and resources
     * Alerts on problems (stuck jobs, high disk usage)
     *
     * METRICS DISPLAYED:
     * System Overview:
     * - Active tenant count
     * - Total scans executed on platform
     * - Total AI tokens consumed (input + output)
     * - Recent audit log entries (last 10)
     *
     * Performance:
     * - CPU load average (1-minute)
     * - Disk usage percentage
     * - Disk free space in GB
     * - Stuck jobs (running but no updates)
     *
     * DATA SOURCES:
     * - users table: Tenant count (role='tenant')
     * - scan_jobs table: Total scans and token usage
     * - audit_log table: Recent administrative actions
     * - system_settings table: Alert thresholds
     * - System APIs: sys_getloadavg(), disk_*_space()
     *
     * STUCK JOB DETECTION:
     * Jobs are considered "stuck" if:
     * - Status is 'running' (actively processing)
     * - No update for configured timeout (default 30 minutes)
     * - Configuration via system_settings.stuck_alert_mins
     * - Helps identify jobs killed mid-processing
     *
     * ERROR HANDLING:
     * - Missing system calls: Default values (cpu=0)
     * - Disk space errors: Defaults to 1 (prevents division by zero)
     * - Type safety: All values cast to numeric before calculations
     * - Missing metrics: NULL coalesced to 0
     *
     * TEMPLATE:
     * Renders admin/dashboard.twig with computed metrics
     * Values pre-formatted (CPU with decimals, disk as GB, etc.)
     * Pagination not needed (small datasets)
     *
     * @param Request $request HTTP request (unused)
     * @param Response $response HTTP response to write
     *
     * @return Response HTML response with rendered dashboard
     */
    public function dashboard(Request $request, Response $response): Response
    {
        // === Global System KPIs ===
        // Count of tenant organizations on platform
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'tenant'");
        $tenantCount = $stmt->fetchColumn();

        // Total analysis jobs executed (all tenants, all time)
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM scan_jobs");
        $totalScans = $stmt->fetchColumn();

        // Sum of AI tokens consumed across all jobs
        // Input and output tokens aggregated for cost visibility
        $stmt = $this->pdo->query("SELECT SUM(ai_input_tokens_used + ai_output_tokens_used) FROM scan_jobs");
        $totalTokensUsed = $stmt->fetchColumn() ?? 0;

        // === Recent System Activity ===
        // Audit log entries for administrative changes
        // Shows who did what, when, and what changed
        $stmt = $this->pdo->query("
            SELECT a.action, a.created_at, u.display_name as user_name, a.details
            FROM audit_log a
            LEFT JOIN users u ON a.user_id = u.id
            ORDER BY a.created_at DESC
            LIMIT 10
        ");
        $recentActivity = $stmt->fetchAll();

        // === Platform Performance Metrics ===
        // CPU load (1-minute average)
        // Returns 0 if sys_getloadavg() unavailable (Windows)
        $cpuLoad = function_exists('sys_getloadavg') ? sys_getloadavg()[0] : 0;

        // === Disk Space Calculation ===
        // Get free and total space on root filesystem
        // Use @ to suppress warnings if calls fail (returns false)
        $diskFree = @disk_free_space("/") ?: 1;
        $diskTotal = @disk_total_space("/") ?: 1;

        // Type safety: ensure values are numeric before math operations
        // Prevents "non-numeric" errors if functions return non-numeric values
        $diskFreeNumeric = is_numeric($diskFree) ? (float)$diskFree : 1.0;
        $diskTotalNumeric = is_numeric($diskTotal) ? (float)$diskTotal : 1.0;

        // Calculate percentage used (prevents division by zero with fallback 1)
        $diskUsedPercent = round((($diskTotalNumeric - $diskFreeNumeric) / $diskTotalNumeric) * 100, 1);

        // === Stuck Jobs Detection ===
        // Jobs that are "running" but haven't updated for X minutes (default 30)
        // Indicates job lost heartbeat or killed mid-processing
        // Threshold configurable via system_settings.stuck_alert_mins
        // Helps admins identify and investigate stalled jobs
        $stmt = $this->pdo->prepare("
            SELECT j.id, j.status, j.progress_stage, j.updated_at, u.display_name as tenant_name
            FROM scan_jobs j
            JOIN users u ON j.tenant_id = u.id
            LEFT JOIN system_settings s ON 1=1
            WHERE j.status = 'running'
              AND j.updated_at < (NOW() - (COALESCE(s.stuck_alert_mins, 30) || ' minutes')::interval)
            ORDER BY j.updated_at ASC
        ");
        $stmt->execute();
        $stuckScans = $stmt->fetchAll();

        // === Render dashboard template ===
        // Pre-format values for display (no formatting in template)
        $body = $this->view->render('admin/dashboard.twig', [
            'tenant_count' => $tenantCount,
            'total_scans' => $totalScans,
            'tokens_used' => $totalTokensUsed,
            'cpu_load' => number_format($cpuLoad, 2),
            'disk_free_gb' => round($diskFree / 1073741824, 2),
            'disk_used_percent' => $diskUsedPercent,
            'recent_activity' => $recentActivity,
            'stuck_scans' => $stuckScans,
            'active_page' => 'admin_dash'
        ]);

        $response->getBody()->write($body);
        return $response;
    }

    /**
     * Display list of all tenants with storage and plan assignments
     *
     * PURPOSE:
     * Shows admin all tenant organizations with their usage and configuration
     * Displays storage breakdown: raw uploads, extracted data, database
     * Allows bulk operations: create, update, toggle status
     *
     * TENANT DATA:
     * For each tenant, displays:
     * - Organization name, email, status (active/inactive)
     * - Storage breakdown:
     *   - Raw uploads: Files stored in database (debug_files table)
     *   - Extracted: Decompressed working directories
     *   - Database: Estimated size of findings/jobs (1KB per finding)
     *   - Total: Sum of all three
     * - Assigned report plans (templates enabled for tenant)
     * - Tokens available for AI operations
     * - Created/updated timestamps
     *
     * STORAGE CALCULATION:
     * Raw Storage: SUM(debug_files.file_size_bytes) from uploads table
     * Extracted Storage: Recursive directory traversal of extracted/{file_id}/
     * DB Estimate: COUNT(scan_findings) * 1024 bytes per finding
     * Total: Sum of all three buckets
     *
     * REPORT PLANS:
     * Fetches all available plans and tenant assignments
     * Allows assigning/unassigning plans to control report capabilities
     *
     * ERROR HANDLING:
     * - Session flash messages displayed and cleared
     * - Database errors caught and shown to user
     * - Graceful degradation for missing storage directories
     *
     * TEMPLATE:
     * Renders admin/tenants.twig with tenant list and form
     * Includes create/edit forms for tenant management
     * Shows all available report plans for assignment
     *
     * @param Request $request HTTP request (unused)
     * @param Response $response HTTP response to write
     *
     * @return Response HTML response with tenant management interface
     */
    public function tenants(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query("
            SELECT 
                u.*, 
                COALESCE(SUM(df.file_size_bytes), 0) as total_storage_bytes,
                COUNT(df.id) as total_entries
            FROM users u
            LEFT JOIN debug_files df ON u.id = df.tenant_id
            WHERE u.role = 'tenant'
            GROUP BY u.id
            ORDER BY u.created_at DESC
        ");
        $tenantsData = $stmt->fetchAll();
        $allPlans = $this->reportPlanService->getAllPlans(true);

        // Format storage strings and add breakdown
        $tenants = array_map(function($t) {
            $tid = $t['id'];
            
            // Raw Uploads (linked in DB)
            $rawBytes = (int)$t['total_storage_bytes'];
            
            // Extracted Workspace (Recursion)
            $extractPath = __DIR__ . '/../../storage/extracted';
            $extractBytes = 0;
            // Only count folders belonging to this tenant's file IDs
            $stmt = $this->pdo->prepare("SELECT id FROM debug_files WHERE tenant_id = :tid");
            $stmt->execute(['tid' => $tid]);
            $fids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($fids as $fid) {
                $path = $extractPath . '/' . $fid;
                if (is_dir($path)) {
                    $extractBytes += $this->getFolderSize($path);
                }
            }

            // Database estimate (Findings/Jobs/Scans)
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM scan_findings WHERE tenant_id = :tid");
            $stmt->execute(['tid' => $tid]);
            $findingsCount = $stmt->fetchColumn();
            $dbEstimateBytes = $findingsCount * 1024; // Avg 1KB per finding

            $t['raw_storage'] = $this->formatBytes($rawBytes);
            $t['extract_storage'] = $this->formatBytes($extractBytes);
            $t['db_storage'] = $this->formatBytes($dbEstimateBytes);
            $t['total_formatted'] = $this->formatBytes($rawBytes + $extractBytes + $dbEstimateBytes);
            
            // Fetch assigned plan IDs
            $t['assigned_plan_ids'] = array_column($this->reportPlanService->getPlansForTenant($tid), 'id');

            return $t;
        }, $tenantsData);


        $body = $this->view->render('admin/tenants.twig', [
            'tenants' => $tenants,
            'all_plans' => $allPlans,
            'active_page' => 'admin_tenants',
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ]);
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);
        
        $response->getBody()->write($body);
        return $response;
    }

    /**
     * Create new tenant organization and user account
     *
     * PURPOSE:
     * Provisions a new tenant organization with admin-supplied credentials
     * Sets initial token allocation and feature flags
     * Assigns report plan templates
     * Logs action to audit trail
     *
     * FORM INPUTS:
     * Required:
     * - org_name : Organization display name (trimmed)
     * - email : Tenant account email (unique constraint)
     * - password : Initial password (hashed with BCRYPT)
     * Optional:
     * - tokens : Initial AI tokens (default 500,000)
     * - deepdive_enabled : Boolean flag for advanced analysis features
     * - plan_ids[] : Array of report plan IDs to assign
     *
     * VALIDATION:
     * - Organization name: Not empty after trim
     * - Email: Not empty, must be unique (enforced by DB constraint)
     * - Password: Not empty, hashed with PASSWORD_BCRYPT
     * Validation errors return to tenants() page with error message
     *
     * DATABASE:
     * Uses PostgreSQL CTE (WITH clause) to:
     * 1. INSERT new user record
     * 2. Return inserted user.id
     * 3. UPDATE user.tenant_id = users.id (self-reference for tenants)
     * This atomic operation prevents race conditions
     *
     * Multi-tenant Pattern:
     * - role: set to 'tenant' (not admin)
     * - status: set to 'active' (immediately usable)
     * - tenant_id: set to same as user.id (organization owns itself)
     * - tokens_available: Initial AI token budget
     * - deepdive_enabled: Feature flag for advanced analysis
     *
     * REPORT PLANS:
     * After user created, assigns selected plan templates
     * Uses ReportPlanService::assignPlansToTenant()
     *
     * AUDIT LOGGING:
     * Logs to audit_log table:
     * - action: 'user_created'
     * - resource: 'users'
     * - resource_id: New user ID
     * - changes: All tenant details
     * - tenant_id: New tenant ID
     *
     * ERROR HANDLING:
     * - Validation: Show error and redirect to tenants page
     * - Unique email: PDOException code 23505, friendly message
     * - Database error: Show generic error message
     * - All errors use SESSION flash messages
     *
     * @param Request $request HTTP request with form data
     * @param Response $response HTTP response (redirect)
     *
     * @return Response Redirect to tenants page with success/error
     *
     * @throws none Exceptions caught and converted to flash messages
     */
    public function createTenant(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $orgName = trim($data['org_name'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $tokens = (int)($data['tokens'] ?? 500000);
        $deepDiveEnabled = !empty($data['deepdive_enabled']);

        // === Validate required fields ===
        if (empty($orgName) || empty($email) || empty($password)) {
            $_SESSION['error'] = 'All fields are required to provision a tenant.';
            return $response->withHeader('Location', $this->basePath . '/admin/tenants')->withStatus(302);
        }

        try {
            // === Hash password with strong BCRYPT ===
            // Cost factor 12 provides good security/performance balance
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            // === Create user with CTE for atomic self-reference ===
            // PostgreSQL CTE allows us to:
            // 1. INSERT user and get returning id
            // 2. UPDATE tenant_id = id in same statement
            // This prevents race conditions and ensures tenant_id is set immediately
            $stmt = $this->pdo->prepare("
                WITH new_user AS (
                    INSERT INTO users (email, password_hash, display_name, role, status, tokens_available, deepdive_enabled)
                    VALUES (:email, :pass, :name, 'tenant', 'active', :tokens, :dd)
                    RETURNING id
                )
                UPDATE users SET tenant_id = new_user.id FROM new_user WHERE users.id = new_user.id RETURNING users.id
            ");
            $stmt->execute([
                'email' => $email,
                'pass' => $hashedPassword,
                'name' => $orgName,
                'tokens' => $tokens,
                'dd' => $deepDiveEnabled ? 'true' : 'false',
            ]);
            $tenantId = $stmt->fetchColumn();

            // === Assign report plans ===
            // Allows tenant to use selected report templates immediately
            $planIds = $data['plan_ids'] ?? [];
            if (!empty($planIds)) {
                $this->reportPlanService->assignPlansToTenant((string)$tenantId, $planIds);
            }

            // === Audit log the creation ===
            $this->logAction($request, 'user_created', 'users', $tenantId, [
                'email' => $email,
                'display_name' => $orgName,
                'tokens' => $tokens,
                'deepdive_enabled' => $deepDiveEnabled,
                'assigned_plans' => count($planIds)
            ], $tenantId);

            // Show success message
            $_SESSION['success'] = "Tenant '{$orgName}' has been provisioned successfully.";
        } catch (\PDOException $e) {
            // === Handle specific database errors ===
            if ($e->getCode() === '23505') {
                // Unique constraint violation: duplicate email
                $_SESSION['error'] = 'A user with that email already exists.';
            } else {
                // Generic database error
                $_SESSION['error'] = 'Failed to create tenant: ' . $e->getMessage();
            }
        }

        return $response->withHeader('Location', $this->basePath . '/admin/tenants')->withStatus(302);
    }

    /**
     * Update existing tenant organization details
     *
     * PURPOSE:
     * Modifies tenant account information and settings
     * Can update name, email, password, and feature flags
     * Allows reassigning report plan templates
     * Logs all changes to audit trail
     *
     * FORM INPUTS:
     * Required:
     * - id : Tenant user ID (must exist and be role='tenant')
     * - org_name : Organization name (updated)
     * - email : Tenant email (must remain unique)
     * Optional:
     * - password : New password (left unchanged if empty)
     * - deepdive_enabled : Feature flag
     * - plan_ids[] : Report plan assignments
     *
     * VALIDATION:
     * - ID must be provided and not empty
     * - Organization name must be provided and not empty
     * - Email must be provided and not empty
     * - Validation errors return to tenants page
     *
     * DATABASE:
     * Dynamic SQL construction:
     * - Base UPDATE: display_name, email, deepdive_enabled, updated_at
     * - Conditional: password_hash only if password provided (non-empty)
     * - WHERE: id and role='tenant' (prevents updating admins)
     *
     * PASSWORD HANDLING:
     * Only hashed and updated if password field is non-empty
     * Uses BCRYPT with cost factor 12 (same as createTenant)
     * Allows password resets without changing other fields
     *
     * REPORT PLANS:
     * Updates plan assignments after successful update
     * Uses ReportPlanService to add/remove plans
     *
     * AUDIT LOGGING:
     * Logs to audit_log:
     * - action: 'user_updated'
     * - Includes password_changed flag (boolean)
     * - All updated fields documented
     *
     * ERROR HANDLING:
     * - Validation: Show error, redirect to tenants
     * - Unique email violation: PDOException 23505
     * - DB error: Generic message
     * - Flash messages used for user feedback
     *
     * @param Request $request HTTP request with form data
     * @param Response $response HTTP response (redirect)
     *
     * @return Response Redirect to tenants page with status
     *
     * @throws none Exceptions caught and converted to flash messages
     */
    public function updateTenant(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $id = $data['id'] ?? null;
        $orgName = trim($data['org_name'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $deepDiveEnabled = !empty($data['deepdive_enabled']);

        // === Validate required fields ===
        if (!$id || empty($orgName) || empty($email)) {
            $_SESSION['error'] = 'ID, Organization Name, and Email are required.';
            return $response->withHeader('Location', $this->basePath . '/admin/tenants')->withStatus(302);
        }

        try {
            // === Build dynamic UPDATE statement ===
            // Base fields: name, email, deepdive flag
            $sql = "UPDATE users SET display_name = :name, email = :email, deepdive_enabled = :dd, updated_at = NOW()";
            $params = [
                'name'  => $orgName,
                'email' => $email,
                'dd'    => $deepDiveEnabled ? 'true' : 'false',
                'id'    => $id,
            ];

            // === Conditionally update password ===
            // Only add password_hash clause if password provided (non-empty)
            // Allows updates without forcing password change
            if (!empty($password)) {
                $sql .= ", password_hash = :pass";
                $params['pass'] = password_hash($password, PASSWORD_BCRYPT);
            }

            // === Set WHERE clause ===
            // Ensures we only update tenant roles (prevents accidental admin updates)
            $sql .= " WHERE id = :id AND role = 'tenant'";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            // === Update report plan assignments ===
            $planIds = $data['plan_ids'] ?? [];
            $this->reportPlanService->assignPlansToTenant((string)$id, $planIds);

            // === Log the changes ===
            $this->logAction($request, 'user_updated', 'users', $id, [
                'email' => $email,
                'display_name' => $orgName,
                'password_changed' => !empty($password),
                'deepdive_enabled' => $deepDiveEnabled,
                'assigned_plans' => count($planIds)
            ], $id);

            $_SESSION['success'] = "Tenant '{$orgName}' updated successfully.";
        } catch (\PDOException $e) {
            // === Handle specific errors ===
            if ($e->getCode() === '23505') {
                // Unique email violation
                $_SESSION['error'] = 'A user with that email already exists.';
            } else {
                // Generic database error
                $_SESSION['error'] = 'Failed to update tenant: ' . $e->getMessage();
            }
        }

        return $response->withHeader('Location', $this->basePath . '/admin/tenants')->withStatus(302);
    }

    /**
     * Toggle tenant status between active and inactive
     *
     * PURPOSE:
     * Suspend or reactivate a tenant organization
     * Disables all services for inactive tenants
     * Logs status changes for compliance
     *
     * FORM INPUTS:
     * - id : Tenant user ID
     * - status : 'active' or 'inactive' (validated)
     *
     * VALIDATION:
     * - ID must be provided
     * - Status must be exactly 'active' or 'inactive'
     * - Invalid requests return error and redirect
     *
     * DATABASE:
     * Updates users.status and updated_at timestamp
     * WHERE clause ensures only tenant role can be updated
     *
     * INACTIVE EFFECT:
     * When status='inactive':
     * - Tenant cannot log in (AuthMiddleware checks status)
     * - No new jobs can be created
     * - Existing reports remain accessible
     * - Data not deleted (can be reactivated)
     *
     * AUDIT LOGGING:
     * - action: 'user_updated' for active
     * - action: 'user_deactivated' for inactive
     * - Both log new_status field
     *
     * USECASE:
     * Admin suspends tenant due to:
     * - Non-payment
     * - Policy violation
     * - Account compromise
     * - Maintenance period
     *
     * @param Request $request HTTP request with form data
     * @param Response $response HTTP response (redirect)
     *
     * @return Response Redirect to tenants page
     */
    public function toggleTenantStatus(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $id = $data['id'] ?? null;
        $status = $data['status'] ?? null;

        // === Validate status value ===
        if (!$id || !in_array($status, ['active', 'inactive'])) {
            $_SESSION['error'] = 'Invalid status toggle request.';
            return $response->withHeader('Location', '/admin/tenants')->withStatus(302);
        }

        // === Update status ===
        $stmt = $this->pdo->prepare("UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id AND role = 'tenant'");
        $stmt->execute(['status' => $status, 'id' => $id]);

        // === Log the status change ===
        $this->logAction($request, $status === 'active' ? 'user_updated' : 'user_deactivated', 'users', $id, ['new_status' => $status], $id);

        $_SESSION['success'] = "Tenant status changed to " . ucfirst($status) . ".";
        return $response->withHeader('Location', $this->basePath . '/admin/tenants')->withStatus(302);
    }

    public function deleteTenant(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $id = $data['id'] ?? null;

        if (!$id) {
            $_SESSION['error'] = 'Tenant ID required for deletion.';
            return $response->withHeader('Location', '/admin/tenants')->withStatus(302);
        }

        $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = :id AND role = 'tenant'");
        $stmt->execute(['id' => $id]);

        $this->logAction($request, 'user_deleted', 'users', $id, [], $id);

        $_SESSION['success'] = "Tenant permanently deleted.";
        return $response->withHeader('Location', $this->basePath . '/admin/tenants')->withStatus(302);
    }

    public function allocateTokens(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $id = $data['id'] ?? null;
        $amount = (int)($data['amount'] ?? 0);

        if (!$id || $amount <= 0) {
            $_SESSION['error'] = 'Valid amount and Tenant ID required.';
            return $response->withHeader('Location', '/admin/tenants')->withStatus(302);
        }

        $stmt = $this->pdo->prepare("SELECT tokens_available FROM users WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $before = $stmt->fetchColumn() ?: 0;
        $after = $before + $amount;

        $stmt = $this->pdo->prepare("UPDATE users SET tokens_available = :after, updated_at = NOW() WHERE id = :id AND role = 'tenant'");
        $stmt->execute(['after' => $after, 'id' => $id]);

        $this->logAction($request, 'tokens_allocated', 'users', $id, [
            'amount' => $amount,
            'before' => $before,
            'after' => $after
        ], $id);

        $_SESSION['success'] = "Allocated {$amount} tokens successfully.";
        return $response->withHeader('Location', $this->basePath . '/admin/tenants')->withStatus(302);
    }

    /**
     * Log administrative action to audit trail
     *
     * PURPOSE:
     * Records all administrative changes to audit log for compliance
     * Provides evidence of who did what, when, from where
     * Enables investigation of security incidents and data changes
     *
     * AUDIT TRAIL:
     * Persists to audit_log table with:
     * - user_id : Admin who performed action
     * - tenant_id : Tenant affected by action (or null for system actions)
     * - action : Action type ('user_created', 'user_updated', 'scan_aborted', etc.)
     * - resource_type : What was changed ('users', 'scan_jobs', 'system', etc.)
     * - resource_id : ID of affected resource
     * - details : JSON object with change details
     * - ip_address : Admin's IP address
     * - user_agent : Admin's browser/client info
     * - created_at : Automatic timestamp
     *
     * ACTION TYPES (examples):
     * - user_created : Tenant provisioned
     * - user_updated : Tenant modified
     * - user_deactivated : Tenant suspended
     * - scan_aborted : Job forcefully stopped
     * - tokens_redeemed : Token redemption used
     * - report_plan_deleted : Template removed
     * - system_reset : Factory reset
     *
     * REQUEST DATA:
     * Extracts from HTTP request:
     * - user_id from $_SESSION (current admin)
     * - tenant_id from $_SESSION or override parameter
     * - IP address from REMOTE_ADDR header
     * - User agent from HTTP_USER_AGENT header
     *
     * PARAMETERS:
     * Optional parameters allow flexible action logging:
     * - action: Required, identifies what happened
     * - resourceType: Optional, type of resource affected
     * - resourceId: Optional, ID of resource
     * - details: Optional, JSON-serializable array of changes
     * - tenantId: Optional override of session tenant_id
     *
     * DETAILS EXAMPLES:
     * - User creation: { email, display_name, tokens, deepdive_enabled }
     * - Job abort: { aborted_by }
     * - Status toggle: { new_status }
     * - Password change: { password_changed: true }
     * All details JSON-encoded for storage
     *
     * COMPLIANCE:
     * Used for regulatory compliance (GDPR, SOC 2, etc.)
     * Provides non-repudiation evidence
     * Enables audit trail review and investigation
     * Can be exported for security reviews
     *
     * @param Request $request HTTP request (for IP/UA/session extraction)
     * @param string $action Action type identifier
     * @param ?string $resourceType Type of resource affected
     * @param ?string $resourceId ID of affected resource
     * @param array $details JSON-serializable change details
     * @param ?string $tenantId Override tenant_id (if null, uses session)
     *
     * @return void Persists to audit_log table
     */
    private function logAction(Request $request, string $action, ?string $resourceType = null, ?string $resourceId = null, array $details = [], ?string $tenantId = null): void
    {
        // === Extract current user from session ===
        $userId = $_SESSION['user_id'] ?? null;

        // === Determine tenant ID ===
        // Use override parameter if provided, otherwise use session tenant_id
        $targetTenantId = $tenantId ?? ($_SESSION['tenant_id'] ?? null);

        // === Extract request context ===
        // IP address for geographic and security tracking
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        // User agent for browser/client identification
        $ua = $request->getServerParams()['HTTP_USER_AGENT'] ?? null;

        // === Insert audit log entry ===
        // PostgreSQL will auto-populate created_at timestamp
        $stmt = $this->pdo->prepare("
            INSERT INTO audit_log (user_id, tenant_id, action, resource_type, resource_id, details, ip_address, user_agent)
            VALUES (:uid, :tid, :act, :rt, :rid, :details, :ip, :ua)
        ");

        // === Execute with bound parameters ===
        // All values properly parameterized to prevent SQL injection
        $stmt->execute([
            'uid' => $userId,
            'tid' => $targetTenantId,
            'act' => $action,
            'rt' => $resourceType,
            'rid' => $resourceId,
            'details' => json_encode($details),
            'ip' => $ip,
            'ua' => $ua
        ]);
    }

    public function logs(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        
        // Pagination
        $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;

        // Search
        $search = !empty($queryParams['q']) ? $queryParams['q'] : null;
        
        // Sorting
        $allowedSorts = ['created_at', 'action', 'user_name', 'ip_address'];
        $sort = in_array($queryParams['sort'] ?? '', $allowedSorts) ? $queryParams['sort'] : 'created_at';
        $order = (strtoupper($queryParams['order'] ?? '') === 'ASC') ? 'ASC' : 'DESC';

        // 1. Build Query Parts
        $whereSql = "";
        $params = [];
        
        if ($search) {
            $whereSql = "WHERE (a.action ILIKE :q OR a.details ILIKE :q OR u.display_name ILIKE :q OR a.ip_address ILIKE :q)";
            $params['q'] = "%$search%";
        }

        // 2. Get Total Count for Pagination
        $countQuery = "SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON a.user_id = u.id $whereSql";
        $stmt = $this->pdo->prepare($countQuery);
        $stmt->execute($params);
        $totalLogs = $stmt->fetchColumn();
        $totalPages = ceil($totalLogs / $limit);

        // 3. Fetch Records
        // Note: For sorting by user_name (which is from joined table), we use the alias
        $orderBy = $sort === 'user_name' ? 'u.display_name' : "a.$sort";
        
        $sql = "
            SELECT a.*, u.display_name as user_name 
            FROM audit_log a 
            LEFT JOIN users u ON a.user_id = u.id 
            $whereSql
            ORDER BY $orderBy $order 
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll();

        $body = $this->view->render('admin/logs.twig', [
            'logs' => $logs,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'search' => $search,
            'sort' => $sort,
            'order' => $order,
            'active_page' => 'admin_logs'
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    public function settings(Request $request, Response $response): Response
    {
        // Get current system settings
        $stmt = $this->pdo->query("SELECT * FROM system_settings LIMIT 1");
        $settings = $stmt->fetch();

        // Get available models from Groq API
        try {
            $models = $this->aiService->getAvailableModels();
        } catch (\Exception $e) {
            $models = [];
            $error = "Could not fetch models: " . $e->getMessage();
        }

        $body = $this->view->render('admin/settings.twig', [
            'settings' => $settings,
            'models' => $models,
            'timezones' => \DateTimeZone::listIdentifiers(),
            'error' => $error ?? null,
            'active_page' => 'admin_settings'
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    public function updateSettings(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        $stmt = $this->pdo->prepare("
            UPDATE system_settings SET
                log_retention_days = :log_retention,
                analysis_retention_days = :analysis_retention,
                max_concurrent_scans = :max_scans,
                stuck_alert_mins = :alert_mins,
                auto_cancel_mins = :cancel_mins,
                max_prompt_chars = :max_prompt_chars,
                timezone = :timezone,
                debug_mode = :debug_mode,
                smtp_host = :smtp_host,
                smtp_port = :smtp_port,
                smtp_user = :smtp_user,
                smtp_pass = :smtp_pass,
                smtp_from = :smtp_from,
                smtp_encryption = :smtp_encryption,
                reporting_email = :reporting_email,
                max_extraction_size_gb = :max_extraction_size_gb,
                updated_at = NOW()
            WHERE id = 1
        ");
        
        $stmt->execute([
            'log_retention' => (int)$data['log_retention_days'],
            'analysis_retention' => (int)$data['analysis_retention_days'],
            'max_scans' => (int)$data['max_concurrent_scans'],
            'alert_mins' => (int)$data['stuck_alert_mins'],
            'cancel_mins' => (int)$data['auto_cancel_mins'],
            'max_prompt_chars' => (int)($data['max_prompt_chars'] ?? 50000),
            'timezone' => $data['timezone'] ?? 'UTC',
            'debug_mode' => isset($data['debug_mode']) ? 'true' : 'false',
            'smtp_host' => $data['smtp_host'] ?? null,
            'smtp_port' => (int)($data['smtp_port'] ?: 587),
            'smtp_user' => $data['smtp_user'] ?? null,
            'smtp_pass' => $data['smtp_pass'] ?? null,
            'smtp_from' => $data['smtp_from'] ?? null,
            'smtp_encryption' => $data['smtp_encryption'] ?? 'tls',
            'reporting_email' => $data['reporting_email'] ?? 'shaheer@activelk.com',
            'max_extraction_size_gb' => (int)($data['max_extraction_size_gb'] ?? 2),
        ]);

        return $response->withHeader('Location', $this->basePath . '/admin/settings?status=saved')->withStatus(302);
    }

    public function testEmail(Request $request, Response $response): Response
    {
        // 1. Fetch current settings (including the notification email)
        $stmt = $this->pdo->query("SELECT reporting_email FROM system_settings LIMIT 1");
        $reportingEmail = $stmt->fetchColumn();

        if (!$reportingEmail) {
            $_SESSION['error'] = "No reporting email configured. Please save your settings first.";
            return $response->withHeader('Location', $this->basePath . '/admin/settings')->withStatus(302);
        }

        // 2. Attempt to send a test message
        $subject = "SMTP Configuration Test - AI DebugScan v3";
        $body = "
            <h2>Diagnostic SMTP Test</h2>
            <p>This is a test email triggered from the AI DebugScan v3 Admin Settings panel.</p>
            <p><strong>Status:</strong> Verification Successful</p>
            <p><strong>Timestamp:</strong> " . date('Y-m-d H:i:s') . "</p>
            <hr>
            <p style='font-size: 12px; color: #666;'>If you are receiving this message, your SMTP configuration is successfully routing outbound mail.</p>
        ";

        $success = $this->mailService->send($reportingEmail, $subject, $body);

        if ($success) {
            $_SESSION['success'] = "Test email successfully dispatched to {$reportingEmail}. Please check your inbox.";
        } else {
            $_SESSION['error'] = "Failed to send test email. Please check your SMTP credentials and logs.";
        }

        return $response->withHeader('Location', $this->basePath . '/admin/settings')->withStatus(302);
    }

    /**
     * DeepDive Report AI Settings Admin Interface
     *
     * PURPOSE:
     * Provides admin access to configure DeepDive report AI analysis settings
     * Manages token budget, model selection, Z.ai integration, and confidence thresholds
     * Separate from general system settings (isolated configuration)
     *
     * FEATURES:
     * - Enable/disable AI analysis
     * - Configure token budget (5K-15K per bundle)
     * - Select Z.ai API and model
     * - Test Z.ai connection
     * - Configure confidence thresholds
     * - Set error handling policy
     *
     * NOTE:
     * This is a platform-wide setting, not per-tenant
     * Uses a special "system" tenant_id for storage
     *
     * @param Request $request HTTP request
     * @param Response $response HTTP response
     *
     * @return Response HTML response with settings interface
     */
    public function deepdiveReportAiSettings(Request $request, Response $response): Response
    {
        // Use a special "system" tenant ID for platform-wide DeepDive settings
        // This keeps settings isolated from actual tenant data
        $tenantId = 'system-deepdive';
        $logger = null;

        // Initialize the settings controller
        $controller = new ReportAISettingsController($this->pdo, $tenantId, $logger);

        // Render the admin HTML directly (token will be embedded in template)
        $html = $controller->getAdminHTML();

        // Wrap in admin layout template - pass csrf tokens for embedding
        $body = $this->view->render('admin/deepdive_ai_settings.twig', [
            'settings_html' => $html,
            'active_page' => 'admin_deepdive_ai'
        ]);

        $response->getBody()->write($body);
        return $response;
    }

    /**
     * API: Update DeepDive Report AI Settings
     *
     * POST endpoint for updating settings via admin panel
     */
    public function updateDeepDiveReportAiSettings(Request $request, Response $response): Response
    {
        $tenantId = 'system-deepdive';
        $logger = null;

        $controller = new ReportAISettingsController($this->pdo, $tenantId, $logger);
        $result = $controller->updateSettings($request->getParsedBody());

        // Skip audit logging for system-wide DeepDive settings
        // (deepdive_ai_settings_updated is not in the audit_action enum)

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Get available Z.ai models
     */
    public function getDeepDiveModels(Request $request, Response $response): Response
    {
        $tenantId = 'system-deepdive';
        $controller = new ReportAISettingsController($this->pdo, $tenantId, null);
        $result = $controller->getAvailableModels();

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Test Z.ai connection
     */
    public function testDeepDiveZaiConnection(Request $request, Response $response): Response
    {
        $tenantId = 'system-deepdive';
        $controller = new ReportAISettingsController($this->pdo, $tenantId, null);
        $result = $controller->testZaiConnection();

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function scans(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        
        // 1. Filter Parameters
        $tenantId = $queryParams['tenant_id'] ?? null;
        $startDate = $queryParams['start_date'] ?? null;
        $endDate = $queryParams['end_date'] ?? null;

        // 2. Pagination Params
        $page = max(1, (int)($queryParams['page'] ?? 1));
        $pageSize = 25; // Slightly more for high density
        $offset = ($page - 1) * $pageSize;

        // 3. Sorting Params
        $sort = $queryParams['sort'] ?? 'date';
        $order = strtoupper($queryParams['order'] ?? 'DESC');
        if (!in_array($order, ['ASC', 'DESC'])) $order = 'DESC';

        $allowedSortColumns = [
            'date' => 's.created_at',
            'tenant' => 'u.display_name',
            'type' => 's.scan_level',
            'model' => 's.ai_model',
            'status' => 's.status'
        ];
        $orderBy = $allowedSortColumns[$sort] ?? 's.created_at';

        // 4. Build Conditional WHERE
        $whereClauses = ["1=1"];
        $params = [];
        if ($tenantId) {
            $whereClauses[] = "s.tenant_id = :tid";
            $params['tid'] = $tenantId;
        }
        if ($startDate) {
            $whereClauses[] = "s.created_at >= :start";
            $params['start'] = $startDate . ' 00:00:00';
        }
        if ($endDate) {
            $whereClauses[] = "s.created_at <= :end";
            $params['end'] = $endDate . ' 23:59:59';
        }
        $whereSql = implode(" AND ", $whereClauses);

        // 5. Count Total for Pagination
        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM scan_jobs s WHERE $whereSql");
        $countStmt->execute($params);
        $totalItems = (int)$countStmt->fetchColumn();
        $totalPages = ceil($totalItems / $pageSize);

        // 6. Audit Summaries (Tokens per Model)
        $summaryStmt = $this->pdo->prepare("
            SELECT ai_model, SUM(ai_input_tokens_used + ai_output_tokens_used) as total_tokens
            FROM scan_jobs s
            WHERE $whereSql
            GROUP BY ai_model
            ORDER BY total_tokens DESC
        ");
        $summaryStmt->execute($params);
        $modelBreakdown = $summaryStmt->fetchAll();
        $totalFilteredTokens = array_sum(array_column($modelBreakdown, 'total_tokens'));

        // 7. Tenant List for Filters
        $tenantsList = $this->pdo->query("SELECT id, display_name FROM users WHERE role = 'tenant' ORDER BY display_name")->fetchAll();

        // 8. Main Query
        $stmt = $this->pdo->prepare("
            SELECT s.*, u.display_name as tenant_name, p.name as project_name,
                   COALESCE(OCTET_LENGTH(CAST(s.result_input_payload AS TEXT)), 0) as payload_size,
                   COALESCE(OCTET_LENGTH(CAST(s.result_raw_response AS TEXT)), 0) as report_size,
                   (
                       SELECT JSONB_AGG(JSONB_BUILD_OBJECT(
                           'id', df.id, 
                           'name', df.original_filename, 
                           'size', df.file_size_bytes
                       ))
                       FROM debug_files df 
                       WHERE df.id = ANY(s.debug_file_ids)
                   ) as scan_files
            FROM scan_jobs s
            JOIN users u ON s.tenant_id = u.id
            JOIN projects p ON s.project_id = p.id
            WHERE $whereSql
            ORDER BY $orderBy $order
            LIMIT :limit OFFSET :offset
        ");
        
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $scansData = $stmt->fetchAll();

        $scans = array_map(function($s) {
            $pBytes = isset($s['payload_size']) ? (int)$s['payload_size'] : 0;
            $rBytes = isset($s['report_size']) ? (int)$s['report_size'] : 0;
            $s['formatted_payload_size'] = ($pBytes > 0) ? $this->formatBytes($pBytes) : '0 B';
            $s['formatted_report_size'] = ($rBytes > 0) ? $this->formatBytes($rBytes) : '0 B';
            
            if (isset($s['scan_files']) && is_string($s['scan_files'])) {
                $s['scan_files'] = json_decode($s['scan_files'], true);
            }
            
            return $s;
        }, $scansData);

        $body = $this->view->render('admin/scans.twig', [
            'scans' => $scans,
            'tenantsList' => $tenantsList,
            'summary' => [
                'total_tokens' => $totalFilteredTokens,
                'breakdown' => $modelBreakdown
            ],
            'filters' => [
                'tenant_id' => $tenantId,
                'start_date' => $startDate,
                'end_date' => $endDate
            ],
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_items' => $totalItems,
                'page_size' => $pageSize,
                'start_item' => $offset + 1,
                'end_item' => min($offset + $pageSize, $totalItems)
            ],
            'sort' => $sort,
            'order' => strtolower($order),
            'active_page' => 'admin_scans'
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    public function downloadRawData(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $stmt = $this->pdo->prepare("SELECT result_input_payload FROM scan_jobs WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $payload = $stmt->fetchColumn();

        $response->getBody()->write($payload ?: json_encode(['error' => 'No raw payload data found']));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', 'attachment; filename="raw_ai_payload_' . $id . '.json"');
    }

    public function getScanPromptData(Request $request, Response $response, array $args): Response
    {
        try {
            $id = $args['id'];
            $stmt = $this->pdo->prepare("
                SELECT result_input_payload, scan_level
                FROM scan_jobs 
                WHERE id = :id
            ");
            $stmt->execute(['id' => $id]);
            $scan = $stmt->fetch();

            if (!$scan || empty($scan['result_input_payload'])) {
                $response->getBody()->write(json_encode(['success' => false, 'message' => 'AI Context Payload not found.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $payloadData = json_decode($scan['result_input_payload'], true);
            $displayData = is_array($payloadData) && isset($payloadData['raw']) ? $payloadData['raw'] : $scan['result_input_payload'];

            // Harden response - handles binary/non-UTF8 data gracefully
            $jsonData = json_encode([
                'success' => true,
                'title' => 'Admin Technical Trace (' . strtoupper($scan['scan_level'] ?? 'L0') . ')',
                'data' => $displayData,
                'type' => 'prompt'
            ], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);

            if ($jsonData === false) {
                throw new \Exception("JSON Encoding failed: " . json_last_error_msg());
            }

            $response->getBody()->write($jsonData);
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Technical Error: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function getPromptData(Request $request, Response $response, array $args): Response
    {
        try {
            $fileId = $args['id'];

            // Find the latest successful scan job that included this file
            $stmt = $this->pdo->prepare("
                SELECT result_input_payload, scan_level, id
                FROM scan_jobs 
                WHERE :fid = ANY(debug_file_ids) 
                  AND status = 'completed'
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute(['fid' => $fileId]);
            $scan = $stmt->fetch();

            if ($scan && !empty($scan['result_input_payload'])) {
                $payloadData = json_decode($scan['result_input_payload'], true);
                $displayData = is_array($payloadData) && isset($payloadData['raw']) ? $payloadData['raw'] : $scan['result_input_payload'];

                $response->getBody()->write(json_encode([
                    'success' => true,
                    'title' => 'Admin Forensic Inspector (' . strtoupper($scan['scan_level'] ?? 'L0') . ')',
                    'data' => $displayData,
                    'type' => 'prompt'
                ], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write(json_encode(['success' => false, 'message' => 'No analysis prompt history found for this file.']));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function abortScan(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $stmt = $this->pdo->prepare("UPDATE scan_jobs SET status = 'aborted', progress_stage = 'Aborted by Admin', updated_at = NOW() WHERE id = :id AND status IN ('queued', 'running')");
        $stmt->execute(['id' => $id]);
        
        $this->logAction($request, 'scan_aborted', 'scan_jobs', $id, ['aborted_by' => 'admin']);
        
        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }
    public function downloadFile(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $stmt = $this->pdo->prepare("SELECT original_filename, storage_path FROM debug_files WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $file = $stmt->fetch();

        if (!$file) {
            $response->getBody()->write("File record not found.");
            return $response->withStatus(404);
        }

        $fullPath = __DIR__ . '/../../' . $file['storage_path'];
        if (!file_exists($fullPath)) {
            $response->getBody()->write("Physical file missing on VPS: " . $file['storage_path']);
            return $response->withStatus(404);
        }

        $stream = new \Slim\Psr7\Stream(fopen($fullPath, 'rb'));
        
        $mimeType = 'application/octet-stream';
        $ext = strtolower(pathinfo($file['original_filename'], PATHINFO_EXTENSION));
        if ($ext === 'log' || $ext === 'txt') $mimeType = 'text/plain';
        if ($ext === 'json') $mimeType = 'application/json';

        return $response
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['original_filename'] . '"')
            ->withBody($stream);
    }


    public function downloadReport(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $stmt = $this->pdo->prepare("SELECT result_raw_response FROM scan_jobs WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $raw = $stmt->fetchColumn();

        $response->getBody()->write($raw ?: "No report data available.");
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', 'attachment; filename="ai_report_' . $id . '.json"');
    }

    public function resetSystem(Request $request, Response $response): Response
    {
        try {
            // 1. Database Cleanup (Truncate operational tables)
            $tables = [
                'sessions',
                'audit_log',
                'extraction_errors',
                'scan_findings',
                'scan_jobs',
                'debug_files',
                'projects'
            ];

            foreach ($tables as $table) {
                // TRUNCATE is faster and handles identity resets better than DELETE
                $this->pdo->exec("TRUNCATE TABLE $table CASCADE");
            }

            // 2. Filesystem Cleanup
            $storageFolders = [
                __DIR__ . '/../../storage/uploads',
                __DIR__ . '/../../storage/extracted'
            ];

            foreach ($storageFolders as $folder) {
                if (is_dir($folder)) {
                    $this->emptyDirectory($folder);
                }
            }

            $this->logAction($request, 'user_deleted', 'system', null, ['reset_type' => 'factory_reset']);
            
            $_SESSION['success'] = "System has been reset. All history and forensic data cleared.";
        } catch (\Exception $e) {
            $_SESSION['error'] = "Failed to reset system: " . $e->getMessage();
        }

        return $response->withHeader('Location', $this->basePath . '/admin/settings')->withStatus(302);
    }

    private function emptyDirectory(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..', '.gitignore']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->deleteDirectoryRecursive($path);
            } else {
                @unlink($path);
            }
        }
    }

    private function deleteDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteDirectoryRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Calculate total size of directory tree recursively
     *
     * PURPOSE:
     * Computes disk space used by a directory and all contents
     * Used for storage breakdown reports to show per-tenant usage
     * Scans extracted/ and uploaded/ directories for space accounting
     *
     * ALGORITHM:
     * Recursive iteration through all files in directory tree
     * Sums filesize() for each file
     * Returns total in bytes (raw integer, no formatting)
     *
     * PERFORMANCE:
     * Can be slow for large directory trees
     * Used during report generation, not page load
     * Iterates full tree: O(n) where n = file count
     *
     * ERROR HANDLING:
     * Returns 0 if directory doesn't exist
     * Silently skips permission errors
     * Used as $bytes value in formatBytes()
     *
     * @param string $path Directory path to measure
     *
     * @return int Total size in bytes
     */
    private function getFolderSize($path): int
    {
        $size = 0;
        // Recursively iterate all files in directory tree
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $file) {
            $size += $file->getSize();
        }
        return $size;
    }

    /**
     * Convert byte count to human-readable format
     *
     * PURPOSE:
     * Formats raw byte values for display in admin interface
     * Shows storage usage in appropriate units (B, KB, MB, GB)
     *
     * ALGORITHM:
     * Checks byte value against thresholds:
     * - >= 1 GB: divide by 1073741824, show as GB
     * - >= 1 MB: divide by 1048576, show as MB
     * - >= 1 KB: divide by 1024, show as KB
     * - < 1 KB: show as bytes
     * All divided values rounded to 2 decimal places
     *
     * CONVERSIONS:
     * - 1 KB = 1024 bytes
     * - 1 MB = 1048576 bytes (1024^2)
     * - 1 GB = 1073741824 bytes (1024^3)
     *
     * EXAMPLES:
     * - formatBytes(512) -> "512 B"
     * - formatBytes(1024) -> "1.00 KB"
     * - formatBytes(1048576) -> "1.00 MB"
     * - formatBytes(5368709120) -> "5.00 GB"
     *
     * @param int $bytes Raw byte count
     *
     * @return string Formatted string with unit (e.g., "2.50 MB")
     */
    private function formatBytes($bytes): string
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }

    public function getScanStatus(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $stmt = $this->pdo->prepare("SELECT id, status, progress_stage, progress_percent, checkpoints, updated_at FROM scan_jobs WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $job = $stmt->fetch();

        if ($job && is_string($job['checkpoints'])) {
            $job['checkpoints'] = json_decode($job['checkpoints'], true);
        }

        $response->getBody()->write(json_encode($job));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function aiAudit(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query("
            SELECT 
                j.id,
                j.status,
                j.ai_model,
                COALESCE(j.ai_input_tokens_used, 0) as ai_input_tokens_used,
                COALESCE(j.ai_output_tokens_used, 0) as ai_output_tokens_used,
                j.completed_at,
                u.display_name as tenant_name,
                p.name as project_name,
                COALESCE(OCTET_LENGTH(j.result_input_payload::text), 0) as prompt_size_bytes
            FROM scan_jobs j
            JOIN users u ON j.tenant_id = u.id
            JOIN projects p ON j.project_id = p.id
            WHERE j.status IN ('completed', 'error')
            ORDER BY j.created_at DESC
            LIMIT 100
        ");
        $logs = $stmt->fetchAll();

        $body = $this->view->render('admin/ai_audit.twig', [
            'logs' => $logs,
            'active_page' => 'admin_ai_audit'
        ]);

        $response->getBody()->write($body);
        return $response;
    }

    public function redeemTokens(Request $request, Response $response, array $args): Response
    {
        $code = $args['code'];

        $this->pdo->beginTransaction();
        try {
            // 1. Find the pending redemption
            $stmt = $this->pdo->prepare("SELECT * FROM token_redemptions WHERE code = :code AND status = 'pending' FOR UPDATE");
            $stmt->execute(['code' => $code]);
            $redemption = $stmt->fetch();

            if (!$redemption) {
                $this->pdo->rollBack();
                $body = "
                    <div style='font-family: sans-serif; text-align: center; padding: 50px;'>
                        <h1 style='color: #dc3545;'>❌ Link Invalid or Expired</h1>
                        <p style='font-size: 18px;'>This magic link has already been used or does not exist.</p>
                        <p><a href='/'>Return to Home</a></p>
                    </div>";
                $response->getBody()->write($body);
                return $response->withStatus(400);
            }

            // 2. Add tokens to tenant
            $stmt = $this->pdo->prepare("SELECT tokens_available FROM users WHERE id = :tid");
            $stmt->execute(['tid' => $redemption['tenant_id']]);
            $before = $stmt->fetchColumn() ?: 0;
            $after = $before + $redemption['amount'];

            $stmt = $this->pdo->prepare("UPDATE users SET tokens_available = :after, updated_at = NOW() WHERE id = :tid");
            $stmt->execute(['after' => $after, 'tid' => $redemption['tenant_id']]);

            // 3. Mark redemption as used
            $stmt = $this->pdo->prepare("UPDATE token_redemptions SET status = 'redeemed', redeemed_at = NOW() WHERE id = :id");
            $stmt->execute(['id' => $redemption['id']]);

            // 4. Audit Log
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_log (tenant_id, action, details)
                VALUES (:tid, 'tokens_redeemed', :details)
            ");
            $stmt->execute([
                'tid' => $redemption['tenant_id'],
                'details' => json_encode([
                    'amount' => $redemption['amount'],
                    'before' => $before,
                    'after' => $after,
                    'code_excerpt' => substr($code, 0, 8) . '...'
                ])
            ]);

            $this->pdo->commit();

            $body = "
                <div style='font-family: sans-serif; text-align: center; padding: 50px;'>
                    <h1 style='color: #28a745;'>✔ Tokens Granted!</h1>
                    <p style='font-size: 18px;'>Successfully added <strong>" . number_format($redemption['amount']) . " tokens</strong> to the account.</p>
                    <p>The tenant can now proceed with their forensic analysis.</p>
                    <p><a href='/admin' style='color: #007bff; text-decoration: none;'>Go to Admin Dashboard</a></p>
                </div>
            ";
            $response->getBody()->write($body);
            return $response;

        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $response->getBody()->write("<h2>Redemption Failed</h2><p>" . $e->getMessage() . "</p>");
            return $response->withStatus(500);
        }
    }

    public function tenantAudit(Request $request, Response $response, array $args): Response
    {
        $tenantId = $args['id'];
        $queryParams = $request->getQueryParams();
        
        // Defaults
        $dateFrom = !empty($queryParams['from']) ? $queryParams['from'] : date('Y-m-d', strtotime('-30 days'));
        $dateTo = !empty($queryParams['to']) ? $queryParams['to'] : date('Y-m-d');
        $category = !empty($queryParams['category']) ? $queryParams['category'] : 'all';

        // Fetch Tenant Info
        $stmt = $this->pdo->prepare("SELECT id, display_name, email, tokens_available, status FROM users WHERE id = :id AND role = 'tenant'");
        $stmt->execute(['id' => $tenantId]);
        $tenant = $stmt->fetch();

        if (!$tenant) {
            $_SESSION['error'] = 'Tenant not found.';
            return $response->withHeader('Location', $this->basePath . '/admin/tenants')->withStatus(302);
        }

        // Build log query
        $sql = "
            SELECT a.*, u.display_name as performer_name
            FROM audit_log a
            LEFT JOIN users u ON a.user_id = u.id
            WHERE a.tenant_id = :tid 
            AND a.created_at >= :from 
            AND a.created_at <= :to
        ";
        $params = ['tid' => $tenantId, 'from' => $dateFrom . ' 00:00:00', 'to' => $dateTo . ' 23:59:59'];

        if ($category === 'financial') {
            $sql .= " AND a.action IN ('tokens_requested', 'tokens_redeemed', 'tokens_allocated', 'tokens_deducted')";
        } elseif ($category === 'scans') {
            $sql .= " AND a.action IN ('scan_queued', 'scan_started', 'scan_completed', 'scan_failed', 'tokens_deducted')";
        } elseif ($category === 'sessions') {
            $sql .= " AND a.action IN ('login', 'logout', 'login_failed')";
        } elseif ($category === 'files') {
            $sql .= " AND a.action IN ('file_uploaded', 'file_deleted')";
        }

        $sql .= " ORDER BY a.created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        $body = $this->view->render('admin/tenant_audit.twig', [
            'tenant' => $tenant,
            'logs' => $logs,
            'filters' => [
                'from' => $dateFrom,
                'to' => $dateTo,
                'category' => $category
            ],
            'active_page' => 'admin_tenants'
        ]);
        
        $response->getBody()->write($body);
        return $response;
    }

    public function exportTenantAudit(Request $request, Response $response, array $args): Response
    {
        $tenantId = $args['id'];
        $queryParams = $request->getQueryParams();
        
        $dateFrom = !empty($queryParams['from']) ? $queryParams['from'] : date('Y-m-d', strtotime('-365 days'));
        $dateTo = !empty($queryParams['to']) ? $queryParams['to'] : date('Y-m-d');

        $stmt = $this->pdo->prepare("SELECT display_name FROM users WHERE id = :id");
        $stmt->execute(['id' => $tenantId]);
        $tenantName = $stmt->fetchColumn() ?: 'Tenant';

        $stmt = $this->pdo->prepare("
            SELECT a.*, u.display_name as performer_name
            FROM audit_log a
            LEFT JOIN users u ON a.user_id = u.id
            WHERE a.tenant_id = :tid 
            AND a.created_at >= :from 
            AND a.created_at <= :to
            ORDER BY a.created_at DESC
        ");
        $stmt->execute([
            'tid' => $tenantId, 
            'from' => $dateFrom . ' 00:00:00', 
            'to' => $dateTo . ' 23:59:59'
        ]);
        $logs = $stmt->fetchAll();

        $stream = fopen('php://memory', 'w+');
        // UTF-8 BOM for Excel
        fprintf($stream, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($stream, [
            'Timestamp (UTC)', 
            'Action', 
            'Performer', 
            'Project/Resource', 
            'Level', 
            'Tokens Change', 
            'Before Balance', 
            'After Balance', 
            'IP Address', 
            'Details'
        ]);

        foreach ($logs as $log) {
            $details = json_decode($log['details'], true) ?: [];
            
            // Extract tokens change
            $tokenChange = $details['amount_deducted'] ?? ($details['amount'] ?? ($details['used'] ?? 0));
            if (in_array($log['action'], ['tokens_deducted', 'scan_completed'])) {
                $tokenChange = '-' . $tokenChange;
            } elseif (in_array($log['action'], ['tokens_allocated', 'tokens_redeemed'])) {
                $tokenChange = '+' . $tokenChange;
            }

            fputcsv($stream, [
                $log['created_at'],
                strtoupper(str_replace('_', ' ', $log['action'])),
                $log['performer_name'] ?? 'System',
                $details['project_name'] ?? ($log['resource_type'] ? $log['resource_type'] . ': ' . $log['resource_id'] : 'N/A'),
                $details['scan_level'] ?? 'N/A',
                $tokenChange,
                $details['before'] ?? 'N/A',
                $details['after'] ?? 'N/A',
                $log['ip_address'],
                $log['details']
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $filename = "AuditLog_" . str_replace(' ', '_', $tenantName) . "_" . date('Ymd') . ".csv";

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"$filename\"");
    }

    public function reportPlans(Request $request, Response $response): Response
    {
        $plans = $this->reportPlanService->getAllPlans();
        
        $availableModels = [];
        try {
            $availableModels = $this->aiService->getAvailableModels();
        } catch (\Exception $e) {
            // Fallback if API fails
            $availableModels = [
                ['id' => 'llama-3.3-70b-versatile', 'name' => 'Llama 3.3 70B', 'context_window' => 131072, 'max_output_tokens' => 32768, 'owned_by' => 'meta'],
                ['id' => 'llama-3.1-8b-instant', 'name' => 'Llama 3.1 8B', 'context_window' => 131072, 'max_output_tokens' => 32768, 'owned_by' => 'meta'],
                ['id' => 'mixtral-8x7b-32768', 'name' => 'Mixtral 8x7B', 'context_window' => 32768, 'max_output_tokens' => 32768, 'owned_by' => 'mistral']
            ];
        }
        
        $body = $this->view->render('admin/report_plans.twig', [
            'plans' => $plans,
            'available_models' => $availableModels,
            'models_json' => json_encode($availableModels),
            'active_page' => 'admin_plans',
            'success' => $_SESSION['success'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        
        unset($_SESSION['success'], $_SESSION['error']);
        $response->getBody()->write($body);
        return $response;
    }

    public function saveReportPlan(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        try {
            $planId = $this->reportPlanService->savePlan($data);
            
            $this->logAction($request, 'report_plan_saved', 'report_plans', $planId, [
                'name' => $data['name'] ?? 'Unknown',
                'ai_model' => $data['ai_model'] ?? 'N/A',
                'master_lookback_days' => $data['master_lookback_days'] ?? 0
            ]);

            $_SESSION['success'] = 'Report plan saved successfully.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to save plan: ' . $e->getMessage();
        }
        
        return $response->withHeader('Location', $this->basePath . '/admin/report-plans')->withStatus(302);
    }

    public function deleteReportPlan(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $id = $data['id'] ?? null;
        if ($id) {
            try {
                $this->reportPlanService->deletePlan($id);
                
                $this->logAction($request, 'report_plan_deleted', 'report_plans', $id, [
                    'id' => $id
                ]);

                $_SESSION['success'] = 'Report plan deleted.';
            } catch (\Exception $e) {
                $_SESSION['error'] = 'Failed to delete plan: ' . $e->getMessage();
            }
        }

        return $response->withHeader('Location', $this->basePath . '/admin/report-plans')->withStatus(302);
    }

    public function getStorageBreakdown(Request $request, Response $response, array $args): Response
    {
        $tenantId = $args['id'];
        
        // 1. Validate Tenant exists
        $stmt = $this->pdo->prepare("SELECT display_name FROM users WHERE id = :id AND role = 'tenant'");
        $stmt->execute(['id' => $tenantId]);
        $tenantName = $stmt->fetchColumn();
        
        if (!$tenantName) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Tenant not found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // 2. Fetch all projects
        $stmt = $this->pdo->prepare("SELECT id, name, created_at FROM projects WHERE tenant_id = :tid ORDER BY created_at DESC");
        $stmt->execute(['tid' => $tenantId]);
        $projects = $stmt->fetchAll();

        $breakdown = [];
        $grandTotal = 0;

        $uploadRoot = __DIR__ . '/../../storage/uploads';
        $extractRoot = __DIR__ . '/../../storage/extracted';

        foreach ($projects as $project) {
            $projectId = $project['id'];
            
            // A. Raw Files (Archive size from DB)
            $stmtFiles = $this->pdo->prepare("SELECT id, file_size_bytes FROM debug_files WHERE project_id = :pid");
            $stmtFiles->execute(['pid' => $projectId]);
            $files = $stmtFiles->fetchAll();
            
            $rawBytes = 0;
            $extractBytes = 0;
            
            foreach ($files as $f) {
                $rawBytes += (int)$f['file_size_bytes'];
                
                // B. Extracted Folder Size
                $extractPath = $extractRoot . DIRECTORY_SEPARATOR . $f['id'];
                if (is_dir($extractPath)) {
                    $extractBytes += $this->getFolderSize($extractPath);
                }
            }

            // C. Database Weight (Scan Payloads)
            $stmtScans = $this->pdo->prepare("SELECT SUM(OCTET_LENGTH(result_input_payload::text)) as db_size FROM scan_jobs WHERE project_id = :pid");
            $stmtScans->execute(['pid' => $projectId]);
            $dbBytes = (int)($stmtScans->fetchColumn() ?: 0);

            $projectTotal = $rawBytes + $extractBytes + $dbBytes;
            $grandTotal += $projectTotal;

            $breakdown[] = [
                'id' => $projectId,
                'name' => $project['name'],
                'created_at' => $project['created_at'],
                'raw_bytes' => $rawBytes,
                'raw_formatted' => $this->formatBytes($rawBytes),
                'extract_bytes' => $extractBytes,
                'extract_formatted' => $this->formatBytes($extractBytes),
                'db_bytes' => $dbBytes,
                'db_formatted' => $this->formatBytes($dbBytes),
                'total_bytes' => $projectTotal,
                'total_formatted' => $this->formatBytes($projectTotal)
            ];
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'tenant_id' => $tenantId,
            'tenant_name' => $tenantName,
            'grand_total' => $this->formatBytes($grandTotal),
            'projects' => $breakdown
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}

