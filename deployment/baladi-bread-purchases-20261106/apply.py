#!/usr/bin/env python3
"""Link baladi bread expenses to existing piece inventory without replacing neighboring changes."""
import argparse
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
import time


PATCHES = {'app/Services/Dashboard/BranchInventory.php': [('        '
                                                 "abort_unless(DB::transactionLevel()>0&&preg_match('/^f:[1-9][0-9]*$/D',$branch),503,'توريد "
                                                 "المصروف يحتاج معاملة الفرع.');\n"
                                                 '        '
                                                 "$this->ready();abort_unless($units>0&&$units<=1000000000000,422,'كمية "
                                                 "مشتريات البضاعة غير صالحة.');\n"
                                                 '        '
                                                 "$i=$this->ingredientList()[$ingredientId]??null;abort_unless($i&&$i['unit']==='kg',409,'صنف "
                                                 "مشتريات البضاعة غير صالح.');\n"
                                                 '        '
                                                 "$r=$this->lockedStock($branch,$ingredientId);$type=$reverse?'expense_reverse':'expense';$delta=$reverse?-$units:$units;\n"
                                                 '        '
                                                 "$old=DB::table('branch_inventory_movements')->where('stock_id',$r->id)->where('source_type',$type)->where('source_id',(string)$expenseId)->first();\n",
                                                 '        '
                                                 "abort_unless(DB::transactionLevel()>0&&preg_match('/^f:[1-9][0-9]*$/D',$branch),503,'توريد "
                                                 "المصروف يحتاج معاملة الفرع.');\n"
                                                 '        '
                                                 "$this->ready();abort_unless($units>0&&$units<=1000000000000,422,'كمية "
                                                 "مشتريات البضاعة غير صالحة.');\n"
                                                 '        '
                                                 "$i=$this->ingredientList()[$ingredientId]??null;abort_unless($i&&in_array($i['unit'],['kg','piece'],true),409,'صنف "
                                                 "مشتريات البضاعة غير صالح.');\n"
                                                 "        abort_if($i['unit']==='piece'&&$units%1000000!==0,422,'توريد "
                                                 "القطع يجب أن يكون عددًا صحيحًا.');\n"
                                                 '        '
                                                 "$r=$this->lockedStock($branch,$ingredientId);$type=$reverse?'expense_reverse':'expense';$delta=$reverse?-$units:$units;\n"
                                                 '        '
                                                 "$old=DB::table('branch_inventory_movements')->where('stock_id',$r->id)->where('source_type',$type)->where('source_id',(string)$expenseId)->first();\n")],
 'app/Services/Dashboard/ExpenseStockPurchases.php': [('    ];\n\n    private ?bool $available = null;\n',
                                                       "        'purchase_baladi_bread' => 'خبز بلدي',\n"
                                                       '    ];\n'
                                                       '\n'
                                                       '    private ?bool $available = null;\n'),
                                                      ('        ] as $key => $names) {\n',
                                                       "            'purchase_baladi_bread'=>['خبز بلدي', 'الخبز "
                                                       "البلدي', 'عيش بلدي', 'العيش البلدي', 'خبز', 'عيش', 'رغيف عيش', "
                                                       "'رغيف خبز', 'baladi bread'],\n"
                                                       '        ] as $key => $names) {\n'),
                                                      ('    /** The trusted category decides the stock link; posted '
                                                       'stock identifiers never do. */\n',
                                                       '    public function unit(?string $categoryKey): string\n'
                                                       '    {\n'
                                                       "        return $categoryKey === 'purchase_baladi_bread' ? "
                                                       "'piece' : 'kg';\n"
                                                       '    }\n'
                                                       '\n'
                                                       '    /** The trusted category decides the stock link; posted '
                                                       'stock identifiers never do. */\n'),
                                                      ('عدد الكيلوات متاح لبنود مشتريات الفلفل والطماطم والليمون '
                                                       'والبصل والزيت والخل والطحينة فقط.',
                                                       'الكمية متاحة لبنود مشتريات البضاعة المرتبطة بالمخزون فقط.'),
                                                      ("            $values['quantity_kg'] = "
                                                       '$this->quantity($this->units($value));\n',
                                                       "            $values['quantity_kg'] = "
                                                       '$this->quantity($this->units($value, '
                                                       '$this->unit($categoryKey)));\n'),
                                                      ('    }\n'
                                                       '\n'
                                                       '    private function units($value): int\n'
                                                       '    {\n'
                                                       '        if (!is_string($value) && !is_int($value)) {\n'
                                                       '            throw '
                                                       "ValidationException::withMessages(['quantity_kg'=>'أدخل عدد "
                                                       "الكيلوات لهذا الصنف قبل تسجيل المصروف.']);\n"
                                                       '        }\n'
                                                       '        $value = strtr(trim((string)$value), '
                                                       "['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٫'=>'.']);\n"
                                                       "        if (!preg_match('/^[0-9]{1,7}(?:\\.[0-9]{1,3})?$/D', "
                                                       '$value)) {\n'
                                                       '            throw '
                                                       "ValidationException::withMessages(['quantity_kg'=>'أدخل كمية "
                                                       "موجبة بالكيلو حتى ثلاث منازل عشرية.']);\n"
                                                       '        }\n'
                                                       "        $parts = explode('.', $value);\n"
                                                       '        $units = (int)$parts[0] * 1000000 + '
                                                       "(int)str_pad($parts[1] ?? '', 6, '0');\n"
                                                       '        if ($units <= 0 || $units > 1000000000000) {\n'
                                                       '            throw '
                                                       "ValidationException::withMessages(['quantity_kg'=>'عدد "
                                                       "الكيلوات يجب أن يكون أكبر من صفر وحتى مليون كيلو.']);\n"
                                                       '        }\n'
                                                       '        return $units;\n',
                                                       '    }\n'
                                                       '\n'
                                                       "    private function units($value, string $unit = 'kg'): int\n"
                                                       '    {\n'
                                                       '        if (!is_string($value) && !is_int($value)) {\n'
                                                       '            throw '
                                                       "ValidationException::withMessages(['quantity_kg'=>$unit === "
                                                       "'piece' ? 'أدخل عدد أرغفة الخبز البلدي قبل تسجيل المصروف.' : "
                                                       "'أدخل عدد الكيلوات لهذا الصنف قبل تسجيل المصروف.']);\n"
                                                       '        }\n'
                                                       '        $value = strtr(trim((string)$value), '
                                                       "['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٫'=>'.']);\n"
                                                       "        if (!preg_match('/^[0-9]{1,7}(?:\\.[0-9]{1,3})?$/D', "
                                                       '$value)) {\n'
                                                       '            throw '
                                                       "ValidationException::withMessages(['quantity_kg'=>$unit === "
                                                       "'piece' ? 'أدخل عددًا صحيحًا موجبًا من أرغفة الخبز البلدي.' : "
                                                       "'أدخل كمية موجبة بالكيلو حتى ثلاث منازل عشرية.']);\n"
                                                       '        }\n'
                                                       "        $parts = explode('.', $value);\n"
                                                       '        $units = (int)$parts[0] * 1000000 + '
                                                       "(int)str_pad($parts[1] ?? '', 6, '0');\n"
                                                       '        if ($units <= 0 || $units > 1000000000000) {\n'
                                                       '            throw '
                                                       "ValidationException::withMessages(['quantity_kg'=>$unit === "
                                                       "'piece' ? 'عدد الأرغفة يجب أن يكون أكبر من صفر وحتى مليون "
                                                       "رغيف.' : 'عدد الكيلوات يجب أن يكون أكبر من صفر وحتى مليون "
                                                       "كيلو.']);\n"
                                                       '        }\n'
                                                       "        if ($unit === 'piece' && $units % 1000000 !== 0) {\n"
                                                       '            throw '
                                                       "ValidationException::withMessages(['quantity_kg'=>'عدد أرغفة "
                                                       "الخبز البلدي يجب أن يكون عددًا صحيحًا.']);\n"
                                                       '        }\n'
                                                       '        return $units;\n'),
                                                      ("        $units = $this->units($values['quantity_kg']);\n",
                                                       '        $unit = $this->unit($key);\n'
                                                       "        $units = $this->units($values['quantity_kg'], "
                                                       '$unit);\n'),
                                                      ('        abort_unless($ingredients->count() === 1 && '
                                                       "$ingredients->first()->unit === 'kg', 503, 'صنف البضاعة غير "
                                                       "مضبوط بالكيلو؛ راجع تحديث المشتريات.');\n",
                                                       '        abort_unless($ingredients->count() === 1 && '
                                                       "$ingredients->first()->unit === $unit, 503, 'وحدة صنف البضاعة "
                                                       "لا تطابق بند المشتريات؛ راجع إعداد المخزون.');\n"),
                                                      ("            'stock_unit'=>$receipt ? 'kg' : null,\n",
                                                       "            'stock_unit'=>$receipt ? "
                                                       '$this->unit($receipt->category_key) : null,\n')],
 'app/Services/Dashboard/ExpenseCategories.php': [('        '
                                                   "$items=$this->catalog($branch);$active=array_filter($items,fn($item)=>$item['active']);\n"
                                                   '        return '
                                                   "['categories'=>array_map(fn($i)=>$i['name'],$items),'active_categories'=>array_map(fn($i)=>$i['name'],$active),\n"
                                                   '            '
                                                   "'stock_categories'=>$this->stockCategories($items),'category_items'=>$manage?array_values($active):[],'category_scope'=>$branch];\n"
                                                   '    }\n'
                                                   '    public function stockCategories(?array $items=null): array\n',
                                                   '        '
                                                   "$items=$this->catalog($branch);$active=array_filter($items,fn($item)=>$item['active']);\n"
                                                   '        return '
                                                   "['categories'=>array_map(fn($i)=>$i['name'],$items),'active_categories'=>array_map(fn($i)=>$i['name'],$active),\n"
                                                   '            '
                                                   "'stock_categories'=>$this->stockCategories($items),'stock_units'=>array_map(fn($key)=>app(ExpenseStockPurchases::class)->unit($key),$this->stockCategories($items)),\n"
                                                   '            '
                                                   "'category_items'=>$manage?array_values($active):[],'category_scope'=>$branch];\n"
                                                   '    }\n'
                                                   '    public function stockCategories(?array $items=null): array\n')],
 'app/Services/Dashboard/BranchExpenses.php': [("        if(!isset($scopedCatalog[$v['category']]))throw "
                                                "ValidationException::withMessages(['category'=>__('expenses.category_invalid')]);\n"
                                                '        '
                                                "$oldCommand=DB::table('branch_expense_commands')->where('branch',$v['branch'])->where('actor_id',$actor->id)->where('request_key',$v['idempotency_key'])->first(['snapshot']);\n"
                                                '        '
                                                "$legacyReplay=$oldCommand&&!array_key_exists('stock_category',json_decode($oldCommand->snapshot,true)??[]);\n"
                                                "        if($legacyReplay)unset($v['quantity_kg']);\n"
                                                '        else{\n',
                                                "        if(!isset($scopedCatalog[$v['category']]))throw "
                                                "ValidationException::withMessages(['category'=>__('expenses.category_invalid')]);\n"
                                                '        '
                                                "$oldCommand=DB::table('branch_expense_commands')->where('branch',$v['branch'])->where('actor_id',$actor->id)->where('request_key',$v['idempotency_key'])->first(['snapshot']);\n"
                                                '        '
                                                "$legacyReplay=$oldCommand&&empty((json_decode($oldCommand->snapshot,true)??[])['stock_category']);\n"
                                                "        if($legacyReplay)unset($v['quantity_kg']);\n"
                                                '        else{\n'),
                                               ('            // A single-material purchase must use its linked choice. '
                                                'Mixed\n'
                                                '            // invoices cannot tell us how much of each material was '
                                                'received.\n'
                                                '            '
                                                "if(str_starts_with($v['branch'],'f:')&&!$stockCategory&&in_array($v['category'],['purchases','other'],true)&&($material=$stock->categoryProduct($v['description'])))throw "
                                                "ValidationException::withMessages(['category'=>'اختر بند مشتريات "
                                                "'.ExpenseStockPurchases::CATEGORIES[$material].' وأدخل الكمية بالكيلو "
                                                "لإضافتها إلى البضاعة.']);\n"
                                                '            $v=$stock->normalizeQuantity($v,$stockCategory);\n'
                                                "            if($stockCategory)$v['stock_category']=$stockCategory;\n",
                                                '            // A single-material purchase must use its linked choice. '
                                                'Mixed\n'
                                                '            // invoices cannot tell us how much of each material was '
                                                'received.\n'
                                                '            '
                                                "if(str_starts_with($v['branch'],'f:')&&!$stockCategory&&in_array($v['category'],['purchases','other'],true)&&($material=$stock->categoryProduct($v['description'])))throw "
                                                "ValidationException::withMessages(['category'=>'اختر بند مشتريات "
                                                "'.ExpenseStockPurchases::CATEGORIES[$material].' وأدخل الكمية "
                                                "'.($stock->unit($material)==='piece'?'بالعدد':'بالكيلو').' لإضافتها "
                                                "إلى البضاعة.']);\n"
                                                '            $v=$stock->normalizeQuantity($v,$stockCategory);\n'
                                                '            '
                                                "if($stockCategory)$v['stock_category']=$stockCategory;\n")],
 'resources/lang/ar/expenses.php': [("    'basic_categories_source_ambiguous'=>'يوجد أكثر من فرع باسم المحلة. راجع "
                                     "أسماء الفروع قبل استخدام البنود الأساسية.',\n"
                                     "    'quantity_kg'=>'الكمية بالكيلو',\n"
                                     "    'kg'=>'كجم',\n"
                                     "    'stock_hint'=>'تُضاف الكمية لرصيد بضاعة الفرع عند حفظ المصروف.',\n",
                                     "    'basic_categories_source_ambiguous'=>'يوجد أكثر من فرع باسم المحلة. راجع "
                                     "أسماء الفروع قبل استخدام البنود الأساسية.',\n"
                                     "    'quantity_kg'=>'الكمية بالكيلو',\n"
                                     "    'quantity_piece'=>'عدد أرغفة الخبز البلدي',\n"
                                     "    'quantity_piece_invalid'=>'أدخل عددًا صحيحًا أكبر من صفر وبحد أقصى مليون "
                                     "رغيف.',\n"
                                     "    'loaf'=>'رغيف',\n"
                                     "    'kg'=>'كجم',\n"
                                     "    'stock_hint'=>'تُضاف الكمية لرصيد بضاعة الفرع عند حفظ المصروف.',\n")],
 'resources/lang/en/expenses.php': [("    'basic_categories_source_ambiguous'=>'More than one Mahalla branch was "
                                     "found. Check branch names before using basic categories.',\n"
                                     "    'quantity_kg'=>'Quantity (kg)',\n"
                                     "    'kg'=>'kg',\n"
                                     "    'stock_hint'=>'Saving this expense adds the quantity to this branch’s "
                                     "inventory.',\n",
                                     "    'basic_categories_source_ambiguous'=>'More than one Mahalla branch was "
                                     "found. Check branch names before using basic categories.',\n"
                                     "    'quantity_kg'=>'Quantity (kg)',\n"
                                     "    'quantity_piece'=>'Number of baladi bread loaves',\n"
                                     "    'quantity_piece_invalid'=>'Enter a positive whole number, up to one million "
                                     "loaves.',\n"
                                     "    'loaf'=>'loaves',\n"
                                     "    'kg'=>'kg',\n"
                                     "    'stock_hint'=>'Saving this expense adds the quantity to this branch’s "
                                     "inventory.',\n")],
 'resources/views/admin/expenses/index.blade.php': [('<button type="button" class="ex-button ex-category-open" '
                                                     'data-expense-categories-open><i class="fas fa-list" '
                                                     'aria-hidden="true"></i> {{ __(\'expenses.manage_categories\') '
                                                     '}}</button>\n'
                                                     '@endif\n'
                                                     '<label data-expense-quantity hidden>{{ '
                                                     "__('expenses.quantity_kg') }} <b>*</b><input "
                                                     'name="quantity_kg" inputmode="decimal" placeholder="0.000" '
                                                     'maxlength="12" autocomplete="off" disabled '
                                                     'aria-describedby="expense-stock-hint"><small '
                                                     'id="expense-stock-hint">{{ __(\'expenses.stock_hint\') '
                                                     '}}</small></label>\n'
                                                     "<label>{{ __('expenses.description') }}<textarea "
                                                     'name="description" maxlength="500" rows="2"></textarea></label>\n'
                                                     '<div class="ex-pair"><label>{{ __(\'expenses.amount\') }} '
                                                     '<b>*</b><input name="amount" inputmode="decimal" '
                                                     'placeholder="0.00" maxlength="14" required></label><label>{{ '
                                                     '__(\'expenses.date\') }} <b>*</b><input name="occurred_on" '
                                                     'type="date" value="{{ $boot[\'today\'] }}" max="{{ '
                                                     '$boot[\'today\'] }}" required></label></div>\n',
                                                     '<button type="button" class="ex-button ex-category-open" '
                                                     'data-expense-categories-open><i class="fas fa-list" '
                                                     'aria-hidden="true"></i> {{ __(\'expenses.manage_categories\') '
                                                     '}}</button>\n'
                                                     '@endif\n'
                                                     '<label data-expense-quantity hidden><span '
                                                     "data-expense-quantity-label>{{ __('expenses.quantity_kg') "
                                                     '}}</span> <b>*</b><input name="quantity_kg" inputmode="decimal" '
                                                     'placeholder="0.000" maxlength="12" autocomplete="off" disabled '
                                                     'aria-describedby="expense-stock-hint"><small '
                                                     'id="expense-stock-hint">{{ __(\'expenses.stock_hint\') '
                                                     '}}</small></label>\n'
                                                     "<label>{{ __('expenses.description') }}<textarea "
                                                     'name="description" maxlength="500" rows="2"></textarea></label>\n'
                                                     '<div class="ex-pair"><label>{{ __(\'expenses.amount\') }} '
                                                     '<b>*</b><input name="amount" inputmode="decimal" '
                                                     'placeholder="0.00" maxlength="14" required></label><label>{{ '
                                                     '__(\'expenses.date\') }} <b>*</b><input name="occurred_on" '
                                                     'type="date" value="{{ $boot[\'today\'] }}" max="{{ '
                                                     '$boot[\'today\'] }}" required></label></div>\n')],
 'public/dashboard/js/branch-expenses.js': [('    }\n'
                                             '    function stockCategory() { return '
                                             '(categoryCatalog(form.elements.branch.value).stock_categories || '
                                             "{})[form.elements.category.value] || ''; }\n"
                                             '    function quantityField() {\n'
                                             '        var field = form.elements.quantity_kg; if (!field) return;\n'
                                             '        var linked = !!stockCategory();\n'
                                             "        root.querySelector('[data-expense-quantity]').hidden = !linked;\n"
                                             '        field.required = linked; field.disabled = !linked || locked();\n'
                                             "        if (!linked) field.value = '';\n",
                                             '    }\n'
                                             '    function stockCategory() { return '
                                             '(categoryCatalog(form.elements.branch.value).stock_categories || '
                                             "{})[form.elements.category.value] || ''; }\n"
                                             '    function stockUnit() { return '
                                             '(categoryCatalog(form.elements.branch.value).stock_units || '
                                             "{})[form.elements.category.value] || 'kg'; }\n"
                                             '    function quantityField() {\n'
                                             '        var field = form.elements.quantity_kg; if (!field) return;\n'
                                             '        var linked = !!stockCategory();\n'
                                             "        root.querySelector('[data-expense-quantity]').hidden = !linked;\n"
                                             "        var piece = stockUnit() === 'piece', label = "
                                             "root.querySelector('[data-expense-quantity-label]');\n"
                                             "        if (label) label.textContent = t(piece ? 'quantity_piece' : "
                                             "'quantity_kg');\n"
                                             "        field.inputMode = piece ? 'numeric' : 'decimal'; "
                                             "field.placeholder = piece ? '0' : '0.000';\n"
                                             '        field.required = linked; field.disabled = !linked || locked();\n'
                                             "        if (!linked) field.value = '';\n"),
                                            ('    }\n'
                                             '    function specificCategoryBranch(value){return '
                                             '/^(?:f|gs):[1-9][0-9]*$/.test(value)&&boot.branches.some(function(item){return '
                                             'item.value===value;});}\n'
                                             '    function emptyCategoryCatalog(){return '
                                             '{categories:{},active_categories:{},category_items:[],stock_categories:{}};}\n'
                                             '    function copyCategoryCatalog(data){return '
                                             '{categories:data.categories||{},active_categories:data.active_categories||data.categories||{},category_items:data.category_items||[],stock_categories:data.stock_categories||{}};}\n'
                                             '    function categoryCatalog(scope){\n'
                                             "        if(scope==='all')return "
                                             'unionCatalog||legacyCatalog||emptyCategoryCatalog();\n',
                                             '    }\n'
                                             '    function specificCategoryBranch(value){return '
                                             '/^(?:f|gs):[1-9][0-9]*$/.test(value)&&boot.branches.some(function(item){return '
                                             'item.value===value;});}\n'
                                             '    function emptyCategoryCatalog(){return '
                                             '{categories:{},active_categories:{},category_items:[],stock_categories:{},stock_units:{}};}\n'
                                             '    function copyCategoryCatalog(data){return '
                                             '{categories:data.categories||{},active_categories:data.active_categories||data.categories||{},category_items:data.category_items||[],stock_categories:data.stock_categories||{},stock_units:data.stock_units||{}};}\n'
                                             '    function categoryCatalog(scope){\n'
                                             "        if(scope==='all')return "
                                             'unionCatalog||legacyCatalog||emptyCategoryCatalog();\n'),
                                            ('            '
                                             "[item.number,item.occurred_on,item.branch_name,expenseCategoryLabel(item),item.description,item.amount,t('method_' "
                                             '+ item.payment_method),item.actor_name].forEach(function (value,index) '
                                             '{\n'
                                             "                var cell=el('td',value);\n"
                                             '                if(index===4 && '
                                             "item.stock_quantity_kg)cell.appendChild(el('small',item.stock_quantity_kg+' "
                                             "'+t('kg')+' · '+item.stock_item_name,'ex-stock-quantity'));\n"
                                             '                row.appendChild(cell);\n'
                                             '            });\n',
                                             '            '
                                             "[item.number,item.occurred_on,item.branch_name,expenseCategoryLabel(item),item.description,item.amount,t('method_' "
                                             '+ item.payment_method),item.actor_name].forEach(function (value,index) '
                                             '{\n'
                                             "                var cell=el('td',value);\n"
                                             '                if(index===4 && '
                                             "item.stock_quantity_kg)cell.appendChild(el('small',item.stock_quantity_kg+' "
                                             "'+t(item.stock_unit==='piece'?'loaf':'kg')+' · "
                                             "'+item.stock_item_name,'ex-stock-quantity'));\n"
                                             '                row.appendChild(cell);\n'
                                             '            });\n'),
                                            ('        if(stockCategory()) {\n'
                                             '            v.quantity_kg = '
                                             'v.quantity_kg.trim().replace(/[٠-٩]/g,function(d){return '
                                             "String('٠١٢٣٤٥٦٧٨٩'.indexOf(d));}).replace(/٫/g,'.');\n"
                                             '            if(!/^[0-9]{1,7}(?:\\.[0-9]{1,3})?$/.test(v.quantity_kg) || '
                                             'Number(v.quantity_kg)<=0 || '
                                             "Number(v.quantity_kg)>1000000){notice(t('quantity_invalid'));form.elements.quantity_kg.focus();return;}\n"
                                             '        } else delete v.quantity_kg;\n'
                                             "        v.approve = approve ? '1' : '0';\n",
                                             '        if(stockCategory()) {\n'
                                             '            v.quantity_kg = '
                                             'v.quantity_kg.trim().replace(/[٠-٩]/g,function(d){return '
                                             "String('٠١٢٣٤٥٦٧٨٩'.indexOf(d));}).replace(/٫/g,'.');\n"
                                             "            var piece = stockUnit() === 'piece';\n"
                                             '            if(!/^[0-9]{1,7}(?:\\.[0-9]{1,3})?$/.test(v.quantity_kg) || '
                                             'Number(v.quantity_kg)<=0 || Number(v.quantity_kg)>1000000 || (piece && '
                                             "!Number.isInteger(Number(v.quantity_kg)))){notice(t(piece?'quantity_piece_invalid':'quantity_invalid'));form.elements.quantity_kg.focus();return;}\n"
                                             '        } else delete v.quantity_kg;\n'
                                             "        v.approve = approve ? '1' : '0';\n"),
                                            ('            '
                                             "[['number',item.number],['branch',item.branch_name],['date',item.occurred_on],['category',expenseCategoryLabel(item)],['description',item.description],['amount',item.amount+' "
                                             "'+t('currency')],['payment_method',t('method_'+item.payment_method)],['payment_reference',item.payment_reference],['status',t('status_'+item.status)],['actor',item.actor_name],['supplier',item.supplier],['cost_center',item.cost_center],['notes',item.notes],['reviewer',item.reviewer_name],['review_reason',item.review_reason]].forEach(function "
                                             "(pair) { var cell = el('div'); cell.appendChild(el('dt',t(pair[0]))); "
                                             "cell.appendChild(el('dd',pair[1]||'—')); grid.appendChild(cell); }); "
                                             'content.appendChild(grid);\n'
                                             '            if(item.stock_quantity_kg){\n'
                                             "                var stockInfo=el('p',item.stock_item_name+' · "
                                             "'+item.stock_quantity_kg+' '+t('kg')+' · "
                                             "'+t(item.stock_reversed?'stock_reversed':'stock_received'),'ex-stock-detail');content.appendChild(stockInfo);\n"
                                             '            }\n'
                                             '            if (item.attachment_url) { var attachment = '
                                             "el('a',item.attachment_name,'ex-button'); attachment.href = "
                                             "url(item.attachment_url); attachment.dataset.spaOff = ''; "
                                             'content.appendChild(attachment); }\n',
                                             '            '
                                             "[['number',item.number],['branch',item.branch_name],['date',item.occurred_on],['category',expenseCategoryLabel(item)],['description',item.description],['amount',item.amount+' "
                                             "'+t('currency')],['payment_method',t('method_'+item.payment_method)],['payment_reference',item.payment_reference],['status',t('status_'+item.status)],['actor',item.actor_name],['supplier',item.supplier],['cost_center',item.cost_center],['notes',item.notes],['reviewer',item.reviewer_name],['review_reason',item.review_reason]].forEach(function "
                                             "(pair) { var cell = el('div'); cell.appendChild(el('dt',t(pair[0]))); "
                                             "cell.appendChild(el('dd',pair[1]||'—')); grid.appendChild(cell); }); "
                                             'content.appendChild(grid);\n'
                                             '            if(item.stock_quantity_kg){\n'
                                             "                var stockInfo=el('p',item.stock_item_name+' · "
                                             "'+item.stock_quantity_kg+' '+t(item.stock_unit==='piece'?'loaf':'kg')+' "
                                             '· '
                                             "'+t(item.stock_reversed?'stock_reversed':'stock_received'),'ex-stock-detail');content.appendChild(stockInfo);\n"
                                             '            }\n'
                                             '            if (item.attachment_url) { var attachment = '
                                             "el('a',item.attachment_name,'ex-button'); attachment.href = "
                                             "url(item.attachment_url); attachment.dataset.spaOff = ''; "
                                             'content.appendChild(attachment); }\n')]}
ALTERNATE_BEFORES = {'app/Services/Dashboard/ExpenseStockPurchases.php': {3: ['عدد الكيلوات متاح لبنود مشتريات الفلفل والطماطم والليمون '
                                                          'والبصل والزيت والخل فقط.']}}
CATALOG_PHP = '<?php\nuse App\\Models\\User;\nuse App\\Services\\Dashboard\\{ExpenseCategories,ExpenseStockPurchases,TakeawayAccess};\nuse App\\Services\\OwnerDashboards\\Runtime;\nuse Illuminate\\Support\\Facades\\{DB,Schema};\n\n/** Enable one protected bread purchase per primary-business branch, using its existing ingredient. */\nfinal class BaladiBreadCatalog20261106\n{\n    public const KEY=\'purchase_baladi_bread\';\n    public const NAME=\'خبز بلدي\';\n\n    private function plan(bool $lock=false): array\n    {\n        $actor=User::withoutGlobalScopes()->find(1);\n        if(!$actor||!Runtime::primary($actor)||!app(ExpenseCategories::class)->canCreate($actor))throw new RuntimeException(\'PRIMARY_BUSINESS_OWNER_REQUIRED\');\n        foreach([\'resturants\',\'stock_ingredients\',\'branch_expense_categories\',\'branch_expense_category_settings\',\'branch_expense_category_overrides\',\'expense_inventory_receipts\',\'branch_inventory\',\'branch_inventory_movements\',\'branch_recipe_sales\'] as $table)\n            if(!Schema::hasTable($table))throw new RuntimeException(\'BREAD_SCHEMA_REQUIRED: \'.$table);\n        $stock=app(ExpenseStockPurchases::class);\n        if(!$stock->ready()||(ExpenseStockPurchases::CATEGORIES[self::KEY]??null)!==self::NAME||$stock->unit(self::KEY)!==\'piece\')throw new RuntimeException(\'BREAD_PURCHASE_RUNTIME_REQUIRED\');\n        $rows=DB::table(\'stock_ingredients\')->where(\'name\',self::NAME)->get();\n        if($rows->count()!==1||$rows->first()->unit!==\'piece\')throw new RuntimeException(\'EXISTING_BALADI_BREAD_INGREDIENT_MUST_BE_UNIQUE_AND_IN_PIECES\');\n        $ingredient=$rows->first();\n        // Do not introduce a second stock identity for an alternate spelling.\n        foreach(DB::table(\'stock_ingredients\')->get([\'id\',\'name\']) as $row)\n            if((int)$row->id!==(int)$ingredient->id&&$stock->categoryProduct($row->name)===self::KEY)throw new RuntimeException(\'AMBIGUOUS_BREAD_STOCK_IDENTITY\');\n        $access=app(TakeawayAccess::class);$service=app(ExpenseCategories::class);\n        $branches=array_values(array_filter($access->branches($actor),fn($b)=>$b[\'kind\']===\'f\'));\n        if(!$branches)throw new RuntimeException(\'NO_RESTAURANT_BRANCHES\');\n        $updates=[];\n        foreach($branches as $branch){\n            if($lock)$access->branch($branch[\'value\'],$actor,true);\n            $catalog=$service->catalog($branch[\'value\']);\n            if(!isset($catalog[self::KEY])||empty($catalog[self::KEY][\'protected\']))throw new RuntimeException(\'PROTECTED_BREAD_CATEGORY_MISSING: \'.$branch[\'value\']);\n            foreach($catalog as $key=>$item){\n                if($key!==self::KEY&&($item[\'stock_category\']??null)!==self::KEY)continue;\n                $active=$key===self::KEY;$name=$active?self::NAME:$item[\'name\'];\n                if(!$active&&$item[\'active\']&&$stock->categoryProduct($name)!==self::KEY)throw new RuntimeException(\'CONFLICTING_BREAD_CATEGORY_NAME: \'.$branch[\'value\'].\' \'.$key);\n                if((bool)$item[\'active\']===$active&&$item[\'name\']===$name)continue;\n                $q=DB::table(\'branch_expense_category_overrides\')->where(\'branch\',$branch[\'value\'])->where(\'category_key\',$key);\n                if($lock)$q->lockForUpdate();$before=$q->first();\n                $updates[]=[\'branch\'=>$branch[\'value\'],\'category_key\'=>$key,\'name\'=>$name,\'active\'=>$active,\n                    \'revision\'=>(int)$item[\'revision\']+1,\'before\'=>$before?(array)$before:null];\n            }\n        }\n        return [\'branches\'=>array_column($branches,\'value\'),\'ingredient_id\'=>(int)$ingredient->id,\'updates\'=>$updates];\n    }\n\n    public function run(string $mode,?string $backup=null): array\n    {\n        if(!in_array($mode,[\'check\',\'apply\',\'verify\'],true))throw new RuntimeException(\'INVALID_CATALOG_MODE\');\n        $plan=$this->plan();\n        if($mode===\'apply\'){\n            if(!$backup||!is_dir($backup)||is_link($backup)||realpath($backup)!==$backup||(fileperms($backup)&0077))throw new RuntimeException(\'PRIVATE_CATALOG_BACKUP_REQUIRED\');\n            DB::transaction(function()use($backup){\n                DB::table(\'users\')->where(\'id\',1)->lockForUpdate()->first();\n                $plan=$this->plan(true);\n                $record=$backup.\'/bread-catalog-before.json\';\n                if(file_exists($record)||is_link($record))throw new RuntimeException(\'CATALOG_BACKUP_ALREADY_EXISTS\');\n                $bytes=json_encode($plan+[\'recorded_at\'=>gmdate(\'c\')],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\\n";\n                $handle=fopen($record,\'xb\');if(!$handle)throw new RuntimeException(\'CATALOG_BACKUP_FAILED\');\n                try{if(!chmod($record,0600)||fwrite($handle,$bytes)!==strlen($bytes)||!fflush($handle))throw new RuntimeException(\'CATALOG_BACKUP_FAILED\');}finally{fclose($handle);}\n                foreach($plan[\'updates\'] as $update){\n                    $values=[\'name\'=>$update[\'name\'],\'active\'=>$update[\'active\'],\'revision\'=>$update[\'revision\'],\'updated_at\'=>now(\'UTC\')];\n                    $q=DB::table(\'branch_expense_category_overrides\')->where(\'branch\',$update[\'branch\'])->where(\'category_key\',$update[\'category_key\']);\n                    if($update[\'before\']!==null)$q->update($values);\n                    else DB::table(\'branch_expense_category_overrides\')->insert([\'branch\'=>$update[\'branch\'],\'category_key\'=>$update[\'category_key\'],\'created_at\'=>now(\'UTC\')]+$values);\n                }\n                if($this->plan(true)[\'updates\'])throw new RuntimeException(\'BREAD_CATALOG_DID_NOT_VERIFY\');\n            },1);\n            $plan=$this->plan();\n        }\n        if($mode===\'verify\'&&$plan[\'updates\'])throw new RuntimeException(\'BREAD_CATALOG_PENDING_UPDATES\');\n        if($mode!==\'check\')foreach($plan[\'branches\'] as $branch){\n            $choices=app(ExpenseCategories::class)->choices(false,$branch);\n            $matches=array_filter($choices[\'active_categories\'],fn($name,$key)=>($choices[\'stock_categories\'][$key]??null)===self::KEY,ARRAY_FILTER_USE_BOTH);\n            if(array_keys($matches)!==[self::KEY]||($choices[\'stock_units\'][self::KEY]??null)!==\'piece\')throw new RuntimeException(\'BREAD_BRANCH_VERIFY_FAILED: \'.$branch);\n        }\n        return [\'status\'=>$mode===\'check\'?\'BALADI_BREAD_CHECK_OK\':\'BALADI_BREAD_ALL_BRANCHES_VERIFIED\',\n            \'branch_count\'=>count($plan[\'branches\']),\'branches\'=>$plan[\'branches\'],\'ingredient_id\'=>$plan[\'ingredient_id\'],\n            \'ingredient_name\'=>self::NAME,\'stock_unit\'=>\'piece\',\'pending_category_updates\'=>count($plan[\'updates\']),\n            \'stock_balances_changed\'=>false,\'financial_entries_created\'=>0,\'historical_expenses_changed\'=>0];\n    }\n}\n\nif(realpath($_SERVER[\'SCRIPT_FILENAME\']??\'\')===__FILE__){\n    try{\n        umask(0077);$options=getopt(\'\',[\'root:\',\'stage:\',\'mode:\',\'backup:\']);\n        $root=$options[\'root\']??\'\';\n        if(realpath($root)!==$root||!is_file($root.\'/vendor/autoload.php\'))throw new RuntimeException(\'INVALID_LARAVEL_ROOT\');\n        require $root.\'/vendor/autoload.php\';\n        if(isset($options[\'stage\']))foreach([\'BranchInventory\',\'ExpenseStockPurchases\',\'ExpenseCategories\',\'BranchExpenses\'] as $class){\n            if(class_exists(\'App\\\\Services\\\\Dashboard\\\\\'.$class,false))throw new RuntimeException(\'CANDIDATE_ALREADY_LOADED\');\n            require $options[\'stage\'].\'/app/Services/Dashboard/\'.$class.\'.php\';\n        }\n        $application=require $root.\'/bootstrap/app.php\';$application->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();\n        echo json_encode((new BaladiBreadCatalog20261106)->run($options[\'mode\']??\'check\',$options[\'backup\']??null),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\\n";\n    }catch(Throwable $error){fwrite(STDERR,\'BALADI_BREAD_CATALOG_STOPPED: \'.$error->getMessage()."\\n");exit(1);}\n}\n'
MAX_BYTES = 4 * 1024 * 1024


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def safe_path(path):
    require(path.is_absolute(), 'ABSOLUTE_PATH_REQUIRED')
    for entry in (path, *path.parents):
        require(not entry.is_symlink(), 'SYMLINK_REJECTED: ' + str(entry))


def read_file(path):
    safe_path(path)
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(descriptor, 'rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1,
                'REGULAR_FILE_REQUIRED: ' + str(path))
        require(info.st_uid == os.geteuid(), 'OWNER_MISMATCH: ' + str(path))
        data = stream.read(MAX_BYTES + 1)
    require(len(data) <= MAX_BYTES and b'\x00' not in data, 'INVALID_SOURCE: ' + str(path))
    return data, info


def candidate(relative, data):
    source_sha256 = hashlib.sha256(data).hexdigest()
    newline = b'\r\n' if b'\r\n' in data else b'\n'
    def encoded(value):
        return value.encode('utf-8').replace(b'\n', newline)
    # A pre-existing bread implementation is accepted only when every reviewed
    # site is already installed. Never insert another category or unit method.
    if relative.endswith('/ExpenseStockPurchases.php') and b"'purchase_baladi_bread'" in data:
        require(all(data.count(encoded(after)) == 1 for _, after in PATCHES[relative]),
                'UNRECOGNIZED_EXISTING_BREAD_IMPLEMENTATION: ' + relative)
    for index, (before, after) in enumerate(PATCHES[relative]):
        new = encoded(after)
        alternatives = [encoded(before)] + [encoded(value) for value in ALTERNATE_BEFORES.get(relative, {}).get(index, [])]
        if data.count(new) == 1 and all(data.replace(new, b'', 1).count(old) == 0 for old in alternatives):
            continue
        matches = [old for old in alternatives if data.count(old) == 1]
        require(len(matches) == 1 and data.count(new) == 0,
                'PATCH_CONFLICT: ' + relative + ' edit=' + str(index + 1)
                + ' source_sha256=' + source_sha256
                + ' old_hits=' + str([data.count(old) for old in alternatives])
                + ' new_hits=' + str(data.count(new)))
        data = data.replace(matches[0], new, 1)
    return data


def signature(info):
    return (info.st_dev, info.st_ino, info.st_uid, info.st_gid,
            stat.S_IMODE(info.st_mode), info.st_mtime_ns)


def unchanged(path, original):
    data, info = read_file(path)
    return data == original[0] and signature(info) == signature(original[1])


def atomic_write(path, data, info):
    safe_path(path)
    descriptor, temporary = tempfile.mkstemp(prefix='.baladi-bread-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            os.fchown(stream.fileno(), info.st_uid, info.st_gid)
            os.fchmod(stream.fileno(), stat.S_IMODE(info.st_mode))
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
            # Blade versions these assets with filemtime(), rounded to seconds.
            modified = max(time.time_ns(), (info.st_mtime_ns // 1_000_000_000 + 1) * 1_000_000_000)
            os.utime(stream.fileno(), ns=(modified, modified))
            os.fsync(stream.fileno())
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def lint_candidates(directory, php):
    node = shutil.which('node')
    for relative in PATCHES:
        command = None
        if relative.endswith('.php') and php:
            command = [php, '-l', str(directory / relative)]
        elif relative.endswith('.js') and node:
            command = [node, '--check', str(directory / relative)]
        if command:
            result = subprocess.run(command, capture_output=True, timeout=30)
            require(result.returncode == 0, 'SYNTAX_INVALID: ' + relative)
    print('SYNTAX_CHECKS: php=' + ('passed' if php else 'unavailable')
          + ' node=' + ('passed' if node else 'unavailable'))


def private_directory(path):
    safe_path(path)
    path.mkdir(mode=0o700, exist_ok=True)
    info = path.stat()
    require(path.is_dir() and info.st_uid == os.geteuid()
            and stat.S_IMODE(info.st_mode) == 0o700,
            'PRIVATE_DIRECTORY_REQUIRED: ' + str(path))


def backup(root, originals, revision):
    parent = root.parent / 'baladi-bread-purchases-backups'
    private_directory(parent)
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
    directory = Path(tempfile.mkdtemp(prefix=stamp, dir=parent))
    manifest = {'root': str(root), 'revision': revision, 'files': {}}
    for relative, (data, info) in originals.items():
        target = directory / relative
        target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        target.write_bytes(data)
        target.chmod(0o600)
        manifest['files'][relative] = {
            'sha256': hashlib.sha256(data).hexdigest(), 'uid': info.st_uid,
            'gid': info.st_gid, 'mode': stat.S_IMODE(info.st_mode),
        }
    (directory / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    (directory / 'manifest.json').chmod(0o600)
    return directory


def runtime_catalog(root, php, stage=None, mode='check', backup=None):
    with tempfile.TemporaryDirectory(prefix='bread-catalog-') as temporary:
        helper = Path(temporary) / 'catalog.php'
        helper.write_text(CATALOG_PHP, encoding='utf-8')
        command = [php, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'opcache.enable_cli=0', str(helper), '--root=' + str(root), '--mode=' + mode]
        if stage is not None:
            command.append('--stage=' + str(stage))
        if backup is not None:
            command.append('--backup=' + str(backup))
        result = subprocess.run(command, capture_output=True, text=True, timeout=90)
        require(result.returncode == 0, 'CATALOG_' + mode.upper() + '_FAILED: ' + result.stderr[-2000:])
        receipt = json.loads(result.stdout)
        require(receipt.get('status') in ('BALADI_BREAD_CHECK_OK', 'BALADI_BREAD_ALL_BRANCHES_VERIFIED'), 'CATALOG_RECEIPT_INVALID')
        print(json.dumps(receipt, ensure_ascii=False), flush=True)


def install(root, php=None, check=False, revision='local'):
    require(php is not None, 'PHP_EXECUTABLE_REQUIRED')
    safe_path(root)
    require(root.is_dir(), 'APPLICATION_ROOT_REQUIRED')
    require(root.stat().st_uid == os.geteuid(), 'APPLICATION_OWNER_MISMATCH')
    lock_path = root.parent / '.baladi-bread-purchases.lock'
    safe_path(lock_path)
    descriptor = os.open(lock_path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(descriptor, 'a+b') as lock:
        info = os.fstat(lock.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1
                and info.st_uid == os.geteuid(), 'INVALID_INSTALLER_LOCK')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        originals = {relative: read_file(root / relative) for relative in PATCHES}
        candidates = {relative: candidate(relative, original[0])
                      for relative, original in originals.items()}
        with tempfile.TemporaryDirectory(prefix='baladi-bread-check-') as temporary:
            directory = Path(temporary)
            for relative, data in candidates.items():
                target = directory / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(data)
            lint_candidates(directory, php)
            runtime_catalog(root, php, stage=directory, mode='check')
        changed = [relative for relative in PATCHES if originals[relative][0] != candidates[relative]]
        for relative, original in originals.items():
            require(unchanged(root / relative, original), 'SOURCE_CHANGED: ' + relative)
        if check:
            print('BALADI_BREAD_CHECK_OK: ' + ', '.join(changed))
            return
        directory = backup(root, {relative: originals[relative] for relative in changed}, revision)
        print('BACKUP: ' + str(directory), flush=True)
        written = []
        old_handler = signal.getsignal(signal.SIGTERM)

        def terminate(signum, frame):
            raise RuntimeError('INSTALL_INTERRUPTED')

        signal.signal(signal.SIGTERM, terminate)
        try:
            for relative in changed:
                require(unchanged(root / relative, originals[relative]), 'SOURCE_CHANGED: ' + relative)
                # Record before replacement so failures after os.replace also roll back.
                written.append(relative)
                atomic_write(root / relative, candidates[relative], originals[relative][1])
            for relative in PATCHES:
                require(read_file(root / relative)[0] == candidates[relative],
                        'POST_INSTALL_MISMATCH: ' + relative)
        except BaseException:
            rollback_errors = []
            for relative in reversed(written):
                try:
                    current = read_file(root / relative)[0]
                    require(current in (candidates[relative], originals[relative][0]),
                            'CONCURRENT_CHANGE_PREVENTS_ROLLBACK: ' + relative)
                    if current != originals[relative][0]:
                        atomic_write(root / relative, originals[relative][0], originals[relative][1])
                except Exception as error:
                    rollback_errors.append(str(error))
            if rollback_errors:
                raise RuntimeError('ROLLBACK_INCOMPLETE; backup=' + str(directory)
                                   + '; ' + '; '.join(rollback_errors))
            print('BALADI_BREAD_ROLLED_BACK', flush=True)
            raise
        finally:
            signal.signal(signal.SIGTERM, old_handler)
        # Once code is fully installed, a database/setup error is resumable by rerunning.
        # Never roll back unit-aware code after a potentially committed catalog operation.
        runtime_catalog(root, php, mode='apply', backup=directory)
        runtime_catalog(root, php, mode='verify')
        print('BALADI_BREAD_APPLIED_AND_VERIFIED: ' + ', '.join(changed))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', required=True, type=Path)
    parser.add_argument('--revision', default='local')
    parser.add_argument('--php')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    require(args.revision == 'local' or re.fullmatch(r'[0-9a-f]{40}', args.revision),
            'FULL_REVISION_REQUIRED')
    if args.php:
        require(Path(args.php).is_file() and os.access(args.php, os.X_OK), 'PHP_EXECUTABLE_REQUIRED')
    install(args.root, args.php, args.check, args.revision)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('BALADI_BREAD_FAILED: ' + str(error), file=sys.stderr)
        sys.exit(1)
