const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const admin = readFileSync('assets/js/admin.js','utf8');
for (const [action,connect] of [['test',false],['connect',false],['ping',false],['connect',true],['reconnect',true]]) {
 let button = {classList:{contains:()=>connect},dataset:{postUrl:'https://shop.example/wp-admin/admin-post.php',do:action}, addEventListener:(_,fn)=>button.click=fn};
 let form;
 const document={querySelectorAll:()=>[button],getElementById:id=>({value:id}),createElement:tag=>tag==='form'?(form={children:[],appendChild(x){this.children.push(x)},submit(){this.sent=true}}):{},body:{appendChild(){}}};
 vm.runInNewContext(admin,{document});button.click();assert.equal(form.sent,true);assert.equal(form.action,button.dataset.postUrl);
 const fields=Object.fromEntries(form.children.map(x=>[x.name,x.value]));
 assert.equal(fields.action,connect?'finlinks_wc_connect':'finlinks_wc_connection');assert.equal(fields.finlinks_do,action);assert.equal(fields._wpnonce,connect?'_finlinks_connect_nonce':'_finlinks_nonce');
}
const script=readFileSync('assets/js/confirmation.js','utf8');
for (const notice of [null,{dataset:{finlinksNext:'12',finlinksDelay:'10000'}}]) {
 let timer,redirect;
 const window={location:{href:'https://shop.example/checkout/order-received/123/?key=order-token&finlinks_wait=11',replace:u=>redirect=u},setTimeout:(fn,ms)=>timer={fn,ms}};
 vm.runInNewContext(script,{document:{querySelector:()=>notice},window,URL});
 if (!notice) {assert.equal(timer,undefined);} else {assert.equal(timer.ms,10000);timer.fn();const u=new URL(redirect);assert.equal(u.searchParams.get('finlinks_wait'),'12');assert.equal(u.searchParams.get('key'),'order-token');}
}
console.log('PASS: five admin button POST routes/nonces and confirmation URL/timer behavior');
