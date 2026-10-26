<?php

namespace App\Services\SystemData;

use App\Models\AdminAlert;
use App\Models\AiAnalysisResult;
use App\Models\AssetReturn;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\BookingMessage;
use App\Models\Client;
use App\Models\ClientNotification;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Presentation;
use App\Models\Quotation;
use App\Models\QuotationHistory;
use App\Models\ReturnItem;
use App\Models\ReturnItemEvidence;
use App\Models\Setting;
use App\Models\StaffChecklistItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class SystemDataExporter
{
    /**
     * Compute a reproducible fingerprint of the database schema migrations.
     */
    public static function computeSchemaFingerprint(): string
    {
        if (DB::getSchemaBuilder()->hasTable('migrations')) {
            $migrations = DB::table('migrations')->orderBy('migration')->pluck('migration')->implode('|');
            return hash('sha256', $migrations);
        }

        return hash('sha256', 'raflora-default-schema');
    }

    /**
     * Export system business data to a temporary ZIP archive and return its path.
     *
     * @return string Absolute path to created ZIP file
     */
    public function export(): string
    {
        $datasetId = 'raflora-export-' . now()->format('Ymd-His') . '-' . Str::random(6);
        $tempDir = storage_path('app/tmp_system_data/export_' . Str::random(12));
        File::ensureDirectoryExists($tempDir . '/data');

        try {
            $manifest = $this->buildDataset($tempDir, $datasetId);

            $zipPath = storage_path('app/tmp_system_data/' . $datasetId . '.zip');
            File::ensureDirectoryExists(dirname($zipPath));

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create ZIP archive for system data export.');
            }

            // Add manifest
            $zip->addFile($tempDir . '/manifest.json', 'manifest.json');

            // Add data files
            $files = File::files($tempDir . '/data');
            foreach ($files as $file) {
                $zip->addFile($file->getPathname(), 'data/' . $file->getFilename());
            }

            $zip->close();

            return $zipPath;
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    /**
     * Build all entity data files in the temporary directory and generate manifest.
     */
    protected function buildDataset(string $tempDir, string $datasetId): array
    {
        $entities = [];

        // 1. Referenced Admin/Staff Users (Safe attributes only)
        $usersData = [];
        $users = User::whereIn('role', ['admin', 'staff'])->orderBy('id')->get();
        foreach ($users as $user) {
            $usersData[] = [
                'key' => 'user-' . $user->id,
                'email' => $user->email,
                'role' => $user->role,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'username' => $user->username,
            ];
        }
        $this->writeJson($tempDir . '/data/users.json', $usersData);
        $entities['users'] = count($usersData);

        // 2. Clients
        $clientsData = [];
        $clients = Client::orderBy('id')->get();
        foreach ($clients as $client) {
            $clientsData[] = [
                'key' => 'client-' . $client->id,
                'full_name' => $client->full_name,
                'email' => $client->email,
                'phone' => $client->phone,
                'address' => $client->address,
                'notes' => $client->notes,
                'created_at' => $client->created_at?->toISOString(),
                'updated_at' => $client->updated_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/clients.json', $clientsData);
        $entities['clients'] = count($clientsData);

        // 3. Inventory Items
        $inventoryData = [];
        $inventoryItems = InventoryItem::withTrashed()->orderBy('id')->get();
        foreach ($inventoryItems as $item) {
            $inventoryData[] = [
                'key' => 'inventory-' . $item->id,
                'item_code' => $item->item_code,
                'name' => $item->name,
                'category' => $item->category,
                'is_perishable' => (bool) $item->is_perishable,
                'current_stock' => (float) $item->current_stock,
                'unit_cost' => (float) $item->unit_cost,
                'min_stock' => (float) $item->min_stock,
                'unit' => $item->unit,
                'image_path' => $item->image_path,
                'deleted_at' => $item->deleted_at?->toISOString(),
                'created_at' => $item->created_at?->toISOString(),
                'updated_at' => $item->updated_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/inventory_items.json', $inventoryData);
        $entities['inventory_items'] = count($inventoryData);

        // 4. Packages
        $packagesData = [];
        $packages = Package::orderBy('id')->get();
        foreach ($packages as $pkg) {
            $packagesData[] = [
                'key' => 'package-' . $pkg->id,
                'package_code' => $pkg->package_code,
                'title' => $pkg->title,
                'category' => $pkg->category,
                'description' => $pkg->description,
                'price' => (float) $pkg->price,
                'included_items' => $pkg->included_items,
                'image_path' => $pkg->image_path,
                'is_active' => (bool) $pkg->is_active,
                'is_archived' => (bool) $pkg->is_archived,
                'created_at' => $pkg->created_at?->toISOString(),
                'updated_at' => $pkg->updated_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/packages.json', $packagesData);
        $entities['packages'] = count($packagesData);

        // 5. Package Materials (Pivot)
        $packageMaterialsData = [];
        if (DB::getSchemaBuilder()->hasTable('inventory_item_package')) {
            $pivots = DB::table('inventory_item_package')->orderBy('id')->get();
            foreach ($pivots as $p) {
                $packageMaterialsData[] = [
                    'key' => 'package-material-' . $p->id,
                    'package_ref' => 'package-' . $p->package_id,
                    'inventory_item_ref' => 'inventory-' . $p->inventory_item_id,
                    'quantity' => (float) $p->quantity,
                    'created_at' => $p->created_at,
                    'updated_at' => $p->updated_at,
                ];
            }
        }
        $this->writeJson($tempDir . '/data/package_materials.json', $packageMaterialsData);
        $entities['package_materials'] = count($packageMaterialsData);

        // 6. Inventory Item Substitutes
        $substitutesData = [];
        if (DB::getSchemaBuilder()->hasTable('inventory_item_substitutes')) {
            $subs = DB::table('inventory_item_substitutes')->orderBy('id')->get();
            foreach ($subs as $sub) {
                $substitutesData[] = [
                    'key' => 'substitute-' . $sub->id,
                    'item_ref' => 'inventory-' . $sub->item_id,
                    'substitute_ref' => 'inventory-' . $sub->substitute_id,
                ];
            }
        }
        $this->writeJson($tempDir . '/data/inventory_item_substitutes.json', $substitutesData);
        $entities['inventory_item_substitutes'] = count($substitutesData);

        // 7. Bookings
        $bookingsData = [];
        $bookings = Booking::orderBy('id')->get();
        foreach ($bookings as $b) {
            $bookingsData[] = [
                'key' => 'booking-' . $b->id,
                'client_ref' => $b->client_id ? 'client-' . $b->client_id : null,
                'package_ref' => $b->package_id ? 'package-' . $b->package_id : null,
                'handled_by_ref' => $b->handled_by ? 'user-' . $b->handled_by : null,
                'staff_ref' => $b->staff_id ? 'user-' . $b->staff_id : null,
                'event_type' => $b->event_type,
                'event_date' => $b->event_date?->format('Y-m-d'),
                'event_time' => $b->event_time,
                'event_size' => $b->event_size,
                'table_count' => $b->table_count,
                'venue' => $b->venue,
                'venue_latitude' => $b->venue_latitude,
                'venue_longitude' => $b->venue_longitude,
                'venue_setting' => $b->venue_setting,
                'end_time' => $b->end_time,
                'design_brief' => $b->design_brief,
                'special_requests' => $b->special_requests,
                'inspiration_image' => $b->inspiration_image,
                'status' => $b->status,
                'pre_cancellation_status' => $b->pre_cancellation_status,
                'confirmed_at' => $b->confirmed_at?->toISOString(),
                'downpayment_amount' => $b->downpayment_amount !== null ? (float) $b->downpayment_amount : null,
                'downpayment_date' => $b->downpayment_date?->format('Y-m-d'),
                'total_quoted' => $b->total_quoted !== null ? (float) $b->total_quoted : null,
                'price_valid_until' => $b->price_valid_until?->format('Y-m-d'),
                'suggested_procurement_date' => $b->suggested_procurement_date?->format('Y-m-d'),
                'preparation_start_date' => $b->preparation_start_date?->format('Y-m-d'),
                'preparation_status' => $b->preparation_status,
                'cancellation_reason' => $b->cancellation_reason,
                'admin_notes' => $b->admin_notes,
                'raw_materials_sum' => $b->raw_materials_sum !== null ? (float) $b->raw_materials_sum : null,
                'multiplier' => $b->multiplier !== null ? (float) $b->multiplier : null,
                'final_quoted_price' => $b->final_quoted_price !== null ? (float) $b->final_quoted_price : null,
                'guest_name' => $b->guest_name,
                'guest_email' => $b->guest_email,
                'guest_phone' => $b->guest_phone,
                'guest_address' => $b->guest_address,
                'ai_analysis_data' => $b->ai_analysis_data,
                'labor_method' => $b->labor_method,
                'labor_rate' => $b->labor_rate !== null ? (float) $b->labor_rate : null,
                'created_at' => $b->created_at?->toISOString(),
                'updated_at' => $b->updated_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/bookings.json', $bookingsData);
        $entities['bookings'] = count($bookingsData);

        // 8. Booking Items
        $bookingItemsData = [];
        $bookingItems = BookingItem::orderBy('id')->get();
        foreach ($bookingItems as $bi) {
            $bookingItemsData[] = [
                'key' => 'booking-item-' . $bi->id,
                'booking_ref' => 'booking-' . $bi->booking_id,
                'inventory_item_ref' => $bi->inventory_item_id ? 'inventory-' . $bi->inventory_item_id : null,
                'item_name' => $bi->item_name,
                'quantity' => (float) $bi->quantity,
                'quoted_unit_price' => $bi->quoted_unit_price !== null ? (float) $bi->quoted_unit_price : null,
                'ai_recommended_price' => $bi->ai_recommended_price !== null ? (float) $bi->ai_recommended_price : null,
                'is_ai_suggested' => (bool) $bi->is_ai_suggested,
                'confirmed_at' => $bi->confirmed_at?->toISOString(),
                'procurement_status' => $bi->procurement_status,
                'fresh_procurement_override' => $bi->fresh_procurement_override,
                'suggested_order_date' => $bi->suggested_order_date,
                'suggested_delivery_date' => $bi->suggested_delivery_date,
                'notes' => $bi->notes,
                'created_at' => $bi->created_at?->toISOString(),
                'updated_at' => $bi->updated_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/booking_items.json', $bookingItemsData);
        $entities['booking_items'] = count($bookingItemsData);

        // 9. AI Analysis Results
        $aiData = [];
        $aiResults = AiAnalysisResult::orderBy('id')->get();
        foreach ($aiResults as $ai) {
            $aiData[] = [
                'key' => 'ai-analysis-' . $ai->id,
                'booking_ref' => 'booking-' . $ai->booking_id,
                'raw_gemini_response' => $ai->raw_gemini_response,
                'suggested_materials' => $ai->suggested_materials,
                'analyzed_at' => $ai->analyzed_at?->toISOString(),
                'created_at' => $ai->created_at?->toISOString(),
                'updated_at' => $ai->updated_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/ai_analysis_results.json', $aiData);
        $entities['ai_analysis_results'] = count($aiData);

        // 10. Quotations
        $quotationsData = [];
        $quotations = Quotation::orderBy('id')->get();
        foreach ($quotations as $q) {
            $quotationsData[] = [
                'key' => 'quotation-' . $q->id,
                'booking_ref' => 'booking-' . $q->booking_id,
                'issued_by_ref' => $q->issued_by ? 'user-' . $q->issued_by : null,
                'suggested_florals' => $q->suggested_florals,
                'recommended_price' => $q->recommended_price !== null ? (float) $q->recommended_price : null,
                'status' => $q->status,
                'valid_until' => $q->valid_until?->format('Y-m-d'),
                'version' => (int) $q->version,
                'raw_materials_sum' => $q->raw_materials_sum !== null ? (float) $q->raw_materials_sum : null,
                'multiplier' => $q->multiplier !== null ? (float) $q->multiplier : null,
                'labor_method' => $q->labor_method,
                'labor_rate' => $q->labor_rate !== null ? (float) $q->labor_rate : null,
                'labor_amount' => $q->labor_amount !== null ? (float) $q->labor_amount : null,
                'final_quoted_price' => $q->final_quoted_price !== null ? (float) $q->final_quoted_price : null,
                'downpayment_percentage' => $q->downpayment_percentage !== null ? (float) $q->downpayment_percentage : null,
                'items_snapshot' => $q->items_snapshot,
                'is_tentative' => (bool) $q->is_tentative,
                'reconfirmed_at' => $q->reconfirmed_at?->toISOString(),
                'created_at' => $q->created_at?->toISOString(),
                'updated_at' => $q->updated_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/quotations.json', $quotationsData);
        $entities['quotations'] = count($quotationsData);

        // 11. Quotation History
        $qhData = [];
        $histories = QuotationHistory::orderBy('id')->get();
        foreach ($histories as $qh) {
            $qhData[] = [
                'key' => 'quotation-history-' . $qh->id,
                'booking_ref' => 'booking-' . $qh->booking_id,
                'changed_by_ref' => $qh->changed_by ? 'user-' . $qh->changed_by : null,
                'field_changed' => $qh->field_changed,
                'old_value' => $qh->old_value,
                'new_value' => $qh->new_value,
                'reason' => $qh->reason,
                'created_at' => $qh->created_at?->toISOString(),
                'updated_at' => $qh->updated_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/quotation_history.json', $qhData);
        $entities['quotation_history'] = count($qhData);

        // 12. Payments
        $paymentsData = [];
        $payments = Payment::orderBy('id')->get();
        foreach ($payments as $p) {
            $paymentsData[] = [
                'key' => 'payment-' . $p->id,
                'booking_ref' => 'booking-' . $p->booking_id,
                'quotation_ref' => $p->quotation_id ? 'quotation-' . $p->quotation_id : null,
                'verified_by_ref' => $p->verified_by ? 'user-' . $p->verified_by : null,
                'recorded_by_ref' => $p->recorded_by ? 'user-' . $p->recorded_by : null,
                'amount' => (float) $p->amount,
                'payment_type' => $p->payment_type,
                'payment_option' => $p->payment_option,
                'amount_paid' => $p->amount_paid !== null ? (float) $p->amount_paid : null,
                'remaining_balance' => $p->remaining_balance !== null ? (float) $p->remaining_balance : null,
                'verified_at' => $p->verified_at?->toISOString(),
                'reference_number' => $p->reference_number,
                'status' => $p->status,
                'created_at' => $p->created_at?->toISOString(),
                'updated_at' => $p->updated_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/payments.json', $paymentsData);
        $entities['payments'] = count($paymentsData);

        // 13. Inventory Transactions
        $txData = [];
        $transactions = InventoryTransaction::orderBy('id')->get();
        foreach ($transactions as $tx) {
            $txData[] = [
                'key' => 'inv-tx-' . $tx->id,
                'inventory_item_ref' => 'inventory-' . $tx->inventory_item_id,
                'booking_ref' => $tx->booking_id ? 'booking-' . $tx->booking_id : null,
                'reference_transaction_ref' => $tx->reference_transaction_id ? 'inv-tx-' . $tx->reference_transaction_id : null,
                'performed_by_ref' => $tx->performed_by ? 'user-' . $tx->performed_by : null,
                'quantity_change' => (float) $tx->quantity_change,
                'transaction_type' => $tx->transaction_type,
                'reason' => $tx->reason,
                'created_at' => $tx->created_at?->toISOString(),
                'updated_at' => $tx->updated_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/inventory_transactions.json', $txData);
        $entities['inventory_transactions'] = count($txData);

        // 14. Staff Checklist Items
        $checklistData = [];
        $checklists = StaffChecklistItem::orderBy('id')->get();
        foreach ($checklists as $sci) {
            $checklistData[] = [
                'key' => 'checklist-item-' . $sci->id,
                'booking_ref' => 'booking-' . $sci->booking_id,
                'completed_by_ref' => $sci->completed_by ? 'user-' . $sci->completed_by : null,
                'checklist_key' => $sci->key,
                'title' => $sci->title,
                'is_completed' => (bool) $sci->is_completed,
                'notes' => $sci->notes,
                'completed_at' => $sci->completed_at?->toISOString(),
                'created_at' => $sci->created_at?->toISOString(),
                'updated_at' => $sci->updated_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/staff_checklist_items.json', $checklistData);
        $entities['staff_checklist_items'] = count($checklistData);

        // 15. Booking Messages
        $messagesData = [];
        $messages = BookingMessage::orderBy('id')->get();
        foreach ($messages as $msg) {
            $messagesData[] = [
                'key' => 'message-' . $msg->id,
                'booking_ref' => 'booking-' . $msg->booking_id,
                'sender_type' => $msg->sender_type,
                'sender_ref' => $msg->sender_type === 'client' ? ($msg->sender_id ? 'client-' . $msg->sender_id : null) : ($msg->sender_id ? 'user-' . $msg->sender_id : null),
                'message' => $msg->message,
                'visibility' => $msg->visibility,
                'related_quotation_version' => $msg->related_quotation_version,
                'attachment_name' => $msg->attachment_name,
                'attachment_category' => $msg->attachment_category,
                'mime_type' => $msg->mime_type,
                'file_size' => $msg->file_size,
                'created_at' => $msg->created_at?->toISOString(),
                'updated_at' => $msg->updated_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/booking_messages.json', $messagesData);
        $entities['booking_messages'] = count($messagesData);

        // 16. Presentations
        $presData = [];
        $presentations = Presentation::orderBy('id')->get();
        foreach ($presentations as $pres) {
            $presData[] = [
                'key' => 'pres-' . $pres->id,
                'booking_ref' => 'booking-' . $pres->booking_id,
                'sent_by_ref' => $pres->sent_by ? 'user-' . $pres->sent_by : null,
                'version' => $pres->version,
                'file_name' => $pres->file_name,
                'file_path' => $pres->file_path,
                'sent_at' => $pres->sent_at?->toISOString(),
                'status' => $pres->status,
                'approval_status' => $pres->approval_status,
                'feedback_text' => $pres->feedback_text,
                'created_at' => $pres->created_at?->toISOString(),
                'updated_at' => $pres->updated_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/presentations.json', $presData);
        $entities['presentations'] = count($presData);

        // 17. Returns
        $returnsData = [];
        $returns = AssetReturn::orderBy('id')->get();
        foreach ($returns as $ret) {
            $returnsData[] = [
                'key' => 'return-' . $ret->id,
                'booking_ref' => 'booking-' . $ret->booking_id,
                'inspected_by_ref' => $ret->inspected_by ? 'user-' . $ret->inspected_by : null,
                'return_date' => $ret->return_date?->format('Y-m-d'),
                'status' => $ret->status,
                'total_damage_charge' => (float) $ret->total_damage_charge,
                'notes' => $ret->notes,
                'created_at' => $ret->created_at?->toISOString(),
                'updated_at' => $ret->updated_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/returns.json', $returnsData);
        $entities['returns'] = count($returnsData);

        // 18. Return Items
        $returnItemsData = [];
        $returnItems = ReturnItem::orderBy('id')->get();
        foreach ($returnItems as $ri) {
            $returnItemsData[] = [
                'key' => 'return-item-' . $ri->id,
                'return_ref' => 'return-' . $ri->return_id,
                'inventory_item_ref' => 'inventory-' . $ri->inventory_item_id,
                'charge_decision_by_ref' => $ri->charge_decision_by ? 'user-' . $ri->charge_decision_by : null,
                'quantity_returned' => (float) $ri->quantity_returned,
                'quantity_good' => (float) $ri->quantity_good,
                'quantity_damaged' => (float) $ri->quantity_damaged,
                'quantity_lost' => (float) $ri->quantity_lost,
                'condition' => $ri->condition,
                'final_amount' => $ri->final_amount !== null ? (float) $ri->final_amount : null,
                'damage_charge' => (float) $ri->damage_charge,
                'notes' => $ri->notes,
                'charge_decision' => $ri->charge_decision,
                'charge_reason' => $ri->charge_reason,
                'charge_decision_at' => $ri->charge_decision_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/return_items.json', $returnItemsData);
        $entities['return_items'] = count($returnItemsData);

        // 19. Return Item Evidences
        $evidencesData = [];
        $evidences = ReturnItemEvidence::orderBy('id')->get();
        foreach ($evidences as $ev) {
            $evidencesData[] = [
                'key' => 'return-ev-' . $ev->id,
                'return_item_ref' => 'return-item-' . $ev->return_item_id,
                'uploaded_by_ref' => $ev->uploaded_by ? 'user-' . $ev->uploaded_by : null,
                'file_name' => $ev->file_name,
                'file_path' => $ev->file_path,
                'mime_type' => $ev->mime_type,
                'size' => $ev->size,
                'created_at' => $ev->created_at?->toISOString(),
                'updated_at' => $ev->updated_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/return_item_evidences.json', $evidencesData);
        $entities['return_item_evidences'] = count($evidencesData);

        // 20. Admin Alerts
        $alertsData = [];
        $alerts = AdminAlert::orderBy('id')->get();
        foreach ($alerts as $a) {
            $alertsData[] = [
                'key' => 'admin-alert-' . $a->id,
                'booking_ref' => $a->booking_id ? 'booking-' . $a->booking_id : null,
                'inventory_item_ref' => $a->inventory_item_id ? 'inventory-' . $a->inventory_item_id : null,
                'type' => $a->type,
                'title' => $a->title,
                'message' => $a->message,
                'is_read' => (bool) $a->is_read,
                'created_at' => $a->created_at?->toISOString(),
                'updated_at' => $a->updated_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/admin_alerts.json', $alertsData);
        $entities['admin_alerts'] = count($alertsData);

        // 21. Client Notifications
        $notifsData = [];
        $notifs = ClientNotification::orderBy('id')->get();
        foreach ($notifs as $n) {
            $notifsData[] = [
                'key' => 'client-notif-' . $n->id,
                'user_ref' => $n->user_id ? 'user-' . $n->user_id : null,
                'booking_ref' => $n->booking_id ? 'booking-' . $n->booking_id : null,
                'type' => $n->type,
                'title' => $n->title,
                'message' => $n->message,
                'is_read' => (bool) $n->is_read,
                'created_at' => $n->created_at?->toISOString(),
                'updated_at' => $n->updated_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/client_notifications.json', $notifsData);
        $entities['client_notifications'] = count($notifsData);

        // 22. Audit Logs
        $logsData = [];
        $logs = AuditLog::orderBy('id')->get();
        foreach ($logs as $l) {
            $logsData[] = [
                'key' => 'audit-log-' . $l->id,
                'user_ref' => $l->user_id ? 'user-' . $l->user_id : null,
                'action' => $l->action,
                'module' => $l->module,
                'event_type' => $l->event_type,
                'details' => $l->details,
                'old_values' => $l->old_values,
                'new_values' => $l->new_values,
                'ip_address' => $l->ip_address,
                'created_at' => $l->created_at?->toISOString(),
                'updated_at' => $l->updated_at?->toISOString(),
            ];
        }
        $this->writeJson($tempDir . '/data/audit_logs.json', $logsData);
        $entities['audit_logs'] = count($logsData);

        // 23. Settings (safe business threshold keys only)
        $settingsData = [
            'downpayment_percentage' => Setting::getSetting(Setting::KEY_DOWNPAYMENT_PERCENTAGE, Setting::DEFAULT_DOWNPAYMENT_PERCENTAGE),
            'long_term_booking_threshold_days' => Setting::getLongTermBookingThresholdDays(),
            'price_reconfirmation_threshold_days' => Setting::getPriceReconfirmationThresholdDays(),
        ];
        $this->writeJson($tempDir . '/data/settings.json', $settingsData);
        $entities['settings'] = count($settingsData);

        // Build Manifest
        $manifest = [
            'format' => 'raflora-system-data',
            'format_version' => 1,
            'dataset_id' => $datasetId,
            'created_at' => now()->toIso8601String(),
            'application' => 'Raflora Enterprises',
            'data_mode' => 'business_export',
            'schema_fingerprint' => self::computeSchemaFingerprint(),
            'entities' => $entities,
        ];

        $this->writeJson($tempDir . '/manifest.json', $manifest);

        return $manifest;
    }

    protected function writeJson(string $path, mixed $data): void
    {
        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
