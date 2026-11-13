<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chat\Conversation;
use App\Models\Chat\Message;
use App\Models\Chat\UserPresence;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TextMessengerController extends Controller
{
    private function authorizeConversation(Conversation $conversation): void
    {
        abort_unless($conversation->activeParticipants()->where('users.id', auth()->id())->exists(), 403);
        $owner = DB::table('messenger_saved_spaces')->where('conversation_id', $conversation->id)->value('user_id');
        abort_if($owner !== null && (int) $owner !== (int) auth()->id(), 403);
    }

    private function messageQuery()
    {
        return Message::query()->with(['user', 'replyTo.user', 'reactions.user', 'forwardedFrom.conversation'])
            ->withMax('edits', 'created_at');
    }

    private function readableMessages()
    {
        $uid = auth()->id();

        return $this->messageQuery()->whereHas('conversation.activeParticipants', fn ($q) => $q->where('users.id', $uid))
            ->whereDoesntHave('conversation.savedSpace', fn ($q) => $q->where('user_id', '!=', $uid));
    }

    private function payload(Message $message, array $starIds = [], array $readIds = []): array
    {
        $legacyMedia = in_array($message->type, ['audio', 'video', 'voice'], true);
        $forwarded = $message->forwardedFrom;
        // Do not disclose a forwarded source's private conversation title to its new recipients.
        $forwardName = $forwarded ? 'پیام مکتوب' : null;
        if ($forwarded && $forwarded->conversation->activeParticipants()->where('users.id', auth()->id())->exists()) {
            $forwardName = $forwarded->conversation->getDisplayName(auth()->id());
        }
        $reply = $message->replyTo;
        if ($reply && $reply->conversation_id !== $message->conversation_id) {
            $reply = null;
        }

        return [
            'id' => $message->id, 'conversation_id' => $message->conversation_id,
            'content' => $legacyMedia ? '[رسانهٔ قدیمی؛ ارسال صوت و ویدئو غیرفعال است]'.($message->body ? "\n".$message->body : '') : $message->body,
            'type' => $legacyMedia ? 'system' : $message->type,
            'file_name' => $message->file_name, 'file_size' => $message->file_size,
            'file_url' => ! $legacyMedia && $message->file_path ? route('admin.messenger-api.attachment', $message) : null,
            'sender_id' => $message->user_id, 'sender_name' => $message->user?->full_name ?? 'کاربر',
            'sender_avatar' => $message->user?->avatar_url, 'is_mine' => (int) $message->user_id === (int) auth()->id(),
            'is_read' => in_array($message->id, $readIds, true),
            'is_starred' => in_array($message->id, $starIds, true), 'edited_at' => $message->edits_max_created_at,
            'time' => $message->created_at->format('H:i'), 'date' => $message->created_at->format('Y-m-d'),
            'date_label' => \Morilog\Jalali\Jalalian::fromCarbon($message->created_at)->format('Y/m/d'),
            'forwarded_from_name' => $forwardName,
            'reply_to' => $reply ? ['id' => $reply->id, 'content' => mb_substr($reply->body ?? '', 0, 150), 'sender_name' => $reply->user?->full_name ?? 'کاربر'] : null,
            'reactions' => $message->reactions->groupBy('emoji')->map(fn ($items) => [
                'emoji' => $items->first()->emoji, 'count' => $items->count(),
                'has_reacted' => $items->contains('user_id', auth()->id()),
                'users' => $items->map(fn ($r) => ['id' => $r->user_id, 'name' => $r->user?->full_name ?? 'کاربر'])->values(),
            ])->values(),
        ];
    }

    private function payloads($messages): array
    {
        $ids = $messages->pluck('id');
        $stars = DB::table('message_stars')->where('user_id', auth()->id())->whereIn('message_id', $ids)->pluck('message_id')->map(fn ($id) => (int) $id)->all();
        $read = DB::table('message_reads')->where('user_id', '!=', auth()->id())->whereIn('message_id', $ids)->distinct()->pluck('message_id')->map(fn ($id) => (int) $id)->all();

        return $messages->map(fn ($m) => $this->payload($m, $stars, $read))->values()->all();
    }

    public function users(): JsonResponse
    {
        $users = User::where('is_active', true)->where(fn ($q) => $q->where('is_staff', true)->orWhere('id', auth()->id()))->with('presence')->orderBy('id')->get();
        $sessions = DB::table('messenger_presence_sessions')->whereIn('user_id', $users->pluck('id'))
            ->where('active', true)->where('last_seen_at', '>=', now()->subSeconds(35))->where('last_seen_at', '<=', now())
            ->select('user_id')->selectRaw('MAX(last_seen_at) AS last_heartbeat')->groupBy('user_id')->get()->keyBy('user_id');

        return response()->json(['users' => $users->map(function ($user) use ($sessions) {
            $seen = $sessions->get($user->id);
            $online = $seen !== null && $user->presence?->status !== 'offline';

            return [
                'id' => $user->id, 'name' => $user->full_name, 'avatar' => $user->avatar_url,
                'presence_verified' => true, 'connection_alive' => $online,
                'presence_state' => $online ? 'active' : 'inactive',
                'last_heartbeat' => $seen ? \Carbon\Carbon::parse($seen->last_heartbeat)->toIso8601String() : null,
                'last_seen_label' => $user->presence?->last_seen_at?->diffForHumans(),
            ];
        })->values()]);
    }

    public function conversations(): JsonResponse
    {
        $uid = auth()->id();
        $saved = DB::table('messenger_saved_spaces')->where('user_id', $uid)->value('conversation_id');
        $conversations = Conversation::whereHas('activeParticipants', fn ($q) => $q->where('users.id', $uid))
            ->whereDoesntHave('savedSpace', fn ($q) => $q->where('user_id', '!=', $uid))
            ->with(['latestMessage', 'activeParticipants'])->get();

        return response()->json(['conversations' => $conversations->map(function ($c) use ($uid, $saved) {
            $self = (int) $saved === (int) $c->id;
            $other = $c->getOtherParticipant($uid);

            return [
                'id' => $c->id, 'type' => $self ? 'saved' : $c->type,
                'display_name' => $self ? 'فضای شخصی' : $c->getDisplayName($uid), 'user_id' => $other?->id,
                'avatar' => $self ? null : ($c->isPrivate() ? $other?->avatar_url : ($c->avatar ? asset('storage/'.$c->avatar) : null)),
                'member_ids' => $c->activeParticipants->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'unread_count' => $self ? 0 : $c->getUnreadCount($uid),
                'last_message' => $c->latestMessage?->body ?? '', 'last_message_time' => $c->latestMessage?->created_at?->diffForHumans() ?? '',
                'last_message_at' => $c->latestMessage?->created_at?->timestamp ?? 0,
                'last_message_id' => $c->latestMessage?->id ?? 0,
                'can_send' => $c->canSendMessage($uid), 'is_pinned_personal' => $self,
            ];
        })->values()]);
    }

    public function personalSpace(): JsonResponse
    {
        $id = DB::transaction(function () {
            // Lock the existing user row, so two tabs cannot create duplicate personal spaces.
            User::whereKey(auth()->id())->lockForUpdate()->firstOrFail();
            $existing = DB::table('messenger_saved_spaces')->where('user_id', auth()->id())->first();
            if ($existing) {
                return $existing->conversation_id;
            }
            $conversation = Conversation::create(['type' => 'private', 'name' => 'فضای شخصی', 'created_by' => auth()->id(), 'settings' => ['personal_space' => true]]);
            $conversation->participants()->attach(auth()->id(), ['joined_at' => now(), 'is_admin' => true]);
            DB::table('messenger_saved_spaces')->insert(['user_id' => auth()->id(), 'conversation_id' => $conversation->id, 'created_at' => now(), 'updated_at' => now()]);

            return $conversation->id;
        });

        return response()->json(['conversation' => ['id' => (int) $id, 'type' => 'saved', 'display_name' => 'فضای شخصی', 'member_ids' => [(int) auth()->id()], 'can_send' => true, 'unread_count' => 0]]);
    }

    public function messages(Conversation $conversation, Request $request): JsonResponse
    {
        $this->authorizeConversation($conversation);
        $request->validate(['before' => 'nullable|integer|min:1', 'limit' => 'nullable|integer|min:1|max:100']);
        $query = $this->messageQuery()->where('conversation_id', $conversation->id);
        if ($request->filled('before')) {
            $query->where('id', '<', $request->integer('before'));
        }
        $messages = $query->orderByDesc('id')->limit($request->integer('limit', 60))->get()->reverse()->values();
        $uid = auth()->id();
        $reads = $messages->where('user_id', '!=', $uid)->map(fn ($m) => ['message_id' => $m->id, 'user_id' => $uid, 'read_at' => now()])->values()->all();
        if ($reads) {
            DB::table('message_reads')->insertOrIgnore($reads);
        }
        $conversation->participants()->updateExistingPivot($uid, ['last_read_at' => now()]);
        $oldest = $messages->first()?->id;

        return response()->json(['messages' => $this->payloads($messages), 'has_more' => $oldest ? $conversation->messages()->where('id', '<', $oldest)->exists() : false]);
    }

    public function search(Conversation $conversation, Request $request): JsonResponse
    {
        $this->authorizeConversation($conversation);
        $data = $request->validate(['q' => 'required|string|min:1|max:150']);
        $q = str_replace(['ي', 'ك'], ['ی', 'ک'], $data['q']);
        $messages = $this->messageQuery()->where('conversation_id', $conversation->id)
            ->whereRaw("REPLACE(REPLACE(body, 'ي', 'ی'), 'ك', 'ک') LIKE ?", ['%'.$q.'%'])->orderByDesc('id')->limit(200)->get()->reverse()->values();

        return response()->json(['messages' => $this->payloads($messages)]);
    }

    public function send(Conversation $conversation, Request $request): JsonResponse
    {
        $this->authorizeConversation($conversation);
        abort_unless($conversation->canSendMessage(auth()->id()), 403, 'ارسال پیام در این گفتگو مجاز نیست.');
        $data = $request->validate([
            'content' => 'nullable|string|max:12000', 'caption' => 'nullable|string|max:12000',
            'type' => ['nullable', Rule::in(['text', 'file', 'image'])],
            'file' => 'nullable|file|max:5120|mimes:jpg,jpeg,png,gif,webp,pdf,txt,csv,doc,docx,xls,xlsx',
            'reply_to_id' => ['nullable', 'integer', Rule::exists('messages', 'id')->where('conversation_id', $conversation->id)->whereNull('deleted_at')],
            'forwarded_from' => ['nullable', 'integer'],
        ]);
        $body = trim($data['content'] ?? $data['caption'] ?? '');
        $source = null;
        if ($request->filled('forwarded_from')) {
            $source = Message::with('conversation')->findOrFail($request->integer('forwarded_from'));
            $this->authorizeConversation($source->conversation);
            abort_if(in_array($source->type, ['audio', 'video', 'voice'], true), 422, 'رسانهٔ صوتی و تصویری قابل ارسال نیست.');
            $body = $source->body ?? '';
        }
        abort_unless($body !== '' || $request->hasFile('file') || $source?->file_path, 422, 'متن یا سند پیام را وارد کنید.');
        $path = $source?->file_path;
        $name = $source?->file_name;
        $size = $source?->file_size;
        $type = $source?->type ?? 'text';
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            abort_if(preg_match('/^(audio|video)\//i', $file->getMimeType() ?? ''), 422, 'فقط تصویر و سند مکتوب قابل ارسال است.');
            $path = $file->store('text-chat', 'local');
            $name = $file->getClientOriginalName();
            $size = $file->getSize();
            $type = str_starts_with($file->getMimeType() ?? '', 'image/') ? 'image' : 'file';
        }
        $message = Message::create([
            'conversation_id' => $conversation->id, 'user_id' => auth()->id(), 'body' => $body,
            'type' => $type, 'file_path' => $path, 'file_name' => $name, 'file_size' => $size,
            'reply_to_id' => $data['reply_to_id'] ?? null, 'forwarded_from' => $source?->id,
        ]);
        $message = $this->messageQuery()->findOrFail($message->id);

        return response()->json(['message' => $this->payload($message)]);
    }

    public function stars(): JsonResponse
    {
        $messages = $this->readableMessages()->whereIn('messages.id', DB::table('message_stars')->where('user_id', auth()->id())->select('message_id'))
            ->orderByDesc('id')->limit(500)->get();
        $messages->load('conversation');
        $rows = $this->payloads($messages);
        foreach ($rows as $i => &$row) {
            $row['conversation_name'] = $messages[$i]->conversation->getDisplayName(auth()->id());
        }

        return response()->json(['messages' => $rows]);
    }

    public function star(Message $message): JsonResponse
    {
        $this->authorizeConversation($message->conversation);
        $key = ['user_id' => auth()->id(), 'message_id' => $message->id];
        $starred = DB::transaction(function () use ($key) {
            User::whereKey(auth()->id())->lockForUpdate()->firstOrFail();
            if (DB::table('message_stars')->where($key)->exists()) {
                DB::table('message_stars')->where($key)->delete();

                return false;
            }
            DB::table('message_stars')->insert($key + ['created_at' => now()]);

            return true;
        });

        return response()->json(['success' => true, 'is_starred' => $starred]);
    }

    public function edit(Message $message, Request $request): JsonResponse
    {
        $this->authorizeConversation($message->conversation);
        abort_unless((int) $message->user_id === (int) auth()->id(), 403);
        $data = $request->validate(['content' => 'required|string|max:12000']);
        DB::transaction(function () use ($message, $data) {
            $locked = Message::whereKey($message->id)->lockForUpdate()->firstOrFail();
            DB::table('message_edits')->insert(['message_id' => $locked->id, 'user_id' => auth()->id(), 'previous_body' => $locked->body, 'new_body' => $data['content'], 'created_at' => now()]);
            $locked->update(['body' => $data['content']]);
        });

        return response()->json(['message' => $this->payload($this->messageQuery()->findOrFail($message->id))]);
    }

    public function drafts(Request $request): JsonResponse
    {
        if ($request->isMethod('post')) {
            $data = $request->validate(['conversation_id' => 'required|integer|exists:conversations,id', 'content' => 'nullable|string|max:12000']);
            $conversation = Conversation::findOrFail($data['conversation_id']);
            $this->authorizeConversation($conversation);
            DB::table('messenger_drafts')->updateOrInsert(['user_id' => auth()->id(), 'conversation_id' => $conversation->id], ['body' => $data['content'] ?? '', 'created_at' => now(), 'updated_at' => now()]);
        }
        $ids = Conversation::whereHas('activeParticipants', fn ($q) => $q->where('users.id', auth()->id()))->pluck('id');

        return response()->json(['drafts' => (object) DB::table('messenger_drafts')->where('user_id', auth()->id())->whereIn('conversation_id', $ids)->pluck('body', 'conversation_id')->all()]);
    }

    public function presence(Request $request): JsonResponse
    {
        $data = $request->validate(['session_id' => 'required|uuid', 'active' => 'required|boolean']);
        DB::table('messenger_presence_sessions')->updateOrInsert(['user_id' => auth()->id(), 'session_id' => $data['session_id']], ['active' => $data['active'], 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        if ($data['active']) {
            $presence = UserPresence::firstOrCreate(['user_id' => auth()->id()], ['status' => 'online', 'last_seen_at' => now()]);
            $presence->update(['last_seen_at' => now()]);
        }

        return response()->json(['success' => true]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)->where('is_staff', true)->whereNull('deleted_at')]]);
        if ((int) $data['user_id'] === (int) auth()->id()) {
            return $this->personalSpace();
        }
        $c = Conversation::findOrCreatePrivate(auth()->id(), (int) $data['user_id']);

        return response()->json(['conversation' => ['id' => $c->id]]);
    }

    public function createGroup(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:80', 'type' => 'required|in:group,channel', 'member_ids' => 'required|string']);
        $members = json_decode($data['member_ids'], true);
        abort_unless(is_array($members) && count($members) > 0 && count($members) <= 200 && collect($members)->every(fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id))), 422);
        $members = array_values(array_unique(array_map('intval', $members)));
        abort_unless(User::whereIn('id', $members)->where('is_active', true)->where('is_staff', true)->count() === count($members), 422);
        $c = DB::transaction(fn () => Conversation::createGroup($data['name'], auth()->id(), $members, null, ['only_admins_can_send' => $data['type'] === 'channel', 'members_can_add_others' => false], $data['type']));

        return response()->json(['conversation' => ['id' => $c->id]]);
    }

    public function reaction(Message $message, Request $request): JsonResponse
    {
        $this->authorizeConversation($message->conversation);

        return app(ChatController::class)->toggleReaction($message, $request);
    }

    public function attachment(Message $message)
    {
        $this->authorizeConversation($message->conversation);
        abort_if(! $message->file_path || in_array($message->type, ['audio', 'video', 'voice'], true), 404);
        $disk = str_starts_with($message->file_path, 'text-chat/') ? 'local' : 'public';
        abort_unless(Storage::disk($disk)->exists($message->file_path), 404);

        return Storage::disk($disk)->response($message->file_path, $message->file_name, ['X-Content-Type-Options' => 'nosniff'], $message->type === 'image' ? 'inline' : 'attachment');
    }
}
