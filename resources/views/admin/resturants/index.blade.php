@extends('admin.index')
@push('custom-css')
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endpush
@section('content')
    <!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
        <!-- Content Header (Page header) -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row gy-3 mb-2">
                    <div class="col-6">
                        <h1 class="m-0 text-dark">@lang('main.showAll') @lang('main.resturants')
            <small class="countModule">( {{$resturants->count()}} ) </small>
         </h1>
                    </div><!-- /.col -->
                    <div class="col-6">
                        <ol class="breadcrumb float-sm-left">
            @can('resturant-create')
           <li class="breadcrumb-item"><a href="{{ url('admin/resturants/create?resturant_id='.request('resturant_id')) }}" class="btn btn-primary">@lang('main.add') 
           
            @if( request('resturant_id'))
             @lang('main.branches')
             @else
            @lang('main.resturants')  @endif
            
        </a></li>  
        @endcan    
                                </ol>
                    </div><!-- /.col -->
                    <div class="col-12">
                        <div class="info-box" style="text-align: start;">
                            <a href="{{url('admin/resturant_map')}}" class="link"></a>
                            <span class="info-box-icon bg-info" style="width: 70px">
                                <!--<i class="fas fa-user-cog"></i>-->
                                <i class="fas fa-map-marked-alt"></i>
                            </span>
                            <div class="info-box-content">
                                <span class="info-box-text">@lang('main.resturants on map')</span>
                            </div>
                          <!-- /.info-box-content -->
                        </div>
                        <!-- /.info-box -->
                    </div>
                </div><!-- /.row -->
            </div><!-- /.container-fluid -->
        </div>
        <!-- /.content-header -->

        <!-- Main content -->
        <section class="content">
            <div class="container-fluid">
                <div class="">
                    <div class="card">
                        @push('card_title')
                            @lang('main.resturants')
                        @endpush
                        @include('admin.partials.card_header_in_index')

                        <div class="card-body">
                            {{-- Buttons part --}}
                            @can('resturant-delete')
                            <div class="btn-group flex-wrap float-left mb-4">
                                @include('admin.partials.button_group', [
                                    'url' => url('admin/resturantsDeleteAll'),
                                ])
                            </div>
                            @endcan
                            {{-- search part --}}
                            @if(auth()->user()->roles->pluck("id")->first() == 11)
                            <div class="float-right mb-4">
                                @include('admin.partials.search_part', [
                                    'route' => route('resturants.index'),
                                ])
                                {{-- <a class="btn btn-info" href="{{route('export-resturants')}}">@lang('main.export excel')</a> --}}
                            </div>
                            @endif
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <th width="50px"><input type="checkbox" id="master"></th>
                                        <th>#</th>
                                        <th>@lang('main.resturant_owner')</th>
                                        <th>@lang('main.resturant_name')</th>
                                        <th>@lang('main.resturant_area')</th>
                                        <th>@lang('main.status')</th>
                                        <th>@lang('main.is_featured')</th>
                                        @if(auth()->user()->roles->pluck("id")->first() == 11)
                                        <th>@lang('main.under_contract')</th>
                                        @endif
                                        <th>@lang('main.created_at')</th>
                                        <th>@lang('main.actions')</th>
    
                                    </thead>
                                    <tbody>
                                        @forelse ($resturants as $resturant)
                                            <tr>
                                                <td>@if($resturant->id != 1) <input type="checkbox" class="sub_chk" data-id="{{ $resturant->id }}">
                                                @endif
                                                </td>
                                                <td>{{ $loop->iteration }}</td>
                                                <td>
                                                    <a href="{{route('users.show',[$resturant->user_id,'account_type'=>'vendor'])}}">{{ $resturant->user?->name }}</a>
                                                </td>
                                                <td>
                                                    {{ $resturant->name }}
                                                </td>
                                                <td>
                                                    {{ ($resturant->resturant_areas->isNotEmpty())? $resturant->resturant_areas->first()->area?->title : '-' }}
                                                </td>
                                                <td @if($resturant->status == 'closed'||$resturant->status == 'hide') style="background: #db0f0f;color: white;" @elseif($resturant->status == 'opened') style="background: #45db0f;color: white;" @elseif($resturant->status == 'busy') style="background: #db820f;color: white;" @elseif($resturant->status == 'disabled') style="background: #ffdd40;color: white;"  @endif>
                                                    {{ __('main.'.$resturant->status) }}
                                                </td>
                                                @if(auth()->user()->roles->pluck("id")->first() == 11)
                                                <td>          
                                                    <form method="post" action="{{route('resturants.changeStatus',[$resturant->id])}}"> @csrf
                                                    <input type="checkbox" onchange="this.form.submit()" class="cm-toggle" id="customSwitch-{{$resturant->id}}" name="is_featured" @if($resturant->is_featured == 'yes') checked="" @endif>
                                                    <label class="" for="customSwitch-{{$resturant->id}}">       
                                                    </form>  
                                                    
                                                    <form method="post" action="{{route('update_sorting_is_featured',$resturant->id)}}" style="display: @if($resturant->is_featured == 'yes') flex @else none @endif ;gap: 5px;border-top: 1px solid #ddd;padding-top: 1rem">
                                                        @csrf
                                                        <input type="hidden" name="page" value="{{request('page')}}">
                                                        <input type="number" class="form-control px-1" min="1" step="1" max="" name="sortby_is_featured" value="{{$resturant->sortby_is_featured}}" style="width: 46px;">
                                                        <button type="submit" class="btn btn-success" style="min-width: unset;border-radius: 6px">
                                                            <i class="fa-solid fa-floppy-disk"></i>
                                                        </button>
                                                    </form>
                                                </td>@else
                                                <td>
                                                    {{ $resturant->is_featured?__('main.'.$resturant->is_featured):''}}
                                                    
                                                </td>
                                                @endif
                                                @if(auth()->user()->roles->pluck("id")->first() == 11)
                                                <td>          
                                                    <form method="post" action="{{route('resturants.changeUnderContract',[$resturant->id])}}"> @csrf
                                                    <input type="checkbox" onchange="this.form.submit()" class="cm-toggle" id="customSwitch-{{$resturant->id}}" name="under_contract" @if($resturant->under_contract == 'yes') checked="" @endif>
                                                    <label class="" for="customSwitch-{{$resturant->id}}">       
                                                    </form>  
                                                </td>
                                                @endif
                                                <td>
                                                    {{$resturant->created_at?->diffForHumans() ?? '—'}}
                                                </td>
                                                
                                                <td width="250px">
                                                    @can('resturant-list')
                                                      @if(auth()->user()->roles->pluck("id")->first() == 11)
                                                        @if($resturant->parent_id == null)
                                                        <a class="btn btn-light w-100 mb-2"
                                                            href="{{ route('resturants.create',['resturant_id' => $resturant->id]) }}">@lang('main.add new branch')</a>
                                                        @else
                                                        <p class="mb-2">@lang('main.One of the branches') {{$resturant->parent?->name}} </p>
                                                        @endif
                                                      @endif
                                                    @endcan
                                                <div>
                                                   @can('resturant-list')

                                                        <a class="btn btn-info"
                                                            href="{{ route('resturants.show',['q' => request('q'),$resturant->id, 'resturant_id' => request('resturant_id')]) }}">@lang('main.show')</a>
                                                    @endcan
                                                   
                                                    @can('resturant-edit')
                                                        <a class="btn btn-warning"
                                                            href="{{ route('resturants.edit',['q' => request('q'),$resturant->id, 'resturant_id' => request('resturant_id')]) }}">@lang('main.edit')</a>
                                                    @endcan
                                                     @if($resturant->id != 1)
                                                    @can('resturant-delete')
                                                        {!! \App\Support\NativeDeleteForm::open([
                                                            'method' => 'DELETE',
                                                            'route' => ['resturants.destroy', $resturant->id],
                                                            'style' => 'display:inline',
                                                        ]) !!}
                                                        <button type="submit"
                                                            class="btn btn-danger show_confirm">@lang('main.delete')</button>
                                                        {!! \App\Support\NativeDeleteForm::close() !!}
                                                    
                                                    @endcan
                                                    @endif
                                                  </div>
                                                </td>
                                            </tr>
                                        @empty
                                            <td class="text-center text-muted" style="font-size: 25px" colspan="10">
                                                {{ trans('main.Noresturants') }}
                                            </td>
                                        @endforelse
                                    </tbody>
                                </table>
                        
                            </div>
                        </div>
                    </div>
                </div>
                {{ $resturants->withQueryString()->links() }}
            </div>
        </section>
    </div>
@endsection
