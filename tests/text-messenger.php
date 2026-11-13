<?php

// Isolated integration checks: php tests/text-messenger.php. Never opens the configured database.
require __DIR__.'/../vendor/autoload.php';
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('CACHE_STORE=array');
putenv('SESSION_DRIVER=array');
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'session.driver' => 'array', 'cache.default' => 'array']);
Illuminate\Support\Facades\DB::purge();
use App\Http\Controllers\Admin\TextMessengerController;
use App\Models\Chat\Conversation;
use App\Models\Chat\Message;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Schema::create('users', function (Blueprint $t) {
    $t->id();
    $t->string('first_name');
    $t->string('last_name')->nullable();
    $t->string('avatar')->nullable();
    $t->string('password')->nullable();
    $t->boolean('is_active')->default(true);
    $t->boolean('is_staff')->default(true);
    $t->softDeletes();
    $t->timestamps();
});
$base = glob(__DIR__.'/../database/migrations/*create_chat_tables*')[0] ?? null;
if (! $base) {
    foreach (glob(__DIR__.'/../database/migrations/*.php') as $f) {
        if (str_contains(file_get_contents($f), "Schema::create('conversations'")) {
            $base = $f;
            break;
        }
    }
}
(require $base)->up();
Schema::table('conversations', function (Blueprint $t) {
    $t->text('settings')->nullable();
    $t->text('description')->nullable();
});
Schema::table('messages', function (Blueprint $t) {
    $t->unsignedBigInteger('forwarded_from')->nullable();
});
Schema::create('message_reactions', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('message_id');
    $t->unsignedBigInteger('user_id');
    $t->string('emoji');
    $t->timestamps();
    $t->unique(['message_id', 'user_id', 'emoji']);
});
$migration = require __DIR__.'/../database/migrations/2026_11_13_140000_create_text_messenger_features.php';
$migration->up();
for ($i = 1; $i <= 3; $i++) {
    DB::table('users')->insert(['id' => $i, 'first_name' => 'همکار '.$i]);
}
function loginAs($id)
{
    auth()->setUser(User::findOrFail($id));
}
function req($params = [], $method = 'POST')
{
    return Request::create('/admin/messenger-api/test', $method, $params);
}
$checks = 0;
function check($value, $label)
{
    global $checks;
    if (! $value) {
        throw new RuntimeException($label);
    } $checks++;
    echo "PASS $label\n";
}
function denied($callback, $status, $label)
{
    try {
        $callback();
    } catch (Illuminate\Validation\ValidationException $e) {
        check($status === 422, $label);

        return;
    } catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
        check($e->getStatusCode() === $status, $label);

        return;
    } throw new RuntimeException("Allowed: $label");
}
function data($response)
{
    return $response->getData(true);
}
$c = new TextMessengerController;
loginAs(1);
$self = data($c->personalSpace())['conversation'];
check($self['type'] === 'saved', 'saved space exists');
check(data($c->personalSpace())['conversation']['id'] === $self['id'], 'one saved space per owner');
$own = Conversation::find($self['id']);
$note = data($c->send($own, req(['content' => 'یادداشت شخصی'])))['message'];
check($note['is_mine'] && $note['content'] === 'یادداشت شخصی', 'personal text uses authenticated author');
loginAs(2);
denied(fn () => $c->messages($own, req([], 'GET')), 403, 'personal space cannot be read by another user');
$self2 = data($c->personalSpace())['conversation'];
check($self2['id'] !== $self['id'], 'saved spaces are isolated');
$chat = Conversation::findOrCreatePrivate(1, 2);
loginAs(1);
$msg = data($c->send($chat, req(['content' => 'متن قابل پیگیری'])))['message'];
check(data($c->star(Message::find($msg['id'])))['is_starred'], 'star saved for owner');
check(count(data($c->stars())['messages']) === 1, 'owner sees star');
loginAs(2);
check(count(data($c->stars())['messages']) === 0, 'stars isolated by user');
denied(fn () => $c->edit(Message::find($msg['id']), req(['content' => 'تغییر غیرمجاز'])), 403, 'only author can edit');
denied(fn () => $c->send($chat, req(['forwarded_from' => $note['id']])), 403, 'cannot forward inaccessible source');
denied(fn () => $c->send($chat, req(['content' => 'پاسخ', 'reply_to_id' => $note['id']])), 422, 'reply must belong to same conversation');
$fwd = data($c->send(Conversation::find($self2['id']), req(['forwarded_from' => $msg['id'], 'content' => 'spoof', 'file_path' => 'secret'])))['message'];
check($fwd['content'] === 'متن قابل پیگیری', 'forward copies authorized source only');
$c->drafts(req(['conversation_id' => $chat->id, 'content' => 'پیش نویس دو']));
loginAs(1);
check(! isset(data($c->drafts(req([], 'GET')))['drafts'][$chat->id]), 'drafts isolated');
$c->edit(Message::find($msg['id']), req(['content' => 'متن ویرایش‌شده']));
check(DB::table('message_edits')->where('message_id', $msg['id'])->value('previous_body') === 'متن قابل پیگیری', 'edit audit keeps original');
denied(fn () => $c->send($chat, req(['type' => 'audio', 'content' => 'صوت'])), 422, 'audio blocked');
denied(fn () => $c->send($chat, req(['type' => 'video', 'content' => 'ویدئو'])), 422, 'video blocked');
$left = Conversation::findOrCreatePrivate(1, 3);
$left->participants()->updateExistingPivot(1, ['left_at' => now()]);
denied(fn () => $c->messages($left, req([], 'GET')), 403, 'left participant cannot read');
$channel = Conversation::createGroup('کانال', 1, [2], null, ['only_admins_can_send' => true]);
loginAs(2);
denied(fn () => $c->send($channel, req(['content' => 'ممنوع'])), 403, 'channel members cannot send');
loginAs(1);
$session = 'b9bdecee-5b6c-4c5f-a391-a84b61c899c2';
$c->presence(req(['session_id' => $session, 'active' => true]));
check(data($c->users())['users'][0]['connection_alive'], 'fresh active heartbeat is online');
DB::table('messenger_presence_sessions')->update(['last_seen_at' => now()->subSeconds(36)]);
check(! data($c->users())['users'][0]['connection_alive'], 'stale heartbeat is offline');
$c->presence(req(['session_id' => $session, 'active' => false]));
check(! data($c->users())['users'][0]['connection_alive'], 'hidden tab is offline');
$middleware = new App\Http\Middleware\TextOnlyMessenger;
check($middleware->handle(Request::create('/admin/chat/calls/initiate', 'POST'), fn () => null)->status() === 410, 'legacy calls disabled');
$reaction = data($c->reaction(Message::find($msg['id']), req(['emoji' => '👍'])));
check($reaction['reactions'][0]['has_reacted'], 'emoji reaction persists');
for ($i = 0; $i < 65; $i++) {
    Message::createText($chat->id, 1, 'history '.$i);
}
$page = data($c->messages($chat, req([], 'GET')));
check(count($page['messages']) === 60 && $page['has_more'], 'history is paginated');
check(count(data($c->search($chat, req(['q' => 'history 0'], 'GET')))['messages']) === 1, 'search covers older history');
$older = data($c->messages($chat, req(['before' => $page['messages'][0]['id']], 'GET')));
check(count($older['messages']) === 6 && ! $older['has_more'], 'older messages load without gaps');
check(count(data($c->conversations())['conversations']) === 3, 'only active joined chats and own saved space listed');
// Private document transport and legacy personal-space protection.
Illuminate\Support\Facades\Storage::fake('local');
$upload = req(['content' => 'سند']);
$upload->files->set('file', Illuminate\Http\UploadedFile::fake()->createWithContent('note.txt', 'written document'));
$document = data($c->send($chat, $upload))['message'];
check(str_contains($document['file_url'], '/messenger-api/messages/'), 'document URL uses protected endpoint');
check($c->attachment(Message::find($document['id']))->getStatusCode() === 200, 'member can download document');
loginAs(3);
denied(fn () => $c->attachment(Message::find($document['id'])), 403, 'outsider cannot download document');
loginAs(1);
$audio = req(['content' => 'disguised upload']);
$audio->files->set('file', Illuminate\Http\UploadedFile::fake()->create('note.txt', 1, 'audio/wav'));
denied(fn () => $c->send($chat, $audio), 422, 'audio MIME rejected even with text extension');
$legacy = Request::create('/admin/chat/conversations/'.$own->id.'/pin/personal', 'POST');
$route = new Illuminate\Routing\Route('POST', 'admin/chat/conversations/{conversation}/pin/personal', fn () => null);
$route->bind($legacy);
$route->setParameter('conversation', $own);
$legacy->setRouteResolver(fn () => $route);
check($middleware->handle($legacy, fn () => null)->status() === 403, 'personal space cannot be unpinned through legacy endpoint');
$migration->down();
check(! Schema::hasTable('message_stars') && Schema::hasTable('messages'), 'rollback leaves existing messages intact');
echo "$checks integration checks passed.\n";
