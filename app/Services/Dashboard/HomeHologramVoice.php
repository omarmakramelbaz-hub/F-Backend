<?php
namespace App\Services\Dashboard;

use App\Services\OwnerDashboards\Runtime;
use Illuminate\Http\Request;

/** Private, session-bound recordings. HTTP requests never run a speech model. */
class HomeHologramVoice
{
    public const VERSION = 1;
    public const VOICE_VERSION = 'go-approved-qwen-base-1.7b-q4-4283-atempo085-v1';
    public const TEMPLATE_VERSION = 'welcome-name-approved-tail-v1';
    public const DISPLAY_NAME_MAX = 150;
    public const SPOKEN_NAME_MAX = 450;
    private const SESSION_KEY = 'go_hologram_voice_ticket';
    private const MAX_AUDIO_BYTES = 8388608;

    public function forLogin($actor, Request $request, array $greeting): ?array
    {
        $request->session()->forget(self::SESSION_KEY);
        $identity = $this->identity($actor);
        $nonce = $greeting['nonce'] ?? null;
        if (!$identity || !is_string($nonce) || !preg_match('/^[a-zA-Z0-9-]{16,80}$/D', $nonce)) {
            return ['state' => 'unavailable'];
        }
        // Only a small local file; the dedicated worker owns synthesis.
        $queued = $this->enqueue($identity);
        $ticket = bin2hex(random_bytes(32));
        $expires = $this->now() + max(60, min(3600, (int) config('home_hologram_voice.ticket_ttl_seconds', 1800)));
        $request->session()->put(self::SESSION_KEY, [
            'token_hash' => hash('sha256', $ticket),
            'session_hash' => hash('sha256', $request->session()->getId()),
            'nonce' => $nonce, 'identity' => $identity, 'expires_at' => $expires,
        ]);
        return $this->descriptor($identity, $ticket, $expires, $queued);
    }

    /** Read-only: polling cannot enqueue or extend the login's expiry. */
    public function status($actor, Request $request, string $ticket): ?array
    {
        $record = $this->ticket($actor, $request, $ticket);
        return $record ? $this->descriptor($record['identity'], $ticket, $record['expires_at']) : null;
    }

    /** The caller serves only this authenticated, hash-verified MP3. */
    public function audio($actor, Request $request, string $ticket): ?array
    {
        $record = $this->ticket($actor, $request, $ticket);
        if (!$record || !$this->ready($record['identity'])) return null;
        $path = $this->root().'/ready/'.$record['identity']['key'].'/audio.mp3';
        return ['path' => $path, 'size' => filesize($path)];
    }

    /** Used only by account events and the explicit CLI prewarm. */
    public function prewarm($actor): string
    {
        return $this->prewarmDetails($actor)['state'];
    }

    /** Private CLI inventory; never returned from a dashboard endpoint. */
    public function prewarmDetails($actor): array
    {
        $display = $this->normalizeName((string) ($actor->name ?? ''));
        $identity = $this->identity($actor);
        $resolution = $display ? $this->resolveName($display, $this->scope(), (string) ($actor->id ?? '')) : null;
        $state = !$identity ? 'unavailable' : ($this->ready($identity) ? 'ready' : ($this->enqueue($identity) ? 'queued' : 'unavailable'));
        return ['actor_id' => (string) ($actor->id ?? ''), 'account_type' => (string) ($actor->account_type ?? ''),
            'display_name' => $display ?? substr((string) ($actor->name ?? ''), 0, 1024),
            'spoken_name' => $identity['spoken_name'] ?? null, 'resolution' => $resolution['method'] ?? 'unresolved',
            'state' => $state, 'key' => $identity['key'] ?? null,
            'reason' => $state !== 'unavailable' ? null : (!$identity ? 'name-or-identity-unresolved' : 'spool-unavailable')];
    }

    public function writePrewarmInventory(array $accounts, array $counts): bool
    {
        try {
            if (!$this->scope() || !$this->prepareDirectories()) return false;
            $path = $this->root().'/prewarm-inventory.json';
            if (is_link($path)) return false;
            $temporary = $this->root().'/.prewarm-inventory.'.bin2hex(random_bytes(8)).'.tmp';
            $stream = @fopen($temporary, 'xb');
            if (!$stream) return false;
            @chmod($temporary, 0600);
            $json = json_encode(['version' => self::VERSION, 'scope' => $this->scope(), 'created_at' => $this->now(),
                'voice_version' => self::VOICE_VERSION, 'template_version' => self::TEMPLATE_VERSION,
                'counts' => $counts, 'accounts' => $accounts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
            $written = fwrite($stream, $json); fflush($stream); fclose($stream);
            if ($written !== strlen($json) || !@rename($temporary, $path)) { @unlink($temporary); return false; }
            return true;
        } catch (\Throwable $error) { return false; }
    }

    protected function root(): string { return storage_path('app/go-hologram-voice'); }
    protected function now(): int { return time(); }
    protected function scope(): string
    {
        if (!Runtime::tenant()) return 'central';
        $id = Runtime::facts()['id'] ?? null;
        // An absent trusted identity must never collapse a tenant into central.
        return is_string($id) && preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $id) ? 'tenant:'.$id : '';
    }

    protected function identity($actor): ?array
    {
        if (!$actor || !in_array($actor->account_type ?? null, DashboardLoginIdentity::ACCOUNT_TYPES, true)) return null;
        $id = (string) ($actor->id ?? '');
        $scope = $this->scope();
        $display = $this->normalizeName((string) ($actor->name ?? ''));
        if (!$scope || !preg_match('/^[1-9][0-9]{0,19}$/D', $id) || !$display) return null;
        $spoken = $this->spokenName($display, $scope, $id);
        if (!$spoken) return null;
        $key = hash('sha256', json_encode([
            'go-hologram-voice', self::VERSION, $scope, 'admin', $id, $display, $spoken,
            self::VOICE_VERSION, self::TEMPLATE_VERSION,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ['version' => self::VERSION, 'key' => $key, 'scope' => $scope, 'guard' => 'admin',
            'actor_id' => $id, 'display_name' => $display, 'spoken_name' => $spoken,
            'voice_version' => self::VOICE_VERSION, 'template_version' => self::TEMPLATE_VERSION];
    }

    protected function normalizeName(string $value, int $maxLength = self::DISPLAY_NAME_MAX): ?string
    {
        if (strlen($value) > 2048 || !preg_match('//u', $value)) return null;
        if (class_exists('Normalizer')) $value = \Normalizer::normalize($value, \Normalizer::FORM_C);
        $value = trim(preg_replace('/[\p{C}\p{Z}\s]+/u', ' ', $value));
        $length = preg_match_all('/./us', $value);
        if ($length < 1 || $length > $maxLength || preg_match('/[<>{}\[\]]/u', $value)) return null;
        return $value;
    }

    protected function spokenName(string $display, string $scope, string $id): ?string
    {
        return $this->resolveName($display, $scope, $id)['spoken_name'] ?? null;
    }

    protected function resolveName(string $display, string $scope, string $id): ?array
    {
        // Overrides are private and bound to the exact current display name.
        $overrides = config('home_hologram_voice.pronunciations', []);
        $private = $this->readJson($this->root().'/pronunciations.json', 262144);
        foreach ([$private, $overrides] as $source) {
            $override = is_array($source) ? ($source[$scope][$id] ?? null) : null;
            if (is_array($override) && ($override['display_name'] ?? null) === $display) {
                $spoken = $this->normalizeName((string) ($override['spoken_name'] ?? ''), self::SPOKEN_NAME_MAX);
                if ($spoken && $this->latinName($spoken)) return ['spoken_name' => $spoken, 'method' => 'override'];
                return null;
            }
        }
        if ($this->latinName($display)) return ['spoken_name' => $display, 'method' => 'latin'];
        // Conventional English name spellings; never send Arabic script to the English model.
        $plain = preg_replace('/[\x{0640}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $display);
        $plain = strtr($plain, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
        $lookup = strtr($plain, ['أ'=>'ا', 'إ'=>'ا', 'آ'=>'ا', 'ٱ'=>'ا', 'ى'=>'ي', 'ک'=>'ك', 'ی'=>'ي']);
        $lexicon = $this->arabicNames();
        $separator = '/[\s,،.\x{002D}\/()_]+/u';
        $parts = preg_split($separator, $lookup, -1, PREG_SPLIT_NO_EMPTY);
        $original = preg_split($separator, $plain, -1, PREG_SPLIT_NO_EMPTY);
        $spoken = [];
        $method = 'lexicon';
        for ($i = 0; $i < count($parts); $i++) {
            $matched = false;
            for ($length = min(4, count($parts) - $i); $length >= 1; $length--) {
                $phrase = implode(' ', array_slice($parts, $i, $length));
                if (isset($lexicon[$phrase])) { $spoken[] = $lexicon[$phrase]; $i += $length - 1; $matched = true; break; }
            }
            if ($matched) continue;
            if ($this->latinName($parts[$i])) { $spoken[] = $parts[$i]; continue; }
            // ICU and the local fallback are pronunciation approximations, recorded in the private inventory.
            $word = $this->icuArabic($original[$i]);
            if ($word) { $spoken[] = $word; if ($method !== 'phonetic') $method = 'icu'; continue; }
            $word = $this->phoneticArabic($original[$i]);
            if (!$word) return null;
            $spoken[] = $word; $method = 'phonetic';
        }
        $result = $this->normalizeName(implode(' ', $spoken), self::SPOKEN_NAME_MAX);
        return $result && $this->latinName($result) ? ['spoken_name' => $result, 'method' => $method] : null;
    }

    protected function icuArabic(string $word): ?string
    {
        if (!preg_match('/^[\p{Arabic}0-9]+$/uD', $word) || !class_exists('Transliterator')) return null;
        try {
            $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII');
            $value = $transliterator ? $this->normalizeName($transliterator->transliterate($word), self::SPOKEN_NAME_MAX) : null;
            return $value && $this->latinName($value) && preg_match('/[aeiou]/i', $value) ? ucwords($value) : null;
        } catch (\Throwable $error) { return null; }
    }

    /** Approximate unwritten vowels without sending unsupported Arabic to the English speaker. */
    protected function phoneticArabic(string $word): ?string
    {
        $map = ['ا'=>'a','أ'=>'a','إ'=>'a','آ'=>'a','ٱ'=>'a','ب'=>'b','ت'=>'t','ث'=>'th','ج'=>'g','ح'=>'h','خ'=>'kh',
            'د'=>'d','ذ'=>'z','ر'=>'r','ز'=>'z','س'=>'s','ش'=>'sh','ص'=>'s','ض'=>'d','ط'=>'t','ظ'=>'z','ع'=>'a',
            'غ'=>'gh','ف'=>'f','ق'=>'k','ك'=>'k','ک'=>'k','ل'=>'l','م'=>'m','ن'=>'n','ه'=>'h','و'=>'oo','ي'=>'ee',
            'ی'=>'ee','ى'=>'a','ة'=>'a','ؤ'=>'oo','ئ'=>'ee','ء'=>"'"];
        $prefix = '';
        if (str_starts_with($word, 'ال') && preg_match_all('/./us', $word) > 3) { $prefix = 'El '; $word = preg_replace('/^ال/u', '', $word); }
        $pieces = [];
        foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) as $letter) {
            if (isset($map[$letter])) $pieces[] = $map[$letter];
            elseif (preg_match('/^[0-9]$/D', $letter)) $pieces[] = $letter;
            else return null;
        }
        $result = '';
        for ($i = 0; $i < count($pieces);) {
            if (preg_match('/^[aeiou0-9]/i', $pieces[$i]) || $pieces[$i] === "'") { $result .= $pieces[$i++]; continue; }
            $run = [];
            while ($i < count($pieces) && !preg_match('/^[aeiou0-9]/i', $pieces[$i]) && $pieces[$i] !== "'") $run[] = $pieces[$i++];
            if ($result === '' && count($run) >= 2) $result .= array_shift($run).'a';
            while (count($run) > 2) $result .= array_shift($run).array_shift($run).'a';
            $result .= implode('', $run);
        }
        $result = $this->normalizeName($prefix.ucfirst($result), self::SPOKEN_NAME_MAX);
        return $result && $this->latinName($result) ? $result : null;
    }

    private function latinName(string $value): bool
    {
        return (bool) preg_match('/^(?=.*[\p{Latin}0-9])[\p{Latin}\p{M}0-9 .,&\x{2019}\x{0027}\x{002D}]+$/uD', $value);
    }

    private function arabicNames(): array
    {
        return [
            'محمد'=>'Mohamed','احمد'=>'Ahmed','محمود'=>'Mahmoud','مصطفي'=>'Mostafa','علي'=>'Ali',
            'عمر'=>'Omar','عمرو'=>'Amr','حسن'=>'Hassan','حسين'=>'Hussein','خالد'=>'Khaled',
            'وليد'=>'Walid','شريف'=>'Sherif','كريم'=>'Karim','يوسف'=>'Youssef','ياسين'=>'Yassin',
            'ابراهيم'=>'Ibrahim','اسماعيل'=>'Ismail','ايمن'=>'Ayman','ايهاب'=>'Ehab','هاني'=>'Hany',
            'هشام'=>'Hesham','طارق'=>'Tarek','تامر'=>'Tamer','عماد'=>'Emad','عصام'=>'Essam',
            'عادل'=>'Adel','اشرف'=>'Ashraf','جمال'=>'Gamal','مجدي'=>'Magdy','ماجد'=>'Maged',
            'سامي'=>'Samy','رامي'=>'Ramy','سمير'=>'Samir','سعيد'=>'Saeed','صلاح'=>'Salah',
            'فؤاد'=>'Fouad','فوزي'=>'Fawzy','فتحي'=>'Fathy','حازم'=>'Hazem','مروان'=>'Marwan',
            'زياد'=>'Ziad','ادهم'=>'Adham','اسلام'=>'Islam','مالك'=>'Malek','حماده'=>'Hamada',
            'مريم'=>'Mariam','فاطمة'=>'Fatma','فاطمه'=>'Fatma','اسماء'=>'Asmaa','ايمان'=>'Eman',
            'مني'=>'Mona','نهي'=>'Noha','ندي'=>'Nada','رانيا'=>'Rania','مروة'=>'Marwa','مروه'=>'Marwa',
            'هبة'=>'Heba','هبه'=>'Heba','هاجر'=>'Hagar','نور'=>'Nour','نورهان'=>'Nourhan',
            'حنين'=>'Haneen','سماح'=>'Samah','سلمي'=>'Salma','بسمة'=>'Basma','بسمه'=>'Basma',
            'ايه'=>'Aya','اية'=>'Aya','اميرة'=>'Amira','اميره'=>'Amira','ليلي'=>'Laila','دينا'=>'Dina',
            'دعاء'=>'Duaa','سحر'=>'Sahar','ريم'=>'Reem','عبدالله'=>'Abdullah','عبد الله'=>'Abdullah',
            'عبدالرحمن'=>'Abdel Rahman','عبد الرحمن'=>'Abdel Rahman',
            'عبدالرحيم'=>'Abdel Raheem','عبد الرحيم'=>'Abdel Raheem',
            'عبدالعزيز'=>'Abdel Aziz','عبد العزيز'=>'Abdel Aziz',
            'عبدالفتاح'=>'Abdel Fattah','عبد الفتاح'=>'Abdel Fattah',
            'عبدالحليم'=>'Abdel Halim','عبد الحليم'=>'Abdel Halim',
            'عبدالحميد'=>'Abdel Hamid','عبد الحميد'=>'Abdel Hamid',
            'عبدالمجيد'=>'Abdel Majeed','عبد المجيد'=>'Abdel Majeed',
            'مكرم'=>'Makram','السيد'=>'El Sayed','سيد'=>'Sayed','العربي'=>'El Arabi',
            'حلمي'=>'Helmy','حمدي'=>'Hamdy','عزت'=>'Ezzat','صالح'=>'Saleh','بدر'=>'Badr',
            'رضا'=>'Reda','سامح'=>'Sameh','جابر'=>'Gaber','رجب'=>'Ragab','صابر'=>'Saber',
            'شعبان'=>'Shaaban','انور'=>'Anwar','مراد'=>'Morad','هلال'=>'Helal','فرج'=>'Farag',
            'عاطف'=>'Atef','عاصم'=>'Assem','اسامة'=>'Osama','اسامه'=>'Osama','حمادة'=>'Hamada',
            'عبدالحكيم'=>'Abdel Hakim','عبد الحكيم'=>'Abdel Hakim',
            'عزالدين'=>'Ezz El Din','عز الدين'=>'Ezz El Din',
            'اكرم'=>'Akram','المنصورة'=>'El Mansoura','نبروه'=>'Nabaroh','نبروة'=>'Nabaroh',
            'المحلة'=>'El Mahalla','المحله'=>'El Mahalla','المحلة الكبري'=>'El Mahalla El Kubra',
            'شبرا'=>'Shubra','شبرا الخيمة'=>'Shubra El Kheima','فسخانستا'=>'Fasakhansta','الباز'=>'El Baz','باز'=>'Baz',
            'المقطم'=>'El Mokattam','زايد'=>'Zayed','التجمع'=>'El Tagamoa','مدينتي'=>'Madinaty',
            'مصر الجديدة'=>'Masr El Gedida','مصر الجديده'=>'Masr El Gedida','المهندسين'=>'El Mohandeseen',
            'مدينة نصر'=>'Madinet Nasr','زهراء مدينة نصر'=>'Zahraa Madinet Nasr','عين شمس'=>'Ain Shams',
            'التجمع الثالث'=>'El Tagamoa El Talet','15مايو'=>'15 May','مايو'=>'May','المعادي'=>'El Maadi',
            'اكتوبر'=>'October','حدائق اكتوبر'=>'Hadayek October','الجيزة'=>'El Giza','الجيزه'=>'El Giza',
            'حلوان'=>'Helwan','الهرم'=>'El Haram','زمالك'=>'Zamalek','الشروق'=>'El Shorouk','الرحاب'=>'El Rehab',
            'منصورية'=>'Mansouria','منصوريه'=>'Mansouria','قليوب'=>'Qalyoub','العاصمة'=>'El Asima','العاصمه'=>'El Asima',
            'العاشر'=>'El Asher','عبور'=>'Obour','العبور'=>'El Obour','كرداسة'=>'Kerdasa','كرداسه'=>'Kerdasa',
            'ستور'=>'Store','جملة'=>'Wholesale',
            'مدير'=>'Manager','المدير'=>'Manager','مكتب'=>'Office','المكتب'=>'Office',
            'فرع'=>'Branch','الفرع'=>'Branch','ادمن'=>'Admin','الادمن'=>'Admin','حساب'=>'Account','الحساب'=>'Account',
        ];
    }

    private function ticket($actor, Request $request, string $ticket): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $ticket)) return null;
        $record = $request->session()->get(self::SESSION_KEY);
        if (!is_array($record) || !is_array($record['identity'] ?? null)
            || !is_string($record['token_hash'] ?? null) || !is_string($record['session_hash'] ?? null)
            || !is_string($record['nonce'] ?? null) || !is_int($record['expires_at'] ?? null)
            || $record['expires_at'] <= $this->now()
            || !hash_equals($record['token_hash'], hash('sha256', $ticket))
            || !hash_equals($record['session_hash'], hash('sha256', $request->session()->getId()))) return null;
        $newLogin = $request->session()->get('go_hologram_login');
        if (is_array($newLogin) && ($newLogin['nonce'] ?? null) !== $record['nonce']) return null;
        $identity = $this->identity($actor);
        return $identity && $identity === $record['identity'] ? $record : null;
    }

    private function descriptor(array $identity, string $ticket, int $expires, bool $queued = true): array
    {
        $ready = $this->ready($identity);
        $dead = is_file($this->root().'/dead/'.$identity['key'].'.json');
        $result = ['state' => $ready ? 'ready' : ($queued && !$dead ? 'pending' : 'unavailable'),
            'status_url' => route('dashboard.hologram-voice.status', ['ticket' => $ticket]),
            'poll_after_ms' => 1500, 'expires_at' => $expires];
        if ($ready) {
            $result['url'] = route('dashboard.hologram-voice.audio', ['ticket' => $ticket]);
            $result['envelope'] = $ready;
        }
        return $result;
    }

    private function enqueue(array $identity): bool
    {
        try {
            if ($this->ready($identity)) return true;
            $root = $this->root();
            if (!$this->prepareDirectories()) return false;
            foreach (['queue', 'processing'] as $dir) {
                $path = $root.'/'.$dir.'/'.$identity['key'].'.json';
                if (is_link($path)) return false;
                if (is_file($path)) return true;
            }
            if (is_file($root.'/dead/'.$identity['key'].'.json')) return false;
            $temporary = $root.'/queue/.'.$identity['key'].'.'.bin2hex(random_bytes(8)).'.tmp';
            $stream = @fopen($temporary, 'xb');
            if (!$stream) return false;
            @chmod($temporary, 0600);
            $json = json_encode($identity + ['created_at' => $this->now()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            $written = fwrite($stream, $json);
            fflush($stream);
            fclose($stream);
            if ($written !== strlen($json) || !@rename($temporary, $root.'/queue/'.$identity['key'].'.json')) {
                @unlink($temporary); return false;
            }
            return true;
        } catch (\Throwable $error) {
            // Account saves and successful login remain independent from worker availability.
            return false;
        }
    }

    private function prepareDirectories(): bool
    {
        $root = $this->root();
        foreach ([$root, $root.'/queue', $root.'/processing', $root.'/ready', $root.'/dead'] as $dir) {
            if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir))) return false;
            @chmod($dir, 0700);
        }
        return true;
    }

    private function ready(array $identity): ?array
    {
        $root = $this->root();
        $dir = $root.'/ready/'.$identity['key'];
        if (is_link($root) || is_link($root.'/ready') || is_link($dir) || !is_dir($dir)) return null;
        $metadata = $this->readJson($dir.'/metadata.json', 16384);
        $envelope = $this->readJson($dir.'/envelope.json', 262144);
        if (!$metadata || !$envelope) return null;
        foreach ($identity as $key => $value) if (($metadata[$key] ?? null) !== $value) return null;
        $duration = $metadata['duration_seconds'] ?? null;
        if ((!is_int($duration) && !is_float($duration)) || !is_finite((float) $duration) || $duration < 1 || $duration > 120
            || ($envelope['sample_rate'] ?? null) !== 50 || !is_array($envelope['values'] ?? null)
            || array_values($envelope['values']) !== $envelope['values']
            || count($envelope['values']) < 50 || count($envelope['values']) > 6001
            || abs(count($envelope['values']) / 50 - (float) $duration) > 0.1) return null;
        foreach ($envelope['values'] as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 1) return null;
        }
        foreach (['audio.mp3' => 'audio_sha256', 'envelope.json' => 'envelope_sha256'] as $file => $field) {
            $path = $dir.'/'.$file;
            $hash = $metadata[$field] ?? null;
            if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash) || is_link($path) || !is_file($path)
                || ($file === 'audio.mp3' && (filesize($path) < 1024 || filesize($path) > self::MAX_AUDIO_BYTES))
                || !hash_equals($hash, (string) @hash_file('sha256', $path))) return null;
        }
        return ['sample_rate' => 50, 'values' => $envelope['values']];
    }

    private function readJson(string $path, int $maxBytes): ?array
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path) || filesize($path) > $maxBytes || filesize($path) < 2) return null;
        $contents = @file_get_contents($path);
        if ($contents === false || strlen($contents) > $maxBytes) return null;
        $data = json_decode($contents, true);
        return is_array($data) ? $data : null;
    }
}
