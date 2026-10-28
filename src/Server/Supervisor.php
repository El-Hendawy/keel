<?php

declare(strict_types=1);

namespace Keel\Server;

use Keel\Runtime\Application;
use Psr\Log\LoggerInterface;

/**
 * `keel run`: opens the listening socket, starts N server processes that share it, restarts
 * any that exit, and on SIGTERM/SIGINT drains them all before exiting. Without pcntl (or with
 * one worker) the server runs in this process.
 */
final class Supervisor
{
    /** @var array<int, true> */
    private array $children = [];

    private bool $stopping = false;

    public function __construct(
        private readonly Application $app,
        private readonly string $host,
        private readonly int $port,
        private readonly int $workers,
    ) {
    }

    public function run(): int
    {
        $context = stream_context_create(['socket' => ['backlog' => 1024, 'so_reuseaddr' => true]]);
        $listener = @stream_socket_server("tcp://{$this->host}:{$this->port}", $errno, $error, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $context);
        if ($listener === false) {
            throw new \RuntimeException("Cannot listen on {$this->host}:{$this->port}: $error");
        }
        $logger = $this->app->container()->get(LoggerInterface::class);
        $options = ServerOptions::fromConfig($this->app->container()->configuration());

        if ($this->workers <= 1 || !\function_exists('pcntl_fork')) {
            $server = new HttpServer($this->app, $listener, $options, $logger);
            $this->signals($server->stop(...));
            $logger->info('Keel server listening on http://{host}:{port} (1 process).', ['host' => $this->host, 'port' => $this->port]);
            $server->run();

            return 0;
        }

        $logger->info('Keel server listening on http://{host}:{port} ({workers} processes).', ['host' => $this->host, 'port' => $this->port, 'workers' => $this->workers]);
        $this->signals(function (): void {
            $this->stopping = true;
            foreach (array_keys($this->children) as $pid) {
                posix_kill($pid, \SIGTERM);
            }
        });
        for ($i = 0; $i < $this->workers; $i++) {
            $this->spawn($listener, $options, $logger);
        }
        while ($this->children !== []) {
            $pid = pcntl_wait($status);
            if ($pid <= 0) {
                continue; // interrupted by a signal
            }
            unset($this->children[$pid]);
            if (!$this->stopping) {
                $status = \is_int($status) ? $status : 1;
                if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                    $logger->error('Server process {pid} exited unexpectedly; restarting it.', ['pid' => $pid]);
                    usleep(200_000);
                }
                $this->spawn($listener, $options, $logger);
            }
        }

        return 0;
    }

    /** @param resource $listener */
    private function spawn($listener, ServerOptions $options, LoggerInterface $logger): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Cannot fork a server process.');
        }
        if ($pid > 0) {
            $this->children[$pid] = true;

            return;
        }
        // Child: serve until told to stop, then exit without running the parent's shutdown code.
        $this->children = [];
        $server = new HttpServer($this->app, $listener, $options, $logger);
        $this->signals($server->stop(...));
        try {
            $server->run();
            $code = 0;
        } catch (\Throwable $e) {
            $logger->error('Server process failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
            $code = 1;
        }
        exit($code);
    }

    private function signals(\Closure $onStop): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        // Do not restart interrupted system calls: pcntl_wait() must return so the handler runs.
        pcntl_signal(\SIGTERM, static fn () => $onStop(), false);
        pcntl_signal(\SIGINT, static fn () => $onStop(), false);
    }
}
