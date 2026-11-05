<?php

use Apitoolkit\Common\Shared;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

class SharedTest extends TestCase
{
    private InMemoryExporter $exporter;
    private TracerProvider $tracerProvider;
    private ScopeInterface $scope;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $this->scope = Configurator::create()->withTracerProvider($this->tracerProvider)->activate();
    }

    protected function tearDown(): void
    {
        $this->scope->detach();
    }

    private function attributes(): array
    {
        $this->assertCount(1, $this->exporter->getSpans());
        return $this->exporter->getSpans()[0]->getAttributes()->toArray();
    }

    public function testRedactsHeadersByNameCaseInsensitively(): void
    {
        $span = $this->tracerProvider->getTracer('test')->spanBuilder('test')->startSpan();
        Shared::setAttributes(
            $span, 'example.com', 200, [], [],
            ['Authorization' => ['Bearer abc'], 'cookie' => ['sid=1'], 'Proxy-Authorization' => ['Basic x'], 'X-Api-Key' => ['k'], 'Accept' => ['*/*']],
            ['Set-Cookie' => ['sid=2'], 'Content-Type' => ['application/json']],
            'GET', '/', 'msg', '/', '', '', [], ['redactHeaders' => ['x-api-key']], 'Test'
        );
        $attrs = $this->attributes();
        foreach (['request.header.Authorization', 'request.header.cookie', 'request.header.Proxy-Authorization', 'request.header.X-Api-Key', 'response.header.Set-Cookie'] as $h) {
            $this->assertSame('[CLIENT_REDACTED]', $attrs["http.$h"], $h);
        }
        $this->assertSame('*/*', $attrs['http.request.header.Accept']);
        $this->assertSame('application/json', $attrs['http.response.header.Content-Type']);
    }

    public function testAcceptsNonRecordingSpanWhenNoSdkIsConfigured(): void
    {
        Shared::setAttributes(
            \OpenTelemetry\API\Trace\Span::getInvalid(), 'example.com', 200, [], [], [], [], 'GET', '/', 'msg', '/', '', '', [], [], 'Test'
        );
        $this->assertCount(0, $this->exporter->getSpans());
    }

    public function testRedactsJsonBodies(): void
    {
        $span = $this->tracerProvider->getTracer('test')->spanBuilder('test')->startSpan();
        Shared::setAttributes(
            $span, 'example.com', 200, [], [], [], [], 'POST', '/', 'msg', '/',
            '{"user":{"password":"p","name":"n"}}', '[{"token":"t"}]', [],
            ['redactRequestBody' => ['$.user.password'], 'redactResponseBody' => ['$[*].token']], 'Test'
        );
        $attrs = $this->attributes();
        $this->assertJsonStringEqualsJsonString('{"user":{"password":"[CLIENT_REDACTED]","name":"n"}}', base64_decode($attrs['http.request.body']));
        $this->assertJsonStringEqualsJsonString('[{"token":"[CLIENT_REDACTED]"}]', base64_decode($attrs['http.response.body']));
    }

    public static function outgoingOutcomes(): array
    {
        return [
            'success' => [new Response(200, ['Set-Cookie' => 'a=b'], 'ok'), 200],
            'server error' => [new Response(500, [], 'err'), 500],
            'connection error' => [new ConnectException('down', new Request('POST', 'http://api.example.com')), 0],
        ];
    }

    /** @dataProvider outgoingOutcomes */
    public function testObserveGuzzleRecordsRequestDetailsOnEveryOutcome($outcome, int $status): void
    {
        $client = Shared::observeGuzzle(['pathPattern' => '/users/{id}'], 'parent-msg');
        $client->getConfig('handler')->setHandler(new MockHandler([$outcome]));
        try {
            $response = $client->post('http://api.example.com/users/42?x=1', ['body' => '{"a":1}', 'headers' => ['Authorization' => 'Bearer zzz']]);
            $this->assertSame('ok', (string) $response->getBody());
        } catch (\Throwable $e) {
            $this->assertNotSame(200, $status);
        }
        $attrs = $this->attributes();
        $this->assertSame('api.example.com', $attrs['net.host.name']);
        $this->assertSame('POST', $attrs['http.request.method']);
        $this->assertSame('/users/{id}', $attrs['http.route']);
        $this->assertSame('/users/42?x=1', $attrs['http.target']);
        $this->assertSame('{"id":"42"}', $attrs['http.request.path_params']);
        $this->assertSame('{"a":1}', base64_decode($attrs['http.request.body']));
        $this->assertSame('[CLIENT_REDACTED]', $attrs['http.request.header.Authorization']);
        $this->assertSame($status, $attrs['http.response.status_code']);
        $this->assertSame('parent-msg', $attrs['apitoolkit.parent_id']);
    }
}
