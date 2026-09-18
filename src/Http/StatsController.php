<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Http;

use Skukunin\MessengerStatsBundle\Report\StatsReportBuilder;
use Skukunin\MessengerStatsBundle\View\JsonReportView;
use Symfony\Component\HttpFoundation\JsonResponse;

final class StatsController
{
    public function __construct(
        private readonly StatsReportBuilder $reports,
        private readonly JsonReportView $view,
        private readonly NoStoreResponseFactory $responses,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        return $this->responses->json($this->view->render($this->reports->build()));
    }
}
