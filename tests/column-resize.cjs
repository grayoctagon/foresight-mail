// Development-only browser regression. Synthetic client state; no login or server writes.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');

(async () => {
    const endpoint = process.argv[2] || 'http://localhost/foresight/';
    const target = new URL(endpoint);
    if (!['localhost', '127.0.0.1'].includes(target.hostname)) throw new Error('Use a local installation.');
    const browser = await chromium.launch({headless:true});
    const page = await browser.newPage({viewport:{width:1600,height:1000}});
    const errors = [], writes = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => {
        if (route.request().method() !== 'GET') { writes.push(route.request().url()); return route.abort(); }
        return route.continue();
    });
    try {
        await page.goto(endpoint);
        await page.locator('#authForm').waitFor();
        await page.evaluate(async () => {
            const root = document.body.dataset.basePath;
            const {state} = await import(root+'/assets/js/state.js');
            const {renderTable} = await import(root+'/assets/js/mail.js');
            state.preferences = {columns:['message','date','hasAttachments'],columnWidths:{},density:'comfortable',pageSize:100,favorites:[]};
            state.accounts = [{id:'resize-fixture',label:'Testpostfach'}];
            state.messages = [{id:'fixture',accountId:'resize-fixture',from:{name:'Absender '.repeat(50),email:'test@example.test'},subject:'Betreff '.repeat(100),snippet:'Zitat: "Hallo" & <b>Text</b> '+'SehrLangesSnippetOhneLeerzeichen'.repeat(100),date:'2026-09-28T10:00:00Z',flags:{seen:false},contentState:'available',hasAttachments:false}];
            state.total = 1;
            document.querySelector('#authView').hidden = true;
            document.querySelector('#app').hidden = false;
            document.documentElement.style.setProperty('--sidebar-w','150px');
            document.documentElement.style.setProperty('--list-w','1100px');
            window.resizeFixture = {state,renderTable};
            renderTable();
        });
        const width = () => page.locator('.message-cell').evaluate(el=>el.getBoundingClientRect().width);
        const within = (actual,expected,label) => assert(Math.abs(actual-expected)<2,`${label}: ${actual}px, expected ${expected}px`);
        within(await width(),310,'Long snippet does not widen the initial column');
        assert.equal(await page.locator('.snippet').getAttribute('title'),await page.evaluate(()=>window.resizeFixture.state.messages[0].snippet),'Tooltip contains the entire snippet including special characters');
        assert.equal(await page.locator('.snippet').textContent(),await page.locator('.snippet').getAttribute('title'));
        assert.equal(await page.locator('.snippet > *').count(),0,'Snippet and tooltip remain escaped plain text');
        const handle = page.locator('[data-resize="message"]');
        const box = await handle.boundingBox();
        await page.mouse.move(box.x+box.width/2,box.y+box.height/2);
        await page.mouse.down();
        await page.mouse.move(box.x+box.width/2-160,box.y+box.height/2,{steps:8});
        within(await width(),150,'Column shrinks while dragging');
        await page.mouse.up();
        const clipped = await page.locator('.snippet').evaluate(el=>({client:el.clientWidth,scroll:el.scrollWidth,overflow:getComputedStyle(el).overflow,textOverflow:getComputedStyle(el).textOverflow,whiteSpace:getComputedStyle(el).whiteSpace}));
        assert(clipped.scroll>clipped.client,'Snippet is actually truncated');
        assert.equal(clipped.overflow,'hidden');assert.equal(clipped.textOverflow,'ellipsis');assert.equal(clipped.whiteSpace,'nowrap');
        await page.evaluate(()=>window.resizeFixture.renderTable());
        within(await width(),150,'Selected width survives rerender');
        await page.evaluate(()=>{const {state,renderTable}=window.resizeFixture;state.preferences.density='compact';renderTable();});
        within(await width(),150,'Compact density preserves selected width');
        await page.evaluate(()=>{const {state,renderTable}=window.resizeFixture;state.preferences.columns=['message'];state.preferences.density='comfortable';renderTable();});
        within(await width(),150,'Column stays narrow even when other columns are hidden');
        await page.evaluate(()=>{const {state,renderTable}=window.resizeFixture;state.preferences.columnWidths.message=30;renderTable();});
        within(await width(),30,'Minimum resize width remains possible');
        const formatCases = [[0,'0B'],[999,'999B'],[1023,'1023B'],[1024,'1KB'],[1372,'1.34KB'],[10240,'10KB'],[1258291,'1.2MB'],[1610612736,'1.5GB'],[1099511627776,'1TB'],[1125899906842624,'1PB']];
        const formatted = await page.evaluate(async cases=>{
            const {fmtSize}=await import(document.body.dataset.basePath+'/assets/js/ui.js');
            return cases.map(([bytes])=>fmtSize(bytes));
        },formatCases);
        assert.deepEqual(formatted,formatCases.map(([,text])=>text));
        await page.evaluate(()=>{
            const {state,renderTable}=window.resizeFixture;
            Object.assign(state.messages[0],{
                bytes:1372,
                from:{name:'Sender " & <b>Name</b>',email:'sender@example.test'},
                to:[{name:'Erster Empfänger',email:'first@example.test'},{name:'',email:'second@example.test'}],
                cc:[{name:'Team',email:'team@example.test'}],
            });
            renderTable();
        });
        await page.locator('#columnsBtn').click();
        for(const [key,label] of [['bytes','Größe'],['from','Von'],['to','An'],['cc','Cc']]) {
            const option=page.locator('#columnsMenu').getByLabel(label,{exact:true});
            assert(!await option.isChecked(),'Additional column is optional: '+key);
            await option.check();
            assert(await page.locator('td[data-column="'+key+'"]').isVisible());
        }
        const sizeCell=page.locator('td[data-column="bytes"]');
        assert.equal(await sizeCell.textContent(),'1.34KB');
        assert.equal(await sizeCell.locator('span').getAttribute('title'),'1372 Bytes');
        for(const [key,expected] of [['from','Sender " & <b>Name</b> <sender@example.test>'],['to','Erster Empfänger <first@example.test>; second@example.test'],['cc','Team <team@example.test>']]) {
            const content=page.locator('td[data-column="'+key+'"] .address-cell-text');
            assert.equal(await content.textContent(),expected);
            assert.equal(await content.getAttribute('title'),expected);
            assert.equal(await content.locator('*').count(),0,'Addresses stay escaped');
        }
        await page.locator('#columnsMenu').getByLabel('Cc',{exact:true}).uncheck();
        assert.equal(await page.locator('td[data-column="cc"]').count(),0);
        await page.locator('#columnsMenu').getByLabel('Cc',{exact:true}).check();
        await page.locator('#columnsBtn').click();
        const sortRequests=[];
        const message=await page.evaluate(()=>window.resizeFixture.state.messages[0]);
        await page.route('**/api.php/webmail/messages?*',route=>{
            sortRequests.push(Object.fromEntries(new URL(route.request().url()).searchParams));
            return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,data:{messages:[message],total:1,nextCursor:null,indexing:[]},error:null})});
        });
        await page.evaluate(()=>window.resizeFixture.state.scopes=['resize-fixture~inbox']);
        await page.locator('th[data-sort="bytes"]').click({position:{x:15,y:12}});
        await page.waitForFunction(()=>!window.resizeFixture.state.loading);
        assert.equal(sortRequests.at(-1).sort,'bytes');
        assert.equal(sortRequests.at(-1).direction,'desc');
        await page.locator('th[data-sort="bytes"]').click({position:{x:15,y:12}});
        await page.waitForFunction(()=>!window.resizeFixture.state.loading);
        assert.equal(sortRequests.at(-1).sort,'bytes');
        assert.equal(sortRequests.at(-1).direction,'asc');
        assert.equal(await sizeCell.locator('span').getAttribute('title'),'1372 Bytes');
        await page.evaluate(()=>{
            const {state,renderTable}=window.resizeFixture;
            state.preferences.columnOrder=undefined;
            state.preferences.columns=['message','date','bytes','from','to','cc'];
            state.preferences.columnWidths={message:180,from:180,to:180,cc:180};
            state.checked.add('resize-fixture/fixture');
            renderTable();
        });
        const headerOrder=()=>page.locator('#tableHead [data-column-key]').evaluateAll(els=>els.map(el=>el.dataset.columnKey));
        const menuOrder=()=>page.locator('#columnsMenu [data-column-key]').evaluateAll(els=>els.map(el=>el.dataset.columnKey));
        const bodyOrder=()=>page.locator('tr.msg-row').first().locator('td[data-column]').evaluateAll(els=>els.map(el=>el.dataset.column));
        const beforeDragRequests=sortRequests.length;
        await page.locator('#tableHead [data-column-key="bytes"]').dragTo(page.locator('#tableHead [data-column-key="message"]'),{targetPosition:{x:8,y:12}});
        assert.deepEqual(await headerOrder(),['bytes','message','date','from','to','cc'],'Header drag moves before target');
        assert.deepEqual(await bodyOrder(),await headerOrder(),'Body follows header order');
        assert.equal((await menuOrder())[0],'bytes','Menu follows header order');
        within(await width(),180,'Width follows moved column');
        assert(await page.locator('[data-message-check]').isChecked(),'Selection preserved when reordering');
        const toBox=await page.locator('#tableHead [data-column-key="to"]').boundingBox();
        await page.locator('#tableHead [data-column-key="bytes"]').dragTo(page.locator('#tableHead [data-column-key="to"]'),{targetPosition:{x:toBox.width-10,y:12}});
        assert.deepEqual(await headerOrder(),['message','date','from','to','bytes','cc'],'Header drag moves after target');
        assert.equal(sortRequests.length,beforeDragRequests,'Dragging headers does not sort or reload');
        await page.locator('#columnsBtn').click();
        await page.locator('#columnsMenu [data-column-key="report"] .column-grip').dragTo(page.locator('#columnsMenu [data-column-key="message"]'),{targetPosition:{x:20,y:3}});
        assert.equal((await menuOrder())[0],'report','Hidden columns can be reordered in menu');
        assert.deepEqual(await headerOrder(),['message','date','from','to','bytes','cc'],'Moving a hidden column leaves visible order intact');
        await page.locator('#columnsMenu').getByLabel('Melden',{exact:true}).check();
        assert.equal((await headerOrder())[0],'report','Enabling column retains chosen position');
        await page.locator('#columnsMenu').getByLabel('Melden',{exact:true}).uncheck();
        const beforeArrow=await menuOrder();
        assert(await page.getByRole('button',{name:'Melden nach oben',exact:true}).isDisabled());
        await page.getByRole('button',{name:'Melden nach unten',exact:true}).click();
        assert(await page.locator('#columnsMenu').isVisible(),'Menu remains open after clicking arrow');
        assert.equal((await menuOrder())[1],'report','Down arrow moves one position');
        await page.getByRole('button',{name:'Melden nach oben',exact:true}).focus();
        await page.keyboard.press('Enter');
        assert.deepEqual(await menuOrder(),beforeArrow,'Up arrow supports keyboard activation');
        assert(await page.locator('#columnsMenu .column-option').last().locator('[data-column-step="1"]').isDisabled());
        assert(await page.locator('#columnsMenu').getByLabel('Nachricht',{exact:true}).isDisabled());
        await page.locator('#columnsBtn').click();
        const orderBeforeResize=await headerOrder();
        const movedHandle=await page.locator('[data-resize="message"]').boundingBox();
        await page.mouse.move(movedHandle.x+movedHandle.width/2,movedHandle.y+12);await page.mouse.down();
        await page.mouse.move(movedHandle.x+movedHandle.width/2-40,movedHandle.y+12,{steps:5});await page.mouse.up();
        within(await width(),140,'Resizing still works after reordering');
        assert.deepEqual(await headerOrder(),orderBeforeResize,'Resizing does not start a reorder');
        assert.equal(sortRequests.length,beforeDragRequests,'Menu reordering and resize do not request messages');
        await page.evaluate(()=>window.resizeFixture.renderTable());
        assert.deepEqual(await headerOrder(),orderBeforeResize,'Order survives table rerender');
        assert.deepEqual(await menuOrder(),beforeArrow,'Hidden-column positions survive rerender');
        // The short suppression window only blocks the synthetic click immediately after a drag.
        await page.waitForTimeout(275);
        await page.locator('th[data-sort="bytes"]').click({position:{x:15,y:12}});
        await page.waitForFunction(()=>!window.resizeFixture.state.loading);
        assert.equal(sortRequests.length,beforeDragRequests+1,'Normal header clicks still sort after drag');
        assert.equal(sortRequests.at(-1).sort,'bytes');
        await page.evaluate(()=>{
            const {state,renderTable}=window.resizeFixture;
            state.preferences.columns=['message','bytes','answerBy'];
            state.preferences.columnWidths={message:40,bytes:45,answerBy:55};
            renderTable();
        });
        for(const key of ['message','bytes','answerBy']) {
            const heading=page.locator('#tableHead [data-column-key="'+key+'"]');
            const clippedTitle=await heading.evaluate(th=>{
                const label=th.querySelector('.column-title'), style=getComputedStyle(label), bounds=label.getBoundingClientRect(), cell=th.getBoundingClientRect();
                return {width:label.clientWidth,scrollWidth:label.scrollWidth,overflow:style.overflow,textOverflow:style.textOverflow,whiteSpace:style.whiteSpace,inside:bounds.left>=cell.left&&bounds.right<=cell.right,title:label.title,text:label.textContent};
            });
            assert(clippedTitle.scrollWidth>clippedTitle.width,'Narrow column title is actually clipped: '+key);
            assert.equal(clippedTitle.overflow,'hidden');assert.equal(clippedTitle.textOverflow,'ellipsis');assert.equal(clippedTitle.whiteSpace,'nowrap');
            assert(clippedTitle.inside,'Title stays within its own column: '+key);
            assert.equal(clippedTitle.title,clippedTitle.text,'Full column title remains available as tooltip');
        }
        assert(await page.locator('#tableHead [data-column-key="bytes"]').evaluate(th=>{
            const arrow=th.querySelector('.sort-ind').getBoundingClientRect(), cell=th.getBoundingClientRect();
            return arrow.width>0&&arrow.left>=cell.left&&arrow.right<=cell.right;
        }),'Sort indicator stays visible within a narrow header');
        const narrowHandle=await page.locator('[data-resize="answerBy"]').boundingBox();
        await page.mouse.move(narrowHandle.x+narrowHandle.width/2,narrowHandle.y+12);await page.mouse.down();
        await page.mouse.move(narrowHandle.x+narrowHandle.width/2+50,narrowHandle.y+12,{steps:5});await page.mouse.up();
        within((await page.locator('#tableHead [data-column-key="answerBy"]').boundingBox()).width,105,'Ellipsis does not clip the resize handle');
        assert.deepEqual(errors,[]);assert.deepEqual(writes,[]);
        console.log('ok - Long snippets, escaped full-snippet tooltip, live resize, ellipsis, rerender, compact density, hidden columns, minimum width; no server writes');
        console.log('ok - Optional size/from/to/cc columns, compact units, byte tooltip, escaped multiple addresses, numeric sort requests');
        console.log('ok - Header/menu drag-and-drop, up/down buttons and keyboard, hidden positions, selection/width retention, no accidental sorting');
        console.log('ok - Header title ellipsis, full-title tooltip, contained sort indicator and usable resize handles');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exit(1);});
