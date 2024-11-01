<?php

namespace Rakutentech\LaravelRequestDocs;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use KitLoong\AppLogger\QueryLog\LogWriter as QueryLogger;
use Log;
use Event;
use Str;

class LaravelRequestDocsMiddleware extends QueryLogger
{
    private array $queries = [];
    private array $logs = [];
    private array $models = [];

    public function handle($request, Closure $next)
    {
        if (!$request->headers->has('X-Request-LRD') || !config('app.debug')) {
            return $next($request);
        }

        if (!config('request-docs.hide_sql_data')) {
            $this->listenToDB();
        }
        if (!config('request-docs.hide_logs_data')) {
            $this->listenToLogs();
        }
        if (!config('request-docs.hide_models_data')) {
            $this->listenToModels();
        }

        $response = $next($request);

        try {
            $response->getData();
        } catch (\Exception $e) {
            // not a json response
            return $response;
        }

        $content = $response->getData();
        $content->_lrd = [
            'queries' => $this->queries,
            'logs' => $this->logs,
            'models' => $this->models,
            'memory' => (string) round(memory_get_peak_usage(true) / 1048576, 2) . "MB",
        ];
        $jsonContent = json_encode($content);

        if (in_array('gzip', $request->getEncodings()) && function_exists('gzencode')) {
            $level = 9; // best compression;
            $jsonContent = gzencode($jsonContent, $level);
            $response->headers->add([
                'Content-type' => 'application/json; charset=utf-8',
                'Content-Length'=> strlen($jsonContent),
                'Content-Encoding' => 'gzip',
            ]);
        }
        $response->setContent($jsonContent);
        return $response;
    }

    public function listenToDB()
    {
        DB::listen(function (QueryExecuted $query) {
            $this->queries[] = $this->getMessages($query);
        });
    }
    public function listenToLogs()
    {
        Log::listen(function ($message) {
            $this->logs[] = $message;
        });
    }

    public function listenToModels()
    {
        Event::listen('eloquent.*', function ($event, $models) {
            foreach (array_filter($models) as $model) {
                // doing and booted ignore
                if (Str::startsWith($event, 'eloquent.booting')
                || Str::startsWith($event, 'eloquent.retrieving')
                || Str::startsWith($event, 'eloquent.creating')
                || Str::startsWith($event, 'eloquent.saving')
                || Str::startsWith($event, 'eloquent.updating')
                || Str::startsWith($event, 'eloquent.deleting')
                ) {
                    continue;
                }
                // split $event by : and take first part
                $event = explode(':', $event)[0];
                $event = Str::replace('eloquent.', '', $event);
                $class = get_class($model);

                if (!isset($this->models[$class])) {
                    $this->models[$class] = [];
                }
                if (!isset($this->models[$class][$event])) {
                    $this->models[$class][$event] = 0;
                }
                $this->models[$class][$event] = $this->models[$class][$event]+1;
            }
        });
    }
}
