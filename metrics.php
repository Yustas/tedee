<?php

use Prometheus\RenderTextFormat;

error_reporting(E_ALL);
ini_set("display_errors", 1);

require __DIR__ . '/vendor/autoload.php';

$registry = Prometheus\CollectorRegistry::getDefault();

$renderer = new RenderTextFormat();
$result = $renderer->render($registry->getMetricFamilySamples());

header('Content-type: ' . RenderTextFormat::MIME_TYPE);
echo $result;