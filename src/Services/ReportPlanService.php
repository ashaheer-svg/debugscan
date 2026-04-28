<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Exception;

/**
 * ReportPlanService: Manage analysis report templates and access control
 *
 * PURPOSE:
 * Define reusable report analysis configurations (templates)
 * Control which plans each tenant is authorized to use
 * Store plan-specific AI prompts, model selection, and token budgets
 * Manage extraction configuration per plan
 *
 * REPORT PLANS:
 * Templates that define how a diagnostic analysis is performed
 * Each plan specifies:
 * - name: Display name (e.g., "Hardware Health Check")
 * - ai_model: LLM to use (llama-3.3-70b-versatile, etc.)
 * - prompt_header: Custom system prompt prefix (optional)
 * - token_charge: Cost in tokens per analysis
 * - master_lookback_days: Data retention window
 * - is_active: Enable/disable for tenant access
 *
 * AUTHORIZATION MODEL:
 * Many-to-many: Multiple tenants can use multiple plans
 * tenant_report_plans table: Tracks which plans each tenant can access
 * Default: New plans assigned to all existing tenants automatically
 * Admins can customize per-tenant access via assignPlansToTenant()
 *
 * EXTRACTION CONFIG:
 * Each plan has default extraction_config settings (what data to extract)
 * Seeded from template plan ID: 11111111-1111-4111-a111-111111111111
 * Includes: section_key, is_enabled flag, max_rows per section
 * Controls report comprehensiveness vs performance trade-off
 *
 * PLAN LIFECYCLE:
 * 1. Admin creates plan → assigned to all active tenants
 * 2. Tenant selects plan when queuing analysis job
 * 3. ScanService validates plan authorization
 * 4. AiService loads plan's AI model and prompt_header
 * 5. Parser respects plan's extraction_config during data extraction
 *
 * @package App\Services
 */
class ReportPlanService
{
    private PDO $pdo;

    /**
     * Constructor: Dependency injection
     *
     * @param PDO $pdo Database connection
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Retrieve all report plans
     *
     * FILTERING:
     * - includeInactive=false: Only active plans (is_active = TRUE)
     * - includeInactive=true: All plans, active and inactive (default)
     *
     * SORTING:
     * Ordered by created_at ascending (oldest first)
     * Ensures stable ordering for plan lists
     *
     * USE CASES:
     * - Admin dashboard: All plans (includeInactive=true)
     * - Tenant plan selector: Only active plans (includeInactive=false)
     * - Plan management UI: All plans with toggle to filter
     *
     * @param bool $includeInactive Include inactive plans (default true)
     *
     * @return array<array<string,mixed>> Array of plan records
     */
    public function getAllPlans(bool $includeInactive = true): array
    {
        $sql = "SELECT * FROM report_plans";
        if (!$includeInactive) {
            $sql .= " WHERE is_active = TRUE";
        }
        $sql .= " ORDER BY created_at ASC";

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get plans authorized for a specific tenant
     *
     * AUTHORIZATION LOOKUP:
     * Joins report_plans with tenant_report_plans
     * Only includes active plans (is_active = TRUE)
     * Only includes plans explicitly assigned to this tenant
     *
     * RESULT:
     * Ordered alphabetically by plan name
     * Empty array if no plans authorized for tenant
     *
     * SECURITY:
     * Multi-tenant: Prevents tenant from accessing unauthorized plans
     * Used by ScanService to validate plan before queueing job
     * Used by frontend to populate plan selector dropdown
     *
     * @param string $tenantId Tenant UUID
     *
     * @return array<array<string,mixed>> Plans accessible by tenant
     */
    public function getPlansForTenant(string $tenantId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT rp.*
            FROM report_plans rp
            JOIN tenant_report_plans trp ON rp.id = trp.report_plan_id
            WHERE trp.tenant_id = :tenant_id AND rp.is_active = TRUE
            ORDER BY rp.name ASC
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Retrieve a single plan by ID
     *
     * FIELDS RETURNED:
     * id: Plan UUID
     * name: Display name
     * prompt_header: Custom system prompt (nullable)
     * ai_model: LLM model ID
     * token_charge: Cost in tokens per analysis
     * master_lookback_days: Data retention window
     * is_active: Enabled/disabled status
     * created_at, updated_at: Timestamps
     *
     * USE CASES:
     * - ScanService: Fetch plan details for validation and token check
     * - AiService: Load prompt_header and ai_model for analysis
     * - Admin: View/edit plan configuration
     *
     * @param string $id Plan UUID
     *
     * @return array<string,mixed>|null Plan record or null if not found
     */
    public function getPlan(string $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM report_plans WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Save plan (create or update)
     *
     * UPSERT PATTERN:
     * - If data['id'] provided: Update existing plan
     * - If no ID: Create new plan
     * - Returns plan ID in both cases
     *
     * CONVENIENCE:
     * Simplifies controller logic (single method for both create/edit)
     * Typical frontend form submission: same endpoint for new + existing
     *
     * @param array<string,mixed> $data Plan data (name, ai_model, prompt_header, token_charge, etc.)
     *
     * @return string Plan UUID (new or existing)
     *
     * @throws Exception If transaction fails during create
     */
    public function savePlan(array $data): string
    {
        $id = $data['id'] ?? null;

        if ($id) {
            $this->updatePlan($id, $data);
            return $id;
        } else {
            return $this->createPlan($data);
        }
    }

    /**
     * Create new report plan with default tenant assignment
     *
     * CREATION WORKFLOW:
     * 1. Insert into report_plans table
     * 2. Auto-assign to all active tenants (requirement)
     * 3. Seed extraction configuration from template plan
     * 4. Commit transaction
     *
     * DEFAULTS:
     * - ai_model: llama-3.3-70b-versatile (if not provided)
     * - token_charge: 100000 (if not provided)
     * - is_active: true (if not provided)
     * - master_lookback_days: 0 (if not provided)
     * - prompt_header: null (optional, tenant-specific)
     *
     * AUTO-ASSIGNMENT:
     * New plans automatically available to all existing tenants
     * Simplifies workflow: no need to manually authorize each tenant
     * Uses ON CONFLICT DO NOTHING to handle duplicates gracefully
     * Respects role='tenant' (skips system/admin users)
     *
     * EXTRACTION CONFIG SEEDING:
     * initializeExtractionConfig() copies defaults from template plan
     * Template plan ID: 11111111-1111-4111-a111-111111111111
     * Ensures consistent extraction settings across new plans
     *
     * TRANSACTION:
     * All operations wrapped in transaction
     * Rollback on any failure (ensures consistency)
     * Partial creation impossible (either all or none)
     *
     * @param array<string,mixed> $data {name, prompt_header?, ai_model?, token_charge?, is_active?, master_lookback_days?}
     *
     * @return string New plan UUID
     *
     * @throws Exception If transaction fails (caught and rolled back)
     */
    public function createPlan(array $data): string
    {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                INSERT INTO report_plans (name, prompt_header, ai_model, token_charge, is_active, master_lookback_days)
                VALUES (:name, :header, :model, :charge, :active, :lookback)
                RETURNING id
            ");

            $stmt->execute([
                'name'     => $data['name'],
                'header'   => $data['prompt_header'] ?? null,
                'model'    => $data['ai_model'] ?? 'llama-3.3-70b-versatile',
                'charge'   => $data['token_charge'] ?? 100000,
                'active'   => isset($data['is_active']) ? (bool)$data['is_active'] : true,
                'lookback' => (int)($data['master_lookback_days'] ?? 0)
            ]);

            $planId = $stmt->fetchColumn();

            // Auto-assign to all active tenants
            $this->pdo->query("
                INSERT INTO tenant_report_plans (tenant_id, report_plan_id)
                SELECT id, '$planId' FROM users WHERE role = 'tenant'
                ON CONFLICT DO NOTHING
            ");

            // Seed extraction configuration from template plan
            $this->initializeExtractionConfig($planId);

            $this->pdo->commit();
            return $planId;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Update existing report plan
     *
     * MUTATION:
     * Updates all configurable fields: name, prompt_header, ai_model, token_charge, is_active, lookback
     * Sets updated_at to NOW() for audit trail
     * Does NOT change tenant assignments (use assignPlansToTenant separately)
     *
     * DEFAULTS (same as createPlan):
     * ai_model: llama-3.3-70b-versatile
     * token_charge: 100000
     * master_lookback_days: 0
     *
     * IMPACT:
     * Affects future jobs using this plan (immediately effective)
     * In-progress jobs use plan version they started with (no retroactive changes)
     * Changing is_active=false disables plan for new jobs
     *
     * @param string $id Plan UUID
     * @param array<string,mixed> $data Fields to update
     *
     * @return void
     */
    public function updatePlan(string $id, array $data): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE report_plans
            SET name = :name,
                prompt_header = :header,
                ai_model = :model,
                token_charge = :charge,
                is_active = :active,
                master_lookback_days = :lookback,
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            'id'       => $id,
            'name'     => $data['name'],
            'header'   => $data['prompt_header'] ?? null,
            'model'    => $data['ai_model'] ?? 'llama-3.3-70b-versatile',
            'charge'   => $data['token_charge'] ?? 100000,
            'active'   => isset($data['is_active']) ? (bool)$data['is_active'] : true,
            'lookback' => (int)($data['master_lookback_days'] ?? 0)
        ]);
    }

    /**
     * Delete a report plan
     *
     * WARNING:
     * Soft delete not implemented — hard delete removes plan
     * In-progress jobs reference plan ID in job record (orphaned if plan deleted)
     * Consider disabling plan (is_active=false) instead of deleting
     *
     * CASCADING:
     * Does NOT delete tenant_report_plans or extraction_config (orphaned)
     * Future consideration: Add ON DELETE CASCADE to foreign keys
     *
     * @param string $id Plan UUID
     *
     * @return void
     */
    public function deletePlan(string $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM report_plans WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    /**
     * Assign a specific set of plans to a tenant
     *
     * REPLACE PATTERN:
     * 1. Delete all existing plan assignments for tenant
     * 2. Insert new assignments from planIds array
     * Result: Tenant has access to exactly the plans in planIds
     *
     * TRANSACTION:
     * All operations atomic (either all succeed or all rollback)
     * Prevents partial assignments
     *
     * USE CASE:
     * Admin edits tenant's plan access (checkbox form in admin panel)
     * Replaces previous access completely
     *
     * EMPTY ARRAY:
     * Passing empty planIds revokes all access
     * Tenant can still view previously queued jobs
     * Tenant cannot queue new jobs without any plans
     *
     * @param string $tenantId Tenant UUID
     * @param array<string> $planIds Plan UUIDs to assign (replaces existing)
     *
     * @return void
     *
     * @throws Exception If transaction fails
     */
    public function assignPlansToTenant(string $tenantId, array $planIds): void
    {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("DELETE FROM tenant_report_plans WHERE tenant_id = :tid");
            $stmt->execute(['tid' => $tenantId]);

            $stmt = $this->pdo->prepare("INSERT INTO tenant_report_plans (tenant_id, report_plan_id) VALUES (:tid, :pid)");
            foreach ($planIds as $pid) {
                $stmt->execute(['tid' => $tenantId, 'pid' => $pid]);
            }

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Seed extraction configuration for a new plan from template
     *
     * TEMPLATE PLAN:
     * ID: 11111111-1111-4111-a111-111111111111
     * Contains default extraction_config (what data to extract)
     * Each section (hardware, logs, disks, etc.) has:
     * - section_key: Identifier (e.g., 'hardware', 'logs')
     * - is_enabled: Whether to extract (boolean)
     * - max_rows: Row limit if applicable
     *
     * SEEDING PROCESS:
     * Copy all extraction_config rows from template plan to new plan
     * Ensures consistent defaults across plans
     * Can be overridden later if needed
     *
     * CALLED BY:
     * createPlan() - automatically during plan creation
     * Never called manually (internal only)
     *
     * @param string $planId New plan UUID
     *
     * @return void
     *
     * @throws PDOException If template plan doesn't exist
     */
    private function initializeExtractionConfig(string $planId): void
    {
        $this->pdo->prepare("
            INSERT INTO extraction_config (report_plan_id, section_key, is_enabled, max_rows)
            SELECT :plan_id, section_key, is_enabled, max_rows
            FROM extraction_config
            WHERE report_plan_id = '11111111-1111-4111-a111-111111111111'
        ")->execute(['plan_id' => $planId]);
    }

    /**
     * Check if tenant is authorized to use a specific plan
     *
     * AUTHORIZATION LOGIC:
     * Tenant authorized if row exists in tenant_report_plans
     * Called by ScanService during job queueing
     * Prevents unauthorized access to plans
     *
     * QUERY:
     * Single-row lookup: SELECT 1 (efficient, early exit)
     * Returns (bool) - true if authorized, false otherwise
     *
     * USE CASE:
     * ScanService: Validate plan before accepting job
     * Raises RuntimeException("FORBIDDEN: ...") if not authorized
     *
     * @param string $tenantId Tenant UUID
     * @param string $planId Plan UUID
     *
     * @return bool True if tenant can use plan, false otherwise
     */
    public function isPlanAuthorized(string $tenantId, string $planId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT 1 FROM tenant_report_plans
            WHERE tenant_id = :tid AND report_plan_id = :pid
        ");
        $stmt->execute(['tid' => $tenantId, 'pid' => $planId]);
        return (bool)$stmt->fetch();
    }
}
