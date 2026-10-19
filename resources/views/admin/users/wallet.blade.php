@extends('admin.index')
@push('custom-css')
    <style type="text/css">
        .hidden {
            display: none;
        }
        .trans{
            color: #243446;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: .5rem;
            background: #fff;
            padding: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .wallet{
            color: #243446;
            display: flex;
            -webkit-box-pack: justify; /* wkhtmltopdf uses this one */
            -webkit-justify-content: space-between;
            justify-content: space-between;
            -webkit-align-items: center;
            align-items: center;
            gap: 30px;
            text-align: center;
            font-weight: bold;
            border-radius: 10px;
            height: calc(100% - 40px);
            padding: 14px;
            background: #fff;
            box-shadow: 0 0 1px rgba(0, 0, 0, 0.125), 0 1px 3px rgba(0, 0, 0, 0.2);
        }
        .wallet img{
          width: 250px;
          align-self: end;
        }
        
        .wallet button{
            min-width: unset;
        }
        .select2-container {
            width: 100% !important;
        }
    </style>
@endpush
@section('content')
    <!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
        <!-- Content Header (Page header) -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row justify-content-between align-items-center gy-2 mb-2">
                    <div class="col-auto">
                        <h1 class="m-0 text-dark">{{trans('main.balance')}}</h1>
                    </div><!-- /.col -->
                    {{--<div class="col-auto">
                        <ol class="breadcrumb float-sm-left">
                            {{$data['balance']}} @lang('main.egp')
                        </ol>
                    </div>--}}<!-- /.col -->
                </div><!-- /.row -->
            </div><!-- /.container-fluid -->
        </div>
        <!-- /.content-header -->

        <!-- Main content -->
        <section class="content">
            <div class="container-fluid">
                @include('admin.layouts.alerts')
                <div class="row gy-3 mb-3">
                    <div class="col-md-6">
                        <h5 class="fw-bold mb-3">@lang('main.charge wallet')</h5>
                        <div class="wallet text-start">
                            <form class="w-100" method="post" action="{{ route('vendor.charging_wallet') }}">
                                    @csrf
                                    <div class="row gy-3 align-items-end">
                                    <div class="col-sm-4">
                                        <label>@lang('main.enter amount')</label>
                                        <input class="form-control" type="number" required value="{{request('amount')}}" name="amount" min="50" max="5000">
                                    </div>
                                    <div class="col-sm-5">
                                        <label> @lang('main.payment_type')</label>
                                        <select class="form-select" name="payment_method" required>
                                            <option value="">@lang('main.choose')</option>
                                            <option value="online">@lang('main.visa')</option>
                                            <option value="v_cash">@lang('main.v_cash')</option>
                                        </select>
                                    </div>
                                    <div class="col-sm-3">
                                        <button type="submit" class="btn btn-success w-100">@lang('main.save')</button>
                                    </div>
                                    </div>
                                </form>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h5 class="fw-bold mb-3">@lang('main.My balance')</h5>
                        <div class="wallet" style="padding-bottom: 0px">
                                    <div style="flex-grow: 1;font-size: larger;">
                                        <h4 class="fw-bold">{{$data['balance']}} @lang('main.egp')</h4>
                                    </div>
                                    <img src="{{url('/')}}/site/images/wallet.svg">
                                </div>
                    </div>
                </div>
                
                @forelse($wallet as $record)
                <div class="trans">
                    <span>
                    <i class="fa-solid fa-money-bill-transfer me-2"></i>
                    @if($record->order_id == null)
                        @if($record->from_user == null || $record->to_user == null)
                           {{__('main.'.$record->type)}} @lang('main.Amount worth') {{$record->amount}} @lang('main.egp') @lang('main.via') {{__('main.'.$record->payment)}}@lang('main.From management to you')
                        @elseif($record->from_user == auth('admin')->user()->id && $record->to_user != auth('admin')->user()->id)
                           {{__('main.'.$record->type)}} @lang('main.Amount worth') {{$record->amount}} @lang('main.egp') @lang('main.via') {{__('main.'.$record->payment)}}@lang('main.From your wallet to wallet')  {{$record->to?->name}} 
                        @elseif($record->to_user == auth('admin')->user()->id && $record->from_user != auth('admin')->user()->id)
                           {{__('main.'.$record->type)}} @lang('main.Amount worth') {{$record->amount}} @lang('main.egp') @lang('main.via') {{__('main.'.$record->payment)}} @lang('main.To your wallet') 
                        @else
                           {{__('main.'.$record->type)}} @lang('main.Amount worth') {{$record->amount}} @lang('main.egp') @lang('main.via') {{__('main.'.$record->payment)}}
                        @endif
                    @elseif($record->cart_order)
                                          {{__('main.'.$record->type)}} @lang('main.Amount worth') {{$record->amount}} @lang('main.egp') @lang('main.via') {{__('main.'.$record->payment)}} @lang('main.On order number') #{{$record->cart_order?->order_no}}
                    @else
                           {{__('main.'.$record->type)}} @lang('main.Amount worth') {{$record->amount}} @lang('main.egp') @lang('main.via') {{__('main.'.$record->payment)}} @lang('main.On request (deleted)')
                    @endif
                    </span>
                    <span>
                        {{$record->created_at->format('Y/m/d')}}
                    </span>
                </div>
                @empty
                        {{ trans('main.empty data') }}
                @endforelse
            </div>
        </section>
    </div>
@endsection
