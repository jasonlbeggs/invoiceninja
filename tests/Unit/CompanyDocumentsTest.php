<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2021. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace Tests\Unit;

use App\Jobs\Util\UploadFile;
use App\Models\Document;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\MockAccountData;
use Tests\TestCase;

class CompanyDocumentsTest extends TestCase
{
    use MockAccountData;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();

        $this->withoutMiddleware(
            ThrottleRequests::class
        );
    }

    public function testCompanyDocumentExists()
    {
        $company_key = $this->company->company_key;

        $original_count = Document::whereCompanyId($this->company->id)->count();

        $image = UploadedFile::fake()->image('avatar.jpg');


        $document = (new UploadFile(
            $image,
            UploadFile::IMAGE,
            $this->user,
            $this->company,
            $this->invoice
        ))->handle();

        $this->assertNotNull($document);

        $this->assertTrue(Storage::exists($document->url));

        $this->assertGreaterThan($original_count, Document::whereCompanyId($this->company->id)->count());

        $this->company->delete();

        $this->assertEquals(0, Document::whereCompanyId($this->company->id)->count());

        // $this->assertFalse(Storage::exists($document->url));
    }

    //     public function testSignedRoutes()
    //     {

    //         $company_key = $this->company->company_key;

    //         $original_count = Document::whereCompanyId($this->company->id)->count();

    //         $image = UploadedFile::fake()->image('avatar.jpg');


    //         $document = (new UploadFile(
    //             $image,
    //             UploadFile::IMAGE,
    //             $this->user,
    //             $this->company,
    //             $this->invoice))->handle();

    //         $this->assertNotNull($document);

    //         // $url = \Illuminate\Support\Facades\URL::signedRoute('api.documents.show', ['document' => $document->hashed_id]);
    //         $url =  \Illuminate\Support\Facades\URL::signedRoute('documents.public_download', ['document_hash' => $document->hash]);
    
    // nlog($url);

    //         $response = $this->withHeaders([
    //             'X-API-SECRET' => config('ninja.api_secret'),
    //             'X-API-TOKEN' => $this->token,
    //         ])->get($url);


    //     $content = $response->streamedContent();
    // nlog($content);


    //         $response->assertStatus(200);

    //         $arr = $response->json();



    //         $this->assertFalse($arr);

    //     }
}
