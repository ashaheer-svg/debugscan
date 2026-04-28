<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ExtractionConfigService;
use App\Services\ReportPlanService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

/**
 * Extraction Configuration UI Controller
 *
 * PURPOSE:
 * Manage the configurable data extraction sections for report plans, allowing
 * administrators to enable/disable specific extraction modules per plan.
 *
 * RESPONSIBILITIES:
 * - Display configurable data extraction sections for a specific report plan
 * - Allow enable/disable toggling of extraction modules per plan
 * - Persist configuration changes to database
 * - Provide admin interface for fine-tuning what data is extracted
 *
 * ROUTE MAPPING:
 * - GET  /admin/extraction-config/{planId}           → showPage() (display config UI)
 * - POST /admin/extraction-config/{planId}/save       → saveConfig() (persist changes)
 *
 * CONFIGURATION MODEL:
 * Each extraction section has:
 *   - key: Identifier (e.g., 'system_info', 'storage_info')
 *   - group: Category grouping (e.g., 'System', 'Storage', 'Network')
 *   - is_enabled: Boolean flag for whether section is extracted
 *   - Other metadata (name, description, etc.)
 *
 * GROUPING:
 * Sections are grouped by 'group' field for UI organization
 * Example groups: System, Storage, Memory, Network, Hardware
 *
 * DEPENDENCIES:
 * - ReportPlanService: Fetch plan name and basic info
 * - ExtractionConfigService: Query and persist extraction configs
 * - Twig: Template rendering for configuration UI
 *
 * ADMIN-ONLY ACCESS:
 * This controller is typically gated behind admin authorization checks
 * in the routing/middleware layer (not enforced in controller itself)
 *
 * @package App\Controllers
 */
class ExtractionConfigController
{
    private Environment $view;
    private ExtractionConfigService $configService;
    private string $basePath;
    private ReportPlanService $reportPlanService;

    /**
     * Constructor: Dependency injection of Twig, services, and base path
     *
     * @param Environment               $view                  Twig environment for template rendering
     * @param ExtractionConfigService   $configService        Service for querying/updating extraction configs
     * @param string                    $basePath             Application base path for URLs
     * @param ReportPlanService         $reportPlanService    Service for fetching plan metadata
     */
    public function __construct(
        Environment $view,
        ExtractionConfigService $configService,
        string $basePath,
        ReportPlanService $reportPlanService
    ) {
        $this->view = $view;
        $this->configService = $configService;
        $this->basePath = $basePath;
        $this->reportPlanService = $reportPlanService;
    }

    /**
     * Display extraction configuration UI for a report plan
     *
     * FLOW:
     * 1. Extract plan ID from route args
     * 2. Fetch plan name and metadata from ReportPlanService
     * 3. Query all extraction sections for this plan from ExtractionConfigService
     * 4. Group sections by 'group' field for organized UI display
     * 5. Calculate active/total section counts for summary
     * 6. Render template with grouped configuration data
     *
     * GROUPING LOGIC:
     * Sections are multi-level keyed:
     *   $groups['System']['system_info'] = {...}
     *   $groups['Storage']['storage_info'] = {...}
     * This allows template to organize UI by category
     *
     * STATUS PARAMETER:
     * Query param 'status' (e.g., ?status=saved) used by template
     * to display success/info messages after form submission
     *
     * @param Request  $request  PSR-7 request with route args and query params
     * @param Response $response PSR-7 response object
     * @param array    $args     Route arguments ['id' => planId]
     *
     * @return Response HTML response with rendered configuration form
     */
    public function showPage(Request $request, Response $response, array $args): Response
    {
        // Extract plan ID from route parameter
        $planId = $args['id'];

        // === Fetch plan metadata ===
        $plan = $this->reportPlanService->getPlan($planId);
        $planName = $plan ? $plan['name'] : 'Unknown Plan';

        // === Query all extraction sections for this plan ===
        $allConfig = $this->configService->getConfig($planId);

        // === Group sections by category for organized display ===
        // Transform flat array into hierarchical structure by 'group' field
        $groups = [];
        foreach ($allConfig as $key => $section) {
            $groups[$section['group']][$key] = $section;
        }

        // === Calculate summary statistics ===
        $activeCount = count(array_filter($allConfig, fn($s) => $s['is_enabled']));
        $total = count($allConfig);

        // === Render configuration UI ===
        $body = $this->view->render('admin/extraction_config.twig', [
            'plan_id'      => $planId,
            'plan_name'    => $planName,
            'groups'       => $groups,                              // Grouped sections for display
            'active_count' => $activeCount,                         // Count of enabled sections
            'total'        => $total,                               // Total section count
            'status'       => $request->getQueryParams()['status'] ?? null, // Status from ?status=...
            'active_page'  => 'admin_plans',                        // Active nav item
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    /**
     * Persist extraction configuration changes to database
     *
     * FORM DATA:
     * POST body contains 'sections' array with extraction section states:
     *   sections[system_info] = 'on'  (enabled)
     *   sections[storage_info] = null (disabled, unchecked checkbox)
     *   sections[...] = ...
     *
     * FLOW:
     * 1. Extract plan ID from route args
     * 2. Extract sections array from POST body
     * 3. Delegate to ExtractionConfigService::saveAll() to persist changes
     * 4. Set success/error message in session flash
     * 5. Redirect to configuration page with status parameter
     * 6. Template displays flash message on next render
     *
     * ERROR HANDLING:
     * - Any exception from ExtractionConfigService caught and shown to user
     * - Session is used for flash messages (survives redirect)
     * - Redirect includes ?status=saved for template success message display
     *
     * REDIRECT BEHAVIOR:
     * - Always redirects to configuration page (showPage) regardless of success/failure
     * - Query parameter status=saved tells template to display confirmation
     * - Flash message in session displays specific success/error details
     *
     * @param Request  $request  PSR-7 request with form data
     * @param Response $response PSR-7 response object
     * @param array    $args     Route arguments ['id' => planId]
     *
     * @return Response Redirect to configuration page (HTTP 302)
     */
    public function saveConfig(Request $request, Response $response, array $args): Response
    {
        // Extract plan ID from route parameter
        $planId = $args['id'];

        // Extract form data: sections array contains enabled section keys
        $data = $request->getParsedBody();
        $sections = $data['sections'] ?? [];

        try {
            // Persist configuration changes to database
            $this->configService->saveAll($planId, $sections);
            // Flash success message for display after redirect
            $_SESSION['success'] = 'Extraction configuration for "' . $planId . '" saved successfully.';
        } catch (\Exception $e) {
            // Flash error message for display after redirect
            $_SESSION['error'] = 'Failed to save configuration: ' . $e->getMessage();
        }

        // Redirect back to configuration page with status indicator
        // Template will display flash message and check status query param for UI feedback
        return $response->withHeader('Location', $this->basePath . "/admin/extraction-config/{$planId}?status=saved")->withStatus(302);
    }
}
