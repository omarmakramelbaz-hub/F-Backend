@php($savedLines = is_array(old('lines')) ? array_values(array_slice(old('lines'),0,30)) : [[]])
<div data-line-editor data-next-index="{{ count($savedLines) }}">
    <div data-line-rows>@foreach($savedLines as $index=>$values)@include('erp.line-row')@endforeach</div>
    <template data-line-template>@include('erp.line-row',['index'=>'__INDEX__','values'=>[]])</template>
    <div class="actions"><button type="button" class="secondary small" data-add-line>+ إضافة صنف</button></div>
    @if($cost)<p>إجمالي الفاتورة: <strong data-line-total>أكمل الكميات والتكلفة</strong></p>@endif
</div>
