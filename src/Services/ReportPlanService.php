<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Exception;

class ReportPlanService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get all currently defined report plans.
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
     * Get plans authorized for a specific tenant.
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
     * Get a single plan by ID.
     */
    public function getPlan(string $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM report_plans WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Combined Save: Create if no ID, Update if ID exists.
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
     * Create a new report plan and assign it to all tenants by default.
     */
    public function createPlan(array $data): string
    {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                INSERT INTO report_plans (name, prompt_header, ai_model, token_charge, is_active)
                VALUES (:name, :header, :model, :charge, :active)
                RETURNING id
            ");
            
            $stmt->execute([
                'name'    => $data['name'],
                'header'  => $data['prompt_header'] ?? null,
                'model'   => $data['ai_model'] ?? 'llama-3.3-70b-versatile',
                'charge'  => $data['token_charge'] ?? 100000,
                'active'  => isset($data['is_active']) ? (bool)$data['is_active'] : true
            ]);

            $planId = $stmt->fetchColumn();

            // Requirement: Assign to all tenants by default
            $this->pdo->query("
                INSERT INTO tenant_report_plans (tenant_id, report_plan_id)
                SELECT id, '$planId' FROM users WHERE role = 'tenant'
                ON CONFLICT DO NOTHING
            ");

            // Also initialize extraction configuration for this plan
            $this->initializeExtractionConfig($planId);

            $this->pdo->commit();
            return $planId;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Update an existing plan.
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
                updated_at = NOW()
            WHERE id = :id
        ");
        
        $stmt->execute([
            'id'      => $id,
            'name'    => $data['name'],
            'header'  => $data['prompt_header'] ?? null,
            'model'   => $data['ai_model'] ?? 'llama-3.3-70b-versatile',
            'charge'  => $data['token_charge'] ?? 100000,
            'active'  => isset($data['is_active']) ? (bool)$data['is_active'] : true
        ]);
    }

    public function deletePlan(string $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM report_plans WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    /**
     * Assign a specific set of plans to a tenant.
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
     * Seed initial extraction configuration for a new plan based on Defaults.
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
     * Check if a tenant is authorized to use a specific plan.
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
