<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ExtractionConfigService;
use App\Services\ReportPlanService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class ExtractionConfigController
{
    private Environment $view;
    private ExtractionConfigService $configService;
    private string $basePath;
    private ReportPlanService $reportPlanService;

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

    public function showPage(Request $request, Response $response, array $args): Response
    {
        $planId = $args['id'];
        
        // Fetch Plan Info
        $plan = $this->reportPlanService->getPlan($planId);
        $planName = $plan ? $plan['name'] : 'Unknown Plan';

        $allConfig = $this->configService->getConfig($planId); 

        // Group by section group name
        $groups = [];
        foreach ($allConfig as $key => $section) {
            $groups[$section['group']][$key] = $section;
        }

        // Active counts
        $activeCount = count(array_filter($allConfig, fn($s) => $s['is_enabled']));
        $total       = count($allConfig);

        $body = $this->view->render('admin/extraction_config.twig', [
            'plan_id'    => $planId,
            'plan_name'  => $planName,
            'groups'     => $groups,
            'active_count' => $activeCount,
            'total'      => $total,
            'status'     => $request->getQueryParams()['status'] ?? null,
            'active_page'=> 'admin_plans',
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    public function saveConfig(Request $request, Response $response, array $args): Response
    {
        $planId = $args['id'];
        $data = $request->getParsedBody();
        $sections = $data['sections'] ?? [];

        try {
            $this->configService->saveAll($planId, $sections);
            $_SESSION['success'] = 'Extraction configuration for "' . $planId . '" saved successfully.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to save configuration: ' . $e->getMessage();
        }

        return $response->withHeader('Location', $this->basePath . "/admin/extraction-config/{$planId}?status=saved")->withStatus(302);
    }
}
