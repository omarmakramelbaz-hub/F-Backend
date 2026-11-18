<input type="number" name="added_by" value="{{ Auth::guard('admin')->user()->id }}" class="form-control" hidden>
<div class="row">
    @php($contractType = $contract->exists ? $contract->type : old('type', 'vendor'))
    @if($contract->exists)
    <input type="hidden" name="type" value="{{ $contract->type }}">
    @endif
<div class="form-group col-sm-6">
        <label for="type"> @lang('main.type')</label><span class="text-danger">*</span>
        <select class="form-select" @if($contract->exists) disabled @else name="type" @endif>
            <option value="vendor" @if($contractType == 'vendor') selected @endif>@lang('main.vendor')</option>
            <option value="delegate" @if($contractType == 'delegate') selected @endif>@lang('main.delegate')</option>
        </select>
    </div>

<div class="form-group col-sm-12">
    <label for="template"> @lang('main.template')</label>
    <textarea type="text" name="template" class="form-control ckeditor @error('template') is-invalid @enderror" id="template" placeholder="@lang('main.entertemplate')">{{ old('template', $contract->template) }}</textarea>
</div>
<div class="form-group col-sm-12">
    <label>@lang('main.use this words')</label>
    <ul style="text-transform: initial;">
        <li>[contractDate]</li>
        <li>[vendorOwnerName]</li>
        <li>[vendorName]</li>
        <li>[vendorMobile]</li>
        <li>[vendorEmail]</li>
        <li>[vendorVodafoneCash]</li>
        <li>[vendorTaxNo]</li>
        <li>[vendorNationalid]</li>
        <li>[vendorCommercialRegistrationNo]</li>
        <li>[vendorDrivingLicenseNo]</li>

    </ul>
</div>

</div>
<div class="form-group col-sm-10">
    <button type="submit" class="btn btn-success from-prevent-multiple-submits">@lang('main.save')</button>
</div>
