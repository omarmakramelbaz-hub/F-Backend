<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponses;
use App\Models\PartnerServiceRequest;
use App\Models\User;
use App\Notifications\NotifyPartnerServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;

class PartnerServiceRequestController extends Controller
{
    use ApiResponses;

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'partner_id' => 'required|integer',
            'profession_key' => 'required|string',
            'description' => 'required|string|min:5|max:2000',
            'customer_phone' => 'nullable|string|max:30',
            'customer_lat' => 'required|numeric|between:-90,90',
            'customer_lng' => 'required|numeric|between:-180,180',
            'address' => 'nullable|string|max:500',
            'scheduled_at' => 'nullable|date',
            'photos' => 'nullable|array|max:5',
            'photos.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $professionKey = (string) $request->profession_key;
        if (!array_key_exists($professionKey, PartnerApplicationController::professions())) {
            return $this->errorResponse('المهنة غير موجودة.', 422);
        }

        $partner = User::where('id', $request->partner_id)
            ->where('account_type', 'delegate')
            ->where('status', 'accepted')
            ->with('pending_vendor')
            ->first();

        if (!$partner || !$partner->pending_vendor) {
            return $this->errorResponse('مقدم الخدمة غير متاح حاليًا.', 404);
        }

        $application = $partner->pending_vendor;
        if ($application->application_kind !== 'partner'
            || $application->status !== 'accepted'
            || $application->profession_key !== $professionKey) {
            return $this->errorResponse('مقدم الخدمة غير مسجل في هذه المهنة.', 422);
        }

        $distance = $this->distanceKm(
            (float) $request->customer_lat,
            (float) $request->customer_lng,
            (float) $application->lat,
            (float) $application->lng
        );

        if ($distance > (float) ($application->work_radius_km ?: 5)) {
            return $this->errorResponse('العنوان خارج نطاق عمل مقدم الخدمة.', 422);
        }

        $customer = auth('api')->user();

        $serviceRequest = PartnerServiceRequest::create([
            'user_id' => $customer->id,
            'partner_id' => $partner->id,
            'profession_key' => $professionKey,
            'description' => trim($request->description),
            'customer_phone' => $request->customer_phone ?: $customer->mobile,
            'customer_lat' => $request->customer_lat,
            'customer_lng' => $request->customer_lng,
            'address' => $request->address,
            'scheduled_at' => $request->scheduled_at,
            'status' => 'pending',
        ]);

        foreach ($request->file('photos', []) as $photo) {
            if ($photo && $photo->isValid()) {
                $serviceRequest->addMedia($photo)
                    ->toMediaCollection('partner_request_photos');
            }
        }

        Notification::send($partner, new NotifyPartnerServiceRequest($serviceRequest));

        return $this->successResponse(
            $this->serializeRequest($serviceRequest->fresh(['partner', 'customer'])),
            'تم إرسال طلب الخدمة لمقدم الخدمة.'
        );
    }

    public function customerIndex()
    {
        $items = PartnerServiceRequest::where('user_id', auth('api')->id())
            ->with(['partner.pending_vendor', 'customer'])
            ->latest('id')
            ->get()
            ->map(fn ($item) => $this->serializeRequest($item));

        return $this->successResponse($items, 'GO service requests');
    }

    public function partnerIndex(Request $request)
    {
        $partner = auth('api')->user();
        if ($partner->account_type !== 'delegate') {
            return $this->errorResponse('هذا المسار متاح للشركاء فقط.', 403);
        }

        $items = PartnerServiceRequest::where('partner_id', $partner->id)
            ->when($request->status, function ($query, $status) {
                if ($status === 'current') {
                    $query->whereIn('status', ['pending', 'accepted']);
                } else {
                    $query->where('status', $status);
                }
            })
            ->with(['partner.pending_vendor', 'customer'])
            ->latest('id')
            ->get()
            ->map(fn ($item) => $this->serializeRequest($item));

        return $this->successResponse($items, 'Partner service requests');
    }

    public function updateStatus(Request $request, PartnerServiceRequest $serviceRequest)
    {
        $partner = auth('api')->user();
        if ($partner->account_type !== 'delegate'
            || (int) $serviceRequest->partner_id !== (int) $partner->id) {
            return $this->errorResponse('غير مصرح لك بتعديل هذا الطلب.', 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:accepted,declined,completed',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $nextStatus = $request->status;

        if ($serviceRequest->status === 'completed'
            || $serviceRequest->status === 'declined') {
            return $this->errorResponse('تم إغلاق هذا الطلب بالفعل.', 422);
        }

        if ($nextStatus === 'completed' && $serviceRequest->status !== 'accepted') {
            return $this->errorResponse('يجب قبول الطلب قبل إتمامه.', 422);
        }

        $serviceRequest->status = $nextStatus;
        if ($nextStatus === 'accepted') {
            $serviceRequest->accepted_at = now();
        }
        if ($nextStatus === 'completed') {
            $serviceRequest->completed_at = now();
        }
        $serviceRequest->save();

        return $this->successResponse(
            $this->serializeRequest($serviceRequest->fresh(['partner.pending_vendor', 'customer'])),
            'تم تحديث حالة الطلب.'
        );
    }

    private function serializeRequest(PartnerServiceRequest $item): array
    {
        $profession = PartnerApplicationController::professions()[$item->profession_key] ?? null;
        $partnerApplication = $item->partner?->pending_vendor;

        return [
            'id' => $item->id,
            'status' => $item->status,
            'profession_key' => $item->profession_key,
            'profession' => $profession,
            'description' => $item->description,
            'customer' => [
                'id' => $item->customer?->id,
                'name' => $item->customer?->name,
                'mobile' => $item->customer_phone ?: $item->customer?->mobile,
            ],
            'partner' => [
                'id' => $item->partner?->id,
                'name' => $partnerApplication?->full_name ?: $item->partner?->name,
            ],
            'location' => [
                'lat' => (float) $item->customer_lat,
                'lng' => (float) $item->customer_lng,
                'address' => $item->address,
            ],
            'scheduled_at' => optional($item->scheduled_at)->toIso8601String(),
            'accepted_at' => optional($item->accepted_at)->toIso8601String(),
            'completed_at' => optional($item->completed_at)->toIso8601String(),
            'created_at' => optional($item->created_at)->toIso8601String(),
            'photos' => $item->getMedia('partner_request_photos')
                ->map(fn ($media) => $media->getUrl())
                ->values(),
        ];
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
