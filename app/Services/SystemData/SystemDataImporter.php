<?php

namespace App\Services\SystemData;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\InventoryItem;
use App\Models\Package;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

class SystemDataImporter
{
    /**
     * Allowed booking lifecycle statuses.
     */
    public const ALLOWED_BOOKING_STATUSES = [
        'pending',
        'quotation_sent',
        'approved',
        'downpayment_received',
        'confirmed',
        'in_preparation',
        'event_in_progress',
        'event_completed',
        'pending_return',
        'pending_resolution',
        'completed',
        'declined',
        'cancelled',
        'cancellation_requested',
        'change_requested',
    ];

    /**
     * Allowed payment statuses and types.
     */
    public const ALLOWED_PAYMENT_STATUSES = ['pending', 'verified', 'rejected', 'failed'];
    public const ALLOWED_PAYMENT_TYPES = ['downpayment', 'final_payment', 'full_payment', 'damage_fee'];

    /**
     * Allowed return conditions and charge decisions.
     */
    public const ALLOWED_RETURN_STATUSES = ['Pending', 'Partially Returned', 'Completed', 'pending', 'partially_returned', 'completed'];
    public const ALLOWED_RETURN_CONDITIONS = ['pending', 'good', 'damaged', 'lost'];
    public const ALLOWED_CHARGE_DECISIONS = ['pending', 'no_charge', 'charge'];

    /**
     * Entity DATE column definitions (normalized to YYYY-MM-DD).
     */
    public const ENTITY_DATE_FIELDS = [
        'bookings' => [
            'event_date',
            'downpayment_date',
            'price_valid_until',
            'suggested_procurement_date',
            'preparation_start_date',
        ],
        'booking_items' => [
            'suggested_order_date',
            'suggested_delivery_date',
        ],
        'quotations' => [
            'valid_until',
        ],
        'returns' => [
            'return_date',
        ],
    ];

    /**
     * Entity DATETIME/TIMESTAMP column definitions (normalized to database-compatible format).
     */
    public const ENTITY_DATETIME_FIELDS = [
        'users' => [
            'email_verified_at',
            'created_at',
            'updated_at',
        ],
        'clients' => [
            'created_at',
            'updated_at',
        ],
        'inventory_items' => [
            'deleted_at',
            'created_at',
            'updated_at',
        ],
        'packages' => [
            'created_at',
            'updated_at',
        ],
        'inventory_item_package' => [
            'created_at',
            'updated_at',
        ],
        'inventory_item_substitutes' => [
            'created_at',
            'updated_at',
        ],
        'bookings' => [
            'confirmed_at',
            'created_at',
            'updated_at',
        ],
        'booking_items' => [
            'confirmed_at',
            'created_at',
            'updated_at',
        ],
        'ai_analysis_results' => [
            'analyzed_at',
            'created_at',
            'updated_at',
        ],
        'quotations' => [
            'reconfirmed_at',
            'created_at',
            'updated_at',
        ],
        'quotation_history' => [
            'created_at',
            'updated_at',
        ],
        'payments' => [
            'verified_at',
            'created_at',
            'updated_at',
        ],
        'inventory_transactions' => [
            'created_at',
            'updated_at',
        ],
        'staff_checklist_items' => [
            'completed_at',
            'created_at',
            'updated_at',
        ],
        'booking_messages' => [
            'created_at',
            'updated_at',
        ],
        'presentations' => [
            'sent_at',
            'created_at',
            'updated_at',
        ],
        'returns' => [
            'created_at',
            'updated_at',
        ],
        'return_items' => [
            'charge_decision_at',
            'created_at',
            'updated_at',
        ],
        'return_item_evidences' => [
            'created_at',
            'updated_at',
        ],
        'admin_alerts' => [
            'created_at',
            'updated_at',
        ],
        'client_notifications' => [
            'created_at',
            'updated_at',
        ],
        'audit_logs' => [
            'created_at',
            'updated_at',
        ],
        'settings' => [
            'created_at',
            'updated_at',
        ],
    ];

    /**
     * Extract archive securely and validate data without committing any database mutations.
     *
     * @return array Validation & preview report
     */
    public function preview(string $zipPath): array
    {
        $extractDir = $this->extractZipSecurely($zipPath);

        try {
            return $this->validateDataset($extractDir);
        } finally {
            File::deleteDirectory($extractDir);
        }
    }

    /**
     * Atomically import dataset into the database within a transaction.
     *
     * @throws RuntimeException If validation fails or import cannot be completed.
     */
    public function import(string $zipPath): array
    {
        $extractDir = $this->extractZipSecurely($zipPath);

        try {
            $validation = $this->validateDataset($extractDir);

            if (!$validation['isValid']) {
                $errorMessage = 'Import validation failed: ' . implode('; ', $validation['errors']);
                throw new RuntimeException($errorMessage);
            }

            return DB::transaction(function () use ($extractDir, $validation): array {
                return $this->executeImport($extractDir, $validation);
            });
        } catch (\Throwable $e) {
            throw new RuntimeException('Import failed. No changes were made: ' . $e->getMessage(), 0, $e);
        } finally {
            File::deleteDirectory($extractDir);
        }
    }

    /**
     * Securely extract ZIP archive guarding against Zip Slip and directory traversal.
     */
    public function extractZipSecurely(string $zipPath): string
    {
        if (!file_exists($zipPath)) {
            throw new InvalidArgumentException('Uploaded archive file does not exist.');
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            throw new InvalidArgumentException('Failed to open archive. File may be corrupted or not a valid ZIP.');
        }

        $targetDir = storage_path('app/tmp_system_data/extract_' . Str::random(16));
        File::ensureDirectoryExists($targetDir);
        $realTargetDir = realpath($targetDir);

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);

                // Zip Slip & traversal protection: disallow parent paths, absolute paths, null bytes
                if (
                    str_contains($entryName, '..') ||
                    str_starts_with($entryName, '/') ||
                    str_starts_with($entryName, '\\') ||
                    str_contains($entryName, "\0")
                ) {
                    throw new InvalidArgumentException("Unsafe entry detected in archive: [{$entryName}]. Extraction halted.");
                }

                $destination = $targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entryName);

                // If entry is directory
                if (str_ends_with($entryName, '/')) {
                    File::ensureDirectoryExists($destination);
                    continue;
                }

                File::ensureDirectoryExists(dirname($destination));

                // Verify normalized realpath does not escape target directory
                $parentDirReal = realpath(dirname($destination));
                if ($parentDirReal === false || !str_starts_with($parentDirReal, $realTargetDir)) {
                    throw new InvalidArgumentException("Entry path escapes target directory: [{$entryName}].");
                }

                $stream = $zip->getStream($entryName);
                if (!$stream) {
                    throw new InvalidArgumentException("Unable to read archive entry: [{$entryName}].");
                }

                file_put_contents($destination, stream_get_contents($stream));
                fclose($stream);
            }
        } finally {
            $zip->close();
        }

        return $targetDir;
    }

    /**
     * Validate structure, manifest, schemas, relational integrity, and business rules.
     */
    public function validateDataset(string $extractDir): array
    {
        $errors = [];
        $warnings = [];
        $recordCounts = [];

        // 1. Structure validation
        $manifestPath = $extractDir . '/manifest.json';
        if (!file_exists($manifestPath)) {
            $errors[] = 'Missing manifest.json in the archive root.';
            return [
                'isValid' => false,
                'manifest' => null,
                'record_counts' => [],
                'warnings' => [],
                'errors' => $errors,
            ];
        }

        $manifestContent = file_get_contents($manifestPath);
        $manifest = json_decode($manifestContent, true);
        if (!is_array($manifest)) {
            $errors[] = 'manifest.json is not valid JSON.';
            return [
                'isValid' => false,
                'manifest' => null,
                'record_counts' => [],
                'warnings' => [],
                'errors' => $errors,
            ];
        }

        // Validate manifest fields
        if (($manifest['format'] ?? '') !== 'raflora-system-data') {
            $errors[] = "Invalid format: expected 'raflora-system-data', received '{$manifest['format']}'.";
        }
        if ((int) ($manifest['format_version'] ?? 0) !== 1) {
            $errors[] = "Unsupported format version: {$manifest['format_version']}. Expected version 1.";
        }

        $currentFingerprint = SystemDataExporter::computeSchemaFingerprint();
        $importedFingerprint = $manifest['schema_fingerprint'] ?? '';
        if ($importedFingerprint && $importedFingerprint !== $currentFingerprint) {
            $warnings[] = "Schema fingerprint differs from current system migration state. Dataset may be from a different version.";
        }

        $dataDir = $extractDir . '/data';
        if (!is_dir($dataDir)) {
            $errors[] = 'Missing data/ directory in the archive.';
            return [
                'isValid' => false,
                'manifest' => $manifest,
                'record_counts' => [],
                'warnings' => $warnings,
                'errors' => $errors,
            ];
        }

        // 2. Load and validate entity JSON files
        $expectedFiles = [
            'users' => 'users.json',
            'clients' => 'clients.json',
            'inventory_items' => 'inventory_items.json',
            'packages' => 'packages.json',
            'package_materials' => 'package_materials.json',
            'inventory_item_substitutes' => 'inventory_item_substitutes.json',
            'bookings' => 'bookings.json',
            'booking_items' => 'booking_items.json',
            'ai_analysis_results' => 'ai_analysis_results.json',
            'quotations' => 'quotations.json',
            'quotation_history' => 'quotation_history.json',
            'payments' => 'payments.json',
            'inventory_transactions' => 'inventory_transactions.json',
            'staff_checklist_items' => 'staff_checklist_items.json',
            'booking_messages' => 'booking_messages.json',
            'presentations' => 'presentations.json',
            'returns' => 'returns.json',
            'return_items' => 'return_items.json',
            'return_item_evidences' => 'return_item_evidences.json',
            'admin_alerts' => 'admin_alerts.json',
            'client_notifications' => 'client_notifications.json',
            'audit_logs' => 'audit_logs.json',
            'settings' => 'settings.json',
        ];

        $data = [];
        $keyRegistry = [];

        foreach ($expectedFiles as $entityName => $fileName) {
            $filePath = $dataDir . '/' . $fileName;
            if (!file_exists($filePath)) {
                // Settings is optional, others required
                if ($entityName === 'settings') {
                    $data[$entityName] = [];
                    $recordCounts[$entityName] = 0;
                    continue;
                }
                $errors[] = "Missing required entity file: data/{$fileName}.";
                continue;
            }

            $content = file_get_contents($filePath);
            $parsed = json_decode($content, true);

            if ($parsed === null && trim($content) !== 'null') {
                $errors[] = "Invalid JSON in data/{$fileName}.";
                continue;
            }

            $data[$entityName] = $parsed ?? [];
            $recordCounts[$entityName] = is_array($parsed) ? count($parsed) : 0;

            // Collect keys and check duplicates within each entity list
            if (is_array($parsed) && $entityName !== 'settings') {
                $keyRegistry[$entityName] = [];
                foreach ($parsed as $idx => $record) {
                    if (!isset($record['key'])) {
                        $errors[] = "Missing 'key' field in data/{$fileName} at record #{$idx}.";
                        continue;
                    }

                    $k = (string) $record['key'];
                    if (isset($keyRegistry[$entityName][$k])) {
                        $errors[] = "Duplicate dataset key '{$k}' in data/{$fileName}.";
                    }
                    $keyRegistry[$entityName][$k] = true;
                }
            }
        }

        if (!empty($errors)) {
            return [
                'isValid' => false,
                'manifest' => $manifest,
                'record_counts' => $recordCounts,
                'warnings' => $warnings,
                'errors' => $errors,
            ];
        }

        // 3. Validate user references: Referenced admin/staff users must exist in the database!
        $userKeyToEmail = [];
        if (!empty($data['users'])) {
            foreach ($data['users'] as $u) {
                $userKeyToEmail[$u['key']] = $u['email'] ?? null;
            }
        }

        $referencedUserKeys = [];

        // Collect referenced users from all entities
        foreach ($data['bookings'] as $b) {
            if (!empty($b['handled_by_ref'])) $referencedUserKeys[$b['handled_by_ref']] = true;
            if (!empty($b['staff_ref'])) $referencedUserKeys[$b['staff_ref']] = true;
        }
        foreach ($data['quotations'] as $q) {
            if (!empty($q['issued_by_ref'])) $referencedUserKeys[$q['issued_by_ref']] = true;
        }
        foreach ($data['payments'] as $p) {
            if (!empty($p['verified_by_ref'])) $referencedUserKeys[$p['verified_by_ref']] = true;
            if (!empty($p['recorded_by_ref'])) $referencedUserKeys[$p['recorded_by_ref']] = true;
        }
        foreach ($data['returns'] as $r) {
            if (!empty($r['inspected_by_ref'])) $referencedUserKeys[$r['inspected_by_ref']] = true;
        }
        foreach ($data['return_items'] as $ri) {
            if (!empty($ri['charge_decision_by_ref'])) $referencedUserKeys[$ri['charge_decision_by_ref']] = true;
        }
        foreach ($data['inventory_transactions'] as $tx) {
            if (!empty($tx['performed_by_ref'])) $referencedUserKeys[$tx['performed_by_ref']] = true;
        }

        foreach (array_keys($referencedUserKeys) as $uRef) {
            $email = $userKeyToEmail[$uRef] ?? null;
            if (!$email) {
                $errors[] = "Referenced user '{$uRef}' is not declared in users.json.";
                continue;
            }

            $userExists = User::where('email', $email)->exists();
            if (!$userExists) {
                $errors[] = "Referenced user account '{$email}' ({$uRef}) does not exist in the system database.";
            }
        }

        // 4. Validate relational references (*_ref)
        foreach ($data['package_materials'] as $pm) {
            if (!isset($keyRegistry['packages'][$pm['package_ref'] ?? ''])) {
                $errors[] = "Package material '{$pm['key']}' references non-existent package '{$pm['package_ref']}'.";
            }
            if (!isset($keyRegistry['inventory_items'][$pm['inventory_item_ref'] ?? ''])) {
                $errors[] = "Package material '{$pm['key']}' references non-existent inventory item '{$pm['inventory_item_ref']}'.";
            }
        }

        foreach ($data['inventory_item_substitutes'] as $sub) {
            if (!isset($keyRegistry['inventory_items'][$sub['item_ref'] ?? ''])) {
                $errors[] = "Substitute '{$sub['key']}' references non-existent item '{$sub['item_ref']}'.";
            }
            if (!isset($keyRegistry['inventory_items'][$sub['substitute_ref'] ?? ''])) {
                $errors[] = "Substitute '{$sub['key']}' references non-existent item '{$sub['substitute_ref']}'.";
            }
        }

        foreach ($data['bookings'] as $b) {
            if (!empty($b['client_ref']) && !isset($keyRegistry['clients'][$b['client_ref']])) {
                $errors[] = "Booking '{$b['key']}' references non-existent client '{$b['client_ref']}'.";
            }
            if (!empty($b['package_ref']) && !isset($keyRegistry['packages'][$b['package_ref']])) {
                $errors[] = "Booking '{$b['key']}' references non-existent package '{$b['package_ref']}'.";
            }

            // Status check
            $status = (string) ($b['status'] ?? '');
            if (!in_array($status, self::ALLOWED_BOOKING_STATUSES, true)) {
                $errors[] = "Booking '{$b['key']}' has invalid status '{$status}'.";
            }

            // Financial ranges
            if (isset($b['total_quoted']) && $b['total_quoted'] !== null && (float) $b['total_quoted'] < 0) {
                $errors[] = "Booking '{$b['key']}' has negative total_quoted.";
            }
            if (isset($b['downpayment_amount']) && $b['downpayment_amount'] !== null && (float) $b['downpayment_amount'] < 0) {
                $errors[] = "Booking '{$b['key']}' has negative downpayment_amount.";
            }
        }

        foreach ($data['booking_items'] as $bi) {
            if (!isset($keyRegistry['bookings'][$bi['booking_ref'] ?? ''])) {
                $errors[] = "Booking item '{$bi['key']}' references non-existent booking '{$bi['booking_ref']}'.";
            }
            if (!empty($bi['inventory_item_ref']) && !isset($keyRegistry['inventory_items'][$bi['inventory_item_ref']])) {
                $errors[] = "Booking item '{$bi['key']}' references non-existent inventory item '{$bi['inventory_item_ref']}'.";
            }
            if ((float) ($bi['quantity'] ?? 0) <= 0) {
                $errors[] = "Booking item '{$bi['key']}' must have quantity greater than 0.";
            }
        }

        foreach ($data['quotations'] as $q) {
            if (!isset($keyRegistry['bookings'][$q['booking_ref'] ?? ''])) {
                $errors[] = "Quotation '{$q['key']}' references non-existent booking '{$q['booking_ref']}'.";
            }
            if (isset($q['final_quoted_price']) && (float) $q['final_quoted_price'] < 0) {
                $errors[] = "Quotation '{$q['key']}' has negative final_quoted_price.";
            }
        }

        foreach ($data['payments'] as $p) {
            if (!isset($keyRegistry['bookings'][$p['booking_ref'] ?? ''])) {
                $errors[] = "Payment '{$p['key']}' references non-existent booking '{$p['booking_ref']}'.";
            }
            if (!empty($p['quotation_ref']) && !isset($keyRegistry['quotations'][$p['quotation_ref']])) {
                $errors[] = "Payment '{$p['key']}' references non-existent quotation '{$p['quotation_ref']}'.";
            }
            if (!in_array($p['status'] ?? '', self::ALLOWED_PAYMENT_STATUSES, true)) {
                $errors[] = "Payment '{$p['key']}' has invalid status '{$p['status']}'.";
            }
            if ((float) ($p['amount'] ?? 0) < 0) {
                $errors[] = "Payment '{$p['key']}' has negative amount.";
            }
        }

        foreach ($data['inventory_transactions'] as $tx) {
            if (!isset($keyRegistry['inventory_items'][$tx['inventory_item_ref'] ?? ''])) {
                $errors[] = "Inventory transaction '{$tx['key']}' references non-existent item '{$tx['inventory_item_ref']}'.";
            }
            if (!empty($tx['booking_ref']) && !isset($keyRegistry['bookings'][$tx['booking_ref']])) {
                $errors[] = "Inventory transaction '{$tx['key']}' references non-existent booking '{$tx['booking_ref']}'.";
            }
            if (!empty($tx['reference_transaction_ref']) && !isset($keyRegistry['inventory_transactions'][$tx['reference_transaction_ref']])) {
                $errors[] = "Inventory transaction '{$tx['key']}' references non-existent reference transaction '{$tx['reference_transaction_ref']}'.";
            }

            // Ledger sign convention: the application stores a release as a positive quantity and
            // hold calculations subtract it (InventoryItem::reservedForBooking), so a negative
            // release would read as an extra hold. Refused rather than guessed.
            // Types are not limited to a list: older versions wrote types such as 'consumed' and
            // 'event_started'. A row without a type cannot be read by any calculation.
            if (!is_string($tx['transaction_type'] ?? null) || trim($tx['transaction_type']) === '') {
                $errors[] = "Inventory transaction '{$tx['key']}' has no transaction_type.";
            }

            if (!isset($tx['quantity_change']) || !is_numeric($tx['quantity_change'])) {
                $errors[] = "Inventory transaction '{$tx['key']}' has a missing or non-numeric quantity_change.";
            } elseif (($tx['transaction_type'] ?? null) === 'booking_release' && (float) $tx['quantity_change'] <= 0) {
                $errors[] = "Inventory transaction '{$tx['key']}' is a release and must have a positive quantity_change (got {$tx['quantity_change']}).";
            } elseif (($tx['transaction_type'] ?? null) === 'booking_lock' && (float) $tx['quantity_change'] > 0) {
                // Every application version stored a hold as -quantity (one wrote 0 as a marker);
                // some readers count only negative holds, so a positive one would be read two ways.
                $errors[] = "Inventory transaction '{$tx['key']}' is a hold and must have a negative quantity_change, or 0 for an older marker (got {$tx['quantity_change']}).";
            }
        }

        foreach ($data['returns'] as $r) {
            if (!isset($keyRegistry['bookings'][$r['booking_ref'] ?? ''])) {
                $errors[] = "Return '{$r['key']}' references non-existent booking '{$r['booking_ref']}'.";
            }
            if (!in_array($r['status'] ?? '', self::ALLOWED_RETURN_STATUSES, true)) {
                $errors[] = "Return '{$r['key']}' has invalid status '{$r['status']}'.";
            }
        }

        foreach ($data['return_items'] as $ri) {
            if (!isset($keyRegistry['returns'][$ri['return_ref'] ?? ''])) {
                $errors[] = "Return item '{$ri['key']}' references non-existent return '{$ri['return_ref']}'.";
            }
            if (!isset($keyRegistry['inventory_items'][$ri['inventory_item_ref'] ?? ''])) {
                $errors[] = "Return item '{$ri['key']}' references non-existent inventory item '{$ri['inventory_item_ref']}'.";
            }
            if (!empty($ri['condition']) && !in_array($ri['condition'], self::ALLOWED_RETURN_CONDITIONS, true)) {
                $errors[] = "Return item '{$ri['key']}' has invalid condition '{$ri['condition']}'.";
            }
            if (!empty($ri['charge_decision']) && !in_array($ri['charge_decision'], self::ALLOWED_CHARGE_DECISIONS, true)) {
                $errors[] = "Return item '{$ri['key']}' has invalid charge_decision '{$ri['charge_decision']}'.";
            }
        }

        foreach ($data['return_item_evidences'] as $ev) {
            if (!isset($keyRegistry['return_items'][$ev['return_item_ref'] ?? ''])) {
                $errors[] = "Return evidence '{$ev['key']}' references non-existent return item '{$ev['return_item_ref']}'.";
            }
        }

        // 5. Validate DATE and DATETIME fields across all entities
        foreach ($data as $entityName => $records) {
            if (!is_array($records)) {
                continue;
            }

            $dateFields = self::ENTITY_DATE_FIELDS[$entityName] ?? [];
            $dateTimeFields = self::ENTITY_DATETIME_FIELDS[$entityName] ?? [];

            if (empty($dateFields) && empty($dateTimeFields)) {
                continue;
            }

            foreach ($records as $idx => $record) {
                if (!is_array($record)) {
                    continue;
                }
                $recordKey = $record['key'] ?? "record #{$idx}";

                foreach ($dateFields as $field) {
                    if (isset($record[$field]) && $record[$field] !== null && $record[$field] !== '') {
                        if (!DateTimeNormalizer::isValidDate($record[$field])) {
                            $errors[] = "Invalid date value for {$entityName}.{$field} in record '{$recordKey}'.";
                        }
                    }
                }

                foreach ($dateTimeFields as $field) {
                    if (isset($record[$field]) && $record[$field] !== null && $record[$field] !== '') {
                        if (!DateTimeNormalizer::isValidDateTime($record[$field])) {
                            $errors[] = "Invalid datetime value for {$entityName}.{$field} in record '{$recordKey}'.";
                        }
                    }
                }
            }
        }

        return [
            'isValid' => empty($errors),
            'manifest' => $manifest,
            'record_counts' => $recordCounts,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    /**
     * Execute the atomic database inserts in strict foreign-key dependency order.
     */
    protected function executeImport(string $extractDir, array $validation): array
    {
        $dataDir = $extractDir . '/data';
        $datasetId = $validation['manifest']['dataset_id'] ?? 'unknown';

        $idMaps = [
            'users' => [],
            'clients' => [],
            'inventory_items' => [],
            'packages' => [],
            'bookings' => [],
            'quotations' => [],
            'returns' => [],
            'return_items' => [],
            'inventory_transactions' => [],
        ];

        // 1. Resolve User references
        $usersData = $this->readJson($dataDir . '/users.json');
        foreach ($usersData as $u) {
            $existingUser = User::where('email', $u['email'])->first();
            if ($existingUser) {
                $idMaps['users'][$u['key']] = $existingUser->id;
            }
        }

        // 2. Clients
        $clientsData = $this->readJson($dataDir . '/clients.json');
        foreach ($clientsData as $c) {
            $existing = Client::where('email', $c['email'])->first();
            if ($existing) {
                $existing->update([
                    'full_name' => $c['full_name'],
                    'phone' => $c['phone'] ?? $existing->phone,
                    'address' => $c['address'] ?? $existing->address,
                    'notes' => $c['notes'] ?? $existing->notes,
                ]);
                $idMaps['clients'][$c['key']] = $existing->id;
            } else {
                $id = DB::table('clients')->insertGetId([
                    'full_name' => $c['full_name'],
                    'email' => $c['email'],
                    'phone' => $c['phone'] ?? null,
                    'address' => $c['address'] ?? null,
                    'notes' => $c['notes'] ?? null,
                    'created_at' => DateTimeNormalizer::normalizeDateTime($c['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                    'updated_at' => DateTimeNormalizer::normalizeDateTime($c['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                ]);
                $idMaps['clients'][$c['key']] = $id;
            }
        }

        // 3. Inventory Items
        $inventoryData = $this->readJson($dataDir . '/inventory_items.json');
        foreach ($inventoryData as $item) {
            $existing = null;
            if (!empty($item['item_code'])) {
                $existing = InventoryItem::withTrashed()->where('item_code', $item['item_code'])->first();
            }

            if ($existing) {
                $existing->update([
                    'name' => $item['name'],
                    'category' => $item['category'],
                    'is_perishable' => (bool) $item['is_perishable'],
                    'current_stock' => (float) $item['current_stock'],
                    'unit_cost' => (float) $item['unit_cost'],
                    'min_stock' => (float) $item['min_stock'],
                    'unit' => $item['unit'],
                    'image_path' => $item['image_path'] ?? $existing->image_path,
                ]);
                $idMaps['inventory_items'][$item['key']] = $existing->id;
            } else {
                $id = DB::table('inventory_items')->insertGetId([
                    'item_code' => $item['item_code'] ?? null,
                    'image_path' => $item['image_path'] ?? null,
                    'name' => $item['name'],
                    'category' => $item['category'],
                    'is_perishable' => (bool) $item['is_perishable'],
                    'current_stock' => (float) $item['current_stock'],
                    'unit_cost' => (float) $item['unit_cost'],
                    'min_stock' => (float) $item['min_stock'],
                    'unit' => $item['unit'],
                    'deleted_at' => DateTimeNormalizer::normalizeDateTime($item['deleted_at'] ?? null),
                    'created_at' => DateTimeNormalizer::normalizeDateTime($item['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                    'updated_at' => DateTimeNormalizer::normalizeDateTime($item['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                ]);
                $idMaps['inventory_items'][$item['key']] = $id;
            }
        }

        // 4. Packages
        $packagesData = $this->readJson($dataDir . '/packages.json');
        foreach ($packagesData as $pkg) {
            $existing = null;
            if (!empty($pkg['package_code'])) {
                $existing = Package::where('package_code', $pkg['package_code'])->first();
            }

            if ($existing) {
                $existing->update([
                    'title' => $pkg['title'],
                    'category' => $pkg['category'] ?? $existing->category,
                    'description' => $pkg['description'],
                    'price' => (float) $pkg['price'],
                    'included_items' => $pkg['included_items'],
                    'is_active' => (bool) ($pkg['is_active'] ?? true),
                    'is_archived' => (bool) ($pkg['is_archived'] ?? false),
                ]);
                $idMaps['packages'][$pkg['key']] = $existing->id;
            } else {
                $id = DB::table('packages')->insertGetId([
                    'package_code' => $pkg['package_code'],
                    'title' => $pkg['title'],
                    'category' => $pkg['category'] ?? null,
                    'description' => $pkg['description'] ?? null,
                    'price' => (float) $pkg['price'],
                    'included_items' => json_encode($pkg['included_items'] ?? []),
                    'image_path' => $pkg['image_path'] ?? null,
                    'is_active' => (bool) ($pkg['is_active'] ?? true),
                    'is_archived' => (bool) ($pkg['is_archived'] ?? false),
                    'created_at' => DateTimeNormalizer::normalizeDateTime($pkg['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                    'updated_at' => DateTimeNormalizer::normalizeDateTime($pkg['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                ]);
                $idMaps['packages'][$pkg['key']] = $id;
            }
        }

        // 5. Package Materials (Pivot)
        $materialsData = $this->readJson($dataDir . '/package_materials.json');
        foreach ($materialsData as $pm) {
            $pkgId = $idMaps['packages'][$pm['package_ref']] ?? null;
            $invId = $idMaps['inventory_items'][$pm['inventory_item_ref']] ?? null;

            if ($pkgId && $invId) {
                DB::table('inventory_item_package')->updateOrInsert(
                    ['package_id' => $pkgId, 'inventory_item_id' => $invId],
                    [
                        'quantity' => (float) ($pm['quantity'] ?? 1),
                        'created_at' => DateTimeNormalizer::normalizeDateTime($pm['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                        'updated_at' => DateTimeNormalizer::normalizeDateTime($pm['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                    ]
                );
            }
        }

        // 6. Inventory Substitutes
        $subsData = $this->readJson($dataDir . '/inventory_item_substitutes.json');
        foreach ($subsData as $sub) {
            $itemId = $idMaps['inventory_items'][$sub['item_ref']] ?? null;
            $subId = $idMaps['inventory_items'][$sub['substitute_ref']] ?? null;

            if ($itemId && $subId) {
                DB::table('inventory_item_substitutes')->updateOrInsert(
                    ['item_id' => $itemId, 'substitute_id' => $subId],
                    [
                        'created_at' => DateTimeNormalizer::normalizeDateTime($sub['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                        'updated_at' => DateTimeNormalizer::normalizeDateTime($sub['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                    ]
                );
            }
        }

        // 7. Bookings
        $bookingsData = $this->readJson($dataDir . '/bookings.json');
        foreach ($bookingsData as $b) {
            $clientId = !empty($b['client_ref']) ? ($idMaps['clients'][$b['client_ref']] ?? null) : null;
            $pkgId = !empty($b['package_ref']) ? ($idMaps['packages'][$b['package_ref']] ?? null) : null;
            $handledBy = !empty($b['handled_by_ref']) ? ($idMaps['users'][$b['handled_by_ref']] ?? null) : null;
            $staffId = !empty($b['staff_ref']) ? ($idMaps['users'][$b['staff_ref']] ?? null) : null;

            $bookingId = DB::table('bookings')->insertGetId([
                'client_id' => $clientId,
                'package_id' => $pkgId,
                'handled_by' => $handledBy,
                'staff_id' => $staffId,
                'event_type' => $b['event_type'],
                'event_date' => DateTimeNormalizer::normalizeDate($b['event_date'] ?? null),
                'event_time' => $b['event_time'] ?? null,
                'event_size' => $b['event_size'] ?? null,
                'table_count' => $b['table_count'] ?? null,
                'venue' => $b['venue'] ?? null,
                'venue_latitude' => $b['venue_latitude'] ?? null,
                'venue_longitude' => $b['venue_longitude'] ?? null,
                'venue_setting' => $b['venue_setting'] ?? null,
                'end_time' => $b['end_time'] ?? null,
                // Raw insert (no model casts), so the brief is encoded here.
                'design_brief' => !empty($b['design_brief']) ? json_encode($b['design_brief']) : null,
                'special_requests' => $b['special_requests'] ?? null,
                'inspiration_image' => $b['inspiration_image'] ?? null,
                'status' => $b['status'] ?? 'pending',
                'pre_cancellation_status' => $b['pre_cancellation_status'] ?? null,
                'confirmed_at' => DateTimeNormalizer::normalizeDateTime($b['confirmed_at'] ?? null),
                'downpayment_amount' => $b['downpayment_amount'] ?? null,
                'downpayment_date' => DateTimeNormalizer::normalizeDate($b['downpayment_date'] ?? null),
                'total_quoted' => $b['total_quoted'] ?? null,
                'price_valid_until' => DateTimeNormalizer::normalizeDate($b['price_valid_until'] ?? null),
                'suggested_procurement_date' => DateTimeNormalizer::normalizeDate($b['suggested_procurement_date'] ?? null),
                'preparation_start_date' => DateTimeNormalizer::normalizeDate($b['preparation_start_date'] ?? null),
                'preparation_status' => $b['preparation_status'] ?? 'scheduled',
                'cancellation_reason' => $b['cancellation_reason'] ?? null,
                'admin_notes' => $b['admin_notes'] ?? null,
                'raw_materials_sum' => $b['raw_materials_sum'] ?? 0.00,
                'multiplier' => $b['multiplier'] ?? 3.00,
                'final_quoted_price' => $b['final_quoted_price'] ?? 0.00,
                'guest_name' => $b['guest_name'] ?? null,
                'guest_email' => $b['guest_email'] ?? null,
                'guest_phone' => $b['guest_phone'] ?? null,
                'guest_address' => $b['guest_address'] ?? null,
                'ai_analysis_data' => is_array($b['ai_analysis_data'] ?? null) ? json_encode($b['ai_analysis_data']) : ($b['ai_analysis_data'] ?? null),
                'labor_method' => $b['labor_method'] ?? 'markup',
                'labor_rate' => $b['labor_rate'] ?? null,
                'created_at' => DateTimeNormalizer::normalizeDateTime($b['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                'updated_at' => DateTimeNormalizer::normalizeDateTime($b['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
            ]);

            $idMaps['bookings'][$b['key']] = $bookingId;
        }

        // 8. Booking Items
        $bookingItemsData = $this->readJson($dataDir . '/booking_items.json');
        foreach ($bookingItemsData as $bi) {
            $bookingId = $idMaps['bookings'][$bi['booking_ref']] ?? null;
            $invId = !empty($bi['inventory_item_ref']) ? ($idMaps['inventory_items'][$bi['inventory_item_ref']] ?? null) : null;

            if ($bookingId) {
                DB::table('booking_items')->insert([
                    'booking_id' => $bookingId,
                    'inventory_item_id' => $invId,
                    'item_name' => $bi['item_name'],
                    'quantity' => (float) $bi['quantity'],
                    'quoted_unit_price' => $bi['quoted_unit_price'] ?? null,
                    'ai_recommended_price' => $bi['ai_recommended_price'] ?? null,
                    'is_ai_suggested' => (bool) ($bi['is_ai_suggested'] ?? false),
                    'confirmed_at' => DateTimeNormalizer::normalizeDateTime($bi['confirmed_at'] ?? null),
                    'procurement_status' => $bi['procurement_status'] ?? 'pending',
                    'fresh_procurement_override' => $bi['fresh_procurement_override'] ?? null,
                    'suggested_order_date' => DateTimeNormalizer::normalizeDate($bi['suggested_order_date'] ?? null),
                    'suggested_delivery_date' => DateTimeNormalizer::normalizeDate($bi['suggested_delivery_date'] ?? null),
                    'notes' => $bi['notes'] ?? null,
                    'created_at' => DateTimeNormalizer::normalizeDateTime($bi['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                    'updated_at' => DateTimeNormalizer::normalizeDateTime($bi['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                ]);
            }
        }

        // 9. AI Analysis Results
        $aiData = $this->readJson($dataDir . '/ai_analysis_results.json');
        foreach ($aiData as $ai) {
            $bookingId = $idMaps['bookings'][$ai['booking_ref']] ?? null;
            if ($bookingId) {
                DB::table('ai_analysis_results')->insert([
                    'booking_id' => $bookingId,
                    'raw_gemini_response' => $ai['raw_gemini_response'] ?? null,
                    'suggested_materials' => is_array($ai['suggested_materials'] ?? null) ? json_encode($ai['suggested_materials']) : ($ai['suggested_materials'] ?? null),
                    'analyzed_at' => DateTimeNormalizer::normalizeDateTime($ai['analyzed_at'] ?? null),
                    'created_at' => DateTimeNormalizer::normalizeDateTime($ai['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                    'updated_at' => DateTimeNormalizer::normalizeDateTime($ai['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                ]);
            }
        }

        // 10. Quotations
        $quotationsData = $this->readJson($dataDir . '/quotations.json');
        foreach ($quotationsData as $q) {
            $bookingId = $idMaps['bookings'][$q['booking_ref']] ?? null;
            $issuedBy = !empty($q['issued_by_ref']) ? ($idMaps['users'][$q['issued_by_ref']] ?? null) : null;

            if ($bookingId) {
                $qId = DB::table('quotations')->insertGetId([
                    'booking_id' => $bookingId,
                    'issued_by' => $issuedBy,
                    'suggested_florals' => is_array($q['suggested_florals'] ?? null) ? json_encode($q['suggested_florals']) : ($q['suggested_florals'] ?? null),
                    'recommended_price' => $q['recommended_price'] ?? null,
                    'status' => $q['status'] ?? 'pending',
                    'valid_until' => DateTimeNormalizer::normalizeDate($q['valid_until'] ?? null),
                    'version' => (int) ($q['version'] ?? 1),
                    'raw_materials_sum' => $q['raw_materials_sum'] ?? null,
                    'multiplier' => $q['multiplier'] ?? null,
                    'labor_method' => $q['labor_method'] ?? null,
                    'labor_rate' => $q['labor_rate'] ?? null,
                    'labor_amount' => $q['labor_amount'] ?? null,
                    'final_quoted_price' => $q['final_quoted_price'] ?? null,
                    'downpayment_percentage' => $q['downpayment_percentage'] ?? null,
                    'items_snapshot' => is_array($q['items_snapshot'] ?? null) ? json_encode($q['items_snapshot']) : ($q['items_snapshot'] ?? null),
                    'is_tentative' => (bool) ($q['is_tentative'] ?? false),
                    'reconfirmed_at' => DateTimeNormalizer::normalizeDateTime($q['reconfirmed_at'] ?? null),
                    'created_at' => DateTimeNormalizer::normalizeDateTime($q['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                    'updated_at' => DateTimeNormalizer::normalizeDateTime($q['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                ]);
                $idMaps['quotations'][$q['key']] = $qId;
            }
        }

        // 11. Quotation History
        $histories = $this->readJson($dataDir . '/quotation_history.json');
        foreach ($histories as $qh) {
            $bookingId = $idMaps['bookings'][$qh['booking_ref']] ?? null;
            $changedBy = !empty($qh['changed_by_ref']) ? ($idMaps['users'][$qh['changed_by_ref']] ?? null) : null;

            if ($bookingId) {
                DB::table('quotation_history')->insert([
                    'booking_id' => $bookingId,
                    'changed_by' => $changedBy,
                    'field_changed' => $qh['field_changed'] ?? '',
                    'old_value' => is_array($qh['old_value'] ?? null) ? json_encode($qh['old_value']) : ($qh['old_value'] ?? null),
                    'new_value' => is_array($qh['new_value'] ?? null) ? json_encode($qh['new_value']) : ($qh['new_value'] ?? null),
                    'reason' => $qh['reason'] ?? null,
                    'created_at' => DateTimeNormalizer::normalizeDateTime($qh['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                    'updated_at' => DateTimeNormalizer::normalizeDateTime($qh['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                ]);
            }
        }

        // 12. Payments
        $paymentsData = $this->readJson($dataDir . '/payments.json');
        foreach ($paymentsData as $p) {
            $bookingId = $idMaps['bookings'][$p['booking_ref']] ?? null;
            $qId = !empty($p['quotation_ref']) ? ($idMaps['quotations'][$p['quotation_ref']] ?? null) : null;
            $verifiedBy = !empty($p['verified_by_ref']) ? ($idMaps['users'][$p['verified_by_ref']] ?? null) : null;
            $recordedBy = !empty($p['recorded_by_ref']) ? ($idMaps['users'][$p['recorded_by_ref']] ?? null) : null;

            if ($bookingId) {
                DB::table('payments')->insert([
                    'booking_id' => $bookingId,
                    'quotation_id' => $qId,
                    'amount' => (float) $p['amount'],
                    'payment_type' => $p['payment_type'] ?? 'downpayment',
                    'payment_option' => $p['payment_option'] ?? 'cash',
                    'amount_paid' => $p['amount_paid'] ?? null,
                    'remaining_balance' => $p['remaining_balance'] ?? null,
                    'verified_by' => $verifiedBy,
                    'verified_at' => DateTimeNormalizer::normalizeDateTime($p['verified_at'] ?? null),
                    'reference_number' => $p['reference_number'] ?? ('REF-' . Str::random(8)),
                    'status' => $p['status'] ?? 'pending',
                    'recorded_by' => $recordedBy,
                    'created_at' => DateTimeNormalizer::normalizeDateTime($p['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                    'updated_at' => DateTimeNormalizer::normalizeDateTime($p['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                ]);
            }
        }

        // 13. Inventory Transactions (Two-pass for self-reference)
        $txData = $this->readJson($dataDir . '/inventory_transactions.json');
        foreach ($txData as $tx) {
            $invId = $idMaps['inventory_items'][$tx['inventory_item_ref']] ?? null;
            $bookingId = !empty($tx['booking_ref']) ? ($idMaps['bookings'][$tx['booking_ref']] ?? null) : null;
            $performedBy = !empty($tx['performed_by_ref']) ? ($idMaps['users'][$tx['performed_by_ref']] ?? null) : null;
            $refTxId = !empty($tx['reference_transaction_ref']) ? ($idMaps['inventory_transactions'][$tx['reference_transaction_ref']] ?? null) : null;

            if ($invId) {
                $txId = DB::table('inventory_transactions')->insertGetId([
                    'inventory_item_id' => $invId,
                    'booking_id' => $bookingId,
                    'reference_transaction_id' => $refTxId,
                    'quantity_change' => (float) $tx['quantity_change'],
                    'transaction_type' => $tx['transaction_type'] ?? null,
                    'reason' => $tx['reason'] ?? null,
                    'performed_by' => $performedBy,
                    'created_at' => DateTimeNormalizer::normalizeDateTime($tx['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                    'updated_at' => DateTimeNormalizer::normalizeDateTime($tx['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                ]);
                $idMaps['inventory_transactions'][$tx['key']] = $txId;
            }
        }

        // 14. Staff Checklist Items
        $checklists = $this->readJson($dataDir . '/staff_checklist_items.json');
        foreach ($checklists as $sci) {
            $bookingId = $idMaps['bookings'][$sci['booking_ref']] ?? null;
            $completedBy = !empty($sci['completed_by_ref']) ? ($idMaps['users'][$sci['completed_by_ref']] ?? null) : null;

            if ($bookingId) {
                DB::table('staff_checklist_items')->updateOrInsert(
                    ['booking_id' => $bookingId, 'key' => $sci['checklist_key']],
                    [
                        'title' => $sci['title'] ?? $sci['checklist_key'],
                        'is_completed' => (bool) ($sci['is_completed'] ?? false),
                        'notes' => $sci['notes'] ?? null,
                        'completed_by' => $completedBy,
                        'completed_at' => DateTimeNormalizer::normalizeDateTime($sci['completed_at'] ?? null),
                        'created_at' => DateTimeNormalizer::normalizeDateTime($sci['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                        'updated_at' => DateTimeNormalizer::normalizeDateTime($sci['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                    ]
                );
            }
        }

        // 15. Booking Messages
        $messages = $this->readJson($dataDir . '/booking_messages.json');
        foreach ($messages as $msg) {
            $bookingId = $idMaps['bookings'][$msg['booking_ref']] ?? null;
            $senderId = null;
            if ($msg['sender_type'] === 'client') {
                $senderId = !empty($msg['sender_ref']) ? ($idMaps['clients'][$msg['sender_ref']] ?? null) : null;
            } else {
                $senderId = !empty($msg['sender_ref']) ? ($idMaps['users'][$msg['sender_ref']] ?? null) : null;
            }

            if ($bookingId) {
                DB::table('booking_messages')->insert([
                    'booking_id' => $bookingId,
                    'sender_type' => $msg['sender_type'] ?? 'admin',
                    'sender_id' => $senderId,
                    'message' => $msg['message'] ?? '',
                    'visibility' => $msg['visibility'] ?? 'all',
                    'related_quotation_version' => $msg['related_quotation_version'] ?? null,
                    'attachment_name' => $msg['attachment_name'] ?? null,
                    'attachment_category' => $msg['attachment_category'] ?? null,
                    'mime_type' => $msg['mime_type'] ?? null,
                    'file_size' => $msg['file_size'] ?? null,
                    'created_at' => DateTimeNormalizer::normalizeDateTime($msg['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                    'updated_at' => DateTimeNormalizer::normalizeDateTime($msg['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                ]);
            }
        }

        // 16. Presentations
        $presentations = $this->readJson($dataDir . '/presentations.json');
        foreach ($presentations as $pres) {
            $bookingId = $idMaps['bookings'][$pres['booking_ref']] ?? null;
            $sentBy = !empty($pres['sent_by_ref']) ? ($idMaps['users'][$pres['sent_by_ref']] ?? null) : null;

            if ($bookingId) {
                DB::table('presentations')->insert([
                    'booking_id' => $bookingId,
                    'version' => $pres['version'] ?? 'v1',
                    'file_name' => $pres['file_name'] ?? 'proposal.pdf',
                    'file_path' => $pres['file_path'] ?? 'bookings/proposals/proposal.pdf',
                    'sent_at' => DateTimeNormalizer::normalizeDateTime($pres['sent_at'] ?? null),
                    'sent_by' => $sentBy,
                    'status' => $pres['status'] ?? 'sent',
                    'approval_status' => $pres['approval_status'] ?? null,
                    'feedback_text' => $pres['feedback_text'] ?? null,
                    'created_at' => DateTimeNormalizer::normalizeDateTime($pres['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                    'updated_at' => DateTimeNormalizer::normalizeDateTime($pres['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                ]);
            }
        }

        // 17. Returns
        $returnsData = $this->readJson($dataDir . '/returns.json');
        foreach ($returnsData as $r) {
            $bookingId = $idMaps['bookings'][$r['booking_ref']] ?? null;
            $inspectedBy = !empty($r['inspected_by_ref']) ? ($idMaps['users'][$r['inspected_by_ref']] ?? null) : null;

            if ($bookingId) {
                $returnId = DB::table('returns')->insertGetId([
                    'booking_id' => $bookingId,
                    'return_date' => DateTimeNormalizer::normalizeDate($r['return_date'] ?? null),
                    'status' => $r['status'] ?? 'Pending',
                    'total_damage_charge' => (float) ($r['total_damage_charge'] ?? 0),
                    'inspected_by' => $inspectedBy,
                    'notes' => $r['notes'] ?? null,
                    'created_at' => DateTimeNormalizer::normalizeDateTime($r['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                    'updated_at' => DateTimeNormalizer::normalizeDateTime($r['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                ]);
                $idMaps['returns'][$r['key']] = $returnId;
            }
        }

        // 18. Return Items
        $returnItems = $this->readJson($dataDir . '/return_items.json');
        foreach ($returnItems as $ri) {
            $returnId = $idMaps['returns'][$ri['return_ref']] ?? null;
            $invId = $idMaps['inventory_items'][$ri['inventory_item_ref']] ?? null;
            $decisionBy = !empty($ri['charge_decision_by_ref']) ? ($idMaps['users'][$ri['charge_decision_by_ref']] ?? null) : null;

            if ($returnId && $invId) {
                $rItemId = DB::table('return_items')->insertGetId([
                    'return_id' => $returnId,
                    'inventory_item_id' => $invId,
                    'quantity_returned' => (float) ($ri['quantity_returned'] ?? 0),
                    'quantity_good' => (float) ($ri['quantity_good'] ?? 0),
                    'quantity_damaged' => (float) ($ri['quantity_damaged'] ?? 0),
                    'quantity_lost' => (float) ($ri['quantity_lost'] ?? 0),
                    'condition' => $ri['condition'] ?? 'pending',
                    'final_amount' => $ri['final_amount'] ?? null,
                    'damage_charge' => (float) ($ri['damage_charge'] ?? 0),
                    'notes' => $ri['notes'] ?? null,
                    'charge_decision' => $ri['charge_decision'] ?? 'pending',
                    'charge_reason' => $ri['charge_reason'] ?? null,
                    'charge_decision_by' => $decisionBy,
                    'charge_decision_at' => DateTimeNormalizer::normalizeDateTime($ri['charge_decision_at'] ?? null),
                ]);
                $idMaps['return_items'][$ri['key']] = $rItemId;
            }
        }

        // 19. Return Item Evidences
        $evidences = $this->readJson($dataDir . '/return_item_evidences.json');
        foreach ($evidences as $ev) {
            $riId = $idMaps['return_items'][$ev['return_item_ref']] ?? null;
            $uploaderId = !empty($ev['uploaded_by_ref']) ? ($idMaps['users'][$ev['uploaded_by_ref']] ?? null) : null;

            if ($riId) {
                DB::table('return_item_evidences')->insert([
                    'return_item_id' => $riId,
                    'file_name' => $ev['file_name'] ?? 'evidence.jpg',
                    'file_path' => $ev['file_path'] ?? 'returns/evidence/evidence.jpg',
                    'mime_type' => $ev['mime_type'] ?? 'image/jpeg',
                    'size' => $ev['size'] ?? 1000,
                    'uploaded_by' => $uploaderId,
                    'created_at' => DateTimeNormalizer::normalizeDateTime($ev['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                    'updated_at' => DateTimeNormalizer::normalizeDateTime($ev['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                ]);
            }
        }

        // 20. Admin Alerts
        $alerts = $this->readJson($dataDir . '/admin_alerts.json');
        foreach ($alerts as $a) {
            $bookingId = !empty($a['booking_ref']) ? ($idMaps['bookings'][$a['booking_ref']] ?? null) : null;
            $invId = !empty($a['inventory_item_ref']) ? ($idMaps['inventory_items'][$a['inventory_item_ref']] ?? null) : null;

            DB::table('admin_alerts')->insert([
                'booking_id' => $bookingId,
                'inventory_item_id' => $invId,
                'type' => $a['type'] ?? 'info',
                'title' => $a['title'] ?? 'Alert',
                'message' => $a['message'] ?? '',
                'is_read' => (bool) ($a['is_read'] ?? false),
                'created_at' => DateTimeNormalizer::normalizeDateTime($a['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                'updated_at' => DateTimeNormalizer::normalizeDateTime($a['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
            ]);
        }

        // 21. Client Notifications
        $notifications = $this->readJson($dataDir . '/client_notifications.json');
        foreach ($notifications as $n) {
            $userId = !empty($n['user_ref']) ? ($idMaps['users'][$n['user_ref']] ?? null) : null;
            $bookingId = !empty($n['booking_ref']) ? ($idMaps['bookings'][$n['booking_ref']] ?? null) : null;

            if ($userId) {
                DB::table('client_notifications')->insert([
                    'user_id' => $userId,
                    'booking_id' => $bookingId,
                    'type' => $n['type'] ?? 'info',
                    'title' => $n['title'] ?? 'Notification',
                    'message' => $n['message'] ?? '',
                    'is_read' => (bool) ($n['is_read'] ?? false),
                    'created_at' => DateTimeNormalizer::normalizeDateTime($n['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                    'updated_at' => DateTimeNormalizer::normalizeDateTime($n['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                ]);
            }
        }

        // 22. Audit Logs
        $auditLogs = $this->readJson($dataDir . '/audit_logs.json');
        foreach ($auditLogs as $al) {
            $userId = !empty($al['user_ref']) ? ($idMaps['users'][$al['user_ref']] ?? null) : null;

            DB::table('audit_logs')->insert([
                'user_id' => $userId,
                'action' => $al['action'] ?? 'system_data_record',
                'module' => $al['module'] ?? 'admin_system_data',
                'event_type' => $al['event_type'] ?? 'imported_record',
                'details' => is_array($al['details'] ?? null) ? json_encode($al['details']) : ($al['details'] ?? null),
                'old_values' => is_array($al['old_values'] ?? null) ? json_encode($al['old_values']) : null,
                'new_values' => is_array($al['new_values'] ?? null) ? json_encode($al['new_values']) : null,
                'ip_address' => $al['ip_address'] ?? '127.0.0.1',
                'created_at' => DateTimeNormalizer::normalizeDateTime($al['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                'updated_at' => DateTimeNormalizer::normalizeDateTime($al['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s'),
            ]);
        }

        // 23. Settings
        $settingsPath = $dataDir . '/settings.json';
        if (file_exists($settingsPath)) {
            $settings = $this->readJson($settingsPath);
            if (isset($settings['downpayment_percentage'])) {
                Setting::setSetting(Setting::KEY_DOWNPAYMENT_PERCENTAGE, (float) $settings['downpayment_percentage'], 'float');
            }
            if (isset($settings['long_term_booking_threshold_days'])) {
                Setting::setSetting(Setting::KEY_LONG_TERM_THRESHOLD, (int) $settings['long_term_booking_threshold_days'], 'integer');
            }
            if (isset($settings['price_reconfirmation_threshold_days'])) {
                Setting::setSetting(Setting::KEY_PRICE_RECONFIRMATION_THRESHOLD, (int) $settings['price_reconfirmation_threshold_days'], 'integer');
            }
        }

        // Record Audit Log for successful system data import
        AuditLog::record(
            auth()->id(),
            'system_data_imported',
            "System data dataset '{$datasetId}' imported successfully.",
            'admin_security',
            ['dataset_id' => $datasetId, 'record_counts' => $validation['record_counts']]
        );

        return [
            'success' => true,
            'dataset_id' => $datasetId,
            'record_counts' => $validation['record_counts'],
        ];
    }

    protected function readJson(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $content = file_get_contents($path);
        return json_decode($content, true) ?: [];
    }
}
