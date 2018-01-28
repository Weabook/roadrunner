<?php
/**
 * High-performance PHP process supervisor and load balancer written in Go
 *
 * @author Wolfy-J
 */

namespace Spiral\RoadRunner;

use Spiral\Goridge\Exceptions\GoridgeException;
use Spiral\Goridge\RelayInterface as Relay;
use Spiral\RoadRunner\Exceptions\RoadRunnerException;

/**
 * Accepts connection from RoadRunner server over given Goridge relay.
 *
 * Example:
 *
 * $worker = new Worker(new Goridge\StreamRelay(STDIN, STDOUT));
 * while ($task = $worker->receive($context)) {
 *      $worker->send("DONE", json_encode($context));
 * }
 */
class Worker
{
    // Must be set as context value in order to perform controlled demolition of worker
    const TERMINATE = "TERMINATE";

    // Must be set as context value in order to represent content as an error
    const ERROR = "ERROR";

    /** @var Relay */
    private $relay;

    /**
     * @param Relay $relay
     */
    public function __construct(Relay $relay)
    {
        $this->relay = $relay;
    }

    /**
     * Receive packet of information to process, returns null when process must be stopped. Might
     * return Error to wrap error message from server.
     *
     * @param array $context Contains parsed context array send by the server.
     *
     * @return \Error|null|string
     * @throws GoridgeException
     */
    public function receive(&$context)
    {
        $body = $this->relay->receiveSync($flags);

        if ($flags & Relay::PAYLOAD_CONTROL) {
            if ($this->handleControl($body, $context)) {
                // wait for the next command
                return $this->receive($context);
            }

            // Expect process termination
            return null;
        }

        if ($flags & Relay::PAYLOAD_ERROR) {
            return new \Error($body);
        }

        return $body;
    }

    /**
     * Respond to the server with result of task execution and execution context.
     *
     * Example:
     * $worker->respond((string)$response->getBody(), json_encode($response->getHeaders()));
     *
     * @param string $payload
     * @param string $context
     */
    public function send(string $payload, string $context = null)
    {
        if (is_null($context)) {
            $this->relay->send($context, Relay::PAYLOAD_CONTROL | Relay::PAYLOAD_NONE);
        } else {
            $this->relay->send($context, Relay::PAYLOAD_CONTROL | Relay::PAYLOAD_RAW);
        }

        //todo: null payload?
        $this->relay->send($payload, Relay::PAYLOAD_RAW);
    }

    /**
     * Respond to the server with an error. Error must be treated as TaskError and might not cause
     * worker destruction.
     *
     * Example:
     *
     * $worker->error("invalid payload");
     *
     * @param string $message
     */
    public function error(string $message)
    {
        $this->relay->send(
            $message,
            Relay::PAYLOAD_CONTROL | Relay::PAYLOAD_RAW | Relay::PAYLOAD_ERROR
        );
    }

    /**
     * Terminate the process. Server must automatically pass task to the next available process.
     * Worker will receive TerminateCommand context after calling this method.
     *
     * @throws GoridgeException
     */
    public function terminate()
    {
        $this->send(null, self::TERMINATE);
    }

    /**
     * Handles incoming control command payload and executes it if required.
     *
     * @param string $body
     * @param array  $context Exported context (if any).
     *
     * @returns bool True when continue processing.
     *
     * @throws RoadRunnerException
     */
    private function handleControl(string $body = null, &$context = null): bool
    {
        if (is_null($body)) {
            // empty prefix
            return true;
        }

        $p = json_decode($body, true);
        if ($p === false) {
            throw new RoadRunnerException("invalid task context, JSON payload is expected");
        }

        // PID negotiation (socket connections only)
        if (!empty($p['pid'])) {
            $this->relay->send(sprintf('{"pid":%s}', getmypid()), Relay::PAYLOAD_CONTROL);
        }

        // termination request
        if (!empty($p['stop'])) {
            return false;
        }

        // not a command but execution context
        $context = $p;

        return true;
    }
}