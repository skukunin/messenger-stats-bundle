<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Integration\Collector;

use Skukunin\MessengerStatsBundle\Tests\Support\Message\SendInvoice;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class PhpSerializerDoctrineTransportStatsCollectorTest extends DoctrineTransportStatsCollectorTestCase
{
    protected function serializer(): SerializerInterface
    {
        return new PhpSerializer();
    }

    public function testTheMessageClassIsReadFromTheBodyWhenNoHeadersAreWritten(): void
    {
        $this->send($this->transport(), new SendInvoice());

        self::assertSame('[]', $this->headersOfTheOnlyRow());
        self::assertSame([SendInvoice::class => 1], $this->onlyQueue($this->collector()->collect($this->definition()))->classBreakdown);
    }

    private function headersOfTheOnlyRow(): string
    {
        $headers = $this->database->executeQuery('SELECT headers FROM '.$this->database->quoteIdentifier(self::TABLE))->fetchOne();
        self::assertIsString($headers);

        return $headers;
    }
}
