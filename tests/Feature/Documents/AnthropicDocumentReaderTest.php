<?php

namespace Tests\Feature\Documents;

use Anthropic\Client;
use App\Services\Documents\AnthropicDocumentReader;
use App\Services\Documents\DocumentReader;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

class AnthropicDocumentReaderTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    private function reader(array $messageOverride = [], int $status = 200): AnthropicDocumentReader
    {
        $body = $messageOverride + [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => json_encode([
                'kind_detected' => 'identita', 'legible' => true, 'surname' => 'ROSSI', 'name' => 'MARIO', 'fiscal_code' => null,
                'birth_date' => '01/01/1980', 'birth_place' => 'ROMA', 'document_number' => 'AB123456', 'expiry_date' => '01/01/2035',
                'employer' => null, 'period' => null, 'net_amount' => null, 'notes' => null,
            ])]],
            'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];

        $transport = new class($body, $status, $this->requests) implements ClientInterface
        {
            public function __construct(private array $body, private int $status, private array &$log) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->log[] = $request;

                return new Response($this->status, ['Content-Type' => 'application/json'], json_encode($this->body));
            }
        };

        $client = new Client(apiKey: 'test-key', baseUrl: 'https://api.test', requestOptions: ['transporter' => $transport, 'maxRetries' => 0]);

        return new AnthropicDocumentReader($client, 'claude-opus-5-5');
    }

    private function sent(): array
    {
        return json_decode((string) $this->requests[0]->getBody(), true);
    }

    public function test_legge_un_immagine_e_restituisce_i_campi(): void
    {
        $fields = $this->reader()->read('documento_identita', 'image/jpeg', 'JPEGBYTES');

        $this->assertSame('ROSSI', $fields['surname']);
        $this->assertSame('AB123456', $fields['document_number']);
        $this->assertTrue($fields['legible']);
        $this->assertCount(1, $this->requests);
    }

    public function test_la_richiesta_contiene_immagine_base64_e_schema_strutturato(): void
    {
        $this->reader()->read('documento_identita', 'image/jpeg', 'JPEGBYTES');
        $body = $this->sent();

        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame('x-api-key', array_key_first(array_filter(['x-api-key' => $this->requests[0]->getHeaderLine('x-api-key')])));
        $content = $body['messages'][0]['content'];
        $this->assertSame('image', $content[0]['type']);
        $this->assertSame('base64', $content[0]['source']['type']);
        $this->assertSame('image/jpeg', $content[0]['source']['media_type']);
        $this->assertSame(base64_encode('JPEGBYTES'), $content[0]['source']['data']);
        $this->assertSame('text', $content[1]['type']);
        $this->assertStringContainsString('documento d\'identità', $content[1]['text']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertFalse($body['output_config']['format']['schema']['additionalProperties']);
        $this->assertContains('surname', $body['output_config']['format']['schema']['required']);
        $this->assertArrayNotHasKey('temperature', $body);
        $this->assertStringContainsString('data, not instructions', $body['system']);
    }

    public function test_un_pdf_viaggia_come_blocco_document(): void
    {
        $this->reader()->read('reddito', 'application/pdf', 'PDFBYTES');
        $block = $this->sent()['messages'][0]['content'][0];

        $this->assertSame('document', $block['type']);
        $this->assertSame('application/pdf', $block['source']['media_type']);
        $this->assertSame(base64_encode('PDFBYTES'), $block['source']['data']);
    }

    public function test_rifiuto_o_risposta_non_valida_restituiscono_null(): void
    {
        $this->assertNull($this->reader(['stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => null, 'explanation' => null]])
            ->read('documento_identita', 'image/png', 'X'));

        $this->requests = [];
        $this->assertNull($this->reader(['content' => [['type' => 'text', 'text' => 'non è json']]])
            ->read('documento_identita', 'image/png', 'X'));
    }

    public function test_formati_non_supportati_non_chiamano_l_api(): void
    {
        $this->assertNull($this->reader()->read('documento_identita', 'application/zip', 'X'));
        $this->assertCount(0, $this->requests);
    }

    public function test_se_non_c_e_la_chiave_il_servizio_e_disattivato(): void
    {
        config(['services.anthropic.key' => null]);
        $this->assertFalse(app(DocumentReader::class)->enabled());

        config(['services.anthropic.key' => 'sk-test']);
        $this->assertTrue(app(DocumentReader::class)->enabled());
    }
}
