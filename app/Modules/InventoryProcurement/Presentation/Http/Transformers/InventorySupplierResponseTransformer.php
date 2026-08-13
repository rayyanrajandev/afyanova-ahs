<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Transformers;

class InventorySupplierResponseTransformer
{
    public static function transform(array $supplier): array
    {
        return [
            'id' => $supplier['id'] ?? null,
            'supplierCode' => $supplier['supplier_code'] ?? null,
            'supplierName' => $supplier['supplier_name'] ?? null,
            'tinNumber' => $supplier['tin_number'] ?? null,
            'bankAccountNumber' => $supplier['bank_account_number'] ?? null,
            'lipaNumber' => $supplier['lipa_number'] ?? null,
            'contactPerson' => $supplier['contact_person'] ?? null,
            'phone' => $supplier['phone'] ?? null,
            'email' => $supplier['email'] ?? null,
            'addressLine' => $supplier['address_line'] ?? null,
            'countryCode' => $supplier['country_code'] ?? null,
            'status' => $supplier['status'] ?? null,
            'statusReason' => $supplier['status_reason'] ?? null,
            'notes' => $supplier['notes'] ?? null,
            'createdAt' => $supplier['created_at'] ?? null,
            'updatedAt' => $supplier['updated_at'] ?? null,
        ];
    }
}
