<?php
require 'vendor/autoload.php';
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

$loader = new FilesystemLoader(__DIR__ . '/../templates');
$twig = new Environment($loader, ['cache' => false, 'debug' => true]);

$twig->addFilter(new \Twig\TwigFilter('json_decode', function ($string) {
    if ($string === null || $string === '') return null;
    return json_decode((string)$string, true);
}));

$job = [
    'health_score' => 'B-', 
    'result_summary' => "[]", 
];
$project = ['name' => 'Test', 'id' => '1', 'serial_number' => '123'];
$findings = [
    [
        'id' => '1',
        'severity' => 'critical',
        'category' => 'Network',
        'recommendation' => 'Fix it',
        'evidence' => json_encode("this is a string evidence") // String inside JSON!
    ]
];

try {
    $out = $twig->render('tenant/report.twig', ['job' => $job, 'findings' => $findings, 'project' => $project, 'active_page' => 'scans']);
    echo "RENDER OK.\n";
} catch (\Exception $e) {
    echo "ERROR:\n" . $e->getMessage() . "\n";
} catch (\Error $e) {
    echo "FATAL:\n" . $e->getMessage() . "\n";
}
