<div class="document-line" data-line-row>
    <label>الصنف<select name="lines[{{ $index }}][item_id]" required><option value="">اختر الصنف</option>@foreach($items as $item)<option value="{{ $item->id }}" {{ (int)($values['item_id']??0)===(int)$item->id ? 'selected':'' }}>{{ $item->name }} — {{ $item->unit==='kg' ? 'كجم':'قطعة' }}</option>@endforeach</select></label>
    <label>الكمية<input name="lines[{{ $index }}][quantity]" type="number" min="0.001" step="0.001" value="{{ $values['quantity']??'' }}" required></label>
    @if($cost)<label>تكلفة الوحدة بالجنيه<input name="lines[{{ $index }}][unit_cost]" type="number" min="0.01" step="0.01" value="{{ $values['unit_cost']??'' }}" required></label>@endif
    <button type="button" class="secondary small" data-remove-line>حذف السطر</button>
</div>
