<?php

declare(strict_types=1);

namespace Keel\Queue;

/**
 * Pushes jobs for background processing.
 *
 * With ->withQueue() jobs are rows in the application database, written through the
 * request's Database: a job pushed inside a transaction is committed or rolled back with
 * it (a transactional outbox), so "saved the order but lost the email" cannot happen.
 */
interface Queue
{
    /**
     * @param object $job an instance of a class with a #[Handler]
     * @param string|null $type internal: the job type when it is not the job's class
     * @return string the job id
     */
    public function push(object $job, int $delaySeconds = 0, ?string $type = null): string;
}
