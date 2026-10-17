@extends('admin.index')
@push('custom-css')
<link rel="stylesheet" href="{{ asset('dashboard/css/branch-expenses.css') }}?v={{ filemtime(public_path('dashboard/css/branch-expenses.css')) }}">
@endpush
@section('content')
<div class="content-wrapper branch-expenses-wrapper"><main id="branch-expenses" class="ex-page">
<header class="ex-heading"><div><h1>{{ __('expenses.title') }}</h1><p>{{ __('expenses.subtitle') }}</p></div><label>{{ __('expenses.branch') }}<select data-expense-branch @if(count($boot['branches'])===1) disabled @endif>@if($boot['allow_all'])<option value="all" @if($boot['selected_branch']==='all') selected @endif>{{ __('expenses.all_branches') }}</option>@endif @foreach($boot['branches'] as $branch)<option value="{{ $branch['value'] }}" @if($boot['selected_branch']===$branch['value']) selected @endif>{{ $branch['name'] }}</option>@endforeach</select></label><div class="ex-balance"><i class="fas fa-cash-register" aria-hidden="true"></i><span>{{ __('expenses.cash_balance') }}<strong data-expense-balance>—</strong></span></div></header>
<div class="ex-notice" data-expense-message role="status" hidden><span></span><button type="button" data-expense-retry hidden>{{ __('expenses.retry') }}</button></div>
<div class="ex-layout"><section class="ex-main">
<div class="ex-metrics">@foreach(['today'=>'fa-wallet','month'=>'fa-calendar-alt','average'=>'fa-chart-line','top_category'=>'fa-arrow-trend-up','count'=>'fa-file-invoice'] as $key=>$icon)<article class="ex-metric"><span class="ex-metric-icon"><i class="fas {{ $icon }}" aria-hidden="true"></i></span><div><h2>{{ __('expenses.'.$key) }}</h2><strong data-expense-metric="{{ $key }}">—</strong><small @if($key==='top_category') data-expense-top-amount @endif>{{ $key==='count' ? __('expenses.count') : __('expenses.currency') }}</small></div></article>@endforeach</div>
<p class="ex-summary-note">{{ __('expenses.summary_note') }}</p>
<section class="ex-panel"><form class="ex-filters" data-expense-filters><label class="ex-search"><span class="ex-sr">{{ __('expenses.search') }}</span><input name="search" type="search" placeholder="{{ __('expenses.search') }}" maxlength="100"></label><label>{{ __('expenses.from') }}<input type="date" name="from" value="{{ $boot['initial']['filters']['from'] }}" required></label><label>{{ __('expenses.to') }}<input type="date" name="to" value="{{ $boot['initial']['filters']['to'] }}" required></label><select name="category" aria-label="{{ __('expenses.category') }}"><option value="">{{ __('expenses.all_categories') }}</option>@foreach($boot['initial']['categories'] as $category=>$categoryName)<option value="{{ $category }}">{{ $categoryName }}</option>@endforeach</select><select name="actor_id" data-expense-actors aria-label="{{ __('expenses.actor') }}"><option value="">{{ __('expenses.all_users') }}</option></select><select name="status" aria-label="{{ __('expenses.status') }}"><option value="">{{ __('expenses.all_statuses') }}</option>@foreach(['pending','approved','rejected','voided'] as $status)<option value="{{ $status }}">{{ __('expenses.status_'.$status) }}</option>@endforeach</select><button class="ex-button" type="submit">{{ __('expenses.apply') }}</button></form>
<div class="ex-toolbar"><button class="ex-primary" type="button" data-expense-new><i class="fas fa-plus" aria-hidden="true"></i>{{ __('expenses.new') }}</button>@if($boot['permissions']['can_manage_categories'])<button class="ex-button ex-category-open" type="button" data-expense-categories-open><i class="fas fa-plus-circle" aria-hidden="true"></i> {{ __('expenses.add_category') }}</button>@endif<span data-expense-period></span><div><a data-expense-export class="ex-button" data-spa-off download><i class="fas fa-file-excel" aria-hidden="true"></i>{{ __('expenses.export') }}</a><button class="ex-button" type="button" data-expense-report><i class="fas fa-print" aria-hidden="true"></i>{{ __('expenses.print') }}</button></div></div>
<div class="ex-table-scroll"><table class="ex-table"><thead><tr>@foreach(['number','date','branch','category','description','amount','payment_method','actor','status','attachments','actions'] as $key)<th>{{ __('expenses.'.$key) }}</th>@endforeach</tr></thead><tbody data-expense-rows></tbody></table><p class="ex-empty" data-expense-empty hidden>{{ __('expenses.empty') }}</p></div>
<footer class="ex-pagination"><button class="ex-button" type="button" data-expense-previous>{{ __('expenses.previous') }}</button><span data-expense-page></span><button class="ex-button" type="button" data-expense-next>{{ __('expenses.next') }}</button></footer>
</section></section>
<aside class="ex-panel ex-editor" data-expense-editor><header><h2 data-expense-form-title>{{ __('expenses.new') }}</h2><button type="button" data-expense-cancel aria-label="{{ __('expenses.close') }}">×</button></header><form data-expense-form>
<div class="ex-pair"><label>{{ __('expenses.branch') }}<select name="branch" required @if(count($boot['branches'])===1) disabled @endif>@foreach($boot['branches'] as $branch)<option value="{{ $branch['value'] }}">{{ $branch['name'] }}</option>@endforeach</select></label>
<label>{{ __('expenses.category') }} <b>*</b><select name="category" required><option value="">{{ __('expenses.category') }}</option>@foreach($boot['initial']['active_categories'] as $category=>$categoryName)<option value="{{ $category }}">{{ $categoryName }}</option>@endforeach</select></label></div>
@if($boot['permissions']['can_manage_categories'])
<button type="button" class="ex-button ex-category-open" data-expense-categories-open><i class="fas fa-list" aria-hidden="true"></i> {{ __('expenses.manage_categories') }}</button>
@endif
<label>{{ __('expenses.description') }} <b>*</b><textarea name="description" maxlength="500" rows="2" required></textarea></label>
<div class="ex-pair"><label>{{ __('expenses.amount') }} <b>*</b><input name="amount" inputmode="decimal" placeholder="0.00" maxlength="14" required></label><label>{{ __('expenses.date') }} <b>*</b><input name="occurred_on" type="date" value="{{ $boot['today'] }}" max="{{ $boot['today'] }}" required></label></div>
<p class="ex-cash-source">{{ __('expenses.payment_method') }}: <strong>{{ __('expenses.method_cash') }}</strong><input type="hidden" name="payment_method" value="cash"></p>
<p class="ex-note">{{ __('expenses.cash_note') }}</p>
<div class="ex-form-actions"><button type="submit" class="ex-primary" data-expense-save>{{ __('expenses.save') }}</button>@if($boot['permissions']['can_approve'])<button type="button" class="ex-approve" data-expense-save-approve>{{ __('expenses.save_approve') }}</button>@endif</div>
</form></aside></div>
@if($boot['permissions']['can_manage_categories'])
<dialog class="ex-dialog ex-categories-dialog" data-expense-categories-dialog aria-labelledby="ex-categories-title">
<header><h2 id="ex-categories-title">{{ __('expenses.manage_categories') }}</h2><button type="button" data-expense-categories-close aria-label="{{ __('expenses.close') }}">×</button></header>
<div><p class="ex-category-help">{{ __('expenses.category_help') }}</p>
<form data-expense-category-form class="ex-category-form"><label>{{ __('expenses.category_name') }}<input data-expense-category-name maxlength="80" autocomplete="off" required></label><button type="submit" class="ex-primary" data-expense-category-save>{{ __('expenses.add_category') }}</button><button type="button" class="ex-button" data-expense-category-cancel hidden>{{ __('expenses.cancel_category_edit') }}</button></form>
<p class="ex-category-status" data-expense-category-status role="status"></p>
<div class="ex-category-list" data-expense-category-list></div>
</div></dialog>
@endif
<dialog class="ex-dialog" data-expense-dialog><header><h2>{{ __('expenses.details') }}</h2><button type="button" data-expense-close aria-label="{{ __('expenses.close') }}">×</button></header><div data-expense-detail></div></dialog>
</main></div>
@endsection
@push('custom-js')
<script type="application/json" id="branch-expenses-bootstrap">@json($boot)</script>
<script type="application/json" id="branch-expenses-labels">@json(trans('expenses'))</script>
<script src="{{ asset('dashboard/js/branch-expenses.js') }}?v={{ filemtime(public_path('dashboard/js/branch-expenses.js')) }}"></script>
@endpush
