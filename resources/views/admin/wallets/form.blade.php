<input type="number" name="from_user" value="" class="form-control" hidden>
<div class="row">
<input type="text" name="type" value="transfer" class="form-control" hidden>

<div class="form-group col-sm-6">
    <label for="to_user"> @lang('main.to_user')</label><span class="text-danger">*</span>
<select name="to_user" class="form-select" required>
        <option value="">@lang('main.choose')</option>
        @foreach($walletUsers as $value)
            <option value="{{$value->id}}" @if($value->id == old('to_user')) selected @endif >{{__('main.'.$value->account_type)}} : {{$value->name}} / {{$value->mobile}} / {{$value->balance}} @lang('main.egp')</option>
        @endforeach
    </select>
    </div>

<div class="form-group col-sm-6">
    <label for="amount"> @lang('main.amount')</label><span class="text-danger">*</span>
    <input type="number" min="1" max="5000" name="amount" value="{{ old('amount') }}"  required
        class="form-control @error('amount') is-invalid @enderror" id="amount" placeholder="@lang('main.send amount')">
</div>

</div>
<div class="form-group col-sm-10">
    <button type="submit" class="btn btn-success">@lang('main.save')</button>
</div>
