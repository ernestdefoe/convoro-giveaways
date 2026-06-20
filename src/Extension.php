<?php

namespace Convoro\Ext\Giveaways;

use App\Models\User;
use App\Support\ExtensionManager;
use App\Support\ExtPage;
use Convoro\Ext\Giveaways\Models\Giveaway;
use Convoro\Ext\Giveaways\Models\GiveawayEntry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

/**
 * Giveaways — first-party Convoro extension.
 *
 * Members enter an active giveaway with one click; a commit–reveal seed makes
 * the draw provably fair (winner = lowest sha256(seed:user_id), recomputable by
 * anyone from the revealed seed + entrant list, with the seed pre-committed via
 * its hash). Ships a forum sidebar widget, a public giveaways page with a
 * fairness verifier, scheduled auto-draws and a themed admin manager.
 */
class Extension extends ServiceProvider
{
    private const ID = 'convoro-giveaways';

    public function boot(): void
    {
        $this->registerRoutes();

        // Auto-draw: run every minute via the host scheduler. The command is also
        // runnable by hand (`php artisan convoro:giveaways-draw`).
        if ($this->app->runningInConsole()) {
            $this->commands([DrawDueCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(DrawDueCommand::class)
                ->everyMinute()
                ->name('convoro-giveaways-draw')
                ->withoutOverlapping();
        });
    }

    private static function setting(string $key, mixed $default = null): mixed
    {
        return ExtensionManager::setting(self::ID, $key, $default);
    }

    private static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }

    // --- Routes ------------------------------------------------------------

    private function registerRoutes(): void
    {
        // Public API: the current active giveaway (+ whether the viewer entered).
        \Illuminate\Support\Facades\Route::middleware('web')->get('/api/ext/giveaways/active', function (Request $request) {
            // Opportunistic auto-draw so winners are picked on schedule even on
            // hosts without a cron-driven scheduler. perform() is transactional
            // and idempotent, so this is race-safe.
            Draw::drawDue();

            if (! self::setting('show_widget', true)) {
                return response()->json(['giveaway' => null]);
            }

            $g = Giveaway::where('active', true)->orderByDesc('id')->first();
            if (! $g) {
                return response()->json(['giveaway' => null]);
            }
            $entries = $g->entries()->count();
            $entered = $request->user()
                ? $g->entries()->where('user_id', $request->user()->id)->exists()
                : false;

            return response()->json(['giveaway' => [
                'id' => $g->id, 'title' => $g->title, 'prize' => $g->prize,
                'description' => $g->description, 'endsAt' => optional($g->ends_at)->toIso8601String(),
                'entries' => $entries, 'entered' => $entered,
                'authed' => (bool) $request->user(),
            ]]);
        });

        // Enter (auth).
        \Illuminate\Support\Facades\Route::middleware(['web', 'auth'])->post('/api/ext/giveaways/{id}/enter', function (Request $request, int $id) {
            $g = Giveaway::where('id', $id)->where('active', true)->first();
            abort_if(! $g, 404);
            if ($g->hasEnded()) {
                return response()->json(['ok' => false, 'message' => 'This giveaway has ended.'], 422);
            }
            GiveawayEntry::updateOrCreate(
                ['giveaway_id' => $id, 'user_id' => $request->user()->id],
                ['created_at' => now()],
            );

            return response()->json(['ok' => true, 'entries' => $g->entries()->count()]);
        });

        // Public pages: giveaways index + per-giveaway fairness verifier.
        \Illuminate\Support\Facades\Route::middleware('web')->group(function () {
            \Illuminate\Support\Facades\Route::get('/giveaways', fn () => self::indexPage());
            \Illuminate\Support\Facades\Route::get('/giveaways/{id}/verify', function (int $id) {
                $g = Giveaway::find($id);
                abort_if(! $g, 404);

                return self::verifyPage($g);
            });
        });

        // Admin manager + CRUD (admin-only via the admin area).
        \Illuminate\Support\Facades\Route::middleware(['web', 'auth', 'admin'])->prefix('admin/ext/giveaways')->group(function () {
            \Illuminate\Support\Facades\Route::get('/', fn () => self::adminPage());

            \Illuminate\Support\Facades\Route::get('/list', function () {
                return response()->json(Giveaway::orderByDesc('id')->get()->map(function (Giveaway $g) {
                    return [
                        'id' => $g->id, 'title' => $g->title, 'prize' => $g->prize,
                        'active' => $g->active, 'entries' => $g->entries()->count(),
                        'winner' => $g->winner_user_id ? optional(User::find($g->winner_user_id))->name : null,
                        // The commitment is always public; the raw seed is only
                        // revealed after the draw.
                        'seedHash' => $g->seed_hash,
                        'seed' => $g->drawn_at ? $g->seed : null,
                        'endsAt' => optional($g->ends_at)->toIso8601String(),
                        'drawnAt' => optional($g->drawn_at)->toIso8601String(),
                    ];
                }));
            });

            \Illuminate\Support\Facades\Route::post('/', function (Request $request) {
                $data = $request->validate([
                    'title' => ['required', 'string', 'max:160'],
                    'prize' => ['required', 'string', 'max:200'],
                    'description' => ['nullable', 'string', 'max:2000'],
                    'ends_at' => ['nullable', 'date'],
                ]);
                [$seed, $seedHash] = Draw::newSeed();
                $g = new Giveaway;
                $g->title = $data['title'];
                $g->prize = $data['prize'];
                $g->description = $data['description'] ?? null;
                $g->ends_at = $data['ends_at'] ?? null;
                $g->seed = $seed;
                $g->seed_hash = $seedHash;
                $g->active = true;
                $g->save();

                return response()->json(['id' => $g->id]);
            });

            \Illuminate\Support\Facades\Route::post('/{id}/draw', function (int $id) {
                $g = Giveaway::find($id);
                abort_if(! $g, 404);
                if ($g->drawn_at) {
                    return response()->json(['ok' => false, 'message' => 'Already drawn.'], 422);
                }
                $winnerId = Draw::perform($id);
                if ($winnerId === null) {
                    return response()->json(['ok' => false, 'message' => 'No entries to draw from.'], 422);
                }

                return response()->json(['ok' => true, 'winner' => optional(User::find($winnerId))->name]);
            });

            \Illuminate\Support\Facades\Route::post('/{id}/toggle', function (int $id) {
                $g = Giveaway::find($id);
                abort_if(! $g, 404);
                if ($g->drawn_at) {
                    return response()->json(['ok' => false, 'message' => 'A drawn giveaway can\'t be reopened.'], 422);
                }
                $g->active = ! $g->active;
                $g->save();

                return response()->json(['active' => $g->active]);
            });

            \Illuminate\Support\Facades\Route::delete('/{id}', function (int $id) {
                Giveaway::whereKey($id)->delete();

                return response()->json(['ok' => true]);
            });
        });
    }

    // --- Public pages ------------------------------------------------------

    private static function indexPage(): \Inertia\Response
    {
        $active = Giveaway::where('active', true)->orderByDesc('id')->first();
        $past = Giveaway::whereNotNull('drawn_at')->orderByDesc('drawn_at')->limit(20)->get();
        $names = User::whereIn('id', $past->pluck('winner_user_id')->filter()->unique())->pluck('name', 'id');

        $activeHtml = $active
            ? '<div class="gv-card gv-active">'
                .'<div class="gv-badge">🎁 Active giveaway</div>'
                .'<h2 class="gv-t">'.self::e($active->title).'</h2>'
                .'<div class="gv-prize">🏆 '.self::e($active->prize).'</div>'
                .($active->description ? '<p class="gv-desc">'.self::e($active->description).'</p>' : '')
                .'<div class="gv-meta">'.number_format($active->entries()->count()).' entries'
                .($active->ends_at ? ' · ends '.self::e($active->ends_at->toFormattedDayDateString()) : '').'</div>'
                .'<div class="gv-actions"><a class="gv-btn gv-btn-p" href="/giveaways/'.$active->id.'/verify">Verify fairness</a></div>'
                .'</div>'
            : '<div class="gv-blank"><div class="gv-blank-ico">🎁</div><div class="gv-blank-t">No active giveaway right now</div><p class="gv-muted">Check back soon.</p></div>';

        $rows = '';
        foreach ($past as $g) {
            $winner = $g->winner_user_id ? ($names[$g->winner_user_id] ?? 'Member') : '—';
            $rows .= '<a class="gv-row" href="/giveaways/'.$g->id.'/verify">'
                .'<div class="gv-row-b"><div class="gv-row-t">'.self::e($g->title).'</div>'
                .'<div class="gv-row-m">🏆 '.self::e($g->prize).' · won by <b>'.self::e($winner).'</b></div></div>'
                .'<span class="gv-verify">Verify →</span></a>';
        }
        $pastHtml = $past->count()
            ? '<section class="gv-sec"><h3 class="gv-h3">Past winners</h3><div class="gv-list">'.$rows.'</div></section>'
            : '';

        $body = '<div class="gv-wrap"><div class="gv-hero"><div class="gv-eyebrow">Giveaways</div>'
            .'<h1 class="gv-h1">Win prizes — provably fair</h1>'
            .'<p class="gv-sub">Enter with one click. Every draw is verifiable: the winning seed is committed up front and revealed at the draw, so anyone can recompute the winner.</p></div>'
            .$activeHtml.$pastHtml.'</div>';

        return ExtPage::render('Giveaways', $body, self::css());
    }

    private static function verifyPage(Giveaway $g): \Inertia\Response
    {
        $drawn = $g->drawn_at !== null;
        $ids = GiveawayEntry::where('giveaway_id', $g->id)->orderBy('user_id')->pluck('user_id')->all();
        $winnerName = $g->winner_user_id ? (optional(User::find($g->winner_user_id))->name ?? 'Member') : null;

        $commit = '<div class="gv-kv"><span class="gv-k">Commitment (published at creation)</span>'
            .'<code class="gv-code" id="gv-hash">'.self::e($g->seed_hash).'</code>'
            .'<span class="gv-note">sha256 of the secret seed</span></div>';

        if ($drawn) {
            $reveal = '<div class="gv-kv"><span class="gv-k">Revealed seed</span><code class="gv-code" id="gv-seed">'.self::e($g->seed).'</code></div>'
                .'<div class="gv-kv"><span class="gv-k">Entrants ('.count($ids).')</span><code class="gv-code" id="gv-ids">'.self::e(implode(',', $ids)).'</code></div>'
                .'<div class="gv-kv"><span class="gv-k">Recorded winner</span><span class="gv-win" id="gv-winner" data-id="'.(int) $g->winner_user_id.'">'.self::e($winnerName).' (user #'.(int) $g->winner_user_id.')</span></div>'
                .'<div class="gv-check" id="gv-check">Recomputing in your browser…</div>';
            $js = <<<JS
            async function h(s){const b=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(s));return [...new Uint8Array(b)].map(x=>x.toString(16).padStart(2,'0')).join('');}
            (async function(){
              var seed=document.getElementById('gv-seed').textContent.trim();
              var idsTxt=document.getElementById('gv-ids').textContent.trim();
              var ids=idsTxt?idsTxt.split(',').map(function(x){return x.trim();}).filter(Boolean):[];
              var recordedHash=document.getElementById('gv-hash').textContent.trim();
              var recordedWinner=document.getElementById('gv-winner').getAttribute('data-id');
              var check=document.getElementById('gv-check');
              var seedOk=(await h(seed))===recordedHash;
              var best=null,bestH=null;
              for(var i=0;i<ids.length;i++){var hv=await h(seed+':'+ids[i]);if(bestH===null||hv<bestH){bestH=hv;best=ids[i];}}
              var winnerOk=String(best)===String(recordedWinner);
              if(seedOk&&winnerOk){check.className='gv-check gv-ok';check.textContent='✓ Verified — sha256(seed) matches the commitment, and the winner is the entrant with the lowest sha256(seed:id).';}
              else{check.className='gv-check gv-bad';check.textContent='✗ Verification failed'+(seedOk?'':' — seed does not match the published commitment')+(winnerOk?'':' — recomputed winner differs from the recorded one')+'.';}
            })();
            JS;
        } else {
            $reveal = '<div class="gv-pending">The winner hasn\'t been drawn yet. The secret seed is revealed here the moment it is, so you can confirm it matches the commitment above.</div>'
                .'<div class="gv-kv"><span class="gv-k">Entrants so far</span><span class="gv-note">'.count($ids).'</span></div>';
            $js = '';
        }

        $body = '<div class="gv-wrap gv-narrow"><div class="gv-crumbs"><a href="/giveaways">Giveaways</a> <span>/</span> Verify</div>'
            .'<div class="gv-card"><div class="gv-badge">'.($drawn ? '✅ Drawn' : '⏳ Open').'</div>'
            .'<h1 class="gv-h2">'.self::e($g->title).'</h1><div class="gv-prize">🏆 '.self::e($g->prize).'</div>'
            .'<div class="gv-how">Winner = the entrant whose <code>sha256(seed : user-id)</code> is lowest. '
            .'The seed is committed (hashed) before entries open and revealed at the draw, so the operator can\'t change it after the fact.</div>'
            .$commit.$reveal.'</div></div>';

        return ExtPage::render('Verify giveaway', $body, self::css(), $js);
    }

    // --- Admin -------------------------------------------------------------

    private static function adminPage(): \Inertia\Response
    {
        $body = <<<HTML
        <div class="gv-wrap gv-narrow">
          <div class="gv-hero gv-hero-sm">
            <div class="gv-eyebrow">Giveaways</div>
            <h1 class="gv-h1">Run a giveaway</h1>
            <p class="gv-sub">The latest active giveaway shows in the forum sidebar and on the public <a href="/giveaways" target="_blank">Giveaways</a> page. Draws are provably fair from a pre-committed seed.</p>
          </div>
          <div class="gv-card">
            <label class="gv-f">Title</label><input id="title" placeholder="December community giveaway">
            <label class="gv-f">Prize</label><input id="prize" placeholder="$50 gift card">
            <label class="gv-f">Description (optional)</label><textarea id="description" rows="2"></textarea>
            <label class="gv-f">Ends at (optional — auto-draws when reached)</label><input id="ends_at" type="datetime-local">
            <div class="gv-create"><button class="gv-btn gv-btn-p" id="add">Create giveaway</button></div>
          </div>
          <div class="gv-card"><div id="list" class="gv-muted">Loading…</div></div>
        </div>
        HTML;

        return ExtPage::render('Giveaways', $body, self::css(), self::adminJs());
    }

    private static function adminJs(): string
    {
        return <<<JS
        function notify(m,k){try{if(window.parent!==window)window.parent.postMessage({type:'convoro:toast',message:m,kind:k||'success'},location.origin);}catch(e){}}
        var esc=function(s){return (s||'').replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});};
        function load(){fetch('/admin/ext/giveaways/list',{headers:H}).then(function(r){return r.json();}).then(function(rows){
          var el=document.getElementById('list');
          if(!rows.length){el.innerHTML='<p class="gv-muted">No giveaways yet.</p>';return;}
          el.innerHTML=rows.map(function(g){
            var status=g.drawnAt?'drawn':(g.active?'on':'off');
            var win=g.winner?(' · winner: <span class="gv-win">'+esc(g.winner)+'</span>'):'';
            var seedLine=g.seed?('seed: '+esc(g.seed)):('commit: '+esc(g.seedHash));
            var actions='<a class="gv-btn gv-btn-ghost" href="/giveaways/'+g.id+'/verify" target="_blank">Verify</a>';
            if(!g.drawnAt){actions='<button class="gv-btn gv-btn-ghost" data-act="draw" data-id="'+g.id+'">Draw winner</button>'
              +'<button class="gv-btn gv-btn-ghost" data-act="tog" data-id="'+g.id+'">'+(g.active?'Close':'Reopen')+'</button>'+actions;}
            actions+='<button class="gv-btn gv-btn-x" data-act="del" data-id="'+g.id+'">Delete</button>';
            return '<div class="gv-row"><span class="gv-dot gv-'+status+'"></span>'
              +'<div class="gv-row-b"><div class="gv-row-t">'+esc(g.title)+' — '+esc(g.prize)+'</div>'
              +'<span class="gv-tag">'+g.entries+' entries'+win+'</span>'
              +'<div class="gv-seed">'+seedLine+'</div></div>'
              +'<div class="gv-row-a">'+actions+'</div></div>';
          }).join('');
        });}
        function add(){var body={title:document.getElementById('title').value.trim(),prize:document.getElementById('prize').value.trim(),
          description:document.getElementById('description').value.trim(),ends_at:document.getElementById('ends_at').value||null};
          if(!body.title||!body.prize){notify('Title and prize are required','error');return;}
          fetch('/admin/ext/giveaways',{method:'POST',headers:H,body:JSON.stringify(body)}).then(function(r){
            if(r.ok){document.getElementById('title').value='';document.getElementById('prize').value='';document.getElementById('description').value='';document.getElementById('ends_at').value='';notify('Giveaway created');load();}
            else notify('Could not create','error');});}
        function draw(id){if(!confirm('Draw a winner now? This closes the giveaway and reveals the seed.'))return;
          fetch('/admin/ext/giveaways/'+id+'/draw',{method:'POST',headers:H}).then(function(r){return r.json().then(function(d){return {ok:r.ok,d:d};});}).then(function(x){
            if(x.ok&&x.d.ok){notify('Winner: '+x.d.winner);}else{notify(x.d.message||'Could not draw','error');}load();});}
        function tog(id){fetch('/admin/ext/giveaways/'+id+'/toggle',{method:'POST',headers:H}).then(function(){load();});}
        function del(id){if(!confirm('Delete this giveaway? This removes its entries too.'))return;
          fetch('/admin/ext/giveaways/'+id,{method:'DELETE',headers:H}).then(function(){notify('Deleted');load();});}
        document.getElementById('add').addEventListener('click',add);
        document.getElementById('list').addEventListener('click',function(ev){var b=ev.target.closest('button[data-act]');if(!b)return;
          var id=b.getAttribute('data-id'),act=b.getAttribute('data-act');if(act==='draw')draw(id);else if(act==='tog')tog(id);else if(act==='del')del(id);});
        load();
        JS;
    }

    // --- Shared styling ----------------------------------------------------

    private static function css(): string
    {
        return <<<CSS
        .gv-wrap{max-width:900px;margin:0 auto;padding:24px 16px 64px}
        .gv-narrow{max-width:680px}
        .ext-embed .gv-wrap{padding:0}
        .gv-muted{color:rgb(var(--c-muted));font-size:14px}
        .gv-hero{padding:28px 30px;margin-bottom:22px;border-radius:18px;border:1px solid rgb(var(--c-border));
          background:linear-gradient(135deg,rgba(91,91,214,.16),rgba(139,92,246,.10)),rgb(var(--c-surface))}
        .gv-hero-sm{padding:22px 24px}
        .gv-eyebrow{font-size:13px;font-weight:800;letter-spacing:.04em;color:rgb(var(--c-primary));margin-bottom:6px}
        .gv-h1{font-size:1.9rem;font-weight:900;letter-spacing:-.02em;margin:0;color:rgb(var(--c-text))}
        .gv-h2{font-size:1.4rem;font-weight:800;margin:0 0 4px;color:rgb(var(--c-text))}
        .gv-h3{font-size:1.05rem;font-weight:800;margin:0 0 12px;color:rgb(var(--c-text))}
        .gv-sub{margin:8px 0 0;color:rgb(var(--c-text-2));font-size:14px;line-height:1.55;max-width:620px}
        .gv-sub a,.gv-crumbs a{color:rgb(var(--c-primary));font-weight:600;text-decoration:none}
        .gv-card{background:rgb(var(--c-surface));border:1px solid rgb(var(--c-border));border-radius:16px;padding:22px;margin-bottom:16px}
        .gv-active{border-color:rgb(var(--c-primary)/.5)}
        .gv-badge{display:inline-block;font-size:12px;font-weight:800;letter-spacing:.03em;color:rgb(var(--c-primary));
          background:rgb(var(--c-primary)/.10);padding:4px 10px;border-radius:999px;margin-bottom:12px}
        .gv-t{font-size:1.3rem;font-weight:800;margin:0 0 4px;color:rgb(var(--c-text))}
        .gv-prize{font-size:15px;color:rgb(var(--c-text-2));margin-bottom:8px}
        .gv-desc{color:rgb(var(--c-text-2));line-height:1.6;margin:0 0 10px}
        .gv-meta{font-size:13px;color:rgb(var(--c-muted));margin-bottom:14px}
        .gv-actions{display:flex;gap:8px}
        .gv-f{display:block;font-size:13px;font-weight:600;color:rgb(var(--c-text-2));margin:14px 0 5px}
        .gv-card input,.gv-card textarea{width:100%;font:inherit;font-size:14px;padding:10px 12px;border-radius:10px;
          border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface-2));color:rgb(var(--c-text))}
        .gv-card input:focus,.gv-card textarea:focus{outline:none;border-color:rgb(var(--c-primary))}
        .gv-create{margin-top:16px}
        .gv-btn{font:inherit;font-size:13.5px;font-weight:700;padding:9px 15px;border-radius:10px;border:1px solid rgb(var(--c-border));
          background:rgb(var(--c-surface));color:rgb(var(--c-text));cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
        .gv-btn-p{background:rgb(var(--c-primary));border-color:rgb(var(--c-primary));color:#fff}
        .gv-btn-p:hover{filter:brightness(1.06)}
        .gv-btn-ghost{background:rgb(var(--c-surface-2))}
        .gv-btn-x{color:#e5484d;border-color:transparent;background:rgba(229,72,77,.08)}
        .gv-row{display:flex;align-items:center;gap:12px;padding:14px 0;border-bottom:1px solid rgb(var(--c-border))}
        .gv-row:last-child{border-bottom:0}
        .gv-row-b{flex:1;min-width:0}
        .gv-row-t{font-weight:700;color:rgb(var(--c-text))}
        .gv-row-m{font-size:13px;color:rgb(var(--c-muted));margin-top:2px}
        .gv-row-a{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}
        .gv-tag{font-size:12px;color:rgb(var(--c-muted))}
        .gv-win{color:#10b981;font-weight:700}
        .gv-seed{font-family:ui-monospace,monospace;font-size:11px;color:rgb(var(--c-muted));word-break:break-all;margin-top:3px}
        .gv-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}
        .gv-on{background:#10b981}.gv-off{background:rgb(var(--c-muted))}.gv-drawn{background:rgb(var(--c-primary))}
        .gv-list{display:flex;flex-direction:column}
        .gv-row-b .gv-row-t{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        a.gv-row{display:flex;align-items:center;gap:12px;padding:13px 0;border-bottom:1px solid rgb(var(--c-border));text-decoration:none;color:inherit}
        a.gv-row:last-child{border-bottom:0}
        .gv-verify{font-size:13px;font-weight:700;color:rgb(var(--c-primary))}
        .gv-blank{text-align:center;padding:48px 20px;border:1.5px dashed rgb(var(--c-border));border-radius:16px}
        .gv-blank-ico{font-size:40px;margin-bottom:8px}
        .gv-blank-t{font-weight:800;color:rgb(var(--c-text))}
        .gv-crumbs{font-size:13px;color:rgb(var(--c-muted));margin-bottom:14px}
        .gv-crumbs span{margin:0 4px}
        .gv-how{font-size:13.5px;color:rgb(var(--c-text-2));line-height:1.6;margin:10px 0 16px}
        .gv-how code,.gv-code{font-family:ui-monospace,monospace}
        .gv-kv{margin:14px 0}
        .gv-k{display:block;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:rgb(var(--c-muted));margin-bottom:5px}
        .gv-code{display:block;font-size:12.5px;background:rgb(var(--c-surface-2));border:1px solid rgb(var(--c-border));border-radius:9px;padding:9px 11px;color:rgb(var(--c-text));word-break:break-all}
        .gv-note{font-size:12px;color:rgb(var(--c-muted))}
        .gv-win{color:#10b981;font-weight:700}
        .gv-pending{font-size:14px;color:rgb(var(--c-text-2));background:rgb(var(--c-surface-2));border-radius:10px;padding:12px 14px;margin:14px 0}
        .gv-check{margin-top:16px;font-size:13.5px;font-weight:600;padding:11px 13px;border-radius:10px;background:rgb(var(--c-surface-2));color:rgb(var(--c-text-2))}
        .gv-ok{background:rgba(16,185,129,.12);color:#059669}
        .gv-bad{background:rgba(229,72,77,.12);color:#e5484d}
        CSS;
    }
}
