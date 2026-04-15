<?php
require 'vendor/autoload.php';
$loader = new \Twig\Loader\ArrayLoader(['test' => '{{ myvar|replace({"%": ""}) }}']);
$twig = new \Twig\Environment($loader);
try {
    echo $twig->render('test', ['myvar' => null]);
} catch (\Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage();
}
