<?php

/** Standalone policy fixture: no Laravel boot, database, accounts, or network calls. */
namespace App\Models {
    class User
    {
        public int $id;
        public string $account_type;
        public ?int $owner_resturant_id;
        public bool $canReadOrders;

        public function __construct(int $id, string $type, ?int $branch = null, bool $canRead = true)
        {
            $this->id = $id;
            $this->account_type = $type;
            $this->owner_resturant_id = $branch;
            $this->canReadOrders = $canRead;
        }
    }
}

namespace Illuminate\Support\Facades {
    class Schema
    {
        public static array $tables = [];
        public static function hasTable(string $name): bool { return isset(self::$tables[$name]); }
    }
}

namespace App\Services\Dashboard {
    use App\Models\User;

    // An injected persisted-identity fixture. This tests that inbox policy trusts the fresh
    // result from TakeawayAccess, rather than account fields carried by the caller's session.
    class TakeawayAccess
    {
        public array $persisted = [];
        public int $reloads = 0;
        public bool $fail = false;

        public function actor($session): User
        {
            $this->reloads++;
            if ($this->fail) throw new \RuntimeException('Fixture policy failure');
            \abort_unless($session instanceof User && isset($this->persisted[$session->id]), 403);
            $fresh = clone $this->persisted[$session->id];
            \abort_unless($fresh->canReadOrders, 403);
            return $fresh;
        }
    }
}

namespace {
    use App\Models\User;
    use App\Services\Dashboard\TakeawayAccess;
    use App\Services\Dashboard\WhatsAppInboxAccess;
    use Illuminate\Support\Facades\Schema;

    class InboxFixtureDenied extends \RuntimeException {}
    $fixturePolicy = new TakeawayAccess();
    $fixtureLocal = false;

    function app(string $name)
    {
        global $fixturePolicy;
        if ($name !== TakeawayAccess::class) throw new \RuntimeException('Unknown fixture service');
        return $fixturePolicy;
    }

    function config(string $key, $default = null)
    {
        global $fixtureLocal;
        return $key === 'desktop_dashboard.local' ? $fixtureLocal : $default;
    }

    function abort_unless($condition, int $status): void
    {
        if (!$condition) throw new InboxFixtureDenied('Denied', $status);
    }

    function check(bool $condition, string $label): void
    {
        if (!$condition) throw new \RuntimeException('Access fixture failed: ' . $label);
    }

    function denied(callable $action, string $label): void
    {
        try {
            $action();
        } catch (InboxFixtureDenied $error) {
            check($error->getCode() === 403, $label . ' status');
            return;
        }
        throw new \RuntimeException('Expected denial: ' . $label);
    }

    require __DIR__ . '/../app/Services/Dashboard/WhatsAppInboxAccess.php';

    try {
        $access = new WhatsAppInboxAccess();
        $fixturePolicy->persisted = [
            1 => new User(1, 'admin'),
            2 => new User(2, 'admin', null, true),
            3 => new User(3, 'admin', 18),
            4 => new User(4, 'vendor'),
            5 => new User(5, 'resturant_owner', 18),
            6 => new User(6, 'admin', null, false),
        ];

        check($access->actor(new User(1, 'admin'))->id === 1, 'Owner accepted');
        check($access->actor(new User(2, 'admin'))->id === 2, 'Central Admin accepted');
        denied(function () use ($access) { $access->actor(null); }, 'Guest denied');
        denied(function () use ($access) { $access->actor(new User(99, 'admin')); }, 'Missing persisted actor denied');
        denied(function () use ($access) { $access->actor(new User(3, 'admin', 18)); }, 'Branch Admin denied');
        denied(function () use ($access) { $access->actor(new User(4, 'vendor')); }, 'Branch vendor denied');
        denied(function () use ($access) { $access->actor(new User(5, 'resturant_owner', 18)); }, 'Restaurant owner denied');
        denied(function () use ($access) { $access->actor(new User(6, 'admin')); }, 'Upstream order permission denial preserved');

        denied(function () use ($access) { $access->actor(new User(3, 'admin', null)); }, 'Stale central session cannot grant branch access');
        denied(function () use ($access) { $access->actor(new User(4, 'admin', null)); }, 'Forged session role cannot grant vendor access');
        $fresh = $access->actor(new User(2, 'vendor', 18));
        check($fresh->account_type === 'admin' && $fresh->owner_resturant_id === null,
            'Persisted central identity overrides stale session fields');

        $session = new User(2, 'admin');
        check($access->canAccess($session), 'Sidebar central access accepted');
        $fixturePolicy->persisted[2] = new User(2, 'admin', 18);
        check(!$access->canAccess($session), 'Sidebar detects persisted role/branch change');
        $fixturePolicy->fail = true;
        check(!$access->canAccess(new User(1, 'admin')), 'Policy error hides sidebar without breaking dashboard');
        $fixturePolicy->fail = false;
        check($fixturePolicy->reloads >= 14, 'Every policy entry reloads persisted identity');

        $required = ['whatsapp_inbox_conversations', 'whatsapp_inbox_messages', 'whatsapp_inbox_ingestion_failures'];
        Schema::$tables = array_fill_keys($required, true);
        check($access->available(), 'Complete online schema available');
        foreach ($required as $missing) {
            unset(Schema::$tables[$missing]);
            check(!$access->available(), 'Partial schema unavailable: ' . $missing);
            Schema::$tables[$missing] = true;
        }
        $fixtureLocal = true;
        check(!$access->available(), 'Desktop local mode honestly unavailable');
        $fixtureLocal = false;
        check(WhatsAppInboxAccess::WABA_ID === '468336579702269'
            && WhatsAppInboxAccess::PHONE_ID === '515388018324075', 'Exact real account and phone scope');
        echo "WHATSAPP_INBOX_ACCESS_PASS persisted roles, stale-session denial, sidebar failure, complete schema, local mode\n";
    } catch (\Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
