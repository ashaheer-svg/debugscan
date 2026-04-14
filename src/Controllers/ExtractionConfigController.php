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
        $config = $this->configService->getConfig();

        // Group sections for the template
        $groups = [];
        foreach ($config as $key => $section) {
            $groups[$section['group']][$key] = $section;
        }

        // Count active sections for summary bar
        $activeCount = count(array_filter($config, fn($s) => $s['is_enabled']));
        $totalCount  = count($config);

        $body = $this->view->render('admin/extraction_config.twig', [
            'groups'        => $groups,
            'active_count'  => $activeCount,
            'total_count'   => $totalCount,
            'status'        => $request->getQueryParams()['status'] ?? null,
            'active_page'   => 'admin_extraction',
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
