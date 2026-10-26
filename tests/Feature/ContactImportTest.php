<?php

namespace Tests\Feature;

use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Jobs\ProcessContactImportJob;
use App\Livewire\Imports\Index as ImportsIndex;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\SuppressionEntry;
use App\Services\Contacts\ContactImportProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithSms;
use Tests\TestCase;

class ContactImportTest extends TestCase
{
    use InteractsWithSms;
    use RefreshDatabase;

    private const CSV = <<<'CSV'
    name,phone,email
    Ada Okafor,08031234567,ada@example.com
    Duplicate Ada,+2348031234567,
    Broken,12345,
    Existing Person,08089876543,existing@example.com
    Suppressed Person,08021234567,
    Bad Email,08051234567,not-an-email
    CSV;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->fakeGateway();
        $this->configureSettings();
    }

    public function test_csv_validation_flags_valid_invalid_duplicate_and_suppressed_rows(): void
    {
        Contact::factory()->create(['phone' => '2348089876543']);
        SuppressionEntry::factory()->create(['phone' => '2348021234567']);

        $import = $this->importWithCsv();

        $this->assertTrue(app(ContactImportProcessor::class)->validate($import));

        $import->refresh();

        $this->assertSame(ImportStatus::Ready, $import->status);
        $this->assertSame(6, $import->total_rows);
        $this->assertSame(1, $import->valid_rows);
        $this->assertSame(3, $import->invalid_rows);
        $this->assertSame(2, $import->duplicate_rows);

        $statuses = $import->rows()->orderBy('row_number')->pluck('status', 'row_number');

        $this->assertSame(ImportRowStatus::Valid, $statuses[2]);
        $this->assertSame(ImportRowStatus::Duplicate, $statuses[3]);
        $this->assertSame(ImportRowStatus::Invalid, $statuses[4]);
        $this->assertSame(ImportRowStatus::Duplicate, $statuses[5]);
        $this->assertSame(ImportRowStatus::Invalid, $statuses[6]);
        $this->assertSame(ImportRowStatus::Invalid, $statuses[7]);
    }

    public function test_committing_an_import_creates_only_the_valid_contacts(): void
    {
        Contact::factory()->create(['phone' => '2348089876543']);
        SuppressionEntry::factory()->create(['phone' => '2348021234567']);

        $import = $this->importWithCsv();

        app(ContactImportProcessor::class)->validate($import);

        $import->refresh();

        $statuses = $import->rows()->orderBy('row_number')->pluck('status', 'row_number')->all();
        $context = 'row statuses: '.json_encode(array_map(fn ($status) => $status->value, $statuses));

        $this->assertSame(1, $import->valid_rows, 'valid rows — '.$context);
        $this->assertSame(3, $import->invalid_rows, 'invalid rows — '.$context);
        $this->assertSame(2, $import->duplicate_rows, 'duplicate rows — '.$context);
        $this->assertSame(1, Contact::query()->count(), 'contacts before commit — '.$context);

        $admin = $this->admin();
        $inserted = app(ContactImportProcessor::class)->commit($import, $admin);

        $this->assertSame(1, $inserted, 'inserted contacts');
        $this->assertSame(2, Contact::query()->count());

        $contact = Contact::query()->firstOrFail();
        $this->assertSame('2348031234567', $contact->phone);
        $this->assertSame('Ada Okafor', $contact->name);

        $import->refresh();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(1, $import->imported_rows);
    }

    public function test_a_file_without_a_phone_column_fails_with_a_clear_reason(): void
    {
        $path = 'imports/no-phone.csv';
        Storage::disk('local')->put($path, "name,email\nAda,ada@example.com\n");

        $import = ContactImport::factory()->create([
            'user_id' => $this->admin()->id,
            'stored_path' => $path,
        ]);

        $this->assertFalse(app(ContactImportProcessor::class)->validate($import));

        $import->refresh();

        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertStringContainsString('phone', (string) $import->failure_reason);
    }

    public function test_uploading_a_file_through_the_component_queues_validation(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $this->actingAs($admin);

        Livewire::test(ImportsIndex::class)
            ->set('file', UploadedFile::fake()->createWithContent('contacts.csv', "name,phone\nAda,08031234567\n"))
            ->call('upload')
            ->assertHasNoErrors()
            ->assertRedirect();

        $import = ContactImport::query()->firstOrFail();

        $this->assertSame(ImportStatus::Pending, $import->status);
        $this->assertSame('contacts.csv', $import->original_filename);
        Storage::disk('local')->assertExists($import->stored_path);

        Queue::assertPushed(ProcessContactImportJob::class, fn ($job) => $job->importId === $import->id);
    }

    private function importWithCsv(): ContactImport
    {
        $path = 'imports/contacts.csv';
        Storage::disk('local')->put($path, self::CSV);

        return ContactImport::factory()->create([
            'user_id' => $this->admin()->id,
            'stored_path' => $path,
            'file_type' => 'csv',
            'max_rows' => 20000,
        ]);
    }
}
