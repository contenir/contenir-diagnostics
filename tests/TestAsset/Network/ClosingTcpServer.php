<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Tests\TestAsset\Network;

use RuntimeException;

use function fclose;
use function fgets;
use function is_resource;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function trim;

use const PHP_BINARY;

/**
 * A TCP server on a free local port, in a separate process, that accepts
 * each connection and closes it at once. A MySQL client connecting to it
 * fails while reading the greeting, which tells it apart from a refused
 * connection.
 */
final class ClosingTcpServer
{
    private const string SCRIPT = <<<'PHP'
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $name   = (string) stream_socket_get_name($server, false);
        echo substr($name, strrpos($name, ':') + 1), "\n";
        while (false !== ($connection = stream_socket_accept($server, 30))) {
            fclose($connection);
        }
        PHP;

    public readonly int $port;

    /** @var resource */
    private mixed $process;

    /** @var resource */
    private mixed $stdout;

    public function __construct()
    {
        $process = proc_open([PHP_BINARY, '-r', self::SCRIPT], [1 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the TCP server.');
        }

        $this->process = $process;
        $this->stdout  = $pipes[1];
        $this->port    = (int) trim((string) fgets($this->stdout));
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        fclose($this->stdout);
        proc_close($this->process);
    }
}
