<?php

namespace Apitoolkit\Common;

use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\API\Globals;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\Span;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\Trace\SpanInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Promise\Create;
use JsonPath\JsonObject;
use JsonPath\InvalidJsonException;




class Shared {

  public static function setAttributes(
    Span $span,
    string $host,
    int $status_code,
    array $query_params,
    array $path_params,
    array $req_headers,
    array $resp_headers,
    string $method,
    string $raw_url,
    ?string $msg_id,
    string $url_path,
    string $req_body,
    string $resp_body,
    array $errors,
    array $config,
    string $sdk_type,
    ?string $parent_id = null
) {
  if ($span === null) {
        return;
    }
  try {
        $span->setAttributes([
            "net.host.name" => $host,
            "apitoolkit.msg_id" => $msg_id,
            "http.route" => $url_path,
            "http.target" => $raw_url,
            "http.request.method" => $method,
            "http.response.status_code" => $status_code,
            "http.request.query_params" => json_encode($query_params),
            "http.request.path_params" => json_encode($path_params),
            "apitoolkit.sdk_type" => $sdk_type,
            "apitoolkit.parent_id" => $parent_id ?? "",
            "http.request.body" => base64_encode(self::redactJSONFields($config['redactRequestBody'] ?? [], $req_body)),
            "http.response.body" => base64_encode(self::redactJSONFields($config['redactResponseBody'] ?? [], $resp_body)),
            "apitoolkit.errors" => json_encode($errors),
            "apitoolkit.service_version" => $config['serviceVersion'] ?? "",
            "apitoolkit.tags" => json_encode($config['tags'] ?? []),
        ]);

        $redacted = array_map('strtolower', array_merge($config['redactHeaders'] ?? [], ['authorization', 'cookie', 'set-cookie', 'proxy-authorization']));
        foreach (['request' => $req_headers, 'response' => $resp_headers] as $kind => $headers) {
            foreach ($headers as $header => $value) {
                $span->setAttribute("http.$kind.header.$header", in_array(strtolower($header), $redacted) ? "[CLIENT_REDACTED]" : self::toString($value));
            }
        }
    } catch (Exception $error) {
        $span->recordException($error);
    } finally {
        $span->end();
    }
}
public static function observeGuzzle($options, $msgId)
{
    $handlerStack = HandlerStack::create();
    // One middleware per call, so the span and request details stay per-request and are
    // still recorded when the request fails before a response arrives (e.g. ConnectException).
    $handlerStack->push(function (callable $handler) use ($msgId, $options) {
        return function ($request, array $reqOptions) use ($handler, $msgId, $options) {
            $span = Globals::tracerProvider()->getTracer("apitoolkit-http-tracer")->spanBuilder('apitoolkit-http-span')->startSpan();
            $uri = $request->getUri();
            parse_str($uri->getQuery(), $query);
            $urlPath = $options['pathPattern'] ?? $uri->getPath();
            $reqBody = (string) $request->getBody();
            $record = function ($response, array $errors) use ($span, $request, $uri, $query, $urlPath, $reqBody, $msgId, $options) {
                $respBody = $response ? (string) $response->getBody() : "";
                self::setAttributes(
                    $span,
                    $uri->getHost(),
                    $response ? $response->getStatusCode() : 0,
                    $query,
                    self::extractPathParams($urlPath, $uri->getPath()),
                    $request->getHeaders(),
                    $response ? $response->getHeaders() : [],
                    $request->getMethod(),
                    $uri->getPath() . '?' . $uri->getQuery(),
                    null,
                    $urlPath,
                    $reqBody,
                    $respBody,
                    $errors,
                    $options,
                    "GuzzleOutgoing",
                    $msgId,
                );
                return $respBody;
            };
            return $handler($request, $reqOptions)->then(
                fn($response) => $response->withBody(\GuzzleHttp\Psr7\Utils::streamFor($record($response, []))),
                function ($reason) use ($record) {
                    $record($reason instanceof RequestException ? $reason->getResponse() : null, $reason instanceof \Throwable ? [self::buildError($reason)] : []);
                    return Create::rejectionFor($reason);
                }
            );
        };
    });

    $client = new Client(['handler' => $handlerStack]);
    return $client;
}


private static function extractPathParams($pattern, $url)
{
    $patternSegments = explode('/', trim($pattern, '/'));
    $urlSegments = explode('/', trim($url, '/'));

    $params = array();

    foreach ($patternSegments as $key => $segment) {
        if (strpos($segment, '{') === 0 && strpos($segment, '}') === strlen($segment) - 1) {
            $paramName = trim($segment, '{}');
            if (isset($urlSegments[$key])) {
                $params[$paramName] = $urlSegments[$key];
            }
        }
    }

    return $params;
}

private static function toString($input): string
{
    if (is_string($input)) {
        return $input;
    }
    if (is_array($input)) {
        return implode(", ", $input); // Customize delimiter as needed
    }
    return strval($input); // Fallback to string conversion
}

private static function rootCause($err)
{
    $cause = $err;
    while ($cause && property_exists($cause, 'cause')) {
        $cause = $cause->cause;
    }
    return $cause;
}

private static function buildError($err)
{
    $errType = get_class($err);
    $rootError = self::rootCause($err);
    $rootErrorType = get_class($rootError);

    return [
        'when' => date('c'),
        'error_type' => $errType,
        'message' => $err->getMessage(),
        'root_error_type' => $rootErrorType,
        'root_error_message' => $rootError->getMessage(),
        'stack_trace' => $err->getTraceAsString(),
    ];
}

public static function reportError($error, $client)
{
    $atError = self::buildError($error);
    $client->addError($atError);
}

// redactJSONFields accepts a list of jsonpath's to redact, and a json object to redact from,
// and returns the final json after the redacting has been done.
private static function redactJSONFields(array $redactKeys, string $jsonStr): string
{
    try {
        $obj = new JsonObject($jsonStr);
    } catch (InvalidJsonException $e) {
        // For any data that isn't json, we simply return the data as is.
        return $jsonStr;
    }

    foreach ($redactKeys as $jsonPath) {
        $obj->set($jsonPath, '[CLIENT_REDACTED]');
    }
    return $obj->getJson();
}

}
