<?php

namespace Aerys;

use \Generator;


use Amp\{
    Struct,
    Reactor,
    Promise,
    Success,
    Failure,
    Deferred,
    function resolve,
    function timeout,
    function any,
    function all
};

class Server {
    use Struct;

    const STOPPED  = 0;
    const STARTING = 1;
    const STARTED  = 2;
    const STOPPING = 3;

    const PARSE = [
        "ERROR"  => 1,
        "RESULT" => 2,
        "ENTITY_HEADERS" => 3,
        "ENITTY_PART" => 4,
        "ENTITY_RESULT" => 5,
        "HTTP2_PREFACE" => 6,
    ];
    const HTTP2_PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
    const HTTP1_HEADER_REGEX = "(
        ([^()<>@,;:\\\"/[\]?={}\x01-\x20\x7F]+):[\x20\x09]*
        ([^\x01-\x08\x0A-\x1F\x7F]*)[\x0D]?[\x20\x09]*[\r]?[\n]
    )x";

    private $state = self::STOPPED;
    private $reactor;
    private $options;
    private $vhostContainer;
    private $logger;
    private $timeContext;
    private $observers;
    private $decrementer;
    private $acceptWatcherIds = [];
    private $boundServers = [];
    private $pendingTlsStreams = [];
    private $clients = [];
    private $clientCount = 0;
    private $keepAliveTimeouts = [];
    private $exporter;
    private $nullBody;
    private $stopPromisor;

    // private callables that we pass to external code //
    private $onAcceptable;
    private $negotiateCrypto;
    private $onReadable;
    private $onWritable;
    private $onParse;
    private $onCoroutineAppResolve;
    private $onCompletedResponse;
    private $startResponseCodec;
    private $genericResponseCodec;
    private $headResponseCodec;
    private $deflateResponseCodec;
    private $chunkResponseCodec;

    /**
     * @param \Amp\Reactor $reactor
     * @param \Aerys\Options $options
     * @param \Aerys\VhostContainer $vhostContainer
     * @param \Aerys\Logger $logger
     * @param \Aerys\TimeContext $timeContext
     */
    public function __construct(
        Reactor $reactor,
        Options $options,
        VhostContainer $vhostContainer,
        Logger $logger,
        TimeContext $timeContext
    ) {
        $this->reactor = $reactor;
        $this->options = $options;
        $this->vhostContainer = $vhostContainer;
        $this->logger = $logger;
        $this->timeContext = $timeContext;
        $this->observers = new \SplObjectStorage;
        $this->observers->attach($vhostContainer);
        $this->observers->attach($timeContext);
        $this->decrementer = function() {
            if ($this->clientCount) {
                $this->clientCount--;
            }
        };
        $this->timeContext->use($this->makePrivateCallable("timeoutKeepAlives"));
        $this->nullBody = new NullBody;
        $this->exporter = function(Reactor $reactor, string $watcherId, Client $client) {
            ($client->onUpgrade)($client->socket, $this->export($client));
        };

        // private callables that we pass to external code //
        $this->onAcceptable = $this->makePrivateCallable("onAcceptable");
        $this->negotiateCrypto = $this->makePrivateCallable("negotiateCrypto");
        $this->onReadable = $this->makePrivateCallable("onReadable");
        $this->onWritable = $this->makePrivateCallable("onWritable");
        $this->onParse = $this->makePrivateCallable("onParse");
        $this->onCoroutineAppResolve = $this->makePrivateCallable("onCoroutineAppResolve");
        $this->onCompletedResponse = $this->makePrivateCallable("onCompletedResponse");
        $this->startResponseCodec = $this->makePrivateCallable("startResponseCodec");
        $this->genericResponseCodec = $this->makePrivateCallable("genericResponseCodec");
        $this->headResponseCodec = $this->makePrivateCallable("headResponseCodec");
        $this->deflateResponseCodec = $this->makePrivateCallable("deflateResponseCodec");
        $this->chunkResponseCodec = $this->makePrivateCallable("chunkResponseCodec");
    }

    /**
     * Retrieve the current server state
     *
     * @return int
     */
    public function state(): int {
        return $this->state;
    }

    /**
     * Retrieve a server option value
     *
     * @param string $option The option to retrieve
     * @throws \DomainException on unknown option
     * @return mixed
     */
    public function getOption(string $option) {
        return $this->options->{$option};
    }

    /**
     * Attach a ServerObserver
     *
     * @param \Aerys\ServerObserver $observer
     * @return void
     */
    public function attach(ServerObserver $observer) {
        $this->observers->attach($observer);
    }

    /**
     * Detach a ServerObserver
     *
     * @param \Aerys\ServerObserver $observer
     * @return void
     */
    public function detach(ServerObserver $observer) {
        $this->observers->detach($observer);
    }

    /**
     * Notify observers of a server state change
     *
     * Resolves to an indexed any() promise combinator array.
     *
     * @return \Amp\Promise
     */
    public function notify(): Promise {
        $promises = [];
        foreach ($this->observers as $observer) {
            $promises[] = $observer->update($this);
        }

        $promise = any($promises);
        $promise->when(function($error, $result) {
            // $error is always empty because an any() combinator promise never fails.
            // Instead we check the error array at index zero in the two-item amy() $result
            // and log as needed.
            list($observerErrors) = $result;
            foreach ($observerErrors as $error) {
                $this->logger->error($error);
            }
        });

        return $promise;
    }

    /**
     * Start the server
     *
     * @return \Amp\Promise
     */
    public function start(): Promise {
        try {
            switch ($this->state) {
                case self::STOPPED:
                    if ($this->vhostContainer->count() === 0) {
                        return new Failure(new \LogicException(
                            "Cannot start: no virtual hosts registered in composed VhostContainer"
                        ));
                    }
                    return resolve($this->doStart(), $this->reactor);
                case self::STARTING:
                    return new Failure(new \LogicException(
                        "Cannot start server: already STARTING"
                    ));
                case self::STARTED:
                    return new Failure(new \LogicException(
                        "Cannot start server: already STARTED"
                    ));
                case self::STOPPING:
                    return new Failure(new \LogicException(
                        "Cannot start server: already STOPPING"
                    ));
                default:
                    return new Failure(new \LogicException(
                        sprintf("Unexpected server state encountered: %s", $this->state)
                    ));
            }
        } catch (\BaseException $uncaught) {
            return new Failure($uncaught);
        }
    }

    private function doStart(): Generator {
        assert($this->logDebug("starting"));

        $addrCtxMap = $this->generateBindableAddressContextMap();
        foreach ($addrCtxMap as $address => $context) {
            $serverName = substr(str_replace('0.0.0.0', '*', $address), 6);
            $this->boundServers[$serverName] = $this->bind($address, $context);
        }

        $this->state = self::STARTING;
        $notifyResult = yield $this->notify();
        if ($hadErrors = (bool)$notifyResult[0]) {
            yield from $this->doStop();
            throw new \RuntimeException(
                "Server::STARTING observer initialization failure"
            );
        }

        $this->state = self::STARTED;

        $notifyResult = yield $this->notify();
        if ($hadErrors = $notifyResult[0]) {
            yield from $this->doStop();
            throw new \RuntimeException(
                "Server::STARTED observer initialization failure"
            );
        }

        assert($this->logDebug("started"));

        foreach ($this->boundServers as $serverName => $server) {
            $this->acceptWatcherIds[$serverName] = $this->reactor->onReadable($server, $this->onAcceptable);
            $this->logger->info("Listening on {$serverName}");
        }
    }

    private function generateBindableAddressContextMap() {
        $addrCtxMap = [];
        $addresses = $this->vhostContainer->getBindableAddresses();
        $tlsBindings = $this->vhostContainer->getTlsBindingsByAddress();
        $backlogSize = $this->options->socketBacklogSize;
        $shouldReusePort = empty($this->options->debug);

        foreach ($addresses as $address) {
            $context = stream_context_create(["socket" => [
                "backlog"      => $backlogSize,
                "so_reuseport" => $shouldReusePort,
            ]]);
            if (isset($tlsBindings[$address])) {
                stream_context_set_option($context, ["ssl" => $tlsBindings[$address]]);
            }
            $addrCtxMap[$address] = $context;
        }

        return $addrCtxMap;
    }

    private function bind(string $address, $context) {
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        if (!$socket = stream_socket_server($address, $errno, $errstr, $flags, $context)) {
            throw new \RuntimeException(
                sprintf(
                    "Failed binding socket on %s: [Err# %s] %s",
                    $address,
                    $errno,
                    $errstr
                )
            );
        }

        return $socket;
    }

    private function onAcceptable(Reactor $reactor, string $watcherId, $server) {
        if (!$client = @stream_socket_accept($server, $timeout = 0, $peerName)) {
            return;
        }

        if ($this->clientCount++ === $this->options->maxConnections) {
            assert($this->logDebug("client denied: too many existing connections"));
            $this->clientCount--;
            @fclose($client);
            return;
        }

        assert($this->logDebug("accept {$peerName}"));

        stream_set_blocking($client, false);
        $contextOptions = stream_context_get_options($client);
        if (isset($contextOptions["ssl"])) {
            $clientId = (int) $client;
            $watcherId = $this->reactor->onReadable($client, $this->negotiateCrypto, $options = [
                "cb_data" => $peerName,
            ]);
            $this->pendingTlsStreams[$clientId] = [$watcherId, $client];
        } else {
            $this->importClient($client, $peerName, $isHttp2 = false);
        }
    }

    private function negotiateCrypto(Reactor $reactor, string $watcherId, $socket, string $peerName) {
        if ($handshake = @stream_socket_enable_crypto($socket, true)) {
            $socketId = (int) $socket;
            $reactor->cancel($watcherId);
            unset($this->pendingTlsStreams[$socketId]);
            $meta = stream_get_meta_data($socket)["crypto"];
            $isH2 = (isset($meta["alpn_protocol"]) && $meta["alpn_protocol"] === "h2");
            assert($this->logDebug(sprintf("crypto negotiated %s%s", ($isH2 ? "(h2) " : ""), $peerName)));
            $this->importClient($socket, $peerName, $isH2);
        } elseif ($handshake === false) {
            assert($this->logDebug("crypto handshake error {$peerName}"));
            $this->failCryptoNegotiation($socket);
        }
    }

    private function failCryptoNegotiation($socket) {
        $this->clientCount--;
        $socketId = (int) $socket;
        list($watcherId) = $this->pendingTlsStreams[$socketId];
        $this->reactor->cancel($watcherId);
        unset($this->pendingTlsStreams[$socketId]);
        @fclose($socket);
    }

    /**
     * Stop the server
     *
     * @return \Amp\Promise
     */
    public function stop(): Promise {
        switch ($this->state) {
            case self::STARTED:
                $stopPromise = resolve($this->doStop(), $this->reactor);
                return timeout($stopPromise, $this->options->shutdownTimeout, $this->reactor);
            case self::STOPPED:
                return new Success;
            case self::STOPPING:
                return new Failure(new \LogicException(
                    "Cannot stop server: currently STOPPING"
                ));
            case self::STARTING:
                return new Failure(new \LogicException(
                    "Cannot stop server: currently STARTING"
                ));
            default:
                return new Success;
        }
    }

    private function doStop(): Generator {
        assert($this->logDebug("stopping"));
        $this->state = self::STOPPING;

        foreach ($this->acceptWatcherIds as $watcherId) {
            $this->reactor->cancel($watcherId);
        }
        $this->boundServers = [];
        $this->acceptWatcherIds = [];
        foreach ($this->pendingTlsStreams as list(, $socket)) {
            $this->failCryptoNegotiation($socket);
        }

        $this->stopPromisor = new Deferred;
        if (empty($this->clients)) {
            $this->stopPromisor->succeed();
        } else {
            foreach ($this->clients as $client) {
                if (empty($client->requestCycles)) {
                    $this->close($client);
                }
            }
        }

        yield all([$this->stopPromisor->promise(), $this->notify()]);

        assert($this->logDebug("stopped"));
        $this->state = self::STOPPED;
        $this->stopPromisor = null;

        yield $this->notify();
    }

    private function importClient($socket, string $peerName, bool $isHttp2) {
        $client = new Client;
        $client->id = (int) $socket;
        $client->socket = $socket;
        $client->isHttp2 = $isHttp2;
        $client->requestsRemaining = $this->options->maxRequests;

        $portStartPos = strrpos($peerName, ":");
        $client->clientAddr = substr($peerName, 0, $portStartPos);
        $client->clientPort = substr($peerName, $portStartPos + 1);

        $serverName = stream_socket_get_name($socket, false);
        $portStartPos = strrpos($serverName, ":");
        $client->serverAddr = substr($serverName, 0, $portStartPos);
        $client->serverPort = substr($serverName, $portStartPos + 1);

        $meta = stream_get_meta_data($socket);
        $client->cryptoInfo = $meta["crypto"] ?? [];
        $client->isEncrypted = (bool) $client->cryptoInfo;

        // @TODO Use different parser for h2 connections
        $parser = [$this, "h1Parser"];
        $client->requestParser = \call_user_func($parser, $this->onParse, $options = [
            "max_body_size" => $this->options->maxBodySize,
            "max_header_size" => $this->options->maxHeaderSize,
            "body_emit_size" => $this->options->ioGranularity,
            "cb_data" => $client
        ]);
        $client->readWatcher = $this->reactor->onReadable($socket, $this->onReadable, $options = [
            "enable" => true,
            "cb_data" => $client,
        ]);
        $client->writeWatcher = $this->reactor->onWritable($socket, $this->onWritable, $options = [
            "enable" => false,
            "cb_data" => $client,
        ]);

        $this->renewKeepAliveTimeout($client);

        $this->clients[$client->id] = $client;
    }

    private function onWritable(Reactor $reactor, string $watcherId, $socket, $client) {
        $bytesWritten = @fwrite($socket, $client->writeBuffer);
        if ($bytesWritten === false) {
            if (!is_resource($socket) || @feof($socket)) {
                $client->isDead = true;
                $this->close($client);
            }
        } elseif ($bytesWritten === strlen($client->writeBuffer)) {
            $client->writeBuffer = "";
            $reactor->disable($watcherId);
            if ($client->onWriteDrain) {
                ($client->onWriteDrain)($client);
            }
        } else {
            $client->writeBuffer = substr($client->writeBuffer, $bytesWritten);
            $reactor->enable($watcherId);
        }
    }

    private function timeoutKeepAlives(int $now) {
        foreach ($this->keepAliveTimeouts as $id => $expiresAt) {
            if ($now > $expiresAt) {
                $this->close($this->clients[$id]);
            } else {
                break;
            }
        }
    }

    private function renewKeepAliveTimeout(Client $client) {
        $timeoutAt = $this->timeContext->currentTime + $this->options->keepAliveTimeout;
        // DO NOT remove the call to unset(); it looks superfluous but it's not.
        // Keep-alive timeout entries must be ordered by value. This means that
        // it's not enough to replace the existing map entry -- we have to remove
        // it completely and push it back onto the end of the array to maintain the
        // correct order.
        unset($this->keepAliveTimeouts[$client->id]);
        $this->keepAliveTimeouts[$client->id] = $timeoutAt;
    }

    private function clearKeepAliveTimeout(Client $client) {
        unset($this->keepAliveTimeouts[$client->id]);
    }

    private function onReadable(Reactor $reactor, string $watcherId, $socket, $client) {
        $data = @fread($socket, $this->options->ioGranularity);
        if ($data == "") {
            if (!is_resource($socket) || @feof($socket)) {
                $client->isDead = true;
                $this->close($client);
            }
            return;
        }

        // We only renew the keep-alive timeout if the client isn't waiting
        // for us to generate a response.
        if (!($client->requestCycles || $client->currentRequestCycle)) {
            $this->renewKeepAliveTimeout($client);
        }

        $client->requestParser->send($data);
    }

    private function onParse(array $parseStruct, $client) {
        list($eventType, $parseResult, $errorStruct) = $parseStruct;
        switch ($eventType) {
            case self::PARSE["RESULT"]:
                $this->onParsedMessageWithoutEntity($client, $parseResult);
                break;
            case self::PARSE["ENTITY_HEADERS"]:
                $this->onParsedEntityHeaders($client, $parseResult);
                break;
            case self::PARSE["ENTITY_PART"]:
                $this->onParsedEntityPart($client, $parseResult);
                break;
            case self::PARSE["ENTITY_RESULT"]:
                $this->onParsedMessageWithEntity($client, $parseResult);
                break;
            case self::PARSE["ERROR"]:
                $this->onParseError($client, $parseResult, $errorStruct);
                break;
            /*
            // @TODO
            case self::PARSE["HTTP2_PREFACE"]:
                $initBuffer = $parseResult;
                $this->transitionToHttp2c($client, $initBuffer);
                break;
            */
            default:
                assert(false, "Unexpected parser result code encountered");
        }
    }

    private function onParsedMessageWithoutEntity(Client $client, array $parseResult) {
        $requestCycle = $this->initializeRequestCycle($client, $parseResult);
        $this->clearKeepAliveTimeout($client);

        // @TODO Update $canRespondNow for HTTP/2.0 where we don't have to respond in order
        $canRespondNow = empty($client->requestCycles);

        // @TODO Handle h2c HTTP/2.0 upgrade responses here.

        if ($canRespondNow) {
            $this->respond($requestCycle);
        } else {
            $client->requestCycles[] = $requestCycle;
        }
    }

    private function onParsedEntityPart(Client $client, array $parseResult) {
        $client->currentRequestCycle->bodyPromisor->update($parseResult["body"]);
    }

    private function onParsedEntityHeaders(Client $client, array $parseResult) {
        $requestCycle = $this->initializeRequestCycle($client, $parseResult);
        $requestCycle->bodyPromisor = new Deferred;
        $requestCycle->internalRequest->body = new StreamBody($requestCycle->bodyPromisor->promise());
        $this->clearKeepAliveTimeout($client);

        // @TODO Update $canRespondNow for HTTP/2.0 where we don't have to respond in order
        $canRespondNow = empty($client->requestCycles);

        // @TODO Handle h2c HTTP/2.0 upgrade responses here.

        if ($canRespondNow) {
            $this->respond($requestCycle);
        } else {
            $client->requestCycles[] = $requestCycle;
        }
    }

    private function onParsedMessageWithEntity(Client $client, array $parseResult) {
        $client->currentRequestCycle->bodyPromisor->succeed();
        $client->currentRequestCycle->bodyPromisor = null;
        // @TODO Update trailer headers if present

        // Don't respond() because we always start the response when headers arrive

        // @TODO If receiving body as part of an h2c HTTP/2.0 upgrade we need to
        // complete the transition to the h2parser here now that the full body has
        // been received.
    }

    private function onParseError(Client $client, array $parseResult, string $error) {
        // @TODO how to handle parse error with entity body after request cycle already started?

        $this->clearKeepAliveTimeout($client);
        $requestCycle = $this->initializeRequestCycle($client, $parseResult);
        $requestCycle->preAppResponder = function(Request $request, Response $response) use ($error) {
            $response->setStatus(HTTP_STATUS["BAD_REQUEST"]);
            $response->setHeader("Connection", "close");
            $response->end($this->makeGenericBody($code, null, $error));
        };

        // @TODO Update $canRespondNow for HTTP/2.0 where we don't have to respond in order
        $canRespondNow = empty($client->requestCycles);

        if ($canRespondNow) {
            $this->respond($requestCycle);
        } else {
            $client->requestCycles[] = $requestCycle;
        }
    }

    private function initializeRequestCycle(Client $client, array $parseResult): RequestCycle {
        $requestCycle = new RequestCycle;
        $requestCycle->client = $client;
        $client->requestsRemaining--;
        $client->currentRequestCycle = $requestCycle;

        $trace = $parseResult["trace"];
        $protocol = empty($parseResult["protocol"]) ? "1.0" : $parseResult["protocol"];
        $method = empty($parseResult["method"]) ? "GET" : $parseResult["method"];
        if ($this->options->normalizeMethodCase) {
            $method = strtoupper($method);
        }
        $uri = empty($parseResult["uri"]) ? "/" : $parseResult["uri"];
        $headers = empty($parseResult["headers"]) ? [] : $parseResult["headers"];

        assert($this->logDebug(sprintf(
            "%s %s HTTP/%s @ %s:%s",
            $method,
            $uri,
            $protocol,
            $client->clientAddr,
            $client->clientPort
        )));

        $ireq = $requestCycle->internalRequest = new InternalRequest;
        $ireq->time = $this->timeContext->currentTime;
        $ireq->debug = $this->options->debug;
        $ireq->locals = [];
        $ireq->remaining = $client->requestsRemaining;
        $ireq->isEncrypted = $client->isEncrypted;
        $ireq->cryptoInfo = $client->cryptoInfo;
        $ireq->trace = $trace;
        $ireq->protocol = $protocol;
        $ireq->method = $method;
        $ireq->headers = $headers;
        $ireq->body = $this->nullBody;
        $ireq->serverPort = $client->serverPort;
        $ireq->serverAddr = $client->serverAddr;
        $ireq->clientPort = $client->clientPort;
        $ireq->clientAddr = $client->clientAddr;
        $ireq->uriRaw = $uri;
        if (stripos($uri, "http://") === 0 || stripos($uri, "https://") === 0) {
            extract(parse_url($uri, EXTR_PREFIX_ALL, "uri"));
            $ireq->uriHost = $uri_host;
            $ireq->uriPort = $uri_port;
            $ireq->uriPath = $uri_path;
            $ireq->uriQuery = $uri_query;
            $ireq->uri = isset($uri_query) ? "{$uri_path}?{$uri_query}" : $uri_path;
        } elseif ($qPos = strpos($uri, '?')) {
            $ireq->uriQuery = substr($uri, $qPos + 1);
            $ireq->uriPath = substr($uri, 0, $qPos);
            $ireq->uri = "{$ireq->uriPath}?{$ireq->uriQuery}";
        } else {
            $ireq->uri = $ireq->uriPath = $uri;
        }

        if (!($protocol === "1.0" || $protocol === "1.1" || $protocol === "2.0")) {
            $requestCycle->preAppResponder = [$this, "sendPreAppVersionNotSupportedResponse"];
        }

        if (!$vhost = $this->vhostContainer->selectHost($ireq)) {
            $vhost = $this->vhostContainer->getDefaultHost();
            $requestCycle->preAppResponder = [$this, "sendPreAppInvalidHostResponse"];
        }

        $requestCycle->vhost = $vhost;

        if (!($client->isHttp2 || $client->isEncrypted)) {
            $h2cUpgrade = $headers["upgrade"][0] ?? "";
            $h2cSettings = $headers["http2-settings"][0] ?? "";
            if ($h2cUpgrade && $h2cSettings && strcasecmp($h2cUpgrade, "h2c") === 0) {
                // @TODO
            }
        }

        // @TODO Handle 100 Continue responses
        // $expectsContinue = empty($headers["expect"]) ? false : stristr($headers["expect"], "100-continue");

        return $requestCycle;
    }

    private function respond(RequestCycle $requestCycle) {
        if ($requestCycle->preAppResponder) {
            $application = $requestCycle->preAppResponder;
        } elseif ($this->stopPromisor) {
            $application = [$this, "sendPreAppServiceUnavailableResponse"];
        } elseif (!in_array($requestCycle->internalRequest->method, $this->options->allowedMethods)) {
            $application = [$this, "sendPreAppMethodNotAllowedResponse"];
        } elseif ($requestCycle->internalRequest->method === "TRACE") {
            $application = [$this, "sendPreAppTraceResponse"];
        } elseif ($requestCycle->internalRequest->method === "OPTIONS" && $uri->raw === "*") {
            $application = [$this, "sendPreAppOptionsResponse"];
        } else {
            $application = $requestCycle->vhost->getApplication();
        }

        $this->tryApplication($requestCycle, $application);
    }

    private function sendPreAppVersionNotSupportedResponse(Request $request, Response $response) {
        $status = HTTP_STATUS["HTTP_VERSION_NOT_SUPPORTED"];
        $response->setStatus($status);
        $response->setHeader("Connection", "close");
        $response->end($body = $this->makeGenericBody($status));
    }

    private function sendPreAppServiceUnavailableResponse(Request $request, Response $response) {
        $status = HTTP_STATUS["SERVICE_UNAVAILABLE"];
        $response->setStatus($status);
        $response->setHeader("Connection", "close");
        $response->end($body = $this->makeGenericBody($status));
    }

    private function sendPreAppInvalidHostResponse(Request $request, Response $response) {
        $status = HTTP_STATUS["BAD_REQUEST"];
        $response->setStatus($status);
        $response->setReason("Bad Request: Invalid Host");
        $response->setHeader("Connection", "close");
        $response->end($this->makeGenericBody($status));
    }

    private function sendPreAppMethodNotAllowedResponse(Request $request, Response $response) {
        $status = HTTP_STATUS["METHOD_NOT_ALLOWED"];
        $response->setStatus($status);
        $response->setHeader("Connection", "close");
        $response->setHeader("Allow", implode(",", $this->options->allowedMethods));
        $response->end($body = $this->makeGenericBody($status));
    }

    private function sendPreAppTraceResponse(Request $request, Response $response) {
        $response->setStatus(HTTP_STATUS["OK"]);
        $response->setHeader("Content-Type", "message/http");
        $response->end($body = $ireq->trace);
    }

    private function sendPreAppOptionsResponse(Request $request, Response $response) {
        $response->setStatus(HTTP_STATUS["OK"]);
        $response->setHeader("Allow", implode(",", $this->options->allowedMethods));
        $response->end($body = null);
    }

    private function onCompletedResponse(Client $client) {
        if ($client->onUpgrade) {
            $this->reactor->immediately($this->exporter, $options = ["cb_data" => $client]);
        } elseif ($client->shouldClose) {
            $this->close($client);
        } elseif ($client->requestCycles) {
            $requestCycle = array_shift($client->requestCycles);
            $this->respond($requestCycle);
        } else {
            // @TODO we need a flag to know if we're awaiting data for the
            //       currentRequestCycle before renewing this timeout.
            $this->renewKeepAliveTimeout($client);
        }
    }

    private function tryApplication(RequestCycle $requestCycle, callable $application) {
        try {
            $response = $this->initResponse($requestCycle);
            $request = new StandardRequest($requestCycle->internalRequest);

            $out = ($application)($request, $response);
            if ($out instanceof Generator) {
                $promise = resolve($out, $this->reactor);
                $promise->when($this->onCoroutineAppResolve, $requestCycle);
            } elseif ($response->state() & Response::STARTED) {
                $response->end();
            } else {
                $status = HTTP_STATUS["NOT_FOUND"];
                $subHeading = "Requested: {$requestCycle->internalRequest->uri}";
                $response->setStatus($status);
                $response->end($this->makeGenericBody($status, $subHeading));
            }
        } catch (ClientException $error) {
            // Do nothing -- responder actions aren't required to catch this
        } catch (\BaseException $error) {
            $this->onApplicationError($error, $requestCycle);
        }
    }

    private function onCoroutineAppResolve($error, $result, $requestCycle) {
        if (empty($error)) {
            if ($requestCycle->client->isExported || $requestCycle->client->isDead) {
                return;
            } elseif ($requestCycle->response->state() & Response::STARTED) {
                $requestCycle->response->end();
            } else {
                $status = HTTP_STATUS["NOT_FOUND"];
                $subHeading = "Requested: {$requestCycle->internalRequest->uri}";
                $requestCycle->response->setStatus($status);
                $requestCycle->response->end($this->makeGenericBody($status, $subHeading));
            }
        } elseif (!$error instanceof ClientException) {
            // Ignore uncaught ClientException -- applications aren't required to catch this
            $this->onApplicationError($error, $requestCycle);
        }
    }

    private function onApplicationError(\BaseException $error, RequestCycle $requestCycle) {
        $client = $requestCycle->client;
        $this->logger->error($error);

        if ($client->isDead || $client->isExported) {
            // Responder actions may catch the initial ClientException and continue
            // doing further work. If an error arises at this point we can end up
            // here and our only option is to log the error.
            return;
        }

        // If response output has already started we can't proceed any further.
        if ($requestCycle->response->state() & Response::STARTED) {
            $this->close($client);
            return;
        }

        if (!$error instanceof CodecException) {
            $this->sendErrorResponse($error, $requestCycle);
            return;
        }

        do {
            $requestCycle->badCodecKeys[] = $error->getCode();
            $this->initResponse($requestCycle);
            try {
                $this->sendErrorResponse($error, $requestCycle);
                return;
            } catch (CodecException $error) {
                // Keep trying until no broken codecs remain ...
                $this->logger->error($error);
            }
        } while (1);
    }

    private function sendErrorResponse(\BaseException $error, RequestCycle $requestCycle) {
        $status = HTTP_STATUS["INTERNAL_SERVER_ERROR"];
        $subHeading = "Requested: {$requestCycle->internalRequest->uri}";
        $msg = ($this->options->debug)
            ? $this->makeDebugMessage($error, $requestCycle->internalRequest)
            : "<p>Something went wrong ...</p>"
        ;
        $body = $this->makeGenericBody($status, $subHeading, $msg);
        $requestCycle->response->setStatus(HTTP_STATUS["INTERNAL_SERVER_ERROR"]);
        $requestCycle->response->setHeader("Connection", "close");
        $requestCycle->response->end($body);
    }

    private function makeDebugMessage(\BaseException $error, InternalRequest $ireq): string {
        $vars = [
            "debug"         => ($ireq->debug ? "true" : "false"),
            "isEncrypted"   => ($ireq->isEncrypted ? "true" : "false"),
            "serverAddr"    => $ireq->serverAddr,
            "serverPort"    => $ireq->serverPort,
            "clientAddr"    => $ireq->clientAddr,
            "clientPort"    => $ireq->clientPort,
            "method"        => $ireq->method,
            "protocol"      => $ireq->protocol,
            "uri"           => $ireq->uriRaw,
            "headers"       => $ireq->headers,
        ];

        $map = function($s) { return substr($s, 4); };
        $vars = implode("\n", array_map($map, array_slice(explode("\n", print_r($vars, true)), 2, -2)));

        $msg[] = "<pre>{$error}</pre>";
        $msg[] = "\n<hr/>\n";
        $msg[] = "<pre>{$vars}</pre>";
        $msg[] = "\n";

        return implode($msg);
    }

    private function makeGenericBody(int $status, string $subHeading = null, string $msg = null): string {
        $serverToken = $this->options->sendServerToken ? (SERVER_TOKEN . " @ ") : "";
        $reason = HTTP_REASON[$status] ?? "";
        $subHeading = isset($subHeading) ? "<h3>{$subHeading}</h3>" : "";
        $msg = isset($msg) ? "{$msg}\n" : "";

        return sprintf(
            "<html>\n<body>\n<h1>%d %s</h1>\n%s\n<hr/>\n<em>%s%s</em>\n<br/><br/>\n%s</body>\n</html>",
            $status,
            $reason,
            $subHeading,
            $serverToken,
            $this->timeContext->currentHttpDate,
            $msg
        );
    }

    private function close(Client $client) {
        $this->clear($client);
        if (!$client->isDead) {
            @fclose($client->socket);
            $client->isDead = true;
        }
        $this->clientCount--;
        assert($this->logDebug("close {$client->clientAddr}:{$client->clientPort}"));
    }

    private function clear(Client $client) {
        $client->onUpgrade = null;
        $client->requestParser = null;
        $client->onWriteDrain = null;
        $this->reactor->cancel($client->readWatcher);
        $this->reactor->cancel($client->writeWatcher);
        $this->clearKeepAliveTimeout($client);
        unset($this->clients[$client->id]);
        if ($this->stopPromisor && empty($this->clients)) {
            $this->stopPromisor->succeed();
        }
    }

    private function export(Client $client): \Closure {
        $client->isDead = true;
        $client->isExported = true;
        $this->clear($client);

        assert($this->logDebug("export {$client->clientAddr}:{$client->clientPort}"));

        return $this->decrementer;
    }

    private function startResponseCodec(InternalRequest $ireq): Generator {
        $headers = yield;
        $status = $headers[":status"];

        if ($this->options->sendServerToken) {
            $headers["server"] = [SERVER_TOKEN];
        }

        $contentLength = $headers[":aerys-entity-length"];
        unset($headers[":aerys-entity-length"]);

        if ($contentLength === "@") {
            $hasContent = false;
            $shouldClose = ($ireq->protocol === "1.0");
            if ($status >= 200 && $status != 204 && $status != 304) {
                $headers["content-length"] = ["0"];
            }
        } elseif ($contentLength !== "*") {
            $hasContent = true;
            $shouldClose = false;
            $headers["content-length"] = [$contentLength];
            unset($headers["transfer-encoding"]);
        } elseif ($ireq->protocol === "1.1") {
            $hasContent = true;
            $shouldClose = false;
            $headers["transfer-encoding"] = ["chunked"];
            unset($headers["content-length"]);
        } else {
            $hasContent = true;
            $shouldClose = true;
        }

        if ($hasContent && $status >= 200 && ($status < 300 || $status >= 400)) {
            $type = $headers["content-type"][0] ?? $this->options->defaultContentType;
            if (\stripos($type, "text/") === 0 && \stripos($type, "charset=") === false) {
                $type .= "; charset={$this->options->defaultTextCharset}";
            }
            $headers["content-type"] = [$type];
        }

        if ($shouldClose || $this->stopPromisor || $ireq->remaining === 0) {
            $headers["connection"] = ["close"];
        } else {
            $keepAlive = "timeout={$this->options->keepAliveTimeout}, max={$ireq->remaining}";
            $headers["keep-alive"] = [$keepAlive];
        }

        $headers["date"] = [$this->timeContext->currentHttpDate];

        return $headers;
    }

    public function genericResponseCodec(InternalRequest $ireq): Generator {
        $headers = yield;
        if (empty($headers["aerys-generic-response"])) {
            return;
        }

        $subHeading = "Requested: {$ireq->uri}";
        unset($headers["aerys-generic-response"]);
        unset($headers["transfer-encoding"]);
        $body = $this->makeGenericBody($headers[":status"], $subHeading);
        $headers["content-length"] = [strlen($body)];

        yield $headers;

        return $body;
    }

    public function headResponseCodec(InternalRequest $ireq): Generator {
        // Receive the headers in the first yield and immediately send them back.
        yield yield;

        // Send back null (need more data) for all received body data
        while (yield !== null);
    }

    public function deflateResponseCodec(InternalRequest $ireq): Generator {
        if (empty($ireq->headers["accept-encoding"])) {
            return;
        }

        foreach ($ireq->headers["accept-encoding"] as $value) {
            // @TODO Perform a more sophisticated check for gzip acceptance.
            // This check isn't technically correct as the gzip parameter
            // could have a q-value of zero indicating "never accept gzip."
            if (stripos($value, "gzip") !== false) {
                break;
            }
            return;
        }

        $headers = yield;
        if (empty($headers["content-type"])) {
            return;
        }

        // Require a text/* mime Content-Type
        // @TODO Allow option to configure which mime prefixes/types may be compressed
        if (stripos($headers["content-type"][0], "text/") !== 0) {
            return;
        }

        $minBodySize = $this->options->deflateMinimumLength;
        $contentLength = $headers["content-length"][0] ?? null;
        $bodyBuffer = "";

        if (!isset($contentLength)) {
            // Wait until we know there's enough stream data to compress before proceeding.
            // If we receive a FLUSH or an END signal before we have enough then we won't
            // use any compression.
            while (!isset($bodyBuffer[$minBodySize])) {
                $bodyBuffer .= ($tmp = yield);
                if ($tmp === false || $tmp === null) {
                    $bodyBuffer .= yield $headers;
                    return $bodyBuffer;
                }
            }
        } elseif (empty($contentLength) || $contentLength < $minBodySize) {
            // If the Content-Length is too small we can't compress it.
            return $headers;
        }

        // @TODO We have the ability to support DEFLATE and RAW encoding as well. Should we?
        $mode = \ZLIB_ENCODING_GZIP;
        if (($resource = \deflate_init($mode)) === false) {
            $bodyBuffer .= yield $headers;
            return $bodyBuffer;
        }

        // Once we decide to compress output we no longer know what the
        // final Content-Length will be. We need to update our headers
        // according to the HTTP protocol in use to reflect this.
        unset($headers["content-length"]);
        if ($ireq->protocol === "1.1") {
            $headers["transfer-encoding"] = ["chunked"];
        } else {
            $headers["connection"] = ["close"];
        }
        $headers["content-encoding"] = ["gzip"];

        $minFlushOffset = $this->options->deflateBufferSize;

        $deflated = $headers;

        while (($uncompressed = yield $deflated) !== null) {
            $bodyBuffer .= $uncompressed;
            if ($uncompressed === false) {
                if ($bodyBuffer === "") {
                    // If we don't have any buffered data there's nothing to flush
                    $deflated = null;
                } elseif (($deflated = \deflate_add($resource, $bodyBuffer, \ZLIB_SYNC_FLUSH)) === false) {
                    // throw
                } else {
                    $bodyBuffer = "";
                }
            } elseif (!isset($bodyBuffer[$minFlushOffset])) {
                $deflated = null;
            } elseif (($deflated = \deflate_add($resource, $bodyBuffer)) === false) {
                // throw
            } else {
                $bodyBuffer = "";
            }
        }

        if (($deflated = \deflate_add($resource, $bodyBuffer, \ZLIB_FINISH)) === false) {
            // throw
        }

        return $deflated;
    }

    public function chunkResponseCodec(InternalRequest $ireq): Generator {
        $headers = yield;
        if (empty($headers["transfer-encoding"])) {
            return;
        }
        if (!in_array("chunked", $headers["transfer-encoding"])) {
            return;
        }

        $bodyBuffer = "";
        $bufferSize = $this->options->chunkBufferSize;
        $unchunked = yield $headers;

        do {
            $bodyBuffer .= $unchunked;
            if (isset($bodyBuffer[$bufferSize]) || ($unchunked === false && $bodyBuffer != "")) {
                $chunk = \dechex(\strlen($bodyBuffer)) . "\r\n{$bodyBuffer}\r\n";
                $bodyBuffer = "";
            } else {
                $chunk = null;
            }
        } while (($unchunked = yield $chunk) !== null);

        $chunk = ($bodyBuffer != "")
            ? (\dechex(\strlen($bodyBuffer)) . "\r\n{$bodyBuffer}\r\n0\r\n\r\n")
            : "0\r\n\r\n"
        ;

        return $chunk;
    }

    private function h1ResponseWriter(RequestCycle $requestCycle): Generator {
        $headers = yield;

        $client = $requestCycle->client;
        $protocol = $requestCycle->internalRequest->protocol;

        if (!empty($headers["connection"])) {
            foreach ($headers["connection"] as $connection) {
                if (\strcasecmp($connection, "close") === 0) {
                    $client->shouldClose = true;
                }
            }
        }

        // @TODO change the protocol upgrade mechanism ... it's garbage as currently implemented
        if ($client->shouldClose || $headers[":status"] !== "101") {
            $client->onUpgrade = null;
        } else {
            $client->onUpgrade = $headers[":on-upgrade"] ?? null;
        }

        $lines = ["HTTP/{$protocol} {$headers[":status"]} {$headers[":reason"]}"];
        unset($headers[":status"], $headers[":reason"]);
        foreach ($headers as $headerField => $headerLines) {
            if ($headerField[0] !== ":") {
                foreach ($headerLines as $headerLine) {
                    $lines[] = "{$headerField}: {$headerLine}";
                }
            }
        }
        $lines[] = "\r\n";
        $msgPart = \implode("\r\n", $lines);
        $bufferSize = 0;

        do {
            if ($client->isDead) {
                throw new ClientException;
            }

            $buffer[] = $msgPart;
            $bufferSize += \strlen($msgPart);

            if (($msgPart === false || $bufferSize > $this->options->outputBufferSize)) {
                $client->writeBuffer .= \implode("", $buffer);
                $buffer = [];
                $bufferSize = 0;
                $this->onWritable($this->reactor, $client->writeWatcher, $client->socket, $client);
            }
        } while (($msgPart = yield) !== null);

        if ($bufferSize) {
            $client->writeBuffer .= \implode("", $buffer);
            $buffer = [];
            $bufferSize = 0;
        }

        if ($client->writeBuffer == "") {
            $this->onCompletedResponse($client);
        } else {
            $client->onWriteDrain = $this->onCompletedResponse;
            $this->onWritable($this->reactor, $client->writeWatcher, $client->socket, $client);
        }
    }

    private function initResponse(RequestCycle $requestCycle): Response {
        $codecs = [
            $this->startResponseCodec,
            $this->genericResponseCodec,
        ];

        if ($userCodecs = $requestCycle->vhost->getCodecs()) {
            $codecs = array_merge($codecs, array_values($userCodecs));
        }
        if ($requestCycle->internalRequest->method === "HEAD") {
            $codecs[] = $this->headResponseCodec;
        }
        if ($this->options->deflateEnable) {
            $codecs[] = $this->deflateResponseCodec;
        }
        if ($requestCycle->internalRequest->protocol === "1.1") {
            $codecs[] = $this->chunkResponseCodec;
        }
        if ($requestCycle->badCodecKeys) {
            $codecs = array_diff_key($codecs, array_flip($requestCycle->badCodecKeys));
        }

        // @TODO Use a different sink for HTTP/2.0 when $protocol === "2.0"
        $sink = $this->h1ResponseWriter($requestCycle);
        $init = $requestCycle->internalRequest;
        $codec = self::responseCodec($sink, $codecs, $init);

        return $requestCycle->response = new StandardResponse($codec);
    }

    public static function responseCodec(Generator $sink, array $codecs, $init = null): Generator {
        try {
            $generators = [];
            foreach ($codecs as $key => $codec) {
                $out = $codec($init);
                if ($out instanceof Generator) {
                    $generators[$key] = $out;
                }
            }
            $codecs = $generators;

            $headers = yield;
            $isEnding = false;
            $isFlushing = false;

            foreach ($codecs as $key => $codec) {
                $yielded = $codec->send($headers);
                if (!isset($yielded)) {
                    if (!$codec->valid()) {
                        $yielded = $codec->getReturn();
                        if (!isset($yielded)) {
                            // no header modification -- continue
                        } elseif (is_array($yielded)) {
                            assert(self::validateCodecHeaders($codec, $yielded));
                            $headers = $yielded;
                        } else {
                            $type = is_object($yielded) ? get_class($yielded) : gettype($yielded);
                            throw new \DomainException(self::makeCodecErrorMessage(
                                "Codec error; header array required but {$type} returned",
                                $codec
                            ));
                        }
                        unset($codecs[$key]);
                        continue;
                    }

                    while (1) {
                        if ($isEnding) {
                            $toSend = null;
                        } elseif ($isFlushing) {
                            $toSend = false;
                        } else {
                            $toSend = yield;
                            if (!isset($toSend)) {
                                $isEnding = true;
                            } elseif ($toSend === false) {
                                $isFlushing = true;
                            }
                        }

                        $yielded = $codec->send($toSend);
                        if (!isset($yielded)) {
                            if ($isEnding || $isFlushing) {
                                if ($codec->valid()) {
                                    $signal = isset($toSend) ? "FLUSH" : "END";
                                    throw new \DomainException(self::makeCodecErrorMessage(
                                        "Codec error; header array required from {$signal} signal but null yielded",
                                        $codec
                                    ));
                                } else {
                                    // this is always an error because the two-stage codec
                                    // process means any codec receiving non-header data
                                    // must participate in both stages
                                    throw new \DomainException(self::makeCodecErrorMessage(
                                        "Codec error; cannot detach without yielding/returning header array once body data received",
                                        $codec
                                    ));
                                }
                            }
                        } elseif (is_array($yielded)) {
                            assert(self::validateCodecHeaders($codec, $yielded));
                            $headers = $yielded;
                            break;
                        } else {
                            $type = is_object($yielded) ? get_class($yielded) : gettype($yielded);
                            throw new \DomainException(self::makeCodecErrorMessage(
                                "Codec error; header array required but {$type} yielded",
                                $codec
                            ));
                        }
                    }
                } elseif (is_array($yielded)) {
                    assert(self::validateCodecHeaders($codec, $yielded));
                    $headers = $yielded;
                } else {
                    $type = is_object($yielded) ? get_class($yielded) : gettype($yielded);
                    throw new \DomainException(self::makeCodecErrorMessage(
                        "Codec error; header array required but {$type} yielded",
                        $codec
                    ));
                }
            }

            $sink->send($headers);

            $appendBuffer = null;

            do {
                if ($isEnding) {
                    $toSend = null;
                } elseif ($isFlushing) {
                    $toSend = false;
                } else {
                    $toSend = yield;
                    if (!isset($toSend)) {
                        $isEnding = true;
                    } elseif ($toSend === false) {
                        $isFlushing = true;
                    }
                }

                foreach ($codecs as $key => $codec) {
                    while (1) {
                        $yielded = $codec->send($toSend);
                        if (!isset($yielded)) {
                            if (!$codec->valid()) {
                                unset($codecs[$key]);
                                $yielded = $codec->getReturn();
                                if (!isset($yielded)) {
                                    if (isset($appendBuffer)) {
                                        $toSend = $appendBuffer;
                                        $appendBuffer = null;
                                    }
                                    break;
                                } elseif (is_string($yielded)) {
                                    if (isset($appendBuffer)) {
                                        $toSend = $appendBuffer . $yielded;
                                        $appendBuffer = null;
                                    } else {
                                        $toSend = $yielded;
                                    }
                                    break;
                                } else {
                                    $type = is_object($yielded) ? get_class($yielded) : gettype($yielded);
                                    throw new \DomainException(self::makeCodecErrorMessage(
                                        "Codec error; string entity data required but {$type} returned",
                                        $codec
                                    ));
                                }
                            } else {
                                if ($isEnding) {
                                    if (isset($toSend)) {
                                        $toSend = null;
                                    } else {
                                        break;
                                    }
                                } elseif ($isFlushing) {
                                    if ($toSend !== false) {
                                        $toSend = false;
                                    } else {
                                        break;
                                    }
                                } else {
                                    $toSend = yield;
                                    if (!isset($toSend)) {
                                        $isEnding = true;
                                    } elseif ($toSend === false) {
                                        $isFlushing = true;
                                    }
                                }
                            }
                        } elseif (is_string($yielded)) {
                            if (isset($appendBuffer)) {
                                $toSend = $appendBuffer . $yielded;
                                $appendBuffer = null;
                                break;
                            } elseif ($isEnding) {
                                $appendBuffer = $yielded;
                                $toSend = null;
                            } else {
                                $toSend = $yielded;
                                break;
                            }
                        } else {
                            $type = is_object($yielded) ? get_class($yielded) : gettype($yielded);
                            throw new \DomainException(self::makeCodecErrorMessage(
                                "Codec error; string entity data required but {$type} yielded",
                                $codec
                            ));
                        }
                    }
                }

                $sink->send($toSend);
                if ($isFlushing && $toSend !== false) {
                    $sink->send($toSend = false);
                }
                $isFlushing = false;

            } while (!$isEnding);

            if (isset($toSend)) {
                $sink->send(null);
            }
        } catch (ClientException $uncaught) {
            throw $uncaught;
        } catch (CodecException $uncaught) {
            // Userspace code isn't supposed to throw these; rethrow as
            // a different type to avoid breaking our error handling.
            throw new \Exception("", 0, $uncaught);
        } catch (\BaseException $uncaught) {
            throw new CodecException("Uncaught codec exception", $key, $uncaught);
        }
    }

    private static function makeCodecErrorMessage(string $prefix, Generator $gen): string {
        if (!$gen->valid()) {
            return $prefix;
        }
        $reflGen = new \ReflectionGenerator($gen);
        $exeGen = $reflGen->getExecutingGenerator();
        if ($isSubgenerator = ($exeGen !== $gen)) {
            $reflGen = new \ReflectionGenerator($exeGen);
        }
        return sprintf(
            $prefix . " on line %s in %s",
            $reflGen->getExecutingLine(),
            $reflGen->getExecutingFile()
        );
    }

    private static function validateCodecHeaders(Generator $gen, array $yield) {
        if (!isset($yield[":status"])) {
            throw new \DomainException(self::makeCodecErrorMessage(
                "Missing :status key in yielded codec array",
                $gen
            ));
        }
        if (!is_int($yield[":status"])) {
            throw new \DomainException(self::makeCodecErrorMessage(
                "Non-integer :status key in yielded codec array",
                $gen
            ));
        }
        if ($yield[":status"] < 100 || $yield[":status"] > 599) {
            throw new \DomainException(self::makeCodecErrorMessage(
                ":status value must be in the range 100..599 in yielded codec array",
                $gen
            ));
        }
        if (isset($yield[":reason"]) && !is_string($yield[":reason"])) {
            throw new \DomainException(self::makeCodecErrorMessage(
                "Non-string :reason value in yielded codec array",
                $gen
            ));
        }

        foreach ($yield as $headerField => $headerArray) {
            if (!is_string($headerField)) {
                throw new \DomainException(self::makeCodecErrorMessage(
                    "Invalid numeric header field index in yielded codec array",
                    $gen
                ));
            }
            if ($headerField[0] === ":") {
                continue;
            }
            if (!is_array($headerArray)) {
                throw new \DomainException(self::makeCodecErrorMessage(
                    "Invalid non-array header entry at key {$headerField} in yielded codec array",
                    $gen
                ));
            }
            foreach ($headerArray as $key => $headerValue) {
                if (!is_scalar($headerValue)) {
                    throw new \DomainException(self::makeCodecErrorMessage(
                        "Invalid non-scalar header value at index {$key} of " .
                        "{$headerField} array in yielded codec array",
                        $gen
                    ));
                }
            }
        }

        return true;
    }

    public static function h1Parser(callable $emitCallback, array $options = []): Generator {
        $maxHeaderSize = $options["max_header_size"] ?? 32768;
        $maxBodySize = $options["max_body_size"] ?? 131072;
        $bodyEmitSize = $options["body_emit_size"] ?? 32768;
        $callbackData = $options["cb_data"] ?? null;

        $buffer = yield;

        while (1) {
            // break potential references
            unset($traceBuffer, $protocol, $method, $uri, $headers);

            $traceBuffer = null;
            $headers = [];
            $contentLength = null;
            $isChunked = false;
            $protocol = null;
            $uri = null;
            $method = null;

            $parseResult = [
                "trace" => &$traceBuffer,
                "protocol" => &$protocol,
                "method" => &$method,
                "uri" => &$uri,
                "headers" => &$headers,
                "body" => "",
            ];

            while (1) {
                $buffer = ltrim($buffer, "\r\n");

                if ($headerPos = strpos($buffer, "\r\n\r\n")) {
                    $startLineAndHeaders = substr($buffer, 0, $headerPos + 2);
                    $buffer = (string)substr($buffer, $headerPos + 4);
                    break;
                } elseif ($maxHeaderSize > 0 && strlen($buffer) > $maxHeaderSize) {
                    $error = "Bad Request: header size violation";
                    break 2;
                }

                $buffer .= yield;
            }

            if (($startLineAndHeaders . "\r\n") === self::HTTP2_PREFACE) {
                $emitCallback([self::PARSE["HTTP2_PREFACE"], ($startLineAndHeaders . $buffer), null], $callbackData);
                break;
            }

            $startLineEndPos = strpos($startLineAndHeaders, "\n");
            $startLine = rtrim(substr($startLineAndHeaders, 0, $startLineEndPos), "\r\n");
            $rawHeaders = substr($startLineAndHeaders, $startLineEndPos + 1);
            $traceBuffer = $startLineAndHeaders;

            if (!$method = strtok($startLine, " ")) {
                $error = "Bad Request: invalid request line";
                break;
            }

            if (!$uri = strtok(" ")) {
                $error = "Bad Request: invalid request line";
                break;
            }

            $protocol = strtok(" ");
            if (stripos($protocol, "HTTP/") !== 0) {
                $error = "Bad Request: invalid request line";
                break;
            }

            $protocol = substr($protocol, 5);

            if ($rawHeaders) {
                if (strpos($rawHeaders, "\n\x20") || strpos($rawHeaders, "\n\t")) {
                    $error = "Bad Request: multi-line headers deprecated by RFC 7230";
                    break;
                }

                if (!preg_match_all(self::HTTP1_HEADER_REGEX, $rawHeaders, $matches)) {
                    $error = "Bad Request: header syntax violation";
                    break;
                }

                list(, $fields, $values) = $matches;

                $headers = [];
                foreach ($fields as $index => $field) {
                    $headers[$field][] = $values[$index];
                }

                if ($headers) {
                    $headers = array_change_key_case($headers);
                }

                $contentLength = $headers["content-length"][0] ?? null;

                if (isset($headers["transfer-encoding"])) {
                    $value = $headers["transfer-encoding"][0];
                    $isChunked = (bool) strcasecmp($value, "identity");
                }

                // @TODO validate that the bytes in matched headers match the raw input. If not there is a syntax error.
            }

            if ($contentLength > $maxBodySize) {
                $error = "Bad request: entity too large";
                break;
            } elseif (($method == "HEAD" || $method == "TRACE" || $method == "OPTIONS") || $contentLength === 0) {
                // No body allowed for these messages
                $hasBody = false;
            } else {
                $hasBody = $isChunked || $contentLength;
            }

            if (!$hasBody) {
                $parseResult["unparsed"] = $buffer;
                $emitCallback([self::PARSE["RESULT"], $parseResult, null], $callbackData);
                continue;
            }

            $emitCallback([self::PARSE["ENTITY_HEADERS"], $parseResult, null], $callbackData);
            $body = "";

            if ($isChunked) {
                while (1) {
                    while (false === ($lineEndPos = strpos($buffer, "\r\n"))) {
                        $buffer .= yield;
                    }

                    $line = substr($buffer, 0, $lineEndPos);
                    $buffer = substr($buffer, $lineEndPos + 2);
                    $hex = trim(ltrim($line, "0")) ?: 0;
                    $chunkLenRemaining = hexdec($hex);

                    if ($lineEndPos === 0 || $hex != dechex($chunkLenRemaining)) {
                        $error = "Bad Request: hex chunk size expected";
                        break 2;
                    }

                    if ($chunkLenRemaining === 0) {
                        while (!isset($buffer[1])) {
                            $buffer .= yield;
                        }
                        $firstTwoBytes = substr($buffer, 0, 2);
                        if ($firstTwoBytes === "\r\n") {
                            $buffer = substr($buffer, 2);
                            break; // finished ($is_chunked loop)
                        }

                        do {
                            if ($trailerSize = strpos($buffer, "\r\n\r\n")) {
                                $trailers = substr($buffer, 0, $trailerSize + 2);
                                $buffer = substr($buffer, $trailerSize + 4);
                            } else {
                                $buffer .= yield;
                                $trailerSize = \strlen($buffer);
                                $trailers = null;
                            }
                            if ($maxHeaderSize > 0 && $trailerSize > $maxHeaderSize) {
                                $error = "Trailer headers too large";
                                break 3;
                            }
                        } while (!isset($trailers));

                        if (strpos($trailers, "\n\x20") || strpos($trailers, "\n\t")) {
                            $error = "Bad Request: multi-line trailers deprecated by RFC 7230";
                            break 2;
                        }

                        if (!preg_match_all(self::HTTP1_HEADER_REGEX, $trailers, $matches)) {
                            $error = "Bad Request: trailer syntax violation";
                            break 2;
                        }

                        list(, $fields, $values) = $matches;
                        $trailers = [];
                        foreach ($fields as $index => $field) {
                            $trailers[$field][] = $values[$index];
                        }

                        if ($trailers) {
                            $trailers = array_change_key_case($trailers, CASE_UPPER);

                            foreach (["transfer-encoding", "content-length", "trailer"] as $remove) {
                                unset($trailers[$remove]);
                            }

                            if ($trailers) {
                                $headers = array_merge($headers, $trailers);
                            }
                        }

                        break; // finished ($is_chunked loop)
                    } elseif ($chunkLenRemaining > $maxBodySize) {
                        $error = "Bad Request: excessive chunk size";
                        break 2;
                    } else {
                        $bodyBufferSize = 0;

                        while (1) {
                            $bufferLen = \strlen($buffer);
                            // These first two (extreme) edge cases prevent errors where the packet boundary ends after
                            // the \r and before the \n at the end of a chunk.
                            if ($bufferLen === $chunkLenRemaining || $bufferLen === $chunkLenRemaining + 1) {
                                $buffer .= yield;
                                continue;
                            } elseif ($bufferLen >= $chunkLenRemaining + 2) {
                                $body .= substr($buffer, 0, $chunkLenRemaining);
                                $buffer = substr($buffer, $chunkLenRemaining + 2);
                                $bodyBufferSize += $chunkLenRemaining;
                            } else {
                                $body .= $buffer;
                                $bodyBufferSize += $bufferLen;
                                $chunkLenRemaining -= $bufferLen;
                            }

                            if ($bodyBufferSize >= $bodyEmitSize) {
                                $emitCallback([self::PARSE["ENTITY_PART"], ["body" => $body], null], $callbackData);
                                $body = '';
                                $bodyBufferSize = 0;
                            }

                            if ($bufferLen >= $chunkLenRemaining + 2) {
                                $chunkLenRemaining = null;
                                continue 2; // next chunk ($is_chunked loop)
                            } else {
                                $buffer = yield;
                            }
                        }
                    }
                }
            } else {
                $bufferDataSize = \strlen($buffer);

                while ($bufferDataSize < $contentLength) {
                    if ($bufferDataSize >= $bodyEmitSize) {
                        $emitCallback([self::PARSE["ENTITY_PART"], ["body" => $buffer], null], $callbackData);
                        $buffer = "";
                        $contentLength -= $bufferDataSize;
                    }
                    $buffer .= yield;
                    $bufferDataSize = \strlen($buffer);
                }

                if ($bufferDataSize === $contentLength) {
                    $body = $buffer;
                    $buffer = "";
                } else {
                    $body = substr($buffer, 0, $contentLength);
                    $buffer = (string)substr($buffer, $contentLength);
                }
            }

            if ($body != "") {
                $emitCallback([self::PARSE["ENTITY_PART"], ["body" => $body], null], $callbackData);
            }

            $parseResult["unparsed"] = $buffer;
            $emitCallback([self::ENTITY_RESULT, $parseResult, null], $callbackData);
        }

        // An error occurred...
        // stop parsing here ...
        $emitCallback([self::PARSE["ERROR"], $parseResult, $error], $callbackData);
        while (1) {
            yield;
        }
    }

    /**
     * We frequently have to pass callables to outside code (like amp).
     * Use this method to generate a "publicly callable" closure from
     * a private method without exposing that method in the public API.
     */
    private function makePrivateCallable(string $method): \Closure {
        return (new \ReflectionClass($this))->getMethod($method)->getClosure($this);
    }

    /**
     * This function MUST always return TRUE. It should only be invoked
     * inside an assert() block so that we can cancel its opcodes when
     * in production mode. This approach allows us to take full advantage
     * of debug mode log output without adding superfluous method call
     * overhead in production environments.
     */
    private function logDebug($message) {
        $this->logger->log(Logger::DEBUG, (string) $message);
        return true;
    }
}
