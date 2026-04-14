<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ExtractionConfigService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class ExtractionConfigController
{
    private Environment $view;
    private ExtractionConfigService $configService;
    private string $basePath;

    public function __construct(Environment $view, ExtractionConfigService $configService, string $basePath)
    {
        $this->view = $view;
        $this->configService = $configService;
        $this->basePath = $basePath;
    }

    public function showPage(Request $request, Response $response): Response
    {
        $allConfig = $this->configService->getConfig(); // ['level1' => [...], 'level2' => [...]]

        // Group by section group name, with both level configs side by side
        $groups = [];
        foreach ($allConfig['level1'] as $key => $section) {
            $groups[$section['group']][$key] = [
                'meta'   => $section, // base metadata
                'level1' => $section,
                'level2' => $allConfig['level2'][$key],
            ];
        }

        // Per-level active counts for summary bar
        $l1Active = count(array_filter($allConfig['level1'], fn($s) => $s['is_enabled']));
        $l2Active = count(array_filter($allConfig['level2'], fn($s) => $s['is_enabled']));
        $total    = count($allConfig['level1']);

        $body = $this->view->render('admin/extraction_config.twig', [
            'groups'     => $groups,
            'l1_active'  => $l1Active,
            'l2_active'  => $l2Active,
            'total'      => $total,
            'status'     => $request->getQueryParams()['status'] ?? null,
            'active_page'=> 'admin_extraction',
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    public function saveConfig(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $sections = $data['sections'] ?? [];

        try {
            $this->configService->saveAll($sections);
            $_SESSION['success'] = 'Extraction configuration saved successfully.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to save configuration: ' . $e->getMessage();
        }

        return $response->withHeader('Location', $this->basePath . '/admin/extraction-config?status=saved')->withStatus(302);
    }
}
