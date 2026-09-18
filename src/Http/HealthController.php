<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Http;

use Skukunin\MessengerStatsBundle\Report\HealthStatus;
use Skukunin\MessengerStatsBundle\Report\StatsReportBuilder;
use Skukunin\MessengerStatsBundle\View\HealthReportView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class HealthController
{
    public function __construct(
        private readonly StatsReportBuilder $reports,
        private readonly HealthReportView $view,
        private readonly NoStoreResponseFactory $responses,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $report = $this->reports->build();

        return $this->responses->json($this->view->render($report), $this->statusCodeOf($report->status));
    }

    private function statusCodeOf(HealthStatus $status): int
    {
        return HealthStatus::Critical === $status ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK;
    }
}
