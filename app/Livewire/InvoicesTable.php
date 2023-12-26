<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2023. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Livewire;

use Carbon\Carbon;
use App\Models\Company;
use App\Models\Invoice;
use Livewire\Component;
use App\Libraries\MultiDB;
use Livewire\WithPagination;
use App\Utils\Traits\MakesHash;
use Livewire\Attributes\Locked;
use App\Utils\Traits\WithSorting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use App\Http\ViewComposers\PortalComposer;

class InvoicesTable extends Component
{
    use WithPagination, WithSorting, MakesHash;

    public int $per_page = 10;

    public array $status = [];

    #[Locked]
    public int $company_id;

    #[Locked]
    public string $db;

    public $all_selected = false;

    #[Validate('array')]
    public $selected_invoice_ids = [];

    public $mode = 'table';

    public function mount()
    {
        MultiDB::setDb($this->db);

        $this->sort_asc = false;

        $this->sort_field = 'date';
    }

    #[Computed]
    public function company()
    {
        return Company::find($this->company_id);
    }

    #[Computed]
    public function invoices()
    {
        $local_status = [];

        $query = Invoice::query()
            ->where('company_id', $this->company->id)
            ->where('is_deleted', false)
            ->where('is_proforma', false)
            ->with('client.gateway_tokens', 'client.contacts')
            ->orderBy($this->sort_field, $this->sort_asc ? 'asc' : 'desc');

        if (in_array('paid', $this->status)) {
            $local_status[] = Invoice::STATUS_PAID;
        }

        if (in_array('unpaid', $this->status)) {
            $local_status[] = Invoice::STATUS_SENT;
            $local_status[] = Invoice::STATUS_PARTIAL;
        }

        if (in_array('overdue', $this->status)) {
            $local_status[] = Invoice::STATUS_SENT;
            $local_status[] = Invoice::STATUS_PARTIAL;
        }

        if (count($local_status) > 0) {
            $query = $query->whereIn('status_id', array_unique($local_status));
        }

        if (in_array('overdue', $this->status)) {
            $query = $query->where(function ($query) {
                $query
                    ->orWhere('due_date', '<', Carbon::now())
                    ->orWhere('partial_due_date', '<', Carbon::now());
            });
        }

        return $query
            ->where('client_id', auth()->guard('contact')->user()->client_id)
            ->where('status_id', '<>', Invoice::STATUS_DRAFT)
            ->where('status_id', '<>', Invoice::STATUS_CANCELLED)
            ->withTrashed()
            ->paginate($this->per_page);
    }

    #[Computed]
    public function selectedInvoices()
    {
        return $this->invoices->whereIn('hashed_id', $this->selected_invoice_ids);
    }

    #[Computed]
    public function gatewayAvailable()
    {
        /** @var \App\Models\ClientContact $client_contact */
        $client_contact = auth()->user();

        return ! empty($client_contact->client->service()->getPaymentMethods(-1));
    }

    public function updatedAllSelected()
    {
        $this->selected_invoice_ids = $this->all_selected ? $this->invoices->pluck('hashed_id')->toArray() : [];
    }

    public function updatedSelectedInvoices()
    {
        $this->all_selected = false;
    }

    public function updatedStatus()
    {
        $this->recalculateSelected();
    }

    public function updatedPerPage()
    {
        $this->recalculateSelected();
    }

    public function updatedPage()
    {
        $this->recalculateSelected();
    }

    public function updatedSortField()
    {
        $this->recalculateSelected();
    }

    public function updatedSortAsc()
    {
        $this->recalculateSelected();
    }

    public function recalculateSelected()
    {
        $this->all_selected = false;

        $this->selected_invoice_ids = collect($this->selected_invoice_ids)->intersect($this->invoices->pluck('hashed_id'))->toArray();
    }

    public function startDownload()
    {
        abort_unless(auth()->guard('contact')->user()->company->enabled_modules & PortalComposer::MODULE_INVOICES, 403);

        $this->validateOnly('selected_invoice_ids');

        if (count($this->selected_invoices) == 0) {
            return $this->addError('message', ctrans('texts.no_items_selected'));
        }

        $this->mode = 'downloading';
    }

    public function download()
    {
        $this->mode = 'table';

        //if only 1 pdf, output to buffer for download
        if ($this->selected_invoices->count() == 1) {
            $invoice = $this->selected_invoices->first();

            return response()->streamDownload(function () use ($invoice) {
                echo $invoice->service()->getInvoicePdf(auth()->guard('contact')->user());
            }, $invoice->getFileName(), ['Content-Type' => 'application/pdf']);
        }

        return $this->buildZip($this->selected_invoices);
    }

    public function startPayment()
    {
        abort_unless(auth()->guard('contact')->user()->company->enabled_modules & PortalComposer::MODULE_INVOICES, 403);

        $this->validateOnly('selected_invoice_ids');

        if (count($this->selected_invoices) == 0) {
            return $this->addError('message', ctrans('texts.no_items_selected'));
        }

        $this->mode = 'payment';
    }

    private function buildZip($invoices)
    {
        // create new archive
        $zipFile = new \PhpZip\ZipFile();
        try {
            foreach ($invoices as $invoice) {

                if ($invoice->client->getSetting('enable_e_invoice')) {
                    $xml = $invoice->service()->getEInvoice();
                    $zipFile->addFromString($invoice->getFileName("xml"), $xml);
                }

                $file = $invoice->service()->getRawInvoicePdf();
                $zip_file_name = $invoice->getFileName();
                $zipFile->addFromString($zip_file_name, $file);
            }


            $filename = date('Y-m-d').'_'.str_replace(' ', '_', trans('texts.invoices')).'.zip';
            $filepath = sys_get_temp_dir().'/'.$filename;

            $zipFile->saveAsFile($filepath) // save the archive to a file
                   ->close(); // close archive

            return response()->download($filepath, $filename)->deleteFileAfterSend(true);
        } catch (\PhpZip\Exception\ZipException $e) {
            // handle exception
        } finally {
            $zipFile->close();
        }
    }

    public function render()
    {
        return render('components.livewire.invoices-table');
    }
}
