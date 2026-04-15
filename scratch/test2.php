<?php
require 'vendor/autoload.php';
$loader = new \Twig\Loader\ArrayLoader(['test' => '{{ "A"|number_format }}']);
$twig = new \Twig\Environment($loader);
try {
    echo $twig->render('test');
} catch (\Exception $e) { echo $e->getMessage(); } catch (\Error $e) { echo $e->getMessage(); }
