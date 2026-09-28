<?php

namespace App\Actions\Dashboard;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\Customer;
use App\Models\Staff;
use App\Support\CustomerFileLoader;
use App\Support\RequestContext;

/**
 * Detail view for the Dashboard's "Users and Verification" page.
 * Loading the details is audited; opening a document image is a separate
 * (also audited) action on the identity endpoint.
 */
final class ShowCustomerVerificationDetailsAction
{
    public function __construct(
        private readonly RecordAuditLogAction $audit,
        private readonly CustomerFileLoader $file,
    ) {}

    public function handle(Staff $actor, string $customerId, RequestContext $ctx): Customer
    {
        $customer = $this->file->load($customerId);

        $this->audit->execute(
            AuditEvent::CUSTOMER_VERIFICATION_DETAILS_VIEWED,
            'success',
            ['status' => $customer->status->value],
            'customer',
            $customer->customer_id,
            $ctx,
            actorStaffId: $actor->staff_id,
        );

        return $customer;
    }
}
