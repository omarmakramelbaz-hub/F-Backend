@extends('admin.index')
@push('custom-css')
<style>
  .notifi .dropdown-divider{
    width: 100%;
  }
  .notifi .dropdown-item{
      display: flex;
      justify-content: space-between;
      align-items: center;
      white-space: inherit;
      gap: 8px
  }
  .dropdown-item:last-child{
      border-bottom: 1px solid transparent;
  }
  .notifi .dropdown-item:focus, .dropdown-item:hover {
    color: #ffffff;
  text-decoration: none;
  border:0;
  background-color: #ED1C24;
   border-radius:10px;}
    .dropdown-item:focus, .dropdown-item:hover p{
      color: #fff !important;
    }
    .dropdown-item.active, .dropdown-item:active {
      color: #ffffff;
      text-decoration: none;
      border:0;
      background-color: #ED1C24;
       border-radius:10px;
    }
    .btn-info.unseen {
        color: var(--main) !important;
        border: 1px solid var(--main) !important;
        background: transparent !important;
    }

    P{
        margin: 0 !important;
    }
    .read_txt{
        color: #fff;
        background: linear-gradient(41deg, var(--main) 0.31%, var(--main-light) 119.6%) !important;
        font-size: 13px;
        padding: 4px 14px;
        border-radius: 16px;
    }
    @media(max-width: 600px){
        .card-body {
            padding: 1rem;
        }
    }
  </style>
  @endpush
  @section('content')
  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark">@lang('main.Get all notifications')</h1>
          </div><!-- /.col -->
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
                <form action="{{route('mark_all_as_read')}}" method="post" data-desktop-notification-read
                      data-notification-ids="{{ json_encode($data->whereNull('read_at')->pluck('id')->values()->all()) }}">@csrf
                  <button type="submit" class="btn btn-primary">@lang('main.mark all notification as read')</button>
                </form>
            </ol>
        </div><!-- /.col -->
        </div><!-- /.row -->
      </div><!-- /.container-fluid -->
    </div>
    <!-- /.content-header -->

    <!-- Main content -->
    <section class="content">
      <div class="container-fluid">
        <div class="card notifi">
          <div class="card-body">
            @forelse($data as $note)
            {{--@if(array_key_exists('redirect', $note->data))
              <a href="{{url('/admin/'.$note->data['redirect'])}}" class="dropdown-item" id="{{$note->id}}">
                <i class="fas fa-envelope me-2"></i>{{$note->data['title']}}
                <p>{{$note->data['text']}}</p>
                <p class="  text-sm"> @php 
                  $now = \Carbon\Carbon::now();
                  $created= $note->created_at;
                  $x= $created->diffForHumans($now);
                  echo $x;
                @endphp</p>  
              </a> 
            @else
              <a href="" class="dropdown-item" id="{{$note->id}}">
                <i class="fas fa-envelope me-2"></i>{{$note->data['title']}}
                <p>{{$note->data['text']}}</p>
                <p class="  text-sm"> @php 
                  $now = \Carbon\Carbon::now();
                  $created= $note->created_at;
                  $x= $created->diffForHumans($now);
                  echo $x;
                @endphp</p>  
              </a> 
            @endif --}} 
            @if(isset($note->data['data']['notification_type']) && $note->data['data']['notification_type']==1 && isset($note->data['data']['order_id']))
            <a href="{{ route('orders.show',$note->data['data']['order_id']) }}" class="dropdown-item" id="{{$note->id}}">
           
            @else
            <a href="{{ array_key_exists('redirect', $note->data) ? url('/admin/'.$note->data['redirect']) : '' }}" class="dropdown-item" id="{{$note->id}}">
           
            @endif
                <div>
                  <i class="fas fa-envelope me-2"></i>{{$note->data['title']}}
                  <p>{{$note->data['text']}}</p>
                  <p class="text-sm">
                    @php
                      $now = \Carbon\Carbon::now();
                      $created = $note->created_at;
                      echo $created->diffForHumans($now);
                    @endphp
                  </p>
                </div>
              
              @if($note->read_at == null)
              <form action="{{route('read_notify',$note->id)}}" class="" method="post" data-desktop-notification-read data-notification-ids="{{ json_encode([$note->id]) }}">
                @method('PUT') @csrf
                <button type="submit" class="btn btn-info unseen"><i class="fa fa-eye p-0"></i></button>
              </form>
              @else
              <span class="btn btn-info">
                <i class="fa fa-check p-0"></i>
              </span>
              <!--<span class="read_txt">@lang('main.read')</span>-->
              @endif
            </a>
            
            {{--<div class="" style="font-weight: bold;">
              @if($note->read_at == null)
              <form action="{{route('read_notify',$note->id)}}" class="" method="post" style="padding: 0.25rem 1.5rem;">
                @method('PUT') @csrf
                <button type="submit" class="btn btn-info"><i class="fa fa-eye p-0"></i></button>
              </form>
              @else
              <span class="read_txt">@lang('main.read')</span>
              @endif
            </div>--}}
            <!--<div class="dropdown-divider"></div>-->
            @empty
            <h5>@lang('main.No notifications to show')</h5>
            @endforelse            
          </div>
        </div>
      </div>
    </section>
  </div>
  @endsection
