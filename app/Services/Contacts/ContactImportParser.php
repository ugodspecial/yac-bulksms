<?php

namespace App\Services\Contacts;

use App\Models\ContactImport;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;
use ZipArchive;

/**
 * Streams rows out of uploaded CSV/XLSX contact files.
 *
 * The first row must contain headers. Recognised headers are mapped to the
 * contact fields; any other column is imported as a custom contact field.
 */
class ContactImportParser
{
    private const NAME_ALIASES = ['name', 'full_name', 'fullname', 'customer_name', 'contact_name', 'firstname_lastname'];

    private const PHONE_ALIASES = ['phone', 'phone_number', 'phonenumber', 'phone_no', 'mobile', 'mobile_number', 'msisdn', 'number', 'telephone', 'tel', 'whatsapp', 'whatsapp_number'];

    private const EMAIL_ALIASES = ['email', 'email_address', 'mail', 'e_mail'];

    private const IGNORED_HEADERS = ['id', 'row', 'row_number', 'sn', 's_n', 'no', 'index'];

    /**
     * @return Generator<int, array{row: int, name: string|null, phone: string, email: string|null, custom_fields: array<string, string>}>
     */
    public function rows(ContactImport $import): Generator
    {
        $path = $this->localPath($import);

        if ($path === null) {
            throw new ImportFileException('The uploaded file is no longer available. Please upload it again.');
        }

        if ($import->file_type === 'xlsx') {
            yield from $this->xlsxRows($path);
        } else {
            yield from $this->csvRows($path);
        }
    }

    private function localPath(ContactImport $import): ?string
    {
        $disk = Storage::disk($import->disk);

        if (! $disk->exists($import->stored_path)) {
            return null;
        }

        return $disk->path($import->stored_path);
    }

    /**
     * @return Generator<int, array{row: int, name: string|null, phone: string, email: string|null, custom_fields: array<string, string>}>
     */
    private function csvRows(string $path): Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new ImportFileException('The CSV file could not be opened.');
        }

        try {
            $delimiter = $this->detectDelimiter($path);
            $headers = null;
            $rowNumber = 0;

            while (($cells = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                $rowNumber++;

                if ($cells === [null] || $this->isEmptyRow($cells)) {
                    continue;
                }

                if ($headers === null) {
                    // Strip a UTF-8 BOM from the first header cell.
                    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]) ?? (string) $cells[0];
                    $headers = $this->mapping($cells);

                    continue;
                }

                $row = $this->buildRow($rowNumber, $headers, $cells);

                if ($row !== null) {
                    yield $row;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return Generator<int, array{row: int, name: string|null, phone: string, email: string|null, custom_fields: array<string, string>}>
     */
    private function xlsxRows(string $path): Generator
    {
        // An XLSX workbook is a ZIP archive. The extension is required by Composer,
        // but hosts that installed with --ignore-platform-req=ext-zip get a clear
        // message instead of a fatal "class not found".
        if (! class_exists(ZipArchive::class) || ! class_exists(XlsxReader::class)) {
            throw new ImportFileException(
                'Spreadsheet imports need the PHP zip extension and the openspout package. '
                .'Enable "extension=zip" in php.ini and run "composer install", or upload the contacts as CSV.'
            );
        }

        try {
            $reader = new XlsxReader();
            $reader->open($path);
        } catch (Throwable $e) {
            throw new ImportFileException(
                'The spreadsheet could not be opened. Check that the file is a valid .xlsx workbook: '.$e->getMessage()
            );
        }

        try {
            $headers = null;
            $rowNumber = 0;

            foreach ($reader->getSheetIterator() as $sheet) {
                // Only the first worksheet is imported.
                foreach ($sheet->getRowIterator() as $row) {
                    $rowNumber++;

                    // Keep the original column index: OpenSpout may omit empty cells.
                    $cells = [];

                    foreach ($row->getCells() as $cell) {
                        $cells[$cell->getIndex()] = $this->stringify($cell->getValue());
                    }

                    if ($this->isEmptyRow($cells)) {
                        continue;
                    }

                    if ($headers === null) {
                        $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]) ?? (string) $cells[0];
                        $headers = $this->mapping($cells);

                        continue;
                    }

                    $parsed = $this->buildRow($rowNumber, $headers, $cells);

                    if ($parsed !== null) {
                        yield $parsed;
                    }
                }

                break;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Map header cells to a target field per column index.
     *
     * @param  array<int, mixed>  $headers
     * @return array<int, array{target: string, key: string, header: string}>
     */
    private function mapping(array $headers): array
    {
        $map = [];
        $hasPhone = false;

        foreach ($headers as $index => $header) {
            $raw = trim($this->stringify($header));

            if ($raw === '') {
                continue;
            }

            $key = $this->normalizeHeader($raw);

            if (in_array($key, self::IGNORED_HEADERS, true)) {
                continue;
            }

            if (in_array($key, self::NAME_ALIASES, true)) {
                $map[$index] = ['target' => 'name', 'key' => 'name', 'header' => $raw];

                continue;
            }

            if (in_array($key, self::PHONE_ALIASES, true)) {
                $map[$index] = ['target' => 'phone', 'key' => 'phone', 'header' => $raw];
                $hasPhone = true;

                continue;
            }

            if (in_array($key, self::EMAIL_ALIASES, true)) {
                $map[$index] = ['target' => 'email', 'key' => 'email', 'header' => $raw];

                continue;
            }

            $map[$index] = [
                'target' => 'custom',
                'key' => mb_substr(preg_replace('/[^a-z0-9_]/', '_', $key) ?: 'field', 0, 40),
                'header' => $raw,
            ];
        }

        if (! $hasPhone) {
            throw new ImportFileException(
                'No phone number column was found. Rename a column to "phone" (or phone_number, mobile, msisdn) and upload the file again.'
            );
        }

        return $map;
    }

    /**
     * @param  array<int, array{target: string, key: string, header: string}>  $map
     * @param  array<int, mixed>  $cells
     * @return array{row: int, name: string|null, phone: string, email: string|null, custom_fields: array<string, string>}|null
     */
    private function buildRow(int $rowNumber, array $map, array $cells): ?array
    {
        $name = null;
        $phone = '';
        $email = null;
        $custom = [];
        $firstName = null;
        $lastName = null;

        foreach ($map as $index => $column) {
            $value = trim($this->stringify($cells[$index] ?? null));

            if ($column['target'] === 'name') {
                $name = $name ?? ($value !== '' ? $value : null);

                continue;
            }

            if ($column['target'] === 'phone') {
                if ($value !== '' && $phone === '') {
                    $phone = $value;
                }

                continue;
            }

            if ($column['target'] === 'email') {
                $email = $email ?? ($value !== '' ? $value : null);

                continue;
            }

            if ($value === '') {
                continue;
            }

            $key = $column['key'];

            if ($key === 'first_name' || $key === 'firstname') {
                $firstName = $value;

                continue;
            }

            if ($key === 'last_name' || $key === 'lastname' || $key === 'surname') {
                $lastName = $value;

                continue;
            }

            if (count($custom) < 20) {
                $custom[$key] = mb_substr($value, 0, 255);
            }
        }

        if ($name === null && ($firstName !== null || $lastName !== null)) {
            $name = trim(($firstName ?? '').' '.($lastName ?? ''));
        }

        if ($name === null && $phone === '' && $email === null && $custom === []) {
            return null;
        }

        return [
            'row' => $rowNumber,
            'name' => $name !== null ? mb_substr($name, 0, 120) : null,
            'phone' => $phone,
            'email' => $email !== null ? mb_substr($email, 0, 190) : null,
            'custom_fields' => $custom,
        ];
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    private function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim($this->stringify($cell)) !== '') {
                return false;
            }
        }

        return true;
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_float($value)) {
            // Avoid scientific notation for large phone numbers stored as numbers.
            return fmod($value, 1.0) === 0.0 ? number_format($value, 0, '', '') : (string) $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? '';

        return trim($header, '_');
    }

    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');
        $line = $handle !== false ? (string) fgets($handle, 8192) : '';

        if ($handle !== false) {
            fclose($handle);
        }

        $candidates = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];

        arsort($candidates);

        return (string) array_key_first($candidates);
    }
}
