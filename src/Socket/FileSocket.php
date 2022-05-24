<?php

declare(strict_types=1);


namespace Pheanstalk\Socket;

use Pheanstalk\Contract\SocketInterface;
use Pheanstalk\Exception\SocketException;
use Pheanstalk\Values\Timeout;

/**
 * A Socket implementation using the standard file functions.
 */
abstract class FileSocket implements SocketInterface
{
    /**
     * @phpstan-var resource
     * @psalm-var resource|closed-resource
     */
    private $socket;

    protected function __construct(mixed $socket, Timeout $receiveTimeout)
    {
        if (!is_resource($socket)) {
            throw new \InvalidArgumentException("A valid resource is required");
        }

        stream_set_timeout($socket, $receiveTimeout->seconds, $receiveTimeout->microSeconds);
        $this->socket = $socket;
    }

    /**
     * @return resource
     * @throws SocketException
     */
    final protected function getSocket()
    {
        /** @phpstan-ignore-next-line (Bug: https://github.com/phpstan/phpstan/issues/5845) */
        if (!is_resource($this->socket)) {
            $this->throwClosed();
        }
        return $this->socket;
    }

    /**
     * Writes data to the socket.
     */
    public function write(string $data): void
    {
        $socket = $this->getSocket();

        $retries = 0;
        error_clear_last();
        while ($data !== "" && $retries < 10) {
            $written = fwrite($socket, $data);

            if ($written === false) {
                $this->throwException();
            } elseif ($written === 0) {
                $retries++;
                continue;
            }
            $data = substr($data, $written);
        }

        if ($data !== "") {
            throw new SocketException('Write failed');
        }
    }

    /**
     * @return never
     * @throws SocketException
     */
    private function throwClosed(): never
    {
        throw new SocketException('The connection was closed');
    }

    private function throwException(): never
    {
        if (null === $error = error_get_last()) {
            throw new SocketException('Unknown error');
        }
        throw new SocketException($error['message'], $error['type']);
    }

    /**
     * Reads up to $length bytes from the socket.
     * @param int<0, max> $length
     */
    public function read(int $length): string
    {
        $socket = $this->getSocket();
        $buffer = '';
        while (0 < $remaining = $length - mb_strlen($buffer, '8bit')) {
            $result = fread($socket, $remaining);
            if ($result === false) {
                $this->throwException();
            }
            $buffer .= $result;
        }
        return $buffer;
    }

    /**
     * Reads up to the next new-line.
     * Trailing whitespace is trimmed.
     */
    public function getLine(): string
    {
        $socket = $this->getSocket();
        $result = fgets($socket, 8192);
        if ($result === false) {
            $this->throwException();
        }
        return rtrim($result);
    }

    /**
     * Disconnect the socket; subsequent usage of the socket will fail.
     * @idempotent
     */
    public function disconnect(): void
    {
        /** @phpstan-ignore-next-line (Bug: https://github.com/phpstan/phpstan/issues/5845) */
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }
}
