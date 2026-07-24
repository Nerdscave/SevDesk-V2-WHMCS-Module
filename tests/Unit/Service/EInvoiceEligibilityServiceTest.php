<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use ArrayObject;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Capsule\Manager as IlluminateCapsule;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Domain\ContactData;
use WHMCS\Module\Addon\SevDesk\Domain\DocumentTargetDecision;
use WHMCS\Module\Addon\SevDesk\Domain\EInvoiceContext;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;
use WHMCS\Module\Addon\SevDesk\Service\DocumentTargetResolver;
use WHMCS\Module\Addon\SevDesk\Service\EInvoiceEligibilityService;
use WHMCS\Module\Addon\SevDesk\Service\ReferenceData;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsGateway;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class EInvoiceEligibilityServiceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!class_exists(Capsule::class, false)) {
            class_alias(IlluminateCapsule::class, Capsule::class);
        }
        $database = new IlluminateCapsule();
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $database->setAsGlobal();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Capsule::schema()->dropIfExists('tbladdonmodules');
        Capsule::schema()->dropIfExists('tblcustomfields');
        Capsule::schema()->create('tbladdonmodules', static function ($table): void {
            $table->increments('id');
            $table->string('module');
            $table->string('setting');
            $table->text('value')->nullable();
            $table->unique(['module', 'setting']);
        });
        Capsule::schema()->create('tblcustomfields', static function ($table): void {
            $table->increments('id');
            $table->string('type');
            $table->string('fieldname');
            $table->string('fieldtype');
            $table->string('adminonly');
        });
        Capsule::table('tblcustomfields')->insert([
            'id' => 5,
            'type' => 'client',
            'fieldname' => 'ZUGFeRD',
            'fieldtype' => 'tickbox',
            'adminonly' => 'on',
        ]);
        $config = new Config();
        $config->set('e_invoice_client_field_id', 5);
        $config->set('e_invoice_canary_confirmed', true);
    }

    public function testOffAndHistoricalProfilesNeverSelectAnEInvoice(): void
    {
        $service = $this->service([], false);
        $off = $service->decide(
            $this->invoice(),
            $this->contact(),
            '42',
            $this->tax('1'),
            $this->target('1'),
            ['requestedEInvoiceMode' => 'off'],
        );
        $historical = $service->decide(
            $this->invoice(),
            $this->contact(),
            '42',
            $this->tax('1'),
            $this->target('1'),
            $this->candidate() + ['historicalBackfill' => true],
        );

        self::assertTrue($off->isSuccess());
        self::assertNull($off->value());
        self::assertTrue($historical->isSuccess());
        self::assertNull($historical->value());
    }

    public function testOptedInRule19AlwaysRemainsANormalInvoice(): void
    {
        $history = new ArrayObject();
        $service = $this->service([], true, $history);
        $result = $service->decide(
            $this->invoice(),
            $this->contact(),
            '42',
            $this->tax('19'),
            $this->target('19'),
            $this->candidate(),
        );

        self::assertTrue($result->isSuccess());
        self::assertNull($result->value());
        $this->assertOnlyReadCalls($history, 0);
    }

    public function testOptOutAndFutureActivationConsciouslyRemainNormalInvoices(): void
    {
        $scenarios = [
            'client opt-out' => [false, $this->candidate()],
            'invoice before activation' => [
                true,
                array_replace($this->candidate(), ['requestedEInvoiceActiveFrom' => '2027-01-01']),
            ],
        ];

        foreach ($scenarios as $label => [$optedIn, $candidate]) {
            $history = new ArrayObject();
            $result = $this->service([], $optedIn, $history)->decide(
                $this->invoice(),
                $this->contact(),
                '42',
                $this->tax('1'),
                $this->target('1'),
                $candidate,
            );

            self::assertTrue($result->isSuccess(), $label);
            self::assertNull($result->value(), $label);
            $this->assertOnlyReadCalls($history, 0);
        }
    }

    public function testUnsupportedExplicitRecipientAndTaxSelectionsFailClosed(): void
    {
        $scenarios = [
            'foreign organisation' => [$this->contact(countryCode: 'FR'), '1'],
            'private recipient' => [$this->contact(companyName: ''), '1'],
            'non-Rule-1 invoice' => [$this->contact(), '11'],
        ];

        foreach ($scenarios as $label => [$contact, $rule]) {
            $history = new ArrayObject();
            $result = $this->service([], true, $history)->decide(
                $this->invoice(),
                $contact,
                '42',
                $this->tax($rule),
                $this->target($rule),
                $this->candidate(),
            );

            self::assertTrue($result->isFailure(), $label);
            self::assertSame('e_invoice_tax_or_recipient_not_supported', $result->errorCode(), $label);
            $this->assertOnlyReadCalls($history, 0);
        }
    }

    public function testMissingMailOrAddressFailsClosedWithoutANormalInvoiceFallback(): void
    {
        $scenarios = [
            'missing billing email' => $this->contact(email: ''),
            'missing billing street' => $this->contact(street: ''),
        ];

        foreach ($scenarios as $label => $contact) {
            $history = new ArrayObject();
            $result = $this->service(
                array_slice($this->readyResponses(), 0, 4),
                true,
                $history,
            )->decide(
                $this->invoice(),
                $contact,
                '42',
                $this->tax('1'),
                $this->target('1'),
                $this->candidate(),
            );

            self::assertTrue($result->isFailure(), $label);
            self::assertSame('e_invoice_recipient_data_missing', $result->errorCode(), $label);
            $this->assertOnlyReadCalls($history, 4);
        }
    }

    public function testGovernmentContactFailsClosedAfterReadOnlyChecks(): void
    {
        $responses = array_slice($this->readyResponses(), 0, 5);
        $responses[4] = new Response(
            200,
            [],
            '{"objects":[{"id":"42","buyerReference":"7","governmentAgency":"true"}]}',
        );
        $history = new ArrayObject();
        $result = $this->service($responses, true, $history)->decide(
            $this->invoice(),
            $this->contact(),
            '42',
            $this->tax('1'),
            $this->target('1'),
            $this->candidate(),
        );

        self::assertTrue($result->isFailure());
        self::assertSame('e_invoice_contact_master_data_missing', $result->errorCode());
        $this->assertOnlyReadCalls($history, 5);
    }

    public function testMissingOrInvalidReferencesAndCanariesFailClosed(): void
    {
        $ready = $this->readyResponses();
        $missingCandidateCanary = $this->candidate();
        unset($missingCandidateCanary['requestedEInvoiceCanaryConfirmed']);
        $scenarios = [
            'invalid PaymentMethod ID' => [
                array_replace($this->candidate(), ['requestedEInvoicePaymentMethodId' => 'invalid']),
                [],
                'e_invoice_reference_missing',
                [],
                0,
            ],
            'missing PaymentMethod' => [
                $this->candidate(),
                [new Response(200, [], '{"objects":[]}')],
                'e_invoice_reference_missing',
                [],
                1,
            ],
            'invalid Unity ID' => [
                array_replace($this->candidate(), ['requestedEInvoiceUnityId' => 'invalid']),
                [],
                'e_invoice_reference_missing',
                [],
                0,
            ],
            'missing Unity' => [
                $this->candidate(),
                [$ready[0], new Response(200, [], '{"objects":[]}')],
                'e_invoice_reference_missing',
                [],
                2,
            ],
            'invalid SevUser ID' => [
                array_replace($this->candidate(), ['requestedEInvoiceSevUserId' => 'invalid']),
                [],
                'e_invoice_reference_missing',
                [],
                0,
            ],
            'missing SevUser' => [
                $this->candidate(),
                [$ready[0], $ready[1], new Response(200, [], '{"objects":[]}')],
                'e_invoice_reference_missing',
                [],
                3,
            ],
            'missing country' => [
                $this->candidate(),
                [$ready[0], $ready[1], $ready[2], new Response(200, [], '{"objects":[]}')],
                'e_invoice_country_reference_missing',
                [],
                4,
            ],
            'invalid country ID' => [
                $this->candidate(),
                [
                    $ready[0],
                    $ready[1],
                    $ready[2],
                    new Response(200, [], '{"objects":[{"id":"invalid","code":"DE"}]}'),
                ],
                'e_invoice_country_reference_missing',
                [],
                4,
            ],
            'missing frozen canary confirmation' => [
                $missingCandidateCanary,
                [],
                'e_invoice_canary_not_confirmed',
                [],
                0,
            ],
            'revoked configured canary' => [
                $this->candidate(),
                [],
                'e_invoice_canary_not_confirmed',
                ['e_invoice_canary_confirmed' => false],
                0,
            ],
        ];

        foreach ($scenarios as $label => [$candidate, $responses, $code, $configValues, $readCount]) {
            $history = new ArrayObject();
            $result = $this->service($responses, true, $history, $configValues)->decide(
                $this->invoice(),
                $this->contact(),
                '42',
                $this->tax('1'),
                $this->target('1'),
                $candidate,
            );

            self::assertTrue($result->isFailure(), $label);
            self::assertSame($code, $result->errorCode(), $label);
            $this->assertOnlyReadCalls($history, $readCount);
        }
    }

    public function testTruncatedMainEmailSearchFailsClosed(): void
    {
        $emails = array_fill(0, 1000, [
            'contact' => ['id' => '42'],
            'type' => 'EMAIL',
            'main' => '1',
            'value' => 'billing@example.test',
        ]);
        $responses = array_slice($this->readyResponses(), 0, 5);
        $responses[] = new Response(
            200,
            [],
            json_encode(['objects' => $emails], JSON_THROW_ON_ERROR),
        );
        $history = new ArrayObject();
        $result = $this->service($responses, true, $history)->decide(
            $this->invoice(),
            $this->contact(),
            '42',
            $this->tax('1'),
            $this->target('1'),
            $this->candidate(),
        );

        self::assertTrue($result->isFailure());
        self::assertSame('e_invoice_contact_email_search_truncated', $result->errorCode());
        $this->assertOnlyReadCalls($history, 6);
    }

    public function testDuplicateGermanCountryReferencesBlockTheEInvoiceSelection(): void
    {
        $responses = array_slice($this->readyResponses(), 0, 4);
        $responses[3] = new Response(200, [], '{"objects":[
            {"id":"1","code":"DE","nameEn":"Germany"},
            {"id":"2","code":"DE","nameEn":"Federal Republic of Germany"}
        ]}');
        $history = new ArrayObject();

        $result = $this->service($responses, true, $history)->decide(
            $this->invoice(),
            $this->contact(),
            '42',
            $this->tax('1'),
            $this->target('1'),
            $this->candidate(),
        );

        self::assertTrue($result->isFailure());
        self::assertSame('e_invoice_country_reference_missing', $result->errorCode());
        $this->assertOnlyReadCalls($history, 4);
    }

    public function testCompleteGermanB2bProfileCreatesRuntimeOnlyZugferdContext(): void
    {
        $history = new ArrayObject();
        $responses = $this->readyResponses();
        $responses[5] = new Response(200, [], '{"objects":[
            {"contact":{"id":"77"},"type":"EMAIL","main":"1","value":"foreign@example.test"},
            {"contact":{"id":"42"},"type":"PHONE","main":"1","value":"+49 30 123456"},
            {"contact":{"id":"42"},"type":"EMAIL","main":"0","value":"other@example.test"},
            {"contact":{"id":"42"},"type":"EMAIL","main":"1","value":"billing@example.test"}
        ]}');
        $service = $this->service($responses, true, $history);
        $result = $service->decide(
            $this->invoice(),
            $this->contact(),
            '42',
            $this->tax('1'),
            $this->target('1'),
            $this->candidate(),
        );

        self::assertTrue($result->isSuccess());
        self::assertInstanceOf(EInvoiceContext::class, $result->value());
        $frozen = $result->value()->frozenContext();
        self::assertTrue($frozen['isEInvoice']);
        self::assertSame('42', $frozen['eInvoiceContactId']);
        self::assertSame('9', $frozen['eInvoicePaymentMethodId']);
        self::assertArrayNotHasKey('street', $frozen);
        self::assertArrayNotHasKey('email', $frozen);

        $requests = iterator_to_array($history);
        self::assertCount(7, $requests);
        $communicationRequest = null;
        foreach ($requests as $requestEntry) {
            $candidate = is_array($requestEntry) ? ($requestEntry['request'] ?? null) : null;
            if (
                $candidate instanceof \Psr\Http\Message\RequestInterface
                && $candidate->getUri()->getPath() === '/api/v1/CommunicationWay'
            ) {
                $communicationRequest = $candidate;
                break;
            }
        }
        self::assertInstanceOf(\Psr\Http\Message\RequestInterface::class, $communicationRequest);
        parse_str($communicationRequest->getUri()->getQuery(), $query);
        self::assertSame('42', $query['contact']['id'] ?? null);
        self::assertSame('Contact', $query['contact']['objectName'] ?? null);
        self::assertArrayNotHasKey('type', $query);
        self::assertArrayNotHasKey('main', $query);
    }

    public function testMissingRemoteBuyerReferenceBlocksSelectedEInvoice(): void
    {
        $responses = $this->readyResponses();
        $responses[4] = new Response(200, [], '{"objects":[{"id":"42","governmentAgency":"false"}]}');
        $service = $this->service($responses, true);
        $result = $service->decide(
            $this->invoice(),
            $this->contact(),
            '42',
            $this->tax('1'),
            $this->target('1'),
            $this->candidate(),
        );

        self::assertTrue($result->isFailure());
        self::assertSame('e_invoice_contact_master_data_missing', $result->errorCode());
    }

    public function testFrozenSelectionRejectsAChangedAddressWithoutRemoteFallback(): void
    {
        $service = $this->service([], false);
        $candidate = [
            'targetIsEInvoice' => true,
            'targetEInvoiceContactId' => '42',
            'targetEInvoicePaymentMethodId' => '9',
            'targetEInvoiceUnityId' => '8',
            'targetEInvoiceCountryId' => '1',
            'targetEInvoiceAddressHash' => EInvoiceContext::addressHash(
                'Example GmbH',
                'Old Street 1',
                '12345',
                'Berlin',
                'DE',
            ),
        ];
        $result = $service->decide(
            $this->invoice(),
            $this->contact(),
            '42',
            $this->tax('1'),
            $this->target('1'),
            $candidate,
        );

        self::assertTrue($result->isFailure());
        self::assertSame('e_invoice_frozen_context_changed', $result->errorCode());
    }

    public function testFrozenSelectionRevalidatesReferencesBeforeTheFirstWriteOnly(): void
    {
        $hash = EInvoiceContext::addressHash(
            'Example GmbH',
            'Musterstr. 1',
            '12345',
            'Berlin',
            'DE',
        );
        $candidate = [
            'targetIsEInvoice' => true,
            'targetSevUserId' => '7',
            'targetEInvoiceContactId' => '42',
            'targetEInvoicePaymentMethodId' => '9',
            'targetEInvoiceUnityId' => '8',
            'targetEInvoiceCountryId' => '1',
            'targetEInvoiceAddressHash' => $hash,
        ];
        $history = new ArrayObject();
        $beforeWrite = $this->service([
            new Response(200, [], '{"objects":[]}'),
        ], true, $history)->decide(
            $this->invoice(),
            $this->contact(),
            '42',
            $this->tax('1'),
            $this->target('1'),
            $candidate,
        );

        self::assertTrue($beforeWrite->isFailure());
        self::assertSame('e_invoice_frozen_reference_changed', $beforeWrite->errorCode());
        self::assertSame(['GET'], array_map(
            static fn (array $entry): string => $entry['request']->getMethod(),
            iterator_to_array($history),
        ));

        $afterWrite = $this->service([], true)->decide(
            $this->invoice(),
            $this->contact(),
            '42',
            $this->tax('1'),
            $this->target('1'),
            $candidate,
            false,
        );
        self::assertTrue($afterWrite->isSuccess());
        self::assertInstanceOf(EInvoiceContext::class, $afterWrite->value());
    }

    /**
     * @param list<Response> $responses
     * @param array<string,string|int|bool|null> $configValues
     */
    private function service(
        array $responses,
        bool $optedIn,
        ?ArrayObject $history = null,
        array $configValues = [],
    ): EInvoiceEligibilityService {
        $stack = HandlerStack::create(new MockHandler($responses));
        if ($history !== null) {
            $stack->push(Middleware::history($history));
        }
        $client = new SevdeskClient(
            new Client(['handler' => $stack]),
            'token',
        );
        $config = new Config();
        foreach ($configValues as $key => $value) {
            $config->set($key, $value);
        }
        $gateway = new WhmcsGateway($config, static fn (): array => [
            'result' => 'success',
            'client' => [
                'customfields' => ['customfield' => [[
                    'id' => 5,
                    'value' => $optedIn ? 'on' : '',
                ]]],
            ],
        ]);

        return new EInvoiceEligibilityService($config, $gateway, $client, new ReferenceData($client));
    }

    private function assertOnlyReadCalls(ArrayObject $history, int $expectedCount): void
    {
        $requests = array_map(
            static fn (array $entry): string => $entry['request']->getMethod(),
            iterator_to_array($history),
        );

        self::assertCount($expectedCount, $requests);
        self::assertSame(array_fill(0, $expectedCount, 'GET'), $requests);
    }

    /** @return list<Response> */
    private function readyResponses(): array
    {
        return [
            new Response(200, [], '{"objects":[{"id":"9","name":"Bank"}]}'),
            new Response(200, [], '{"objects":[{"id":"8","name":"Stück"}]}'),
            new Response(200, [], '{"objects":[{"id":"7","name":"Erika"}]}'),
            new Response(200, [], '{"objects":[{"id":"1","code":"DE"}]}'),
            new Response(200, [], '{"objects":[{"id":"42","buyerReference":"7","governmentAgency":"false"}]}'),
            new Response(200, [], '{"objects":[{"contact":{"id":"42"},"type":"EMAIL","main":"1","value":"billing@example.test"}]}'),
            new Response(200, [], '{"objects":[{"contact":{"id":"42"},"name":"Example GmbH","street":"Musterstr. 1","zip":"12345","city":"Berlin","country":{"id":"1"}}]}'),
        ];
    }

    /** @return array<string,scalar|null> */
    private function candidate(): array
    {
        return [
            'requestedEInvoiceMode' => EInvoiceEligibilityService::MODE_ZUGFERD_DOMESTIC_B2B,
            'requestedEInvoiceActiveFrom' => '2025-01-01',
            'requestedEInvoiceClientFieldId' => 5,
            'requestedEInvoicePaymentMethodId' => '9',
            'requestedEInvoiceUnityId' => '8',
            'requestedEInvoiceSevUserId' => '7',
            'requestedEInvoiceCanaryConfirmed' => true,
        ];
    }

    private function invoice(): InvoiceSnapshot
    {
        return new InvoiceSnapshot(
            10,
            7,
            'RE-10',
            new DateTimeImmutable('2026-07-19'),
            'EUR',
            '119.00',
            '0.00',
            [new LineItem('Hosting', '100.00', '19', true)],
        );
    }

    private function contact(
        string $companyName = 'Example GmbH',
        string $email = 'billing@example.test',
        string $street = 'Musterstr. 1',
        string $countryCode = 'DE',
    ): ContactData {
        return new ContactData(
            7,
            '42',
            $companyName,
            'Erika',
            'Musterfrau',
            $email,
            $street,
            '',
            '12345',
            'Berlin',
            $countryCode,
            'DE123456789',
            false,
        );
    }

    private function tax(string $rule): TaxDecision
    {
        return TaxDecision::allowInvoice('domestic', $rule, 'allowed', [$rule === '1' ? '19' : '21']);
    }

    private function target(string $rule): DocumentTargetDecision
    {
        return DocumentTargetDecision::select(
            DocumentTargetDecision::DOCUMENT_INVOICE,
            DocumentTargetResolver::AUTHORITY_SEVDESK,
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            $rule === '19' ? DocumentTargetResolver::OSS_RULE_19_CONFIRMED : DocumentTargetResolver::OSS_BLOCKED,
            $rule,
            'selected',
            'selected',
        );
    }
}
