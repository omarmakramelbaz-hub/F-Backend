@php
    $branchReceiver = null;
    try { $branchReceiver = app(\App\Services\Dashboard\TakeawayAccess::class)->receiverBranch(auth('admin')->user()); } catch (\Throwable $error) { $branchReceiver = null; }
    $branchPrintBoot = ['branch'=>$branchReceiver,'actor_id'=>(int)auth('admin')->id(),'jobs'=>route('phone-orders.print-jobs'),'claim'=>route('phone-orders.print-claim'),'complete'=>route('phone-orders.print-complete'),'orders'=>route('phone-orders.index'),'labels'=>['ready'=>__('phone_orders.printer_receiving'),'offline'=>__('phone_orders.printer_offline'),'attention'=>__('phone_orders.printer_attention'),'incoming'=>__('phone_orders.incoming'),'enable_sound'=>__('phone_orders.enable_sound')]];
@endphp
@if($branchReceiver)
<script type="application/json" id="branch-print-bootstrap">@json($branchPrintBoot)</script>
<script src="{{ asset('dashboard/js/branch-print-receiver.js') }}?v={{ filemtime(public_path('dashboard/js/branch-print-receiver.js')) }}"></script>
@endif
