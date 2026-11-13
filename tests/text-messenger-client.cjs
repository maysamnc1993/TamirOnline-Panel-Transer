const vm=require('node:vm'),fs=require('node:fs'),assert=require('node:assert/strict');
const context={window:{},location:{origin:'https://panel.tamironline.com'},URL,FormData,Date,setTimeout,clearTimeout,setInterval,clearInterval,console};vm.createContext(context);vm.runInContext(fs.readFileSync(__dirname+'/../public/js/text-messenger.js','utf8'),context);
(async()=>{
 const c=context.window.textMessenger();c.$nextTick=fn=>fn();c.$refs={};c.currentUserId=42;c.users=[{id:1},{id:42},{id:99}];assert.deepEqual(Array.from(c.otherUsers,u=>u.id),[1,99]);
 const hue=c.avatarHue({id:99});c.users.reverse();assert.equal(c.avatarHue({id:99}),hue);assert(c.avatar({id:99,avatar:'/storage/photo.jpg'}).startsWith('<img'));assert(c.avatar({id:99,avatar:'javascript:alert(1)'}).startsWith('<svg'));
 const now=Date.now();assert(context.window.messengerTextUtils.trulyOnline({presence_verified:true,connection_alive:true,presence_state:'active',last_heartbeat:now},now));assert(!context.window.messengerTextUtils.trulyOnline({is_online:true},now));assert(!context.window.messengerTextUtils.trulyOnline({presence_verified:true,connection_alive:true,presence_state:'active',last_heartbeat:now-36000},now));
 const pending={};c.api=path=>path==='drafts'?Promise.resolve({}):new Promise(resolve=>{pending[path]=resolve});
 const first=c.openConversation({id:10,can_send:true});await new Promise(setImmediate);const second=c.openConversation({id:20,can_send:true});await new Promise(setImmediate);
 pending['conversations/20/messages']({messages:[{id:201,content:'new'}],has_more:false});await second;pending['conversations/10/messages']({messages:[{id:101,content:'old'}],has_more:false});await first;assert.equal(c.current.id,20);assert.equal(c.messages[0].id,201);
 c.current=null;c.messages=[];c.conversations=[{id:1,type:'saved'},{id:2,type:'group',can_send:false,display_name:'کانال'},{id:3,type:'private',can_send:true,display_name:'عضو'}];assert.equal(c.ordinaryConversations.length,2);assert.equal(c.forwardTargets.length,1);
 c.newMessage='<img onerror=alert(1)>';assert.equal(c.highlight(c.newMessage),'&lt;img onerror=alert(1)&gt;');c.current={id:2,can_send:false};let sent=false;c.api=async()=>{sent=true};await c.send();assert(!sent);
 assert(!/getUserMedia|MediaRecorder|RTCPeerConnection|requestPermission|localStorage|stageMessengerFetch/.test(fs.readFileSync(__dirname+'/../public/js/text-messenger.js','utf8')));
 console.log('PASS client identity, stable avatars, photo sanitization, verified presence, navigation race, tabs, forwarding permissions, escaping, written-only transport');
})().catch(e=>{console.error(e);process.exitCode=1});
