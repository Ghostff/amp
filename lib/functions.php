<?php

namespace Amp {

    use React\Promise\PromiseInterface as ReactPromise;

    /**
     * Wraps the callback in a promise/coroutine-aware function that automatically upgrades Generators to coroutines and
     * calls rethrow() on the returned promises (or the coroutine created).
     *
     * @param callable (...$args): \Generator|\Amp\Promise|mixed $callback
     *
     * @return callable(...$args): void
     */
    function wrap(callable $callback): callable {
        return function (...$args) use ($callback) {
            $result = $callback(...$args);

            if ($result instanceof \Generator) {
                $result = new Coroutine($result);
            }

            if ($result instanceof Promise || $result instanceof ReactPromise) {
                Promise\rethrow($result);
            }
        };
    }

    /**
     * Returns a new function that wraps $worker in a promise/coroutine-aware function that automatically upgrades
     * Generators to coroutines. The returned function always returns a promise when invoked. If $worker throws, a failed
     * promise is returned.
     *
     * @param callable (mixed ...$args): mixed $worker
     *
     * @return callable(mixed ...$args): \Amp\Promise
     */
    function coroutine(callable $worker): callable {
        return function (...$args) use ($worker): Promise {
            try {
                $result = $worker(...$args);
            } catch (\Throwable $exception) {
                return new Failure($exception);
            }

            if ($result instanceof \Generator) {
                return new Coroutine($result);
            }

            if ($result instanceof Promise) {
                return $result;
            }

            if ($result instanceof ReactPromise) {
                return Promise\adapt($result);
            }

            return new Success($result);
        };
    }

    /**
     * Calls the given function, always returning a promise. If the function returns a Generator, it will be run as a
     * coroutine. If the function throws, a failed promise will be returned.
     *
     * @param callable (mixed ...$args): mixed $functor
     * @param array ...$args Arguments to pass to the function.
     *
     * @return \Amp\Promise
     */
    function call(callable $functor, ...$args): Promise {
        try {
            $result = $functor(...$args);
        } catch (\Throwable $exception) {
            return new Failure($exception);
        }

        if ($result instanceof \Generator) {
            return new Coroutine($result);
        }

        if ($result instanceof Promise) {
            return $result;
        }

        if ($result instanceof ReactPromise) {
            return Promise\adapt($result);
        }

        return new Success($result);
    }

    /**
     * Wraps the given callable $worker in a promise aware function that has the same number of arguments as $worker,
     * but those arguments may be promises for the future argument value or just values. The returned function will
     * return a promise for the return value of $worker and will never throw. The $worker function will not be called
     * until each promise given as an argument is fulfilled. If any promise provided as an argument fails, the
     * promise returned by the returned function will be failed for the same reason. The promise succeeds with
     * the return value of $worker or failed if $worker throws.
     *
     * @param callable $worker
     *
     * @return callable
     */
    function lift(callable $worker): callable {
        /**
         * @param mixed ...$args Promises or values.
         *
         * @return \Amp\Promise
         */
        return function (...$args) use ($worker): Promise {
            foreach ($args as $key => $arg) {
                if (!$arg instanceof Promise) {
                    if ($arg instanceof ReactPromise) {
                        $args[$key] = Promise\adapt($arg);
                    } else {
                        $args[$key] = new Success($arg);
                    }
                }
            }

            if (1 === \count($args)) {
                return Promise\pipe($args[0], $worker);
            }

            return Promise\pipe(Promise\all($args), function (array $args) use ($worker) {
                \ksort($args); // Needed to ensure correct argument order.
                return $worker(...$args);
            });
        };
    }
}

namespace Amp\Promise {

    use Amp\Loop;
    use Amp\Deferred;
    use Amp\MultiReasonException;
    use Amp\Promise;
    use Amp\Success;
    use Amp\TimeoutException;
    use Amp\UnionTypeError;
    use React\Promise\PromiseInterface as ReactPromise;

    /**
     * Registers a callback that will forward the failure reason to the Loop error handler if the promise fails.
     *
     * @param \Amp\Promise|\React\Promise\PromiseInterface $promise
     *
     * @throws \TypeError If $promise is not an instance of \Amp\Promise or \React\Promise\PromiseInterface.
     */
    function rethrow($promise) {
        if (!$promise instanceof Promise) {
            if ($promise instanceof ReactPromise) {
                $promise = Promise\adapt($promise);
            } else {
                throw new UnionTypeError([Promise::class, ReactPromise::class], $promise);
            }
        }

        $promise->onResolve(function ($exception) {
            if ($exception) {
                throw $exception;
            }
        });
    }

    /**
     * Runs the event loop until the promise is resolved. Should not be called within a running event loop.
     *
     * @param \Amp\Promise|\React\Promise\PromiseInterface $promise
     *
     * @return mixed Promise success value.
     *
     * @throws \TypeError If $promise is not an instance of \Amp\Promise or \React\Promise\PromiseInterface.
     * @throws \Throwable Promise failure reason.
     */
    function wait($promise) {
        if (!$promise instanceof Promise) {
            if ($promise instanceof ReactPromise) {
                $promise = adapt($promise);
            } else {
                throw new UnionTypeError([Promise::class, ReactPromise::class], $promise);
            }
        }

        $resolved = false;
        Loop::run(function () use (&$resolved, &$value, &$exception, $promise) {
            $promise->onResolve(function ($e, $v) use (&$resolved, &$value, &$exception) {
                Loop::stop();
                $resolved = true;
                $exception = $e;
                $value = $v;
            });
        });

        if (!$resolved) {
            throw new \Error("Loop stopped without resolving promise");
        }

        if ($exception) {
            throw $exception;
        }

        return $value;
    }

    /**
     * Pipe the promised value through the specified functor once it resolves.
     *
     * @param \Amp\Promise|\React\Promise\PromiseInterface $promise
     * @param callable (mixed $value): mixed $functor
     *
     * @return \Amp\Promise
     *
     * @throws \TypeError If $promise is not an instance of \Amp\Promise or \React\Promise\PromiseInterface.
     */
    function pipe($promise, callable $functor): Promise {
        if (!$promise instanceof Promise) {
            if ($promise instanceof ReactPromise) {
                $promise = adapt($promise);
            } else {
                throw new UnionTypeError([Promise::class, ReactPromise::class], $promise);
            }
        }

        $deferred = new Deferred;

        $promise->onResolve(function ($exception, $value) use ($deferred, $functor) {
            if ($exception) {
                $deferred->fail($exception);
                return;
            }

            try {
                $deferred->resolve($functor($value));
            } catch (\Throwable $exception) {
                $deferred->fail($exception);
            }
        });

        return $deferred->promise();
    }

    /**
     * @param \Amp\Promise|\React\Promise\PromiseInterface $promise
     * @param string $className Throwable class name to capture. Given callback will only be invoked if the failure reason
     *     is an instance of the given throwable class name.
     * @param callable (\Throwable $exception): mixed $functor
     *
     * @return \Amp\Promise
     *
     * @throws \TypeError If $promise is not an instance of \Amp\Promise or \React\Promise\PromiseInterface.
     */
    function capture($promise, string $className, callable $functor): Promise {
        if (!$promise instanceof Promise) {
            if ($promise instanceof ReactPromise) {
                $promise = adapt($promise);
            } else {
                throw new UnionTypeError([Promise::class, ReactPromise::class], $promise);
            }
        }

        $deferred = new Deferred;

        $promise->onResolve(function ($exception, $value) use ($deferred, $className, $functor) {
            if (!$exception) {
                $deferred->resolve($value);
                return;
            }

            if (!$exception instanceof $className) {
                $deferred->fail($exception);
                return;
            }

            try {
                $deferred->resolve($functor($exception));
            } catch (\Throwable $exception) {
                $deferred->fail($exception);
            }
        });

        return $deferred->promise();
    }

    /**
     * Create an artificial timeout for any Promise.
     *
     * If the timeout expires before the promise is resolved, the returned promise fails with an instance of
     * \Amp\TimeoutException.
     *
     * @param \Amp\Promise|\React\Promise\PromiseInterface $promise
     * @param int $timeout Timeout in milliseconds.
     *
     * @return \Amp\Promise
     *
     * @throws \TypeError If $promise is not an instance of \Amp\Promise or \React\Promise\PromiseInterface.
     */
    function timeout($promise, int $timeout): Promise {
        if (!$promise instanceof Promise) {
            if ($promise instanceof ReactPromise) {
                $promise = adapt($promise);
            } else {
                throw new UnionTypeError([Promise::class, ReactPromise::class], $promise);
            }
        }

        $deferred = new Deferred;
        $resolved = false;

        $watcher = Loop::delay($timeout, function () use (&$resolved, $deferred) {
            if (!$resolved) {
                $resolved = true;
                $deferred->fail(new TimeoutException);
            }
        });
        Loop::unreference($watcher);

        $promise->onResolve(function () use (&$resolved, $promise, $deferred, $watcher) {
            Loop::cancel($watcher);

            if ($resolved) {
                return;
            }

            $resolved = true;
            $deferred->resolve($promise);
        });

        return $deferred->promise();
    }

    /**
     * Adapts any object with a done(callable $onFulfilled, callable $onRejected) or then(callable $onFulfilled,
     * callable $onRejected) method to a promise usable by components depending on placeholders implementing
     * \AsyncInterop\Promise.
     *
     * @param object $promise Object with a done() or then() method.
     *
     * @return \Amp\Promise Promise resolved by the $thenable object.
     *
     * @throws \Error If the provided object does not have a then() method.
     */
    function adapt($promise): Promise {
        $deferred = new Deferred;

        if (\method_exists($promise, 'done')) {
            $promise->done([$deferred, 'resolve'], [$deferred, 'fail']);
        } elseif (\method_exists($promise, 'then')) {
            $promise->then([$deferred, 'resolve'], [$deferred, 'fail']);
        } else {
            throw new \Error("Object must have a 'then' or 'done' method");
        }

        return $deferred->promise();
    }

    /**
     * Returns a promise that is resolved when all promises are resolved. The returned promise will not fail.
     * Returned promise succeeds with a two-item array delineating successful and failed promise results,
     * with keys identical and corresponding to the original given array.
     *
     * This function is the same as some() with the notable exception that it will never fail even
     * if all promises in the array resolve unsuccessfully.
     *
     * @param Promise[] $promises
     *
     * @return \Amp\Promise
     *
     * @throws \Error If a non-Promise is in the array.
     */
    function any(array $promises): Promise {
        if (empty($promises)) {
            return new Success([[], []]);
        }

        $deferred = new Deferred;

        $pending = \count($promises);
        $errors = [];
        $values = [];

        foreach ($promises as $key => $promise) {
            if ($promise instanceof ReactPromise) {
                $promise = adapt($promise);
            } elseif (!$promise instanceof Promise) {
                throw new UnionTypeError([Promise::class, ReactPromise::class], $promise);
            }

            $promise->onResolve(function ($error, $value) use (&$pending, &$errors, &$values, $key, $deferred) {
                if ($error) {
                    $errors[$key] = $error;
                } else {
                    $values[$key] = $value;
                }

                if (--$pending === 0) {
                    $deferred->resolve([$errors, $values]);
                }
            });
        }
        return $deferred->promise();
    }

    /**
     * Returns a promise that succeeds when all promises succeed, and fails if any promise fails. Returned
     * promise succeeds with an array of values used to succeed each contained promise, with keys corresponding to
     * the array of promises.
     *
     * @param Promise[] $promises
     *
     * @return \Amp\Promise
     *
     * @throws \Error If a non-Promise is in the array.
     */
    function all(array $promises): Promise {
        if (empty($promises)) {
            return new Success([]);
        }

        $deferred = new Deferred;

        $pending = \count($promises);
        $resolved = false;
        $values = [];

        foreach ($promises as $key => $promise) {
            if ($promise instanceof ReactPromise) {
                $promise = adapt($promise);
            } elseif (!$promise instanceof Promise) {
                throw new UnionTypeError([Promise::class, ReactPromise::class], $promise);
            }

            $promise->onResolve(function ($exception, $value) use (&$values, &$pending, &$resolved, $key, $deferred) {
                if ($resolved) {
                    return;
                }

                if ($exception) {
                    $resolved = true;
                    $deferred->fail($exception);
                    return;
                }

                $values[$key] = $value;
                if (0 === --$pending) {
                    $deferred->resolve($values);
                }
            });
        }

        return $deferred->promise();
    }

    /**
     * Returns a promise that succeeds when the first promise succeeds, and fails only if all promises fail.
     *
     * @param Promise[] $promises
     *
     * @return \Amp\Promise
     *
     * @throws \Error If the array is empty or a non-Promise is in the array.
     */
    function first(array $promises): Promise {
        if (empty($promises)) {
            throw new \Error("No promises provided");
        }

        $deferred = new Deferred;

        $pending = \count($promises);
        $resolved = false;
        $exceptions = [];

        foreach ($promises as $key => $promise) {
            if ($promise instanceof ReactPromise) {
                $promise = adapt($promise);
            } elseif (!$promise instanceof Promise) {
                throw new UnionTypeError([Promise::class, ReactPromise::class], $promise);
            }

            $promise->onResolve(function ($exception, $value) use (&$exceptions, &$pending, &$resolved, $key, $deferred) {
                if ($resolved) {
                    return;
                }

                if (!$exception) {
                    $resolved = true;
                    $deferred->resolve($value);
                    return;
                }

                $exceptions[$key] = $exception;
                if (0 === --$pending) {
                    $deferred->fail(new MultiReasonException($exceptions));
                }
            });
        }

        return $deferred->promise();
    }

    /**
     * Resolves with a two-item array delineating successful and failed Promise results.
     *
     * The returned promise will only fail if ALL of the promises fail.
     *
     * @param Promise[] $promises
     *
     * @return \Amp\Promise
     */
    function some(array $promises): Promise {
        if (empty($promises)) {
            return new Success([[], []]);
        }

        $pending = \count($promises);

        $deferred = new Deferred;
        $values = [];
        $exceptions = [];

        foreach ($promises as $key => $promise) {
            if ($promise instanceof ReactPromise) {
                $promise = adapt($promise);
            } elseif (!$promise instanceof Promise) {
                throw new UnionTypeError([Promise::class, ReactPromise::class], $promise);
            }

            $promise->onResolve(function ($exception, $value) use (&$values, &$exceptions, &$pending, $key, $deferred) {
                if ($exception) {
                    $exceptions[$key] = $exception;
                } else {
                    $values[$key] = $value;
                }

                if (0 === --$pending) {
                    if (empty($values)) {
                        $deferred->fail(new MultiReasonException($exceptions));
                        return;
                    }

                    $deferred->resolve([$exceptions, $values]);
                }
            });
        }

        return $deferred->promise();
    }

    /**
     * Maps the callback to each promise as it succeeds. Returns an array of promises resolved by the return
     * callback value of the callback function. The callback may return promises or throw exceptions to fail
     * promises in the array. If a promise in the passed array fails, the callback will not be called and the
     * promise in the array fails for the same reason. Tip: Use all() or any() to determine when all
     * promises in the array have been resolved.
     *
     * @param callable (mixed $value): mixed $callback
     * @param Promise[] ...$promises
     *
     * @return \Amp\Promise[] Array of promises resolved with the result of the mapped function.
     */
    function map(callable $callback, array ...$promises): array {
        foreach ($promises as $promiseSet) {
            foreach ($promiseSet as $promise) {
                if (!$promise instanceof Promise && !$promise instanceof ReactPromise) {
                    throw new UnionTypeError([Promise::class, ReactPromise::class], $promise);
                }
            }
        }

        return \array_map(\Amp\lift($callback), ...$promises);
    }
}

namespace Amp\Stream {

    use Amp\Coroutine;
    use Amp\Emitter;
    use Amp\Listener;
    use Amp\Loop;
    use Amp\Producer;
    use Amp\Promise;
    use Amp\Stream;
    use Amp\UnionTypeError;

    /**
     * Creates a stream from the given iterable, emitting the each value. The iterable may contain promises. If any promise
     * fails, the stream will fail with the same reason.
     *
     * @param array|\Traversable $iterable
     *
     * @return \Amp\Stream
     *
     * @throws \TypeError If the argument is not an array or instance of \Traversable.
     */
    function fromIterable(/* iterable */ $iterable): Stream {
        if (!$iterable instanceof \Traversable && !\is_array($iterable)) {
            throw new UnionTypeError(["array", "Traversable"], $iterable);
        }

        return new Producer(function (callable $emit) use ($iterable) {
            foreach ($iterable as $value) {
                yield $emit($value);
            }
        });
    }

    /**
     * @param \Amp\Stream $stream
     * @param callable (mixed $value): mixed $onNext
     * @param callable (mixed $value): mixed|null $onComplete
     *
     * @return \Amp\Stream
     */
    function map(Stream $stream, callable $onNext, callable $onComplete = null): Stream {
        $listener = new Listener($stream);
        return new Producer(function (callable $emit) use ($listener, $onNext, $onComplete) {
            while (yield $listener->advance()) {
                yield $emit($onNext($listener->getCurrent()));
            }
            if ($onComplete === null) {
                return $listener->getResult();
            }
            return $onComplete($listener->getResult());
        });
    }

    /**
     * @param \Amp\Stream $stream
     * @param callable (mixed $value): bool $filter
     *
     * @return \Amp\Stream
     */
    function filter(Stream $stream, callable $filter): Stream {
        $listener = new Listener($stream);
        return new Producer(function (callable $emit) use ($listener, $filter) {
            while (yield $listener->advance()) {
                if ($filter($listener->getCurrent())) {
                    yield $emit($listener->getCurrent());
                }
            }
            return $listener->getResult();
        });
    }

    /**
     * Creates a stream that emits values emitted from any stream in the array of streams.
     *
     * @param \Amp\Stream[] $streams
     *
     * @return \Amp\Stream
     */
    function merge(array $streams): Stream {
        $emitter = new Emitter;
        $pending = true;

        foreach ($streams as $stream) {
            if (!$stream instanceof Stream) {
                throw new UnionTypeError([Stream::class], $stream);
            }
            $stream->listen(function ($value) use (&$pending, $emitter) {
                if ($pending) {
                    return $emitter->emit($value);
                }
                return null;
            });
        }

        Promise\all($streams)->onResolve(function ($exception, array $values = null) use (&$pending, $emitter) {
            $pending = false;

            if ($exception) {
                $emitter->fail($exception);
                return;
            }

            $emitter->resolve($values);
        });

        return $emitter->stream();
    }

    /**
     * Concatenates the given streams into a single stream, emitting values from a single stream at a time. The
     * prior stream must complete before values are emitted from any subsequent stream. Streams are concatenated
     * in the order given (iteration order of the array).
     *
     * @param array $streams
     *
     * @return \Amp\Stream
     */
    function concat(array $streams): Stream {
        foreach ($streams as $stream) {
            if (!$stream instanceof Stream) {
                throw new UnionTypeError([Stream::class], $stream);
            }
        }

        $emitter = new Emitter;
        $subscriptions = [];
        $previous = [];
        $promise = Promise\all($previous);

        foreach ($streams as $stream) {
            $generator = function ($value) use ($emitter, $promise) {
                static $pending = true, $failed = false;

                if ($failed) {
                    return;
                }

                if ($pending) {
                    try {
                        yield $promise;
                        $pending = false;
                    } catch (\Throwable $exception) {
                        $failed = true;
                        return; // Prior stream failed.
                    }
                }

                yield $emitter->emit($value);
            };
            $subscriptions[] = $stream->listen(function ($value) use ($generator) {
                return new Coroutine($generator($value));
            });
            $previous[] = $stream;
            $promise = Promise\all($previous);
        }

        $promise->onResolve(function ($exception, array $values = null) use ($emitter) {
            if ($exception) {
                $emitter->fail($exception);
                return;
            }

            $emitter->resolve($values);
        });

        return $emitter->stream();
    }

    /**
     * Returns a stream that emits a value every $interval milliseconds after (up to $count times). The value emitted
     * is an integer of the number of times the stream emitted a value.
     *
     * @param int $interval Time interval between emitted values in milliseconds.
     * @param int $count Number of values to emit. PHP_INT_MAX by default.
     *
     * @return \Amp\Stream
     *
     * @throws \Error If the number of times to emit is not a positive value.
     */
    function interval(int $interval, int $count = PHP_INT_MAX): Stream {
        if (0 >= $count) {
            throw new \Error("The number of times to emit must be a positive value");
        }

        $emitter = new Emitter;

        Loop::repeat($interval, function ($watcher) use (&$i, $emitter, $count) {
            $emitter->emit(++$i);

            if ($i === $count) {
                Loop::cancel($watcher);
                $emitter->resolve();
            }
        });

        return $emitter->stream();
    }
}
