<?php

namespace App\Actions\Orders\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Actions\Orders\Concerns\TellsOrderParties;
use App\Enums\AuditEvent;
use App\Enums\OrderEvent;
use App\Enums\OrderState;
use App\Enums\UploadPurpose;
use App\Exceptions\DomainApiException;
use App\Models\AgreementAcceptance;
use App\Models\Customer;
use App\Models\LegalDocument;
use App\Models\Order;
use App\Models\OrderCollection;
use App\Notifications\ProxyNamedNotification;
use App\Services\UploadTokenStore;
use App\Support\DatabaseActor;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The buyer names someone else to collect a paid piece (spec 014 US3, FR-018,
 * FR-021, research R14; Part 2 §7). On a ready-to-collect order not yet
 * collected and not frozen: the proxy's name, phone and ID photo (purpose
 * `proxy_id`) go on the collection with the buyer's acceptance of the current
 * `collection_proxy_authorisation` (context `collection_proxy`). Naming again
 * replaces the proxy with a new acceptance. After commit the proxy gets one SMS
 * without the code; the buyer is told. Audited `order` scope, trade gate.
 */
final class NameProxyAction
{
    use MovesOrder, TellsOrderParties;

    public function __construct(
        private readonly UploadTokenStore $uploads,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Customer $buyer, string $orderId, string $name, string $phone, string $idUploadToken,
        int $authorisationId, ?RequestContext $ctx = null): Order
    {
        $order = Order::query()->findOrFail($orderId);
        if ($order->buyer_id !== $buyer->customer_id) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$orderId]);
        }

        $document = LegalDocument::current(LegalDocument::COLLECTION_PROXY_AUTHORISATION);
        if ($document === null || $document->legal_doc_id !== $authorisationId) {
            throw DomainApiException::declarationRequired();
        }

        $idRef = $this->uploads->resolve($idUploadToken, $buyer->customer_id, UploadPurpose::PROXY_ID);
        if ($idRef === null) {
            throw DomainApiException::uploadTokenInvalid();
        }

        $order = DatabaseActor::order(fn () => DB::transaction(function () use ($buyer, $order, $name, $phone, $idRef, $document, $ctx) {
            $order = $this->lockOrder($order->order_id);
            $this->assertNotFrozen($order);
            $collection = OrderCollection::query()->where('order_id', $order->order_id)->lockForUpdate()->first();
            if ($order->state !== OrderState::READY_TO_COLLECT || $collection === null || $collection->collected_at !== null) {
                throw DomainApiException::illegalOrderTransition();
            }

            $replaced = $collection->is_proxy;
            $acceptance = AgreementAcceptance::query()->create([
                'customer_id' => $buyer->customer_id,
                'legal_doc_id' => $document->legal_doc_id,
                'context' => AgreementAcceptance::CONTEXT_COLLECTION_PROXY,
                'ip_address' => filled($ctx?->ip) ? $ctx->ip : null,
                'device_fingerprint' => $ctx?->deviceFingerprintHash,
            ]);

            $collection->forceFill([
                'is_proxy' => true,
                'proxy_name' => $name,
                'proxy_phone' => $phone,
                'proxy_id_storage_ref' => $idRef,
                'proxy_acceptance_id' => $acceptance->acceptance_id,
                'proxy_named_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->execute(
                AuditEvent::ORDER_PROXY_NAMED,
                'success',
                ['order_ref' => $order->order_ref, 'proxy_name' => $name, 'replaced' => $replaced,
                    'authorisation_version' => $document->version],
                'order',
                $order->order_id,
                $ctx,
                actorCustomerId: $buyer->customer_id,
            );

            $listing = $order->listing;
            $this->tellOrder($buyer->customer_id, OrderEvent::PROXY_NAMED, $order, $listing, message: $name);
            $this->flushOrderOutbox();

            $order->loadMissing('branch');
            $proxySms = new ProxyNamedNotification(
                strtok((string) $buyer->full_name, ' ') ?: (string) $buyer->full_name,
                $listing->title(), $listing->title(arabic: true),
                $order->branch?->name_en, $order->branch?->name_ar,
                $buyer->preferred_lang === 'ar',
            );
            DB::afterCommit(fn () => Notification::route('sms', $phone)->notify($proxySms));

            return $order;
        }));

        $this->uploads->forget($idUploadToken);

        return $order;
    }
}
