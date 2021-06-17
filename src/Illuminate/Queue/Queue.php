<?php

namespace Illuminate\Queue;

use Closure;
use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Support\Arr;
use Illuminate\Support\InteractsWithTime;
use Illuminate\Support\Str;

abstract class Queue
{
    use InteractsWithTime;

    /**
     * The IoC container instance.
     *
     * @var \Illuminate\Container\Container
     */
    protected $container;

    /**
     * The connection name for the queue.
     *
     * @var string
     */
    protected $connectionName;

    /**
     * Pushing retries configuration.
     *
     * @var array
     */
    protected $pushingRetriesConfiguration;

    /**
     * Indicate if the secondary queue is enabled.
     *
     * @var bool
     */
    protected $secondaryQueue;

    /**
     * The create payload callbacks.
     *
     * @var callable[]
     */
    protected static $createPayloadCallbacks = [];

    /**
     * Push a new job onto the queue.
     *
     * @param  string  $queue
     * @param  string  $job
     * @param  mixed  $data
     * @return mixed
     */
    public function pushOn($queue, $job, $data = '')
    {
        return $this->push($job, $data, $queue);
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  string  $queue
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @param  string  $job
     * @param  mixed  $data
     * @return mixed
     */
    public function laterOn($queue, $delay, $job, $data = '')
    {
        return $this->later($delay, $job, $data, $queue);
    }

    /**
     * Push an array of jobs onto the queue.
     *
     * @param  array  $jobs
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return void
     */
    public function bulk($jobs, $data = '', $queue = null)
    {
        foreach ((array) $jobs as $job) {
            $this->push($job, $data, $queue);
        }
    }

    /**
     * Create a payload string from the given job and data.
     *
     * @param  \Closure|string|object  $job
     * @param  string  $queue
     * @param  mixed  $data
     * @return string
     *
     * @throws \Illuminate\Queue\InvalidPayloadException
     */
    protected function createPayload($job, $queue, $data = '')
    {
        if ($job instanceof Closure) {
            $job = CallQueuedClosure::create($job);
        }

        $payload = json_encode($this->createPayloadArray($job, $queue, $data));

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new InvalidPayloadException(
                'Unable to JSON encode payload. Error code: '.json_last_error()
            );
        }

        return $payload;
    }

    /**
     * Create a payload array from the given job and data.
     *
     * @param  string|object  $job
     * @param  string  $queue
     * @param  mixed  $data
     * @return array
     */
    protected function createPayloadArray($job, $queue, $data = '')
    {
        return is_object($job)
                    ? $this->createObjectPayload($job, $queue)
                    : $this->createStringPayload($job, $queue, $data);
    }

    /**
     * Create a payload for an object-based queue handler.
     *
     * @param  object  $job
     * @param  string  $queue
     * @return array
     */
    protected function createObjectPayload($job, $queue)
    {
        $payload = $this->withCreatePayloadHooks($queue, [
            'uuid' => (string) Str::uuid(),
            'displayName' => $this->getDisplayName($job),
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'maxTries' => $job->tries ?? null,
            'maxExceptions' => $job->maxExceptions ?? null,
            'backoff' => $this->getJobBackoff($job),
            'timeout' => $job->timeout ?? null,
            'retryUntil' => $this->getJobExpiration($job),
            'data' => [
                'commandName' => $job,
                'command' => $job,
            ],
        ]);

        return array_merge($payload, [
            'data' => [
                'commandName' => get_class($job),
                'command' => serialize(clone $job),
            ],
        ]);
    }

    /**
     * Get the display name for the given job.
     *
     * @param  object  $job
     * @return string
     */
    protected function getDisplayName($job)
    {
        return method_exists($job, 'displayName')
                        ? $job->displayName() : get_class($job);
    }

    /**
     * Get the backoff for an object-based queue handler.
     *
     * @param  mixed  $job
     * @return mixed
     */
    public function getJobBackoff($job)
    {
        if (! method_exists($job, 'backoff') && ! isset($job->backoff)) {
            return;
        }

        return collect(Arr::wrap($job->backoff ?? $job->backoff()))
            ->map(function ($backoff) {
                return $backoff instanceof DateTimeInterface
                                ? $this->secondsUntil($backoff) : $backoff;
            })->implode(',');
    }

    /**
     * Get the expiration timestamp for an object-based queue handler.
     *
     * @param  mixed  $job
     * @return mixed
     */
    public function getJobExpiration($job)
    {
        if (! method_exists($job, 'retryUntil') && ! isset($job->retryUntil)) {
            return;
        }

        $expiration = $job->retryUntil ?? $job->retryUntil();

        return $expiration instanceof DateTimeInterface
                        ? $expiration->getTimestamp() : $expiration;
    }

    /**
     * Create a typical, string based queue payload array.
     *
     * @param  string  $job
     * @param  string  $queue
     * @param  mixed  $data
     * @return array
     */
    protected function createStringPayload($job, $queue, $data)
    {
        return $this->withCreatePayloadHooks($queue, [
            'uuid' => (string) Str::uuid(),
            'displayName' => is_string($job) ? explode('@', $job)[0] : null,
            'job' => $job,
            'maxTries' => null,
            'maxExceptions' => null,
            'backoff' => null,
            'timeout' => null,
            'data' => $data,
        ]);
    }

    /**
     * Register a callback to be executed when creating job payloads.
     *
     * @param  callable  $callback
     * @return void
     */
    public static function createPayloadUsing($callback)
    {
        if (is_null($callback)) {
            static::$createPayloadCallbacks = [];
        } else {
            static::$createPayloadCallbacks[] = $callback;
        }
    }

    /**
     * Create the given payload using any registered payload hooks.
     *
     * @param  string  $queue
     * @param  array  $payload
     * @return array
     */
    protected function withCreatePayloadHooks($queue, array $payload)
    {
        if (! empty(static::$createPayloadCallbacks)) {
            foreach (static::$createPayloadCallbacks as $callback) {
                $payload = array_merge($payload, call_user_func(
                    $callback, $this->getConnectionName(), $queue, $payload
                ));
            }
        }

        return $payload;
    }

    /**
     * Enqueue a job using the given callback.
     *
     * @param  \Closure|string|object  $job
     * @param  string  $payload
     * @param  string  $queue
     * @param  \DateTimeInterface|\DateInterval|int|null  $delay
     * @param  callable  $callback
     * @return mixed
     */
    protected function enqueueUsing($job, $payload, $queue, $delay, $callback)
    {
        try {
            return retry(
                ($this->pushingRetriesConfiguration['times'] ?? 1) + 1,
                function () use ($delay, $queue, $payload, $callback) {
                    return $callback($payload, $queue, $delay);
                },
                $this->pushingRetriesConfiguration['sleep'] ?? 0
            );
        } catch (\Throwable $e) {
            if ($this->secondaryQueue) {
                return $this->storeInSecondaryQueue($job, $queue, $delay, $e);
            }

            throw $e;
        }
    }

    /**
     * Get the connection name for the queue.
     *
     * @return string
     */
    public function getConnectionName()
    {
        return $this->connectionName;
    }

    /**
     * Set the connection name for the queue.
     *
     * @param  string  $name
     * @return $this
     */
    public function setConnectionName($name)
    {
        $this->connectionName = $name;

        return $this;
    }

    /**
     * Set the IoC container instance.
     *
     * @param  \Illuminate\Container\Container  $container
     * @return void
     */
    public function setContainer(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Configure pushing retries.
     *
     * @return void
     */
    public function configurePushingRetries($times, $sleep)
    {
        $this->pushingRetriesConfiguration = compact('times', 'sleep');
    }

    /**
     * Enable storing in the secondary queue.
     *
     * @return void
     */
    public function enableSecondaryQueue()
    {
        $this->secondaryQueue = true;
    }

    /**
     * Store the given job in the secondary queue.
     *
     * @param  mixed  $job
     * @param  string  $queue
     * @param  \DateTimeInterface|\DateInterval|int|null  $delay
     * @param  \Throwable  $e
     */
    protected function storeInSecondaryQueue($job, $queue, $delay, $e): void
    {
        $this->container['queue.secondary']->push(
            $this->getConnectionName(),
            $queue,
            serialize($job),
            $delay,
            $e
        );
    }
}
