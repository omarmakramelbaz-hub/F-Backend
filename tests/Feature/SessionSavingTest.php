<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Session\AtomicFileSessionHandler;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A busy branch device (Shubra's) was signed out every so often with «419 PAGE EXPIRED»: a request read its session
 * file while another one was saving it (emptied, then written) and found it empty.
 */
class SessionSavingTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = sys_get_temp_dir().'/go-erp-sessions-'.bin2hex(random_bytes(4));
        mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->folder);
        parent::tearDown();
    }

    public function test_the_site_saves_file_sessions_whole(): void
    {
        config(['session.driver' => 'file', 'session.files' => $this->folder]);
        $this->assertInstanceOf(AtomicFileSessionHandler::class, app('session')->driver('file')->getHandler());
    }

    public function test_a_session_is_never_found_empty_while_it_is_saved(): void
    {
        $handler = new AtomicFileSessionHandler(new Filesystem(), $this->folder, 120);
        $id = str_repeat('a', 40);
        $this->assertTrue($handler->write($id, 'first'));
        $this->assertSame('first', $handler->read($id));
        // The save goes to a file of its own and replaces the session at once: the session file itself is never
        // opened for writing (an empty moment), so a read in between still finds the previous session.
        $inode = fileinode($this->folder.'/'.$id);
        $this->assertTrue($handler->write($id, str_repeat('x', 5000)));
        clearstatcache();
        $this->assertNotSame($inode, fileinode($this->folder.'/'.$id), 'replaced, not rewritten in place');
        $this->assertSame(str_repeat('x', 5000), $handler->read($id));
        $this->assertSame([$id], array_values(array_diff(scandir($this->folder), ['.', '..'])), 'nothing left beside it');
        $handler->destroy($id);
        $this->assertSame('', $handler->read($id));
    }

    public function test_a_signed_out_page_polling_gets_a_short_answer_instead_of_the_sign_in_page(): void
    {
        Route::middleware(['web', 'IsAdmin'])->get('/admin/_session_probe', fn () => response()->json(['success'=>true]));
        // A page's background request: a short 401 the page recognises, no sign-in page rendered.
        $this->getJson('/admin/_session_probe')->assertStatus(401)->assertHeader('X-GO-Session', 'expired')->assertJsonPath('success', false);
        $this->get('/admin/_session_probe', ['X-Requested-With'=>'XMLHttpRequest'])->assertStatus(401);
        // Opening a page: the sign-in page, as before.
        $this->get('/admin/_session_probe')->assertRedirect(url('admin/login'));
    }

    public function test_a_sign_in_from_a_page_no_longer_current_opens_the_sign_in_page_again(): void
    {
        $middleware = new class(app(), app('encrypter')) extends VerifyCsrfToken {
            protected function runningUnitTests() { return false; }
        };
        $request = Request::create('/admin/signin', 'POST', ['_token' => 'old-page', 'email' => 'branch@example.com', 'password' => 'secret', 'remember' => '1']);
        $request->setLaravelSession($session = app('session')->driver('array'));
        $session->start();
        $response = $middleware->handle($request, fn () => response('signed in'));
        $this->assertTrue($response->isRedirect(url('admin/login')));
        $this->assertSame('انتهت صلاحية صفحة الدخول. سجّل الدخول مرة أخرى.', $session->get('error'));
        $this->assertSame(['email' => 'branch@example.com', 'remember' => '1'], $session->get('_old_input'));

        // Anything else still stops as before.
        foreach ([Request::create('/admin/employees/remove', 'POST', ['_token' => 'old-page']),
                  Request::create('/admin/signin', 'POST', ['_token' => 'old-page'], [], [], ['HTTP_ACCEPT' => 'application/json'])] as $other) {
            $other->setLaravelSession($session);
            try {
                $middleware->handle($other, fn () => response('done'));
                $this->fail('a stale token went through');
            } catch (TokenMismatchException) {
                $this->addToAssertionCount(1);
            }
        }
        // A current page signs in.
        $request = Request::create('/admin/signin', 'POST', ['_token' => $session->token()]);
        $request->setLaravelSession($session);
        $this->assertSame('signed in', $middleware->handle($request, fn () => response('signed in'))->getContent());
    }
}
