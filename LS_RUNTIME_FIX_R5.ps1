$ErrorActionPreference = 'Stop'

Write-Host "" 
Write-Host "LS Runtime 2.1 R5 - Correcao da tela preta / rota login" -ForegroundColor Cyan
Write-Host "" 

$root = 'C:\LSRuntime21'
$desktop = Join-Path $root 'generated\NAA\desktop'
$pkgPath = Join-Path $desktop 'package.json'

if (!(Test-Path $desktop)) { throw "Nao encontrei $desktop" }
if (!(Test-Path $pkgPath)) { throw "Nao encontrei $pkgPath" }

Get-Process NAA -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue

$pkg = Get-Content $pkgPath -Raw | ConvertFrom-Json
$mainRel = $pkg.main
if ([string]::IsNullOrWhiteSpace($mainRel)) { $mainRel = 'main.js' }
$mainPath = Join-Path $desktop $mainRel
if (!(Test-Path $mainPath)) { throw "Nao encontrei o arquivo principal do Electron: $mainPath" }

$bridgePath = Join-Path $desktop 'ls-local-http.cjs'
$bridge = @'
const { app, BrowserWindow, shell } = require('electron');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');

const REMOTE = 'https://naa.lssystem.com.br/';
let localBase = null;
let serverPromise = null;
let primaryWindow = null;

function log(msg) {
  try {
    const f = path.join(app.getPath('userData'), 'ls-runtime-r5.log');
    fs.appendFileSync(f, `[${new Date().toISOString()}] ${msg}\n`, 'utf8');
  } catch {}
}

function findWWW() {
  const candidates = [
    path.join(__dirname, 'prepack', 'www'),
    path.join(__dirname, 'www'),
    path.join(process.resourcesPath || '', 'app.asar', 'prepack', 'www'),
    path.join(process.resourcesPath || '', 'app.asar.unpacked', 'prepack', 'www'),
    path.join(process.resourcesPath || '', 'prepack', 'www')
  ];
  for (const dir of candidates) {
    try {
      if (dir && fs.existsSync(path.join(dir, 'index.html'))) return dir;
    } catch {}
  }
  throw new Error('LS Runtime R5: index.html offline nao encontrado.');
}

const MIME = {
  '.html':'text/html; charset=utf-8', '.htm':'text/html; charset=utf-8',
  '.js':'text/javascript; charset=utf-8', '.mjs':'text/javascript; charset=utf-8',
  '.css':'text/css; charset=utf-8', '.json':'application/json; charset=utf-8',
  '.webmanifest':'application/manifest+json; charset=utf-8',
  '.png':'image/png', '.jpg':'image/jpeg', '.jpeg':'image/jpeg', '.svg':'image/svg+xml',
  '.ico':'image/x-icon', '.woff':'font/woff', '.woff2':'font/woff2', '.ttf':'font/ttf',
  '.csv':'text/csv; charset=utf-8', '.txt':'text/plain; charset=utf-8', '.pdf':'application/pdf'
};

function injectBridge(html) {
  const script = `<script data-ls-runtime-r5>(function(){
    const REMOTE='https://naa.lssystem.com.br';
    function map(raw){
      try{
        const s=String(raw||'');
        if(s.startsWith(REMOTE+'/api/')||s.startsWith(REMOTE+'/uploads/')){
          const u=new URL(s); return location.origin+u.pathname+u.search+u.hash;
        }
      }catch(e){}
      return raw;
    }
    if(window.fetch){
      const f=window.fetch.bind(window);
      window.fetch=function(input,init){
        try{
          if(typeof input==='string'||input instanceof URL){ input=map(input); }
          else if(input instanceof Request){ const m=map(input.url); if(m!==input.url) input=new Request(m,input); }
        }catch(e){}
        return f(input,init);
      };
    }
    const xo=XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open=function(method,url){ const a=Array.from(arguments); a[1]=map(url); return xo.apply(this,a); };
    window.__LS_RUNTIME__=Object.assign(window.__LS_RUNTIME__||{}, {mode:'desktop-http',remote:REMOTE,version:'2.1-R5'});
  })();</script>`;
  if (html.includes('data-ls-runtime-r5')) return html;
  if (/<head[^>]*>/i.test(html)) return html.replace(/<head([^>]*)>/i, `<head$1>${script}`);
  return script + html;
}

async function readBody(req) {
  const chunks=[];
  for await (const c of req) chunks.push(Buffer.from(c));
  return Buffer.concat(chunks);
}

async function proxy(req,res,target) {
  try {
    const headers={};
    for (const [k,v] of Object.entries(req.headers||{})) {
      const key=k.toLowerCase();
      if (['host','connection','content-length','accept-encoding'].includes(key)) continue;
      if (v!==undefined) headers[k]=v;
    }
    const method=(req.method||'GET').toUpperCase();
    const body=['GET','HEAD'].includes(method) ? undefined : await readBody(req);
    const r=await fetch(target,{method,headers,body,redirect:'manual'});
    const out={};
    r.headers.forEach((v,k)=>{
      const key=k.toLowerCase();
      if (!['content-length','content-encoding','transfer-encoding','connection','set-cookie'].includes(key)) out[k]=v;
    });
    const loc=r.headers.get('location');
    if (loc) {
      try {
        const u=new URL(loc,REMOTE);
        if (u.origin===new URL(REMOTE).origin) out.location=u.pathname+u.search+u.hash;
        else out.location=loc;
      } catch { out.location=loc; }
    }
    const data=Buffer.from(await r.arrayBuffer());
    res.writeHead(r.status,out); res.end(data);
  } catch (e) {
    log(`proxy ${target}: ${e.stack||e}`);
    res.writeHead(503,{'content-type':'application/json; charset=utf-8'});
    res.end(JSON.stringify({ok:false,offline:true,error:'Servidor NAA indisponivel no momento.'}));
  }
}

function safePath(root,pathname) {
  let p=pathname;
  try { p=decodeURIComponent(p); } catch {}
  p=p.replace(/\\/g,'/').replace(/^\/+/, '');
  const resolved=path.resolve(root,p||'index.html');
  const rr=path.resolve(root);
  if (resolved!==rr && !resolved.startsWith(rr+path.sep)) return null;
  return resolved;
}

function startServer() {
  if (serverPromise) return serverPromise;
  serverPromise=new Promise((resolve,reject)=>{
    let www;
    try { www=findWWW(); } catch(e) { reject(e); return; }
    const server=http.createServer(async (req,res)=>{
      let u;
      try { u=new URL(req.url||'/',`http://${req.headers.host||'127.0.0.1'}`); }
      catch { res.writeHead(400); res.end('Bad request'); return; }

      if (u.pathname==='/api' || u.pathname.startsWith('/api/') || u.pathname.startsWith('/uploads/')) {
        return proxy(req,res,new URL(u.pathname+u.search,REMOTE).toString());
      }

      let file=safePath(www,u.pathname);
      try { if (file && fs.existsSync(file) && fs.statSync(file).isDirectory()) file=path.join(file,'index.html'); } catch {}

      try {
        if (file && fs.existsSync(file) && fs.statSync(file).isFile()) {
          const ext=path.extname(file).toLowerCase();
          let data=fs.readFileSync(file);
          if (ext==='.html'||ext==='.htm') data=Buffer.from(injectBridge(data.toString('utf8')),'utf8');
          res.writeHead(200,{'content-type':MIME[ext]||'application/octet-stream','cache-control':'no-cache'});
          res.end(data); return;
        }
      } catch(e) { log(`local ${file}: ${e.stack||e}`); }

      // Rota SPA como /login, /dashboard, etc.
      if (!path.extname(u.pathname)) {
        try {
          const html=injectBridge(fs.readFileSync(path.join(www,'index.html'),'utf8'));
          res.writeHead(200,{'content-type':'text/html; charset=utf-8','cache-control':'no-cache'});
          res.end(html); return;
        } catch(e) { log(`spa: ${e.stack||e}`); }
      }

      return proxy(req,res,new URL(u.pathname+u.search,REMOTE).toString());
    });
    server.on('error',reject);
    server.listen(0,'127.0.0.1',()=>{
      const a=server.address();
      localBase=`http://127.0.0.1:${a.port}/`;
      log(`R5 local=${localBase} remote=${REMOTE} www=${www}`);
      resolve(localBase);
    });
  });
  return serverPromise;
}

async function routeWindow(win) {
  if (!win || win.isDestroyed()) return;
  try {
    const base=await startServer();
    if (win.__lsRuntimeR5) return;
    win.__lsRuntimeR5=true;

    win.webContents.on('will-navigate',(e,url)=>{
      try {
        const target=new URL(url);
        const remote=new URL(REMOTE);
        if (target.origin===remote.origin) {
          e.preventDefault();
          const local=new URL(target.pathname+target.search+target.hash,base).toString();
          win.loadURL(local);
        }
      } catch {}
    });

    win.webContents.setWindowOpenHandler(({url})=>{
      try {
        const target=new URL(url);
        const remote=new URL(REMOTE);
        if (target.origin===remote.origin) {
          win.loadURL(new URL(target.pathname+target.search+target.hash,base).toString());
          return {action:'deny'};
        }
      } catch {}
      try { shell.openExternal(url); } catch {}
      return {action:'deny'};
    });

    await win.loadURL(base);
  } catch(e) { log(`routeWindow: ${e.stack||e}`); }
}

app.on('browser-window-created',(_e,win)=>{
  if (!primaryWindow) primaryWindow=win;
  win.webContents.on('did-fail-load',(_ev,code,desc,url)=>log(`did-fail-load ${code} ${desc} ${url}`));
  setTimeout(()=>routeWindow(win),250);
});

app.whenReady().then(()=>{
  if (!primaryWindow) primaryWindow=BrowserWindow.getAllWindows()[0]||null;
  if (primaryWindow) setTimeout(()=>routeWindow(primaryWindow),100);
}).catch(e=>log(String(e)));
'@

Set-Content -Path $bridgePath -Value $bridge -Encoding UTF8

$mainSource = Get-Content $mainPath -Raw
$marker = "require('./ls-local-http.cjs'); // LS_RUNTIME_R5"
if ($mainSource -notmatch 'LS_RUNTIME_R5') {
  Copy-Item $mainPath "$mainPath.pre-r5.bak" -Force
  Set-Content -Path $mainPath -Value ($marker + "`r`n" + $mainSource) -Encoding UTF8
  Write-Host "Bridge R5 conectado ao arquivo principal." -ForegroundColor Green
} else {
  Write-Host "R5 ja estava conectado ao arquivo principal." -ForegroundColor Yellow
}

if ($pkg.build -and $pkg.build.files) {
  $files = @($pkg.build.files)
  if ($files -notcontains 'ls-local-http.cjs') {
    $pkg.build.files = @($files + 'ls-local-http.cjs')
    $pkg | ConvertTo-Json -Depth 100 | Set-Content $pkgPath -Encoding UTF8
  }
}

Set-Location $desktop
if (!(Test-Path '.\brand\build.json')) { throw 'Nao encontrei brand\build.json' }
if (!(Test-Path '.\tools\build-branded.js')) { throw 'Nao encontrei tools\build-branded.js' }

if (Test-Path '.\dist') { Remove-Item '.\dist' -Recurse -Force }

Write-Host "" 
Write-Host "Recompilando NAA Windows R5..." -ForegroundColor Cyan
node .\tools\build-branded.js .\brand\build.json win
if ($LASTEXITCODE -ne 0) { throw "Build falhou. Codigo $LASTEXITCODE" }
if (!(Test-Path '.\dist\win-unpacked\NAA.exe')) { throw 'Nao encontrei dist\win-unpacked\NAA.exe' }

Write-Host "" 
Write-Host "R5 GERADO COM SUCESSO" -ForegroundColor Green
Write-Host "Teste primeiro: C:\LSRuntime21\generated\NAA\desktop\dist\win-unpacked\NAA.exe" -ForegroundColor Green
Write-Host "Depois use o NAA-Setup-2.1.0-x64.exe se o login abrir normalmente." -ForegroundColor Green
Start-Process explorer.exe (Resolve-Path '.\dist').Path
