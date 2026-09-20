@extends('admin.index')
@section('content')
    <!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
        <!-- Content Header (Page header) -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 text-dark">@lang('main.show') @lang('main.pendingvendors') </h1>
                    </div><!-- /.col -->
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-left">
                         @can('pending_vendor-list')
           <li class="breadcrumb-item"><a href="{{ url('admin/pending_vendors') }}" class="btn btn-primary">@lang('main.showAll') @lang('main.pendingvendors')
        </a></li>  
        @endcan                             </ol>
                    </div><!-- /.col -->
                </div><!-- /.row -->
            </div><!-- /.container-fluid -->
        </div>
        <!-- /.content-header -->

        <!-- Main content -->
        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-lg-12 col-md-12">
                        <div class="card show-data">

                            <div class="row card-body">
                                @if($pending_vendor->application_kind === 'partner')
                                @php
                                    $profession = $professions[$pending_vendor->profession_key]['ar'] ?? $pending_vendor->profession_key;
                                    $partnerPhoto = $pending_vendor->getFirstMediaUrl('partner_photo', 'thumb')
                                        ?: $pending_vendor->getFirstMediaUrl('partner_photo');
                                @endphp
                                <div class="col-12 mb-3">
                                    <div class="alert alert-primary">
                                        <strong>بيانات شريك GO</strong>
                                        <div class="small mt-1">سيتم ربط الحساب تلقائياً بكارت المهنة بعد القبول وتفعيل الحساب من تطبيق الشركاء.</div>
                                    </div>
                                </div>
                                @if($partnerPhoto)
                                <div class="col-sm-4">
                                    <div class="form-group">
                                        <label>الصورة الشخصية</label><br>
                                        <img src="{{ $partnerPhoto }}" alt="Partner photo" style="width:120px;height:120px;object-fit:cover;border-radius:18px">
                                    </div>
                                </div>
                                @endif
                                <div class="col-sm-4"><div class="form-group"><label>السن</label><span>{{ $pending_vendor->age }} سنة</span></div></div>
                                <div class="col-sm-4"><div class="form-group"><label>المهنة</label><span>{{ $profession }}</span></div></div>
                                <div class="col-sm-4"><div class="form-group"><label>نطاق العمل</label><span>{{ $pending_vendor->work_radius_km }} كم</span></div></div>
                                <div class="col-sm-4"><div class="form-group"><label>طريقة استلام المستحقات</label><span>{{ $pending_vendor->payment_method === 'instapay' ? 'Instapay' : 'Vodafone Cash' }}</span></div></div>
                                <div class="col-sm-4"><div class="form-group"><label>بيانات الاستلام</label><span>{{ $pending_vendor->payment_identifier }}</span></div></div>
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>الموقع</label>
                                        <span>{{ $pending_vendor->lat }}, {{ $pending_vendor->lng }}</span>
                                        @if($pending_vendor->lat && $pending_vendor->lng)
                                            <a class="btn btn-sm btn-outline-primary ms-2"
                                               target="_blank"
                                               href="https://www.google.com/maps?q={{ $pending_vendor->lat }},{{ $pending_vendor->lng }}">
                                                فتح الخريطة
                                            </a>
                                        @endif
                                    </div>
                                </div>
                                <div class="col-sm-6"><div class="form-group"><label>الموافقة على الشروط</label><span>{{ $pending_vendor->terms_accepted_at ? $pending_vendor->terms_accepted_at : '—' }}</span></div></div>
                                @endif
                                {{--<div class="col-sm-6">
                                    <div class="form-group">
                                        <label> @lang('main.addedBy')</label>
                                        <span>{{$pending_vendor->admin?->name}}</span>
                                    </div>
                                        
                                </div>--}}
                                @if($pending_vendor->type)
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label> @lang('main.type')</label>
                                        <span>{{__('main.'.$pending_vendor->type)}}</span>
                                    </div>
                                </div>
                                @endif

                                @if($pending_vendor->full_name)
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label> @if($pending_vendor->type == 'delegate') @lang('main.full_name') @else @lang('main.vendor_name') @endif</label>
                                        <span>{{$pending_vendor->full_name}}</span>
                                    </div>
                                </div>
                                @endif
                                @if($pending_vendor->owner_name)
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label> @lang('main.owner_name')</label>
                                        <span>{{$pending_vendor->owner_name}}</span>
                                    </div>
                                </div>
                                @endif
                                @if($pending_vendor->branches_no)
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label> @lang('main.branches_no')</label>
                                        <span>{{ $pending_vendor->branches_no }}</span>
                                    </div>
                                </div>
                                @endif
                                @if($pending_vendor->national_id)
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label> @lang('main.national_id')</label>
                                        <span>{{ $pending_vendor->national_id }}</span>
                                    </div>
                                </div>
                                @endif
                                @if ($pending_vendor->getFirstMediaUrl('national_id_image','thumb'))
                                <div class='col-sm-6'>
                                    <div class="form-group">
                                        <label for="email">@lang('main.national_id_image') </label>
                                        @if ($pending_vendor->getFirstMediaUrl('national_id_image','thumb'))
                                        <img src="{{ $pending_vendor->getFirstMediaUrl('national_id_image','thumb') }}" data-bs-toggle="modal" data-bs-target="#exampleModal3{{ $pending_vendor->id }}" width="10%">
                                        @include('admin.components.modal_photo', [
                                        'image' => $pending_vendor->getFirstMediaUrl('national_id_image','thumb'),
                                        'id' => '3'.$pending_vendor->id,
                                        ])
                                        @else
                                        <span> @lang('main.NoOfferImage')</span>
                                        @endif
                                    </div>
                                </div>
                                @endif

                                @if($pending_vendor->commercial_registration_no)
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label> @lang('main.commercial_registration_no')</label>
                                        <span>{{ $pending_vendor->commercial_registration_no }}</span>
                                    </div>
                                </div>
                                @endif
                                @if ($pending_vendor->getFirstMediaUrl('commercial_registration_no_image','thumb'))
                                <div class='col-sm-6'>
                                    <div class="form-group">
                                        <label for="email">@lang('main.commercial_registration_no_image') </label>
                                        @if ($pending_vendor->getFirstMediaUrl('commercial_registration_no_image','thumb'))
                                        <img src="{{ $pending_vendor->getFirstMediaUrl('commercial_registration_no_image','thumb') }}" data-bs-toggle="modal" data-bs-target="#exampleModal4{{ $pending_vendor->id }}" width="10%">
                                        @include('admin.components.modal_photo', [
                                        'image' => $pending_vendor->getFirstMediaUrl('commercial_registration_no_image','thumb'),
                                        'id' => '4'.$pending_vendor->id,
                                        ])
                                        @else
                                        <span> @lang('main.NoOfferImage')</span>
                                        @endif
                                    </div>
                                </div>
                                @endif

                                @if($pending_vendor->driving_license_no)
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label> @lang('main.driving_license_no')</label>
                                        <span>{{ $pending_vendor->driving_license_no }}</span>
                                    </div>
                                </div>
                                @endif
                                @if ($pending_vendor->getFirstMediaUrl('driving_license_image','thumb'))
                                <div class='col-sm-6'>
                                    <div class="form-group">
                                        <label for="email">@lang('main.driving_license_image') </label>
                                        @if ($pending_vendor->getFirstMediaUrl('driving_license_image','thumb'))
                                        <img src="{{ $pending_vendor->getFirstMediaUrl('driving_license_image','thumb') }}" data-bs-toggle="modal" data-bs-target="#exampleModal{{ $pending_vendor->id }}" width="10%">
                                        @include('admin.components.modal_photo', [
                                        'image' => $pending_vendor->getFirstMediaUrl('driving_license_image','thumb'),
                                        'id' => $pending_vendor->id,
                                        ])
                                        @else
                                        <span> @lang('main.NoOfferImage')</span>
                                        @endif
                                    </div>
                                </div>
                                @endif
                                @if($pending_vendor->tax_no)
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label> @lang('main.tax_no')</label>
                                        <span>{{ $pending_vendor->tax_no }}</span>
                                    </div>
                                </div>
                                @endif
                                @if ($pending_vendor->getFirstMediaUrl('tax_no_image','thumb'))
                                <div class='col-sm-6'>
                                    <div class="form-group">
                                        <label for="email">@lang('main.tax_no_image') </label>
                                        @if ($pending_vendor->getFirstMediaUrl('tax_no_image','thumb'))
                                        <img src="{{ $pending_vendor->getFirstMediaUrl('tax_no_image','thumb') }}" data-bs-toggle="modal" data-bs-target="#exampleModal1{{ $pending_vendor->id }}" width="10%">
                                        @include('admin.components.modal_photo', [
                                        'image' => $pending_vendor->getFirstMediaUrl('tax_no_image','thumb'),
                                        'id' => '1'.$pending_vendor->id,
                                        ])
                                        @else
                                        <span> @lang('main.NoOfferImage')</span>
                                        @endif
                                    </div>
                                </div>
                                @endif
                                @if($pending_vendor->location)
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label> @lang('main.location')</label>
                                        <span>{{ $pending_vendor->location }}</span>
                                    </div>
                                </div>
                                @endif
                                @if($pending_vendor->mobile)
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label> @lang('main.mobile')</label>
                                        <span><a href="tel:+20{{ $pending_vendor->mobile }}">{{ $pending_vendor->mobile }}</a></span>
                                    </div>
                                </div>
                                @endif
                                @if($pending_vendor->another_mobile)
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label> @lang('main.another_mobile')</label>
                                        <span><a href="tel:+20{{ $pending_vendor->another_mobile }}">{{ $pending_vendor->another_mobile }}</a></span>
                                    </div>
                                </div>
                                @endif
                                @if($pending_vendor->vodafone_cash_mobile)
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label> @lang('main.vodafone_cash_mobile')</label>
                                        <span><a href="tel:+20{{ $pending_vendor->vodafone_cash_mobile }}">{{ $pending_vendor->vodafone_cash_mobile }}</a></span>
                                    </div>
                                </div>
                                @endif


                              
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label> @lang('main.status')</label>
                                        <span>{{ __('main.user-'.$pending_vendor->status )}}</span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label> @lang('main.created_at')</label>
                                        <span>{{$pending_vendor->created_at->diffForHumans()}}</span>
                                    </div>
                                </div> 
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label> @lang('main.approve')</label>
                                        <span>
                                            @if($pending_vendor->application_kind === 'partner')
                                                @if($pending_vendor->status === 'pending')
                                                    <form method="POST" action="{{ route('pending_vendors.approvePartner', $pending_vendor) }}" style="display:inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-success">قبول الشريك وتفعيل أهلية إنشاء الحساب</button>
                                                    </form>
                                                @elseif($pending_vendor->status === 'accepted')
                                                    <span class="badge bg-success">تم قبول الشريك — في انتظار تفعيل الحساب من التطبيق إن لم يكن قد فعّله.</span>
                                                @endif
                                            @elseif($pending_vendor->type == 'vendor')
                                                @if($pending_vendor->status != 'accepted')
                                                    <a href="{{route('pending_vendors.addVendor',['pending'=> $pending_vendor->id,'account_type'=>'vendor','pending_vendor' =>$pending_vendor->id])}}" class="btn @if($pending_vendor->status == 'pending') btn-warning @else btn-success @endif">@lang('main.add user account')</a>
                                                @else
                                                    <span style="font-weight:bolder">@lang('main.users added done')</span>
                                                @endif
                                            @elseif($pending_vendor->type == 'delegate')
                                                @if($pending_vendor->status != 'accepted')
                                                <a href="{{route('users.create',['account_type' => 'delegate','pending_vendor'=> $pending_vendor->id])}}" class="btn btn-warning">{{ __('main.add user account')}}</a>
                                                @else
                                                    @php $user = \App\Models\User::where('account_type','delegate')->where('mobile',$pending_vendor->mobile)->first(); @endphp
                                                <a disabled href="{{route('users.show',[$user->id,'account_type' => 'delegate','pending_vendor'=> $pending_vendor->id])}}" class="btn btn-success">{{ __('main.show user account')}}</a>
                                                @endif
                                            @endif
                                            @if($pending_vendor->status == 'declined')
                                            <p>@lang('main.decline_reason') : {{$pending_vendor->decline_reason }}</p>
                                            @endif
                                            @if($pending_vendor->status == 'pending')
                                            <!-- Button trigger modal -->
                                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#declineModal">
                                              @lang('main.decline request')
                                            </button>
                                            
                                            <!-- Modal -->
                                            <div class="modal fade" id="declineModal" tabindex="-1" aria-labelledby="declineModalLabel" aria-hidden="true">
                                              <div class="modal-dialog">
                                                <div class="modal-content">
                                                  <div class="modal-header">
                                                    <h5 class="modal-title" id="exampleModalLabel">@lang('main.decline request')</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                  </div>
                                                  <div class="modal-body">
                                                    <div class="alert alert-warning">@lang('main.request decline_reason')</div>
                                                    <form method="post" action="{{route('sendingDeclineMail',$pending_vendor)}}">
                                                        @csrf
                                                        <textarea name="decline_reason" required rows="5" id="decline_reason"
                                                                class="form-control "></textarea>
                                                        <button type="submit" class="btn btn-success mt-3">
                                                            @lang('main.send')
                                                        </button>
                                                    </form>
                                                  </div>
                                                 
                                                </div>
                                              </div>
                                            </div>
                                            @endif
                                        </span>
                                    </div>
                                </div>            
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
@endsection
