<style>
    .country_code{
        position: absolute;
        top: 40px;
        left: 20px;
    }
    label .btn{
        color: #0056b3;
        text-decoration: underline !important;
        padding: 0
    }
</style>
<div class="row">
    @php
        $pending_vendor = request('pending_vendor')&& request('pending_vendor')!=null?App\Models\PendingVendor::where('id',request('pending_vendor'))->first():$user->pending_vendor;
    @endphp
        <input type="hidden" name="pending_vendor_id" value="{{$pending_vendor?->id}}">
    <input type="hidden" name="account_type" value="{{request('account_type')}}">
    <input type="hidden" name="added_by" value="{{auth('admin')->user()->id}}">
    <div class="form-group col-sm-6">
        <label for="name"> @lang('main.name')</label><span class="text-danger">*</span>
        <input type="text" name="name" @if(request('pending_vendor')) value="{{ old('name', $pending_vendor?->full_name) }}" @else value="{{ old('name', $user->name) }}" @endif
            class="form-control  @error('name') is-invalid @enderror" id="name" placeholder="@lang('main.enter') @lang('main.Name')">
    </div>


        
    <div class="form-group col-sm-6">
        
        <label for="mobile"> 
        @lang('main.mobile')
        @if($user->mobile)
            <!-- Button trigger modal -->
            <button type="button" class="btn" data-bs-toggle="modal" data-bs-target="#changeMobile">
             <i class="fa-solid fa-pencil"></i>
             ( 
             @lang('main.change mobile') 
             )
            </button>
        @endif
        </label>
        
        <span class="text-danger">*</span>
        <span class="country_code">20+</span>
        <input type="text" name="mobile" @if(\Route::currentRouteName() == 'users.edit') readonly @endif @if(request('pending_vendor')) value="{{ old('mobile', $pending_vendor?->mobile) }}" @else value="{{ old('mobile', $user->mobile) }}" @endif class="form-control @error('mobile') is-invalid @enderror"
            id="mobile" placeholder="@lang('main.enter') @lang('main.mobile')">
    </div>
    
    
    

    <div class="form-group col-sm-6">
        <label for="email"> @lang('main.email')</label><span class="text-danger">*</span>
        <input type="email" name="email" @if(request('pending_vendor')) value="{{ old('email', $pending_vendor?->email) }}" @else value="{{ old('email', $user->email) }}" @endif class="form-control @error('email') is-invalid @enderror"
            id="email" placeholder="@lang('main.enter') @lang('main.email')">
    </div>
    @if(request('account_type') == 'user')
       <div class="form-group col-sm-6">
                <label for="area_id"> @lang('main.area_id')</label><span class="text-danger">*</span>
                <select name="area_id" id="category-dd" class="form-select @error('area_id') is-invalid @enderror">
                    <option value="">@lang('main.choose')</option>
                    @foreach($areas as $value)
                        <option value="{{$value->id}}" @if($value->id == old('area_id', $user->area_id)) selected @endif >{{$value->title}}</option>
                    @endforeach
                </select>
            </div>
    @endif
     @if(request('account_type') == 'resturant_owner')
     @if($user->owner_resturant && $user->owner_resturant !=null )
          <div class="form-group col-sm-6">
                <label for="owner_resturant_id"> @lang('main.resturant')</label><span class="text-danger">*</span>
                 <input type="text"class="form-select @error('owner_resturant_id') is-invalid @enderror" reaonly disabled value="{{$user->owner_resturant?->name}}"/>
                 <input type="hidden" name="owner_resturant_id" value="{{$user->owner_resturant_id}}"/>
        </div>
     @else
       <div class="form-group col-sm-6">
                <label for="owner_resturant_id"> @lang('main.resturant')</label><span class="text-danger">*</span>
                <select name="owner_resturant_id" id="owner_resturant_id" class="form-select @error('owner_resturant_id') is-invalid @enderror">
                    <option value="">@lang('main.choose')</option>
                    @foreach(\App\Models\Resturant::whereNull('parent_id')->whereDoesntHave('owner')->get() as $value)
                        <option value="{{$value->id}}" @if($value->id == old('owner_resturant_id', $user->owner_resturant_id)) selected @endif >{{$value->name}}</option>
                    @endforeach
                </select>
        </div>
    @endif
    @endif
   
    <div class="form-group col-sm-6">
        <label for="password"> @lang('main.Password')</label><span class="text-danger">*</span>
        <input type="password" name="password" value=""
            class="form-control @error('password') is-invalid @enderror" id="password"
            placeholder="@lang('main.EnterPassword')">
        <button type="button" class="show-pass" toggle="#password">
            <i class="fa fa-eye-slash"></i>
        </button>
    </div>
     @if(request('account_type') == 'user')
      <div class="form-group col-sm-6">
                <label for="gender"> @lang('main.gender')</label><span class="text-danger">*</span>
                <select name="gender" class="form-select @error('gender') is-invalid @enderror">
                    <option value="male" @if('male' == old('gender', $user->gender)) selected @endif>@lang('main.male')</option>
                    <option value="female" @if('female' == old('gender', $user->gender)) selected @endif>@lang('main.female')</option>
                </select>
            </div>
    @endif
  
    @if(request('account_type') == 'admin')
       @if($user->id != 1)
        <div class="form-group col-sm-6">
            <label for="roles_name">@lang('main.AdminRole')</label><span class="text-danger">*</span>
            <select name="roles_name[0]" class="form-control @error('roles_name') is-invalid @enderror" required>
                <option value="">@lang('main.SelecteAdminRole')</option>
                @foreach ($roles as $role)
                    <option value="{{ $role->name }}"
                        {{ isset($userRole) && $role->name == $userRole ? 'selected' : '' }}>{{ $role->name }}
                    </option>
                @endforeach
            </select>
        </div>
        @endif
    @elseif(request('account_type') == 'delegate')
     <div class="form-group col-sm-6">
        <label for="delegate_fees"> @lang('main.delegate_fees')</label><span class="text-danger">*</span>
             <div class="input-group mb-3">
     <span class="input-group-text" id="basic-addon1">%</span>

        <input type="text" name="delegate_fees" @if(request('pending_vendor')) value="{{ old('delegate_fees', $pending_vendor?->delegate_fees) }}" @else value="{{ old('delegate_fees', $user->delegate_fees) }}" @endif
            class="form-control  @error('delegate_fees') is-invalid @enderror" id="delegate_fees" placeholder="@lang('main.enter') @lang('main.delegate_fees')">
   </div>
    </div>
      <input type="hidden" name="roles_name[0]" value="delegate"/>
    @elseif(request('account_type') == 'vendor')
      <input type="hidden" name="roles_name[0]" value="vendor"/>
    @elseif(request('account_type') == 'user')
      <input type="hidden" name="roles_name[0]" value="user"/>
    @elseif(request('account_type') == 'resturant_owner')
      <input type="hidden" name="roles_name[0]" value="resturant_owner"/>
    @endif
    
@if(auth()->user()->roles->pluck("id")->first() == 11)
    @if(request('account_type') == 'delegate' || request('account_type') == 'vendor')
     <div class="form-group col-sm-6">
        <label for="min_wallet"> @lang('main.min_wallet')</label><span class="text-danger">*</span>
             <div class="input-group mb-3">

        <input type="text" name="min_wallet" @if(request('pending_vendor')) value="{{ old('min_wallet', $pending_vendor?->min_wallet) }}" @else value="{{ old('name', $user->min_wallet) }}" @endif
            class="form-control  @error('min_wallet') is-invalid @enderror" id="min_wallet" placeholder="@lang('main.enter') @lang('main.min_wallet')">
   </div>
   @endif
   @elseif(auth()->user()->roles->pluck("id")->first() == 13)
   <div class="form-group col-sm-6">
        <label for="min_wallet"> @lang('main.min_wallet')</label><span class="text-danger">*</span>
             <div class="input-group mb-3">

        <input type="text" name="min_wallet"readonly value="{{ old('min_wallet', auth('admin')->user()->min_wallet) }}" 
            class="form-control  @error('min_wallet') is-invalid @enderror" id="min_wallet" placeholder="@lang('main.enter') @lang('main.min_wallet')">
   </div>
@endif
    @if(! request('pending_vendor'))
        @if(request('account_type') != 'user')
    <div class="form-group col-sm-6">
        <label for="photo_profile">@lang('main.ProfileImage')</label>

        <div class="input-group mb-2">
            <input type="file" name="photo_profile" id="photo_profile" class="form-control"
                onchange="document.getElementById('image').src = window.URL.createObjectURL(this.files[0])">
        </div>
        <div class="col-sm-6">
            @if($user->getFirstMediaUrl('photo_profile','thumb'))
                <img class="cursor-img" data-toggle="modal" data-target="#exampleModal{{ $user->id }}"
                    id="image" src="{{$user->getFirstMediaUrl('photo_profile','thumb')}}" style="width:70%;"
                    alt="@lang('main.NoImageUploaded')">
                @include('admin.components.modal_photo', [
                    'image' => $user->getFirstMediaUrl('photo_profile','thumb'),
                    'id' => $user->id,
                ])
            @else
                <img id="image" src="{{ url('dashboard/dist/img/no-photo.png') }}"
                    style="height: 80px; width: 100px;">
            @endif
        </div>
    </div>
    @endif
    @endif
    
    </div>
    @if(\Route::currentRouteName() == 'users.create') 
         <div class="row">
    
    <!--=================================pending vendor===================================-->
                                      
                                 
                                     @if(request()->account_type=='vendor')
                                        <div class="form-group col-sm-6">
                                            <label for="owner_name"> @lang('main.owner_name')</label><span class="text-danger">*</span>
                                            <input type="text" name="owner_name" required value=" {{ old('owner_name', $pending_vendor?->owner_name) }} " class="form-control @error('owner_name') is-invalid @enderror"
                                                id="owner_name">
                                        </div>
                                         <div class="form-group d-none col-sm-6">
                                            <label for="branches_no"> @lang('main.branches_no')</label><span class="text-danger">*</span>
                                            <input type="text" name="branches_no" required value=" {{ old('branches_no', $pending_vendor?->branches_no) }} " class="form-control @error('branches_no') is-invalid @enderror"
                                                id="branches_no">
                                        </div>
                                        
                                     @endif
                                     @if(request()->account_type=='vendor'||request()->account_type=='delegate')
                                     <div class="form-group col-sm-6">
                                            <label for="national_id"> @lang('main.national_id')</label><span class="text-danger">*</span>
                                            <input type="text" name="national_id" required value=" {{ old('national_id', $pending_vendor?->national_id) }} " class="form-control @error('national_id') is-invalid @enderror"
                                                id="national_id">
                                     </div>
                                     <div class="form-group col-sm-6">
                                            <label for="national_id_image">@lang('main.national_id_image')</label>
                                    
                                            <div class="input-group mb-2">
                                                <input type="file" name="national_id_image" id="national_id_image" class="form-control"
                                                    onchange="document.getElementById('image').src = window.URL.createObjectURL(this.files[0])">
                                            </div>
                                            <div class="col-sm-6">
                                                @if($pending_vendor?->getFirstMediaUrl('national_id_image','thumb'))
                                                    <img class="cursor-img" data-toggle="modal" data-target="#exampleModal{{ $pending_vendor?->id }}"
                                                        id="image" src="{{$pending_vendor?->getFirstMediaUrl('national_id_image','thumb')}}" style="width:70%;"
                                                        alt="@lang('main.NoImageUploaded')">
                                                    @include('admin.components.modal_photo', [
                                                        'image' => $pending_vendor?->getFirstMediaUrl('national_id_image','thumb'),
                                                        'id' => $pending_vendor?->id,
                                                    ])
                                                @else
                                                    <img id="image" src="{{ url('dashboard/dist/img/no-photo.png') }}"
                                                        style="height: 80px; width: 100px;">
                                                @endif
                                            </div>
                                        </div>
                                        @endif
                                     @if(request()->account_type=='vendor')
                                         <div class="form-group col-sm-6">
                                            <label for="commercial_registration_no"> @lang('main.commercial_registration_no')</label><span class="text-danger">*</span>
                                            <input type="text" name="commercial_registration_no" required value=" {{ old('commercial_registration_no', $pending_vendor?->commercial_registration_no) }} " class="form-control @error('commercial_registration_no') is-invalid @enderror"
                                                id="commercial_registration_no">
                                        </div>
                                        <div class="form-group col-sm-6">
                                            <label for="commercial_registration_no_image">@lang('main.commercial_registration_no_image')</label>
                                    
                                            <div class="input-group mb-2">
                                                <input type="file" name="commercial_registration_no_image" id="commercial_registration_no_image" class="form-control"
                                                    onchange="document.getElementById('image').src = window.URL.createObjectURL(this.files[0])">
                                            </div>
                                            <div class="col-sm-6">
                                                @if($pending_vendor?->getFirstMediaUrl('commercial_registration_no_image','thumb'))
                                                    <img class="cursor-img" data-toggle="modal" data-target="#exampleModal{{ $pending_vendor?->id }}"
                                                        id="image" src="{{$pending_vendor?->getFirstMediaUrl('commercial_registration_no_image','thumb')}}" style="width:70%;"
                                                        alt="@lang('main.NoImageUploaded')">
                                                    @include('admin.components.modal_photo', [
                                                        'image' => $pending_vendor?->getFirstMediaUrl('commercial_registration_no_image','thumb'),
                                                        'id' => $pending_vendor?->id,
                                                    ])
                                                @else
                                                    <img id="image" src="{{ url('dashboard/dist/img/no-photo.png') }}"
                                                        style="height: 80px; width: 100px;">
                                                @endif
                                            </div>
                                        </div>
                                     
                                     @endif
                                      @if(request()->account_type=='delegate')
                                      <div class="form-group col-sm-6">
                                            <label for="driving_license_no"> @lang('main.driving_license_no')</label><span class="text-danger">*</span>
                                            <input type="text" name="driving_license_no" required value=" {{ old('driving_license_no', $pending_vendor?->driving_license_no) }} " class="form-control @error('driving_license_no') is-invalid @enderror"
                                                id="driving_license_no">
                                        </div>
                                         <div class="form-group col-sm-6">
                                            <label for="driving_license_image">@lang('main.driving_license_image')</label>
                                    
                                            <div class="input-group mb-2">
                                                <input type="file" name="driving_license_image" id="driving_license_image" class="form-control"
                                                    onchange="document.getElementById('image').src = window.URL.createObjectURL(this.files[0])">
                                            </div>
                                            <div class="col-sm-6">
                                                @if($pending_vendor?->getFirstMediaUrl('driving_license_image','thumb'))
                                                    <img class="cursor-img" data-toggle="modal" data-target="#exampleModal{{ $pending_vendor?->id }}"
                                                        id="image" src="{{$pending_vendor?->getFirstMediaUrl('driving_license_image','thumb')}}" style="width:70%;"
                                                        alt="@lang('main.NoImageUploaded')">
                                                    @include('admin.components.modal_photo', [
                                                        'image' => $pending_vendor?->getFirstMediaUrl('driving_license_image','thumb'),
                                                        'id' => $pending_vendor?->id,
                                                    ])
                                                @else
                                                    <img id="image" src="{{ url('dashboard/dist/img/no-photo.png') }}"
                                                        style="height: 80px; width: 100px;">
                                                @endif
                                            </div>
                                        </div>
                                     
                                     @endif
                                      @if(request()->account_type=='vendor')
                                      <div class="form-group col-sm-6">
                                            <label for="tax_no"> @lang('main.tax_no')</label><span class="text-danger">*</span>
                                            <input type="text" name="tax_no" required value=" {{ old('tax_no', $pending_vendor?->tax_no) }} " class="form-control @error('tax_no') is-invalid @enderror"
                                                id="tax_no">
                                        </div>
                                          <div class="form-group col-sm-6">
                                            <label for="tax_no_image">@lang('main.tax_no_image')</label>
                                    
                                            <div class="input-group mb-2">
                                                <input type="file" name="tax_no_image" id="tax_no_image" class="form-control"
                                                    onchange="document.getElementById('image').src = window.URL.createObjectURL(this.files[0])">
                                            </div>
                                            <div class="col-sm-6">
                                                @if($pending_vendor?->getFirstMediaUrl('tax_no_image','thumb'))
                                                    <img class="cursor-img" data-toggle="modal" data-target="#exampleModal{{ $pending_vendor?->id }}"
                                                        id="image" src="{{$pending_vendor?->getFirstMediaUrl('tax_no_image','thumb')}}" style="width:70%;"
                                                        alt="@lang('main.NoImageUploaded')">
                                                    @include('admin.components.modal_photo', [
                                                        'image' => $pending_vendor?->getFirstMediaUrl('tax_no_image','thumb'),
                                                        'id' => $pending_vendor?->id,
                                                    ])
                                                @else
                                                    <img id="image" src="{{ url('dashboard/dist/img/no-photo.png') }}"
                                                        style="height: 80px; width: 100px;">
                                                @endif
                                            </div>
                                        </div>
                                     
                                     @endif
                                      @if(request()->account_type=='delegate')
                                      @include('admin.users.partials.work_area')
                                     
                                     @endif
                                     @if(request()->account_type=='vendor'||request()->account_type=='delegate')
                                     <div class="form-group col-sm-6">
                                            <label for="vodafone_cash_mobile"> @lang('main.vodafone_cash_mobile')</label><span class="text-danger">*</span>
                                            <input type="text" name="vodafone_cash_mobile" required value=" {{ old('vodafone_cash_mobile', $pending_vendor?->vodafone_cash_mobile) }} " class="form-control @error('vodafone_cash_mobile') is-invalid @enderror"
                                                id="vodafone_cash_mobile">
                                        </div>
                                          <div class="form-group col-sm-6"></div>
                                          @endif
                                   
    
    </div>
    @endif
    
    
    

@if(\Route::currentRouteName() != 'users.create' && $user->account_type == 'delegate')
    <div class="row">
        @include('admin.users.partials.work_area')
    </div>
@endif

<div class="form-group col-sm-6">
    <button type="submit" class="btn btn-success">@lang('main.save')</button>
</div>
