<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Integration\Collector;

use Skukunin\MessengerStatsBundle\Tests\Support\Message\SendInvoice;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer as SymfonySerializer;

final class JsonSerializerDoctrineTransportStatsCollectorTest extends DoctrineTransportStatsCollectorTestCase
{
    protected function serializer(): SerializerInterface
    {
        $normalizers = [new DateTimeNormalizer(), new ArrayDenormalizer(), new ObjectNormalizer()];

        return new Serializer(new SymfonySerializer($normalizers, [new JsonEncoder()]));
    }

    public function testTheMessageClassIsReadFromTheTypeHeader(): void
    {
        $this->send($this->transport(), new SendInvoice());

        self::assertStringContainsString('"type":"'.addslashes(SendInvoice::class).'"', $this->headersOfTheOnlyRow());
        self::assertSame([SendInvoice::class => 1], $this->onlyQueue($this->collector()->collect($this->definition()))->classBreakdown);
    }

    private function headersOfTheOnlyRow(): string
    {
        $headers = $this->database->executeQuery('SELECT headers FROM '.$this->database->quoteIdentifier(self::TABLE))->fetchOne();
        self::assertIsString($headers);

        return $headers;
    }
}
