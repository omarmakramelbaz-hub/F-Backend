"""One-shot, checksum-guarded source edit for the isolated work-area branch."""
from pathlib import Path
import hashlib
import re

ROOT = Path(__file__).resolve().parents[1]
EXPECTED = {
    'resources/views/admin/users/form.blade.php': '02b01427400738f53a2053c08683f02c57780c30',
    'app/Http/Requests/Dashboard/User/StoreUserRequest.php': '1bc3a358cb7a9ab63fdf812758d8b2e687fa18c0',
    'app/Repositories/UserRepository.php': '5fdd643f6ab2235c9830831b885ccaf0af8918b9',
    'app/Services/GoServices/Marketplace.php': 'af7c52c84f138fd82a239648381c6d9207e67c2f',
}
texts = {}
for path, expected in EXPECTED.items():
    data = (ROOT / path).read_bytes()
    sha = hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()
    if sha != expected:
        raise SystemExit(f'Refusing to edit changed source: {path} ({sha})')
    texts[path] = data.decode('utf-8')

def replace(text, old, new, count=1):
    assert text.count(old) == count, (old[:100], text.count(old), count)
    return text.replace(old, new)

path = 'resources/views/admin/users/form.blade.php'
s = texts[path]
pattern = r'<div class="form-group col-sm-6">\s*<label for="location">.*?id="location">\s*</div>'
s, count = re.subn(pattern, "@include('admin.users.partials.work_area')", s, flags=re.S)
assert count == 1
s = replace(s, '<div class="form-group col-sm-6">\n    <button type="submit"', "@if(\\Route::currentRouteName() != 'users.create' && $user->account_type == 'delegate')\n    <div class=\"row\">\n        @include('admin.users.partials.work_area')\n    </div>\n@endif\n\n<div class=\"form-group col-sm-6\">\n    <button type=\"submit\"")
texts[path] = s

path = 'app/Http/Requests/Dashboard/User/StoreUserRequest.php'
s = texts[path]
s = replace(s, 'use Illuminate\\Validation\\Rule;', 'use Illuminate\\Validation\\Rule;\nuse App\\Support\\PartnerWorkArea;')
methods = '''    protected function prepareForValidation()
    {
        foreach (PartnerWorkArea::FIELDS as $field) {
            if ($this->has($field)) {
                $value = PartnerWorkArea::normalize($this->input($field));
                if ($field === 'work_radius_km' && is_string($value) && preg_match('/^[0-9]+$/D', $value)) {
                    $value = ltrim($value, '0') ?: '0';
                }
                $this->merge([$field => $value]);
            }
        }
    }

    private function needsWorkArea(): bool
    {
        $user = $this->route('user');
        return ($user instanceof \\App\\Models\\User ? $user->account_type : $this->input('account_type')) === 'delegate';
    }

    public function messages()
    {
        return [
            'work_lat.required' => 'حدد موقع الشريك بدبوس على الخريطة.',
            'work_lng.required' => 'حدد موقع الشريك بدبوس على الخريطة.',
            'work_lat.numeric' => 'موقع الدبوس غير صالح.',
            'work_lat.between' => 'موقع الدبوس غير صالح.',
            'work_lng.numeric' => 'موقع الدبوس غير صالح.',
            'work_lng.between' => 'موقع الدبوس غير صالح.',
            'work_radius_km.required' => 'أدخل حدود منطقة العمل بالكيلومتر.',
            'work_radius_km.integer' => 'أدخل عددًا صحيحًا من 1 إلى 255 كم.',
            'work_radius_km.between' => 'أدخل عددًا صحيحًا من 1 إلى 255 كم.',
            'work_radius_km.regex' => 'أدخل عددًا صحيحًا من 1 إلى 255 كم.',
        ];
    }

'''
s = replace(s, '    public function rules()\n', methods + '    public function rules()\n')
s = replace(s, "            'name' => ['required','min:2', 'max:130'],", "            'work_lat' => $this->needsWorkArea() ? 'required|numeric|between:-90,90' : 'prohibited',\n            'work_lng' => $this->needsWorkArea() ? 'required|numeric|between:-180,180' : 'prohibited',\n            'work_radius_km' => $this->needsWorkArea() ? ['required', 'integer', 'between:1,255', 'regex:/^[1-9][0-9]{0,2}$/D'] : 'prohibited',\n            'name' => ['required','min:2', 'max:130'],")
texts[path] = s

path = 'app/Repositories/UserRepository.php'
s = texts[path]
s = replace(s, 'use App\\Models\\PendingVendor;', 'use App\\Models\\PendingVendor;\nuse App\\Support\\PartnerWorkArea;')
s = replace(s, '    public function createUser(array $userDetails) \n    {  \n', '    public function createUser(array $userDetails) \n    {  \n        $workArea = PartnerWorkArea::take($userDetails);\n')
s = replace(s, '    public function updateUser($userId, array $newDetails) \n    {\n', '    public function updateUser($userId, array $newDetails) \n    {\n        $workArea = PartnerWorkArea::take($newDetails);\n')
s = replace(s, '        $pending_vendor->save();', '        PartnerWorkArea::apply($pending_vendor, $workArea);\n        $pending_vendor->save();', 2)
s = replace(s, '        $to_email = $user->email;', '        $emailFailed = false;\n        $to_email = $user->email;')
s = replace(s, '                return false;', '                $emailFailed = true; // Finish saving profile/coverage even when mail is unavailable.')
s = replace(s, '        return $user;\n    }', '        return $emailFailed ? false : $user;\n    }')
# Editing coverage must not erase registration data that is absent from the edit form.
a, b = s.split('    public function updateUser', 1)
for field in ['national_id', 'commercial_registration_no', 'driving_license_no', 'tax_no', 'owner_name', 'branches_no', 'location']:
    old = f'        $pending_vendor->{field}=request()->{field};'
    b = replace(b, old, f"        if (request()->has('{field}')) {{ $pending_vendor->{field}=request()->{field}; }}")
b = replace(b, '        $pending_vendor-> vodafone_cash_mobile=request()->vodafone_cash_mobile;', "        if (request()->has('vodafone_cash_mobile')) { $pending_vendor->vodafone_cash_mobile=request()->vodafone_cash_mobile; }")
texts[path] = a + '    public function updateUser' + b

path = 'app/Services/GoServices/Marketplace.php'
s = texts[path]
s = replace(s, '$radius=(int)($p->work_radius_km?:5);', '$radius=(int)($p->work_radius_km??5);', 2)
s = replace(s, 'in_array($radius,[5,10,15,20],true)', '($radius>=1&&$radius<=255)', 2)
texts[path] = s

# All assertions pass before any source file is written.
for path, text in texts.items():
    (ROOT / path).write_text(text, encoding='utf-8')
    print('Updated', path)
