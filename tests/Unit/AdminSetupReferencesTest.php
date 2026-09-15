<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Controllers\AdminController;
use WHMCS\Module\Addon\SevDesk\Service\ReferenceData;
use WHMCS\Module\Addon\SevDesk\Support\Csrf;
use WHMCS\Module\Addon\SevDesk\View;

final class AdminSetupReferencesTest extends TestCase
{
    public function testGuidanceFailureDoesNotHideOtherListsOrLeakRawErrors(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(500, [], '{"error":"synthetic-secret-token"}'),
            new Response(200, [], '{"objects":[{"id":7,"name":"Synthetic user"}]}'),
            new Response(200, [], '{"objects":[{"id":8,"name":"Synthetic unity"}]}'),
            new Response(200, [], '{"objects":[{"id":9,"name":"Synthetic payment"}]}'),
        ]));
        $handler->push(Middleware::history($history));
        $client = new SevdeskClient(new Client(['handler' => $handler]), 'synthetic-token');

        $result = (new ReflectionMethod(AdminController::class, 'readSetupReferences'))->invoke(
            $this->controller(),
            new ReferenceData($client),
        );

        self::assertSame([], $result['accountOptions']);
        self::assertSame('7', $result['sevUsers'][0]['id']);
        self::assertSame('8', $result['unities'][0]['id']);
        self::assertSame('9', $result['paymentMethods'][0]['id']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('vorübergehend', $result['errors']['accountOptions']);
        self::assertStringNotContainsString('synthetic-secret-token', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertCount(4, $history);
        foreach ($history as $entry) {
            self::assertSame('GET', $entry['request']->getMethod());
        }
    }

    public function testAuthenticationFailureStopsRemainingReadsAndTripsTheAlarm(): void
    {
        $alarmed = false;
        $mock = new MockHandler([new Response(401), new Response(200)]);
        $client = new SevdeskClient(
            new Client(['handler' => HandlerStack::create($mock)]),
            'synthetic-token',
            authenticationFailureHandler: static function () use (&$alarmed): void {
                $alarmed = true;
            },
        );
        $result = (new ReflectionMethod(AdminController::class, 'readSetupReferences'))->invoke(
            $this->controller(),
            new ReferenceData($client),
        );

        self::assertTrue($alarmed);
        self::assertCount(1, $mock);
        self::assertCount(4, $result['errors']);
        self::assertStringContainsString('Zugriffsrechte', $result['errors']['accountOptions']);
    }

    public function testReferencesEndpointRejectsGetAndInvalidCsrfBeforeLoadingAnything(): void
    {
        $originalServer = $_SERVER;
        $originalPost = $_POST;
        $originalSession = $_SESSION ?? [];
        try {
            foreach (['GET', 'POST'] as $method) {
                $_SERVER['REQUEST_METHOD'] = $method;
                $_POST = ['token' => 'wrong'];
                $_SESSION['sevdesk_csrf'] = 'expected';
                try {
                    $this->controller()->setupReferences();
                    self::fail('Read-only token previews still require a valid CSRF-protected POST.');
                } catch (RuntimeException $error) {
                    self::assertStringContainsString($method === 'GET' ? 'POST' : 'Sicherheitstoken', $error->getMessage());
                }
            }
        } finally {
            $_SERVER = $originalServer;
            $_POST = $originalPost;
            $_SESSION = $originalSession;
        }
    }

    private function controller(): AdminController
    {
        $csrf = new Csrf();

        return new AdminController(new Application(), new View($csrf), $csrf, 'addonmodules.php?module=sevdesk');
    }
}
