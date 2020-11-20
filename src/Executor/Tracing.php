<?php

namespace GraphQL\Executor;

use DateTimeImmutable;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\ResolveInfo;

class Tracing
{
    /**
     * The timestamp the request was initially started.
     */
    protected $requestStart;

    /**
     * The precise point in time where the request was initially started.
     *
     * This is either in seconds with microsecond precision (float) or nanoseconds (int).
     *
     * @var float|int
     */
    protected $requestStartPrecise;

    /**
     * Trace entries for a single query execution.
     *
     * Is reset between batches.
     *
     * @var array[]
     */
    protected $resolverTraces = [];
    protected $operations = [];

    /**
     * Handle request start.
     */
    public function handleStartRequest(): void
    {
        $this->requestStart = new DateTimeImmutable();
        $this->requestStartPrecise = $this->getTime();
    }

    /**
     * Handle batch request start.
     */
    public function handleStartExecution(): void
    {
        $this->resolverTraces = [];
        $this->operations = [];
    }

    /**
     * Record resolver execution time.
     *
     * @param float|int $start
     * @param float|int $end
     */
    public function record(ResolveInfo $resolveInfo, $start, $end): void
    {
        $operation_name = $resolveInfo->operation->name->value;
        if (!isset($this->operations[$operation_name])) {
            $this->operations[$operation_name] = $resolveInfo->operation->name->loc->source->body;
        }

        $this->resolverTraces [] = [
            'path' => $resolveInfo->path,
            'parent_type' => $resolveInfo->parentType->name,
            'type' => $resolveInfo->returnType->__toString(),
            'response_name' => $resolveInfo->fieldName,
            'start_time' => $this->diffTimeInNanoseconds($this->requestStartPrecise, $start),
            'end_time' => $this->diffTimeInNanoseconds($this->requestStartPrecise, $end),
            'operation' => $operation_name
        ];
    }

    /**
     * Get the system's highest resolution of time possible.
     *
     * This is either in seconds with microsecond precision (float) or nanoseconds (int).
     *
     * @return float|int
     */
    public function getTime()
    {
        return $this->platformSupportsNanoseconds()
            ? hrtime(true)
            : microtime(true);
    }

    public function getTraces()
    {
        return $this->resolverTraces;
    }

    public function getOperations()
    {
        return $this->operations;
    }

    public function getStartTime()
    {
        return $this->requestStartPrecise;
    }

    public function getStateTimeImmutable()
    {
        return $this->requestStart;
    }

    public function generateApolloOutput($hostname, $schemaTag, $executableSchemaId, $client_name, $client_version) {
        $traces = $this->getTraces();

        $traces_by_operation = [];
        foreach ($traces as $trace) {
            $op = $trace['operation'];
            if (!isset($traces_by_operation[$op])) {
                $traces_by_operation[$op] = [];
            }

            unset($trace['operation']);
            $traces_by_operation[$op][] = $trace;
        }

        $top_levels_by_operation = [];
        $i = 0;
        foreach ($traces_by_operation as $operation => $traces) {
            $i++;

            $indexedTraces = [];
            $top_levels = [];
            foreach ($traces as &$trace) {
                $path = $trace['path'];

                if (substr($trace['type'], 0, 1) == '[' && substr($trace['type'], -1) == ']') {
                    array_pop($trace['path']);
                }
                $indexedTraces[implode('||', $trace['path'])] = &$trace;

                if (count($path) == 1) {
                    $top_levels[] = &$trace;
                }
            }

            foreach ($traces as &$trace) {
                // Path is not needed in final output. Therefor cache it in $path and unset from original
                $path = $trace['path'];

                unset($trace['path']);
                array_pop($path);

                // Path looks like ['wells', 'edge', 1] for list items. So is_int checks for that.
                $last_element = end($path);
                if (is_int($last_element)) {
                    $nodeIndex = array_pop($path);
                    $parent = isset($indexedTraces[implode('||', $path)]) ? $indexedTraces[implode('||', $path)] : null;
                    if (!isset($parent['child'])) {
                        $parent['child'] = [];
                    }

                    $childAtIndex = isset($parent['child'][$nodeIndex]) ? $parent['child'][$nodeIndex] : null;
                    if (!$childAtIndex) {
                        $childAtIndex = [
                            'index' => $nodeIndex,
                            'child' => []
                        ];
                    }

                    $childAtIndex['child'][] = $trace;
                    $parent['child'][$nodeIndex] = $childAtIndex;
                    $indexedTraces[implode('||', $path)] = $parent;
                } else {
                    $parentTraceIndex = implode('||', $path);
                    //var_dump('parent:' . $parentTraceIndex);
                    $parent = isset($indexedTraces[$parentTraceIndex]) ? $indexedTraces[$parentTraceIndex] : null;
                    if ($parent) {
                        if (!isset($parent['child'])) {
                            $parent['child'] = [];
                        }

                        $parent['child'][] = $trace;
                        $indexedTraces[implode('||', $path)] = $parent;
                    }
                }
            }

            $top_levels_by_operation[$operation] = $traces;
        }

        $operationInfos = $this->getOperations();

        $traces_by_query = [];
        foreach ($top_levels_by_operation as $operation => $top_level) {
            $traces_by_query['# ' . $operation . '\\n' . $operationInfos[$operation]] = [
                'trace' => [
                    [
                        'clientName' => $client_name,
                        'clientVersion' => $client_version,
                        'durationNs' => $this->getTime() - $this->getStartTime(),
                        'endTime' => (new DateTimeImmutable())->format(DATE_RFC3339),
                        'http' => ['method' => "POST"],
                        'root' => ['child' => $top_levels],
                        'startTime' => $this->getStateTimeImmutable()->format(DATE_RFC3339)
                    ]
                ]
            ];
        }

        return [
            'header' => [
                "hostname" => $hostname,
                "schemaTag" => $schemaTag,
                "executableSchemaId" => $executableSchemaId
            ],
            'tracesPerQuery' => $traces_by_query
        ];
    }

    /**
     * Diff the time results to each other and convert to nanoseconds if needed.
     *
     * @param float|int $start
     * @param float|int $end
     */
    protected function diffTimeInNanoseconds($start, $end): int
    {
        if ($this->platformSupportsNanoseconds()) {
            return (int)($end - $start);
        }

        // Difference is in seconds (with microsecond precision)
        // * 1000 to get to milliseconds
        // * 1000 to get to microseconds
        // * 1000 to get to nanoseconds
        return (int)(($end - $start) * 1000 * 1000 * 1000);
    }

    /**
     * Test if the current PHP version has the `hrtime` function available to get a nanosecond precision point in time.
     */
    protected function platformSupportsNanoseconds(): bool
    {
        return function_exists('hrtime');
    }
}
