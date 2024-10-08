<?php

namespace Darkterminal\TursoHttp\core;

use Darkterminal\TursoHttp\core\Http\LibSQLTypes;
use Darkterminal\TursoHttp\core\Response;
use Darkterminal\TursoHttp\core\Utils;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

class Request implements Response
{
    /**
     * Turso HTTP Request Payload
     *
     * @var array
     */
    protected $requestData;

    /**
     * Turso HTTP Response
     *
     * @var array|object
     */
    protected $response;

    private string $database;
    private string|null $token;
    protected array $bindings = [];
    private \Closure|null $before_hook = null;
    private \Closure|null $after_hook = null;

    public function __construct(string $database, string $token = null, \Closure|null $before_hook, \Closure|null $after_hook)
    {
        $this->requestData = [
            'requests' => [],
        ];
        $this->response = [];
        $this->database = $database;
        $this->token = $token;
        $this->before_hook = $before_hook;
        $this->after_hook = $after_hook;
    }

    public function setBaton(string $baton): void
    {
        $this->requestData['baton'] = $baton;
    }

    public function prepareRequest(string $query, array $parameters = [], bool $isTransaction = false)
    {
        if (Utils::isArrayAssoc($parameters)) {
            $i = 0;
            foreach ($parameters as $key => $value) {
                $type = LibSQLTypes::fromValue($value);
                $this->bindings['named_args'][$i]['name'] = str_replace([':', '@', '$'], '', $key);
                $this->bindings['named_args'][$i]["value"] = $type->bind($value);
                $i++;
            }
        } else {
            for ($i = 0; $i < count($parameters); $i++) {
                $type = LibSQLTypes::fromValue($parameters[$i]);
                $this->bindings['args'][] = $type->bind($parameters[$i]);
            }
        }
        $this->addRequest('execute', $query, $this->bindings);
        $this->bindings = [];
        if ($isTransaction === false) {
            $this->addRequest('close');
        }
        return $this;
    }

    public function closeRequest()
    {
        $this->addRequest('close');
        return $this;
    }

    /**
     * Build request query statement
     *
     * @param string $type request type is only valid with "execute" (execute a statement on the connection.) or "close" (close the connection.)
     * @param string $stmt raw sql query
     * @param array $args query arguments
     *
     * @return Request
     */
    public function addRequest(string $type, string $stmt = '', array $args = [])
    {
        if (!in_array($type, ['execute', 'close'])) {
            throw new \Exception("Invalid request type. The valid types is only: execute and close", 1);
        }

        $request = $type === 'close'
            ? ['type' => $type]
            : [
                'type' => $type,
                'stmt' => empty($args)
                    ? ['sql' => $stmt]
                    : array_merge(['sql' => $stmt], $args),
            ];

        $this->requestData['requests'][] = $request;
        return $this;
    }

    /**
     * Run query for Turso Database
     *
     * @return Request|LibSQLError
     */
    public function executeRequest()
    {
        try {
            $this->runBeforeHook();
            $this->logPipeline();
            $url = "{$this->database}/v2/pipeline";
            $this->response = Utils::makeRequest('POST', $url, $this->token, $this->requestData);
            $this->resetRequestData();
            return $this;
        } catch (\Exception $e) {
            throw new LibSQLError($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Return the full result database query in associative array
     *
     * @return array|string
     */
    public function get(): array|string
    {
        return $this->response;
    }

    /**
     * Return the full result database query in JSON
     *
     * @return void
     */
    public function toJSON(): string|array|null
    {
        return json_encode($this->response, true);
    }

    /**
     * Return only the baton (connection identifier)
     *
     * @return string|null
     */
    public function getBaton(): string|null
    {
        return $this->response['baton'] ?? null;
    }

    /**
     * Return only the base url
     *
     * @return void
     */
    public function getBaseUrl(): string|null
    {
        return $this->response['base_url'] ?? null;
    }

    /**
     * The results for each of the requests made in the pipeline.
     *
     * @return array
     */
    public function getResults(): array
    {
        return $this->response['results'] ?? null;
    }

    /**
     * The list of columns for the returned rows.
     *
     * @return array
     */
    public function getCols(): array
    {
        $results = $this->getResults();
        return $results['cols'] ?? null;
    }

    /**
     * The rows returned for the query.
     *
     * @return array
     */
    public function getRows(): array
    {
        $results = $this->getResults();
        return $results['rows'] ?? null;
    }

    /**
     * The number of rows affected by the query.
     *
     * @return integer|null
     */
    public function getAffectedRowCount(): int|null
    {
        $results = $this->getResults();
        return $results['affected_row_count'] ?? null;
    }

    /**
     * The ID of the last inserted row.
     *
     * @return integer|null
     */
    public function getLastInsertRowId(): int|null
    {
        $results = $this->getResults();
        return $results['last_insert_rowid'] ?? null;
    }

    /**
     * The replication timestamp at which this query was executed.
     *
     * @return string|null
     */
    public function getReplicationIndex(): string|null
    {
        $results = $this->getResults();
        return $results['replication_index'] ?? null;
    }

    /**
     * Reset the Request Data Value
     *
     * @return void
     */
    private function resetRequestData(): void
    {
        $this->requestData = ['requests' => []];
    }

    /**
     * Logs the pipeline request data if the libsql_log_debug parameter is set to true.
     *
     * The log file is located in the user's home directory in a .turso-http/logs directory.
     * The log file name is the value of the libsql_log_name parameter, or 'libsql_debug.log' if not set.
     *
     * @return void
     */
    private function logPipeline(): void
    {
        if (!empty(libsql_log_debug()) && libsql_log_debug() === 'true') {
            $log_name = libsql_log_name() ?? 'libsql_debug';
            $log_path = libsql_log_path() ?? Utils::getUserHomeDirectory() . DIRECTORY_SEPARATOR . '.turso-http' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . $log_name . '.log';
            Utils::createDirectoryAndFile($log_path);
            $log = new Logger($log_name);
            $log->pushHandler(new StreamHandler($log_path, Level::Debug));
            $log->debug('pipeline', $this->requestData);
        }
    }


    /**
     * Executes the before_hook function, if it exists and is not empty.
     *
     * @return void
     */
    private function runBeforeHook()
    {
        if (!empty($this->before_hook)) {
            call_user_func($this->before_hook);
        }
    }


    /**
     * Executes the after_hook function, if it exists and is not empty.
     *
     * @return void
     */
    private function runAfterHook()
    {
        if (!empty($this->after_hook)) {
            call_user_func($this->after_hook);
        }
    }

    public function __destruct()
    {
        $this->runAfterHook();
    }
}
