<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Tests\Fixtures;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 logger keeping every record in memory, for assertions on log levels.
 *
 * @internal
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{mixed, string|\Stringable}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, $message];
    }
}
