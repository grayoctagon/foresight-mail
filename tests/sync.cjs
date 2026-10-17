// Browser timing is virtual; no production mailbox or IMAP connection is touched.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
(async()=>{
    const endpoint=process.argv[2];
    if(!endpoint || !/^http:\/\/127\.0\.0\.1:\d+(?:\/[A-Za-z0-9_-]+)*\/?$/.test(endpoint))throw new Error('Use the isolated PHP fixture URL.');
    const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
    const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];
    page.on('pageerror',e=>errors.push(e.message));
    let calls=0, status=200, answer={job:null,reason:'manual',syncing:false,retryAfterSeconds:300,nextDueAt:null};
    let statusCalls=0, statusOverride, holdNext=false, releaseCheck=null;
    const route='**/api.php/accounts/*/sync/check';
    const login=async name=>{
        await page.getByLabel('Benutzername',{exact:true}).fill(name);
        await page.getByLabel('Passwort',{exact:true}).fill('fixture-password-1234');
        await page.getByRole('button',{name:'Anmelden',exact:true}).click();
        await page.locator('#app').waitFor({state:'visible'});
    };
    const count=async n=>{await page.waitForFunction(expected=>window.__syncChecks===expected,n);};
    // Mirror intercepted requests into the page so asynchronous timer completion can be awaited.
    await page.route(route,async r=>{
        calls++;
        if(holdNext) {
            holdNext=false;await page.evaluate(()=>window.__heldCheck=true);
            await new Promise(resolve=>{releaseCheck=resolve;});
        }
        await r.fulfill({status,contentType:'application/json',body:JSON.stringify(status===200?{ok:true,data:answer,error:null}:{ok:false,data:null,error:{code:'TEST_UNAVAILABLE',message:'Testserver vorübergehend nicht erreichbar.'}})});
        await page.evaluate(n=>window.__syncChecks=n,calls);
    });
    await page.route('**/api.php/accounts/*/sync/status',async r=>{
        assert.equal(r.request().method(),'GET','Fast polling uses only the read-only status endpoint');
        statusCalls++;
        await r.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,data:{job:statusOverride??answer.job},error:null})});
        await page.evaluate(n=>window.__statusChecks=n,statusCalls);
    });
    try {
        await page.goto(endpoint);await login('alice');await page.locator('tr.msg-row').first().waitFor();
        await page.evaluate(async()=>{const root=document.body.dataset.basePath;const {stopAutoSync}=await import(root+'/assets/js/sync.js');stopAutoSync();});
        calls=statusCalls=0;await page.evaluate(()=>{window.__syncChecks=0;window.__statusChecks=0;});
        await page.clock.install();
        await page.evaluate(async()=>{const root=document.body.dataset.basePath;const {startAutoSync}=await import(root+'/assets/js/sync.js');startAutoSync();});
        await page.clock.runFor(1000);await count(1);await page.clock.runFor(10);
        await page.clock.runFor(299000);assert.equal(calls,1,'Idle polling waits five minutes');
        await page.clock.runFor(1100);await count(2);await page.clock.runFor(10);
        assert.equal(statusCalls,0,'Idle accounts do not poll status every five seconds');

        answer={job:{job_id:'synthetic-auto-job',state:'running',trigger:'automatic',phase_label:'Lädt Nachrichteninhalt',current:1,total:5,progress_percent:20},reason:'running',syncing:true,retryAfterSeconds:60,nextDueAt:null};
        await page.clock.runFor(300100);await count(3);await page.clock.runFor(10);
        assert.match(await page.locator('#syncBanner').innerText(),/Status alle 5 Sekunden/);
        await page.clock.runFor(5100);await page.waitForFunction(()=>window.__statusChecks>=1);await page.clock.runFor(100);
        assert.equal(calls,3,'Five-second status reads do not trigger additional work');
        statusOverride={...answer.job,current:3,progress_percent:60};
        await page.clock.runFor(5100);await page.waitForFunction(()=>window.__statusChecks>=2);await page.clock.runFor(100);
        assert.match(await page.locator('#syncBanner').innerText(),/3\/5/,'Read-only status updates refresh the visible progress');
        await page.clock.runFor(48000);assert.equal(calls,3,'Running work still waits one minute');
        await page.clock.runFor(2100);await count(4);await page.clock.runFor(10);statusOverride=undefined;
        answer={...answer,job:{...answer.job,state:'completed',progress_percent:100},reason:'completed',syncing:false,retryAfterSeconds:300};
        await page.clock.runFor(60100);await count(5);await page.clock.runFor(100);
        await page.clock.runFor(299000);assert.equal(calls,5,'Completed work returns to five-minute polling');
        await page.clock.runFor(1100);await count(6);await page.clock.runFor(100);

        answer={job:null,reason:'busy',syncing:true,retryAfterSeconds:60};
        await page.clock.runFor(300100);await count(7);await page.clock.runFor(100);
        assert.match(await page.locator('#syncBanner').innerText(),/Postfach wird bereits synchronisiert.*Status alle 5 Sekunden/s);
        status=503;await page.clock.runFor(60100);await count(8);await page.clock.runFor(100);
        assert.match(await page.locator('#syncBanner.error').innerText(),/Testserver.*5 Minuten/s);
        await page.clock.runFor(299000);assert.equal(calls,8,'API errors do not create tight retry loops');
        status=200;answer={job:null,reason:'manual',syncing:false,retryAfterSeconds:300};
        await page.clock.runFor(1100);await count(9);await page.clock.runFor(100);
        assert(await page.locator('#syncBanner').isHidden(),'Recovered/manual accounts clear automatic banners');

        // Status reads must continue while a work POST itself is still in flight.
        answer={job:{job_id:'held-work',state:'running',trigger:'automatic',phase_label:'Lädt Nachrichteninhalt',current:1,total:5},reason:'running',syncing:true,retryAfterSeconds:60};
        await page.evaluate(async()=>{const {startAutoSync}=await import(document.body.dataset.basePath+'/assets/js/sync.js');startAutoSync();});
        await page.clock.runFor(1100);await count(10);await page.clock.runFor(100);
        holdNext=true;await page.clock.runFor(60100);await page.waitForFunction(()=>window.__heldCheck);
        const statusBefore=statusCalls;
        await page.clock.runFor(5100);await page.waitForFunction(n=>window.__statusChecks>n,statusBefore);
        assert.equal(calls,11,'Status polling never sends a second work POST while one is running');
        answer={...answer,job:{...answer.job,state:'completed'},reason:'completed',syncing:false,retryAfterSeconds:300};
        releaseCheck();await count(11);await page.clock.runFor(100);

        // Real account form round-trip; only scheduler work itself stays intercepted.
        await page.locator('#settingsBtn').click();await page.locator('[data-settings-account]').first().click();
        const intervals=page.getByLabel('Automatisch synchronisieren',{exact:true});
        assert.deepEqual(await intervals.locator('option').evaluateAll(options=>options.map(o=>Number(o.value))),[0,15,30,60,120,240,360,480,720,1440]);
        await intervals.selectOption('120');
        const save=page.waitForResponse(r=>/\/api.php\/accounts\/[^/]+$/.test(r.url())&&r.request().method()==='PUT');
        await page.getByRole('button',{name:'Einstellungen speichern',exact:true}).click();
        assert.equal((await(await save).json()).data.account.sync.interval_minutes,120);
        await page.waitForFunction(()=>document.querySelector('[name="sync.interval_minutes"]')?.value==='120'&&!document.querySelector('#saveAccount')?.disabled);
        assert.match(await page.locator('#accountForm').innerText(),/Ohne geöffneten Browser/);
        await intervals.selectOption('0');
        const reset=page.waitForResponse(r=>/\/api.php\/accounts\/[^/]+$/.test(r.url())&&r.request().method()==='PUT');
        await page.getByRole('button',{name:'Einstellungen speichern',exact:true}).click();await reset;
        await page.waitForFunction(()=>!document.querySelector('#saveAccount')?.disabled);
        await page.clock.runFor(1100);await page.clock.runFor(100);

        await page.route('**/api.php/accounts/*/sync/start',r=>r.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,data:{job:{job_id:'busy-manual',state:'queued',busy:true}}})}));
        const manualStart=page.waitForResponse(r=>r.url().endsWith('/sync/start'));
        await page.evaluate(async()=>{const root=document.body.dataset.basePath;const {runSync}=await import(root+'/assets/js/sync.js');const {state}=await import(root+'/assets/js/state.js');window.__manualSync=runSync(state.accounts[0].id);});
        await manualStart;await page.clock.runFor(100);
        await page.locator('#appMenuBtn').click();await page.locator('[data-action="logout"]').click();await page.locator('#authForm').waitFor();
        const loggedOutCalls=calls, loggedOutStatuses=statusCalls;await page.clock.runFor(600000);assert.equal(calls,loggedOutCalls,'Logout stops all automatic checks');
        assert.equal(statusCalls,loggedOutStatuses,'Logout also stops the five-second status timer');
        await login('bob');await page.clock.runFor(1100);await page.clock.runFor(100);
        assert.equal(calls,loggedOutCalls,'Second user without accounts does not poll previous accounts');
        assert.equal(statusCalls,loggedOutStatuses,'Second user cannot poll previous account statuses');
        const isolated=await page.evaluate(async()=>{await window.__manualSync;const {state}=await import(document.body.dataset.basePath+'/assets/js/state.js');return !state.syncing && state.jobs.size===0;});
        assert(isolated,'Busy manual sync from an old session cannot overwrite the next user state');
        assert.deepEqual(errors,[]);
        console.log('Browser sync intervals, separate 5sec read-only status / 1min work / 5min idle timers, concurrent status reads, busy/error recovery and session isolation passed');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
