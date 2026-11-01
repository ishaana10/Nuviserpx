<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical catalogue of integration events emitted by Nuvis ERPX.
 *
 * A single source of truth used by:
 *  - the webhook subscription UI (which events can be subscribed to),
 *  - the workflow builder (which triggers are available),
 *  - the REST API docs,
 *  - ModuleManager::trigger_hook() consumers.
 *
 * Adding a new event here makes it available everywhere automatically.
 */
final class IntegrationEvents
{
    // Invoice lifecycle
    public const INVOICE_CREATED   = 'invoice.created';
    public const INVOICE_UPDATED   = 'invoice.updated';
    public const INVOICE_PAID      = 'invoice.paid';
    public const INVOICE_PARTIALLY_PAID = 'invoice.partially_paid';
    public const INVOICE_CANCELLED = 'invoice.cancelled';
    public const INVOICE_OVERDUE   = 'invoice.overdue';
    public const INVOICE_DELETED   = 'invoice.deleted';

    // Payment lifecycle
    public const PAYMENT_RECEIVED  = 'payment.received';

    // Procurement — Purchase Orders
    public const PURCHASE_ORDER_CREATED = 'purchase_order.created';
    public const PURCHASE_ORDER_UPDATED = 'purchase_order.updated';
    public const PURCHASE_ORDER_DELETED = 'purchase_order.deleted';

    // Procurement — Goods Receipts
    public const GOODS_RECEIVED = 'goods_receipt.received';

    // Procurement — Vendor Bills (Accounts Payable)
    public const VENDOR_BILL_CREATED = 'vendor_bill.created';
    public const VENDOR_BILL_UPDATED = 'vendor_bill.updated';
    public const VENDOR_BILL_DELETED = 'vendor_bill.deleted';

    /**
     * @return array<string, array{group:string, label:string, description:string, fields:array<int,string>}>
     */
    public static function catalog(): array
    {
        return [
            self::INVOICE_CREATED => [
                'group' => 'Invoices',
                'label' => 'Invoice Created',
                'description' => 'Fires when a new invoice is saved (UI or API).',
                'fields' => ['invoice_id', 'invoice_no', 'party_id', 'party_name', 'total', 'balance', 'status', 'invoice_date', 'due_date'],
            ],
            self::INVOICE_UPDATED => [
                'group' => 'Invoices',
                'label' => 'Invoice Updated',
                'description' => 'Fires when an existing invoice is edited.',
                'fields' => ['invoice_id', 'invoice_no', 'party_id', 'party_name', 'total', 'balance', 'status'],
            ],
            self::INVOICE_PAID => [
                'group' => 'Invoices',
                'label' => 'Invoice Paid (Fully)',
                'description' => 'Fires when an invoice balance reaches zero.',
                'fields' => ['invoice_id', 'invoice_no', 'party_id', 'party_name', 'total', 'paid_amount'],
            ],
            self::INVOICE_PARTIALLY_PAID => [
                'group' => 'Invoices',
                'label' => 'Invoice Partially Paid',
                'description' => 'Fires when a payment is applied but a balance remains.',
                'fields' => ['invoice_id', 'invoice_no', 'party_id', 'total', 'paid_amount', 'balance'],
            ],
            self::INVOICE_CANCELLED => [
                'group' => 'Invoices',
                'label' => 'Invoice Cancelled',
                'description' => 'Fires when an invoice is marked cancelled.',
                'fields' => ['invoice_id', 'invoice_no', 'party_id', 'total'],
            ],
            self::INVOICE_OVERDUE => [
                'group' => 'Invoices',
                'label' => 'Invoice Overdue',
                'description' => 'Fires (via scheduled sweep) when an unpaid invoice passes its due date.',
                'fields' => ['invoice_id', 'invoice_no', 'party_id', 'party_name', 'total', 'balance', 'due_date', 'days_overdue'],
            ],
            self::INVOICE_DELETED => [
                'group' => 'Invoices',
                'label' => 'Invoice Deleted',
                'description' => 'Fires when an invoice is deleted.',
                'fields' => ['invoice_id', 'invoice_no'],
            ],
            self::PAYMENT_RECEIVED => [
                'group' => 'Payments',
                'label' => 'Payment Received',
                'description' => 'Fires when a customer payment receipt is recorded.',
                'fields' => ['payment_id', 'payment_no', 'party_id', 'party_name', 'amount', 'method'],
            ],
            self::PURCHASE_ORDER_CREATED => [
                'group' => 'Purchasing',
                'label' => 'Purchase Order Created',
                'description' => 'Fires when a new purchase order is saved (UI or API).',
                'fields' => ['purchase_order_id', 'po_no', 'vendor_id', 'vendor_name', 'total', 'status', 'order_date'],
            ],
            self::PURCHASE_ORDER_UPDATED => [
                'group' => 'Purchasing',
                'label' => 'Purchase Order Updated',
                'description' => 'Fires when an existing purchase order is edited.',
                'fields' => ['purchase_order_id', 'po_no', 'vendor_id', 'vendor_name', 'total', 'status'],
            ],
            self::PURCHASE_ORDER_DELETED => [
                'group' => 'Purchasing',
                'label' => 'Purchase Order Deleted',
                'description' => 'Fires when a purchase order is deleted.',
                'fields' => ['purchase_order_id', 'po_no'],
            ],
            self::GOODS_RECEIVED => [
                'group' => 'Purchasing',
                'label' => 'Goods Received',
                'description' => 'Fires when stock is received against a purchase order (goods receipt note).',
                'fields' => ['goods_receipt_id', 'grn_no', 'purchase_order_id', 'po_no', 'vendor_id', 'receipt_date'],
            ],
            self::VENDOR_BILL_CREATED => [
                'group' => 'Purchasing',
                'label' => 'Vendor Bill Created',
                'description' => 'Fires when a vendor bill is recorded (posts Accounts Payable).',
                'fields' => ['vendor_bill_id', 'bill_no', 'vendor_id', 'vendor_name', 'total', 'balance', 'status', 'bill_date', 'due_date'],
            ],
            self::VENDOR_BILL_UPDATED => [
                'group' => 'Purchasing',
                'label' => 'Vendor Bill Updated',
                'description' => 'Fires when an existing vendor bill is edited.',
                'fields' => ['vendor_bill_id', 'bill_no', 'vendor_id', 'vendor_name', 'total', 'balance', 'status'],
            ],
            self::VENDOR_BILL_DELETED => [
                'group' => 'Purchasing',
                'label' => 'Vendor Bill Deleted',
                'description' => 'Fires when a vendor bill is deleted.',
                'fields' => ['vendor_bill_id', 'bill_no'],
            ],
        ];
    }

    /** @return string[] */
    public static function all(): array
    {
        return array_keys(self::catalog());
    }

    public static function label(string $event): string
    {
        return self::catalog()[$event]['label'] ?? $event;
    }

    public static function exists(string $event): bool
    {
        return isset(self::catalog()[$event]);
    }
}
