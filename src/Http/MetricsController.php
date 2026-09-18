<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Http;

use Skukunin\MessengerStatsBundle\Report\StatsReportBuilder;
use Skukunin\MessengerStatsBundle\View\PrometheusReportView;
use Symfony\Component\HttpFoundation\Response;

final class MetricsController
{
    public const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    public function __construct(
        private readonly StatsReportBuilder $reports,
        private readonly PrometheusReportView $view,
        private readonly NoStoreResponseFactory $responses,
    ) {
    }

    public function __invoke(): Response
    {
        return $this->responses->text($this->view->render($this->reports->build()), self::CONTENT_TYPE);
    }
}
